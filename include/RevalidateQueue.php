<?php

namespace NextJsRevalidate;

use DateTime;
use DateTimeZone;
use NextJsRevalidate\Abstracts\Base;
use NextJsRevalidate\Interfaces\Hookable;
use NextJsRevalidate\Traits\SendbackUrl;
use WP_Error;

class RevalidateQueue extends Base implements Hookable {
	use SendbackUrl;

	const CRON_HOOK_NAME = 'nextjs_revalidate-queue';
	const CRON_TRANSCIENT_NAME = 'nextjs_revalidate-running_queue';

	const MAX_NB_RUNNING_CRON = 4;

	public function register_hooks(): void {
		add_action( 'admin_init', [$this, 'action_reset_queue'] );
		add_action( 'admin_init', [$this, 'ajax_queue_progress'] );
		add_action( 'admin_notices', [$this, 'admin_queue_notice'] );

		add_action( self::CRON_HOOK_NAME, [$this, 'run_cron'] );
	}

	/**
	 * The queue table of the site currently being served.
	 *
	 * Derived at call time and never cached: the prefix follows
	 * switch_to_blog(), so a value kept from construction would name the
	 * table of whichever site happened to build this instance.
	 *
	 * @return string
	 */
	private function get_table_name() {
		global $wpdb;

		return $wpdb->prefix . 'revalidate_queue';
	}

	/**
	 * The timezone of the site currently being served.
	 *
	 * Derived at call time, for the same reason as the table name.
	 *
	 * @return DateTimeZone
	 */
	private function get_timezone() {
		return new DateTimeZone( get_option('timezone_string') ?: 'Europe/Zurich' );
	}

	/**
	 * Create the custom table to hold the queue
	 *
	 * @return void
	 */
	public function create_table() {
		global $wpdb;

		$table_name = $this->get_table_name();

		// Do not continue if table already exists
		if($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) return;

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE `{$table_name}` (
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

		$table_name = $this->get_table_name();

		$wpdb->query("DROP TABLE IF EXISTS {$table_name}");
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
	 * @return bool|WP_Error Whether the permalink was added to the queue.
	 *                       A `not_configured` WP_Error when the site is
	 *                       unconfigured and the revalidation is refused.
	 */
	public function add_item( $permalink, $priority = 10 ) {
		global $wpdb;

		// Refuse rather than accept a revalidation which could never be
		// delivered: an item queued on an unconfigured site is dropped at
		// drain time, without a trace, long after anyone could connect it
		// to the edit that produced it.
		if ( !$this->settings->is_configured() ) {
			Logger::log(
				sprintf( '⛔ Refused %s — site not configured (missing: %s)', $permalink, implode(', ', $this->settings->missing_settings()) ),
				__FILE__,
				Logger::ERROR
			);

			return $this->settings->not_configured_error();
		}

		$table_name = $this->get_table_name();

		$inserted = false;

		$wpdb->query("START TRANSACTION");

		// Prepared rather than interpolated: a permalink is not this plugin's own
		// string. It arrives from the REST route, which sanitises with
		// `sanitize_text_field()` — that leaves a quote intact — and from the
		// Redirection integration, whose source paths are whatever an editor typed.
		// A quote in either would break this statement and reach past it. The
		// insert below was always escaped by `$wpdb->insert()`; this was the one
		// raw statement in the queue that carried anything but a table name.
		$already_queued = $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `$table_name` WHERE `permalink` = %s", $permalink )
		);

		if (intval($already_queued) === 0) {
			$inserted = $wpdb->insert(
				$table_name,
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

		$table_name = $this->get_table_name();

		$wpdb->query("START TRANSACTION");

		$item = $wpdb->get_row("SELECT * FROM `$table_name` ORDER BY `priority` ASC, `id` ASC LIMIT 1 FOR UPDATE");

		if ($item) {
			$item = new RevalidateItem( $item );
			$wpdb->delete($table_name, ['id' => $item->id]);
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

		$table_name = $this->get_table_name();

		return $wpdb->get_results("SELECT * FROM `$table_name` ORDER BY `priority` ASC, `id` ASC");
	}

	/**
	 * Clear the queue and reset the auto increment
	 *
	 * @return void
	 */
	private function reset_queue() {
		global $wpdb;

		$table_name = $this->get_table_name();

		$wpdb->query("TRUNCATE TABLE `$table_name`");

		$wpdb->query("ALTER TABLE `$table_name` AUTO_INCREMENT = 1");
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

		$next_cron_datetime = new DateTime( 'now', $this->get_timezone() );
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
				$outcome = $this->revalidate->purge( $item->permalink );

				$t_to_revalidate = microtime(true) - $rev_start;

				// `get_next_item()` has already deleted the row, and delivery is
				// at most once, so this is the only moment the attempt is ever
				// accounted for. The window is what an operator who has not
				// switched logging on will see; the log line below is for one
				// who has.
				//
				// A refusal is not evidence about the front-end — an
				// unconfigured site was never attempted against it — so it is
				// not an outcome the window records. The queue refuses at the
				// door, so this only guards against ever being reached.
				$refused = is_wp_error( $outcome ) && 'not_configured' === $outcome->get_error_code();
				if ( !$refused ) FailureWindow::record( $outcome );

				$this->log_purge_outcome( $id, $item, $outcome, $t_to_revalidate );
			}

		} while ($item && $max_exec_time > (time() - $start_time) );

		$this->remove_running_cron();
		$this->schedule_next_cron();
	}

	/**
	 * Write what became of one drained item.
	 *
	 * The item is already gone from the queue by the time this runs — delivery
	 * is at most once, so a **failure** is recorded here and dropped, and this
	 * line is the only surface it ever appears on. Which is why it names the
	 * error code `purge()` came back with instead of a bare ❌: `unreachable`
	 * and `http_401` send an operator to completely different places.
	 * See `docs/adr/0004-at-most-once-revalidation.md`.
	 *
	 * @param string          $id        The id of the drain writing the line.
	 * @param RevalidateItem  $item      The item that was drained.
	 * @param true|WP_Error   $outcome   What `Revalidate::purge()` answered.
	 * @param float           $duration  Seconds the attempt took.
	 *
	 * @return void
	 */
	private function log_purge_outcome( $id, $item, $outcome, $duration ) {

		if ( ! is_wp_error($outcome) ) {
			Logger::log( "#$id: ✅ Revalidated in {$duration}s {$item->permalink} (priority: {$item->priority})", __FILE__ );
			return;
		}

		// A **refusal** is not a **failure**: an unconfigured site declined to
		// deliver rather than tried and missed. Both end the revalidation's life
		// here, and they send an operator to different places.
		$is_refusal = ( 'not_configured' === $outcome->get_error_code() );

		Logger::log(
			sprintf(
				'#%s: %s %s after %ss %s (priority: %s) — %s: %s',
				$id,
				$is_refusal ? '⛔' : '❌',
				$is_refusal ? 'Refused' : 'Failed to revalidate',
				$duration,
				$item->permalink,
				$item->priority,
				$outcome->get_error_code(),
				$outcome->get_error_message()
			),
			__FILE__,
			Logger::ERROR
		);
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
