<?php

namespace NextJsRevalidate;

use DateTime;
use DateTimeZone;
use NextJsRevalidate;

class RevalidateQueue {

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
	 * Reset the table auto increment id counter
	 */
	private function reset_queue() {
		global $wpdb;
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
}
