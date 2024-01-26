<?php

namespace NextJsRevalidate;

use DateTime;
use DateTimeZone;
use NextJsRevalidate;
use NextJsRevalidate\Traits\SendbackUrl;

class RevalidateQueue {
	use SendbackUrl;

	const CRON_HOOK_NAME = 'nextjs_revalidate-queue';
	const CRON_TRANSCIENT_NAME = 'nextjs_revalidate-running_queue';

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

	function __get( $name ) {
		if ( $name === 'njr' ) return NextJsRevalidate::init();
		return null;
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

	private function is_cron_already_running() {
		return false !== get_transient( self::CRON_TRANSCIENT_NAME );
	}

	/**
	 * Run the cron
	 * Will run multiple items in the queue
	 * until the max execution time is reached
	 */
	public function run_cron() {
		if ( $this->is_cron_already_running() ) return;
		set_transient( self::CRON_TRANSCIENT_NAME, true, 3600 );

		$start_time = time();

		// get max php exec time
		$max_exec_time = ini_get('max_execution_time');
		$max_exec_time = $max_exec_time ? $max_exec_time : 60;

		// Remove 5% as a safety margin
		$max_exec_time = $max_exec_time * 0.95;

		do {
			$item = $this->get_next_item();

			if ( $item ) $this->njr->revalidate->purge( $item->permalink );

		} while ($item && $max_exec_time > (time() - $start_time) );


		delete_transient( self::CRON_TRANSCIENT_NAME );

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
								admin_url( 'options-general.php?page='. Settings::PAGE_NAME .'#nextjs_revalidate-queue'),
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
