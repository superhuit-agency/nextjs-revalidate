<?php

namespace NextJsRevalidate;

use DateTime;
use DateTimeZone;
use NextJsRevalidate\Abstracts\Base;
use NextJsRevalidate\Traits\SendbackUrl;

class RevalidateQueue extends Base {
	use SendbackUrl;

	const CRON_HOOK_NAME = 'nextjs_revalidate-queue';
	const CRON_TRANSCIENT_NAME = 'nextjs_revalidate-running_queue';

	const MAX_NB_RUNNING_CRON = 4;

	/**
	 * @var string
	 */
	private $table_name;

	/**
	 * @var DateTimeZone
	 */
	private $timezone;

	public function __construct() {
		global $wpdb;

		$this->table_name = $wpdb->prefix . 'revalidate_queue';
		$this->timezone = new DateTimeZone( get_option('timezone_string') ?: 'Europe/Zurich' );

		add_action( 'admin_init', [$this, 'action_reset_queue'] );
		add_action( 'admin_init', [$this, 'ajax_queue_progress'] );
		add_action( 'admin_notices', [$this, 'admin_queue_notice'] );

		add_action( self::CRON_HOOK_NAME, [$this, 'run_cron'] );
	}

	/**
	 * Create the custom table to hold the queue
	 *
	 * @return void
	 */
	public function create_table() {
		global $wpdb;

		// Do not continue if table already exists
		if($wpdb->get_var("SHOW TABLES LIKE '$this->table_name'") === $this->table_name) return;

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE `{$this->table_name}` (
			id         bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			permalink  text                NOT NULL,
			priority   int(10)             NOT NULL DEFAULT 10,
			UNIQUE KEY permalink (permalink),
			PRIMARY KEY  (id)
		) $charset_collate;";

		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($sql);
	}

	/**
	 * Delete the custom table
	 *
	 * @return void
	 */
	public function delete_table() {
		global $wpdb;

		$wpdb->query("DROP TABLE IF EXISTS {$this->table_name}");
	}

	/**
	 * Add a item to the queue
	 *
	 * @param string $permalink
	 * @param int    $priority   Optional. Used to specify the order in which
	 *                           the url are purged. Lower numbers correspond
	 *                           with earlier purge, and urls with the same
	 *                           priority are executed in the order in which
	 *                           they were added. Default 10.
	 *
	 * @return bool Wether the permalink was added to the queue
	 */
	public function add_item( $permalink, $priority = 10 ) {
		global $wpdb;

		$inserted = false;

		$wpdb->query("START TRANSACTION");
		$in_db = $wpdb->get_results("SELECT * FROM `$this->table_name` WHERE `permalink`='$permalink'");
		if (count($in_db) === 0) {
			$inserted = $wpdb->insert(
				$this->table_name,
				[
					'permalink' => $permalink,
					'priority'  => $priority
				]
			);
		}
		else {
			$inserted = true;
		}

		$wpdb->query("COMMIT");

		$this->schedule_next_cron();

		return $inserted;
	}

	/**
	 * Get the next item in the queue and delete it from the queue
	 *
	 * @return RevalidateItem|null
	 */
	public function get_next_item() {
		global $wpdb;
		$wpdb->query("START TRANSACTION");

		$item = $wpdb->get_row("SELECT * FROM `$this->table_name` ORDER BY `priority` ASC, `id` ASC LIMIT 1 FOR UPDATE");

		if ($item) {
			$item = new RevalidateItem( $item );
			$wpdb->delete($this->table_name, ['id' => $item->id]);
		}

		$wpdb->query("COMMIT");

		return $item;
	}

	/**
	 * Get all items in queue
	 *
	 * @return array
	 */
	public function get_queue() {
		global $wpdb;
		return $wpdb->get_results("SELECT * FROM `$this->table_name` ORDER BY `priority` ASC, `id` ASC");
	}

	/**
	 * Clear the queue and reset the auto increment
	 *
	 * @return void
	 */
	private function reset_queue() {
		global $wpdb;

		$wpdb->query("TRUNCATE TABLE `$this->table_name`");

		$wpdb->query("ALTER TABLE `$this->table_name` AUTO_INCREMENT = 1");
	}

	/**
	 * Schedule the cron queue to run
	 */
	private function schedule_next_cron() {
		if ( wp_next_scheduled(self::CRON_HOOK_NAME) ) return;

		// Do not schedule if queue is empty
		if ( count($this->get_queue()) === 0 ) {
			$this->reset_queue();
			return;
		}

		$next_cron_datetime = new DateTime( 'now', $this->timezone );
		wp_schedule_single_event( $next_cron_datetime->getTimestamp(), self::CRON_HOOK_NAME );
	}

