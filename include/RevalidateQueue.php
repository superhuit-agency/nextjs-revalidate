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
			id           bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			permalink    text                NOT NULL,
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
	 * @return void
	 */
	public function add_item($permalink) {
		global $wpdb;

		$wpdb->query("START TRANSACTION");
		$in_db = $wpdb->get_results("SELECT * FROM `$this->table_name` WHERE permalink='$permalink'");
		if (count($in_db) === 0) {
			$wpdb->insert(
				$this->table_name,
				[	'permalink' => $permalink ]
			);
		}

		$wpdb->query("COMMIT");

		$njr = NextJsRevalidate::init();
		$njr->revalidate->schedule_next_cron();
	}

	/**
	 * Get the next item in the queue and delete it from the queue
	 *
	 * @return string|null
	 */
	public function get_next_item() {
		global $wpdb;
		$wpdb->query("START TRANSACTION");

		$row = $wpdb->get_row("SELECT * FROM `$this->table_name` ORDER BY id ASC LIMIT 1 FOR UPDATE");

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
		return $wpdb->get_results("SELECT * FROM `$this->table_name`");
	}
}
