<?php

namespace NextJsRevalidate;

use NextJsRevalidate;

class RevalidateQueue {

	/**
	 * @var string
	 */
	private $table_name;

	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'revalidate_queue';
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

		$njr = NextJsRevalidate::init();
		$njr->revalidate->schedule_next_cron();

		return $inserted;
	}

	/**
	 * Get the next item in the queue and delete it from the queue
	 *
	 * @return string|null
	 */
	public function get_next_item() {
		global $wpdb;
		$wpdb->query("START TRANSACTION");

		$row = $wpdb->get_row("SELECT * FROM `$this->table_name` ORDER BY `priority` ASC, `id` ASC LIMIT 1 FOR UPDATE");

		if ($row) {
			$wpdb->delete($this->table_name, ['id' => $row->id]);
		}

		$wpdb->query("COMMIT");

		return $row->permalink ?? null;
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
}