	public function unschedule_cron() {
		wp_unschedule_hook( self::CRON_HOOK_NAME );
	}

	private function add_running_cron() {
		$nb_running_cron = get_transient( self::CRON_TRANSCIENT_NAME );
		$nb_running_cron = intval(false === $nb_running_cron ? 0 : $nb_running_cron);

		if ( $nb_running_cron >= self::MAX_NB_RUNNING_CRON ) return false;

		$nb_running_cron++;
		set_transient( self::CRON_TRANSCIENT_NAME, $nb_running_cron, 3600 );

		$this->schedule_next_cron();

		Logger::log( '------', __FILE__ );
		Logger::log( "🆕 revalidate cron (nb running: $nb_running_cron)", __FILE__ );

		return $nb_running_cron;
	}

	private function remove_running_cron() {
		$nb_running_cron = get_transient( self::CRON_TRANSCIENT_NAME );
		$nb_running_cron = intval(false === $nb_running_cron ? 0 : $nb_running_cron);

		$nb_running_cron--;
		if ( $nb_running_cron > 0 ) set_transient( self::CRON_TRANSCIENT_NAME, $nb_running_cron, 3600 );
		else delete_transient( self::CRON_TRANSCIENT_NAME );

		Logger::log( "🗑️ (remove) revalidate cron (nb still running: $nb_running_cron)", __FILE__ );

		return true;
	}

	/**
	 * Run the cron
	 * Will run multiple items in the queue
	 * until the max execution time is reached
	 */
	public function run_cron() {
		$n_cron = $this->add_running_cron();
		if ( false === $n_cron ) return;

		$id = uniqid();
		$start_time = time();

		Logger::log( "#$id: Start revalidate queue", __FILE__ );

		// get max php exec time
		$max_exec_time = ini_get('max_execution_time');
		$max_exec_time = $max_exec_time ? $max_exec_time : 60;

		Logger::log( "#$id: Revalidate queue will be running for max $max_exec_time seconds" , __FILE__ );

		// Remove 5% as a safety margin
		$max_exec_time = $max_exec_time * 0.95;

		do {
			$rev_start = microtime(true);
			$item = $this->get_next_item();

			if ( $item ) {
				$this->revalidate->purge( $item->permalink );

				$t_to_revalidate = microtime(true) - $rev_start;
				Logger::log("#$id: ✅ Revalidated in {$t_to_revalidate}s {$item->permalink} (priority: {$item->priority})", __FILE__);
			}

		} while ($item && $max_exec_time > (time() - $start_time) );

		$this->remove_running_cron();
		$this->schedule_next_cron();
	}

	function admin_queue_notice() {

		$queue = $this->get_queue();
		$nb_left = count($queue);
		if ( $nb_left > 0 ) {
			printf(
				'<div class="notice notice-info nextjs-revalidate-queue__notice"><p>%s</p></div>',
				sprintf(
					__( 'Purging caches. Please wait… %s%s', 'nextjs-revalidate' ),
					sprintf('<span class="nextjs-revalidate-queue__progress">%d page(s) left to purge.</span>', $nb_left ),
					user_can( get_current_user_id(), 'manage_options' )
						? sprintf(
							' <a href="%s">%s</a>',
								admin_url( 'options-general.php?page='. Settings::PAGE_NAME .'#tab-queue'),
							__( 'View purge caches queue', 'nextjs-revalidate' )
					) : ''
				)
			);
		}

		if ( isset( $_GET['nextjs-revalidate-queue-resetted'] ) ) {
			printf(
				'<div class="notice notice-success"><p>%s</p></div>',
				__( 'Queue correctly resetted.', 'nextjs-revalidate' )
			);
		}
	}

	/**
	 * Ajax callback to get the queue progress data
	 */
	function ajax_queue_progress() {
		if ( !isset($_GET['action']) || $_GET['action'] !== 'nextjs-revalidate-queue-progress' ) return;

		if ( false === check_ajax_referer( 'nextjs-revalidate-revalidate_queue_progress' ) ) return;

		$queue = $this->get_queue();
		$nb_left = count($queue);

		$status = $nb_left > 0 ? 'running' : 'done';

		wp_send_json_success( [
			'status' => $status,
			'nbLeft' => $nb_left,
		] );
		exit;
	}

	function action_reset_queue() {
		if ( !(isset($_POST['option_page']) && $_POST['option_page'] === 'nextjs-revalidate-settings') ) return;
		if ( !isset($_POST['revalidate_reset_queue']) ) return;

		$this->reset_queue();

		$sendback  = $this->get_sendback_url();

		wp_safe_redirect(
			add_query_arg( [ 'nextjs-revalidate-queue-resetted' => 1 ], $sendback )
		);
		exit;
	}
}
