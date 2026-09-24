<?php
/**
 * The upgrade to 2.0 drops the revalidation queue — ADR 0034.
 *
 * v2 delivers every change when the request that produced it ends, so the
 * queue v1 kept per site — a table, a cron that drained it, and a transient
 * counting the drains running — has nothing left to do, and `migrate_db()`
 * drops it. Pending rows are dropped rather than converted, and counted in the
 * log. The three settings v2 removed go in the same pass.
 *
 * The upgrade is guarded on the data rather than on the migration ledger: a
 * 1.6.9 site upgrading straight to 2.0 holds no ledger and no fingerprint, is
 * backfilled to the running release, and a `< 2.0.0` gate would never fire for
 * it (ADR 0017). The standalone `tests/MigrationLedgerTest.php` pins the same
 * decisions against a database double; what only this suite can see is a real
 * table being found, counted and dropped by MySQL, a real cron array and the
 * real log file.
 *
 * Real DDL, so the test library's rewrite of `CREATE TABLE` into a temporary
 * one is switched off here: `SHOW TABLES` does not list a temporary table, and
 * the upgrade would never find the queue it is about. DDL commits, so whatever
 * a test wrote is past the rollback, and `tear_down()` undoes it by hand — the
 * table, the cron event, the options and the log.
 *
 * @package NextJsRevalidate
 */

namespace NextJsRevalidate\Tests;

use NextJsRevalidate\Logger;
use NextJsRevalidate\Settings;
use WP_UnitTestCase;

class QueueRemovalUpgradeTest extends WP_UnitTestCase {

	/**
	 * Every option a test here may write, deleted by hand on the way out.
	 */
	const OPTIONS = [
		Settings::DB_VERSION_OPTION_NAME,
		Settings::LEGACY_URL_OPTION_NAME,
		Settings::SETTINGS_DOMAIN_NAME,
		Settings::SETTINGS_ENDPOINT_PATH_NAME,
		Settings::SETTINGS_SECRET_NAME,
		Settings::SETTINGS_DEBUG,
		Settings::LEGACY_FSE_ENDPOINT_PATH_NAME,
		Settings::LEGACY_REVALIDATE_ON_FSE_SAVE,
		Settings::LEGACY_REVALIDATE_ON_MENU_SAVE,
		Logger::SUFFIX_OPTION_NAME,
	];

	/**
	 * Make this class's DDL real. See the file's docblock.
	 */
	public function set_up() {
		parent::set_up();

		remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );
	}

	public function tear_down() {
		global $wpdb;

		// The log first: its path is composed from an option deleted below.
		$this->remove_log();

		$wpdb->query( "DROP TABLE IF EXISTS `{$this->queue_table()}`" );
		wp_unschedule_hook( Settings::LEGACY_QUEUE_CRON_HOOK_NAME );
		delete_transient( Settings::LEGACY_QUEUE_RUNNING_TRANSIENT_NAME );

		foreach ( self::OPTIONS as $option_name ) delete_option( $option_name );

		parent::tear_down();
	}

	// The upgrade
	// ====

	/**
	 * A 1.7 site with paths still waiting: the table goes, the count goes to the
	 * log, the drain is unscheduled, and the removed settings are deleted.
	 */
	public function test_a_site_holding_queued_rows_upgrades_to_no_queue() {
		$this->given_a_1_7_site_with_a_queue( 3 );

		\NextJsRevalidate::init()->settings->migrate_db();

		$this->assertFalse( $this->queue_table_exists(), 'The queue table survived the upgrade.' );
		$this->assertStringContainsString(
			'🗑️ Upgraded to 2.0: dropped the revalidation queue, and the 3 path(s) still waiting in it',
			$this->log(),
			'The rows the queue still held were not counted in the log.'
		);
		$this->assertFalse( wp_next_scheduled( Settings::LEGACY_QUEUE_CRON_HOOK_NAME ), 'The queue cron is still scheduled.' );
		$this->assertFalse( get_transient( Settings::LEGACY_QUEUE_RUNNING_TRANSIENT_NAME ), 'The count of running drains survived the upgrade.' );

		foreach ( [ Settings::LEGACY_FSE_ENDPOINT_PATH_NAME, Settings::LEGACY_REVALIDATE_ON_FSE_SAVE, Settings::LEGACY_REVALIDATE_ON_MENU_SAVE ] as $removed ) {
			$this->assertFalse( $this->option_exists( $removed ), "The removed setting $removed is still stored." );
		}
	}

	/**
	 * The upgrade runs on every admin request, and after the first there is
	 * nothing for it to find: no write reaches the database, and nothing more
	 * reaches the log.
	 */
	public function test_a_second_admin_request_changes_nothing() {
		$this->given_a_1_7_site_with_a_queue( 3 );

		$settings = \NextJsRevalidate::init()->settings;
		$settings->migrate_db();

		$log  = $this->log();
		$cron = _get_cron_array();

		$writes = [];
		$record = function ( $query ) use ( &$writes ) {
			if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|DROP|CREATE|ALTER|TRUNCATE)\b/i', $query ) ) $writes[] = $query;
			return $query;
		};

		add_filter( 'query', $record );
		$settings->migrate_db();
		remove_filter( 'query', $record );

		$this->assertSame( [], $writes, 'A second admin request wrote to the database.' );
		$this->assertSame( $log, $this->log(), 'A second admin request logged something.' );
		$this->assertSame( $cron, _get_cron_array(), 'A second admin request changed the cron array.' );
	}

	/**
	 * The whole road from 1.6.9 in one pass: no ledger, the single legacy URL,
	 * the log at its legacy path, and a queue table with rows in it. The 1.7
	 * migrations still run for it — the URL split and the log move, both
	 * guarded on the data themselves — and the queue goes in the same request.
	 */
	public function test_a_1_6_9_site_reaches_the_2_0_shape_in_one_pass() {
		delete_option( Settings::DB_VERSION_OPTION_NAME );
		delete_option( Settings::SETTINGS_DOMAIN_NAME );
		update_option( Settings::LEGACY_URL_OPTION_NAME, 'https://front-end.test/api/revalidate' );
		update_option( Settings::SETTINGS_SECRET_NAME, 's3cret' );
		update_option( Settings::LEGACY_REVALIDATE_ON_FSE_SAVE, 'on' );
		update_option( Settings::SETTINGS_DEBUG, [ 'enable-logs' => 'on' ] );

		$legacy_log = trailingslashit( wp_upload_dir()['basedir'] ) . Logger::LEGACY_FILENAME;
		file_put_contents( $legacy_log, "a line 1.6.9 wrote\n" );

		$this->create_queue_table( 5 );
		wp_schedule_single_event( time() + 60, Settings::LEGACY_QUEUE_CRON_HOOK_NAME );

		\NextJsRevalidate::init()->settings->migrate_db();

		$this->assertSame( NJR_VERSION, get_option( Settings::DB_VERSION_OPTION_NAME ), 'The site was not stamped.' );
		$this->assertSame( 'https://front-end.test', get_option( Settings::SETTINGS_DOMAIN_NAME ), 'The legacy URL was not split into a domain.' );
		$this->assertSame( '/api/revalidate', get_option( Settings::SETTINGS_ENDPOINT_PATH_NAME ), 'The legacy URL was not split into a path.' );
		$this->assertFalse( $this->option_exists( Settings::LEGACY_URL_OPTION_NAME ), 'The legacy URL survived its split.' );
		$this->assertFalse( $this->option_exists( Settings::LEGACY_REVALIDATE_ON_FSE_SAVE ), 'The removed FSE switch is still stored.' );

		$this->assertFileDoesNotExist( $legacy_log, 'The log was left at its legacy path.' );
		$this->assertStringContainsString( 'a line 1.6.9 wrote', $this->log(), 'The legacy log did not move into the plugin directory.' );
		$this->assertStringContainsString( 'and the 5 path(s) still waiting in it', $this->log(), 'The dropped rows were not counted in the moved log.' );

		$this->assertFalse( $this->queue_table_exists(), 'The queue table survived the upgrade.' );
		$this->assertFalse( wp_next_scheduled( Settings::LEGACY_QUEUE_CRON_HOOK_NAME ), 'The queue cron is still scheduled.' );
	}

	// Fixtures
	// ====

	/**
	 * A site 1.7 left behind: stamped, configured, logging, holding the three
	 * settings v2 removed, and a queue with rows waiting, its drain scheduled
	 * and one drain running.
	 *
	 * @param int $rows How many rows the queue holds.
	 * @return void
	 */
	private function given_a_1_7_site_with_a_queue( $rows ) {
		update_option( Settings::DB_VERSION_OPTION_NAME, '1.7.0' );
		update_option( Settings::SETTINGS_DOMAIN_NAME, 'https://front-end.test' );
		update_option( Settings::SETTINGS_SECRET_NAME, 's3cret' );
		update_option( Settings::SETTINGS_DEBUG, [ 'enable-logs' => 'on' ] );
		update_option( Settings::LEGACY_FSE_ENDPOINT_PATH_NAME, '/api/revalidate-fse' );
		update_option( Settings::LEGACY_REVALIDATE_ON_FSE_SAVE, 'on' );
		update_option( Settings::LEGACY_REVALIDATE_ON_MENU_SAVE, [ 'post' => 'on' ] );

		$this->create_queue_table( $rows );
		wp_schedule_single_event( time() + 60, Settings::LEGACY_QUEUE_CRON_HOOK_NAME );
		set_transient( Settings::LEGACY_QUEUE_RUNNING_TRANSIENT_NAME, 1, HOUR_IN_SECONDS );
	}

	/**
	 * The queue table as 1.7 created it, holding this many rows.
	 *
	 * @param int $rows
	 * @return void
	 */
	private function create_queue_table( $rows ) {
		global $wpdb;

		$table = $this->queue_table();

		$wpdb->query(
			"CREATE TABLE `$table` (
				id              bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				permalink       text                NOT NULL,
				permalink_hash  char(64)            NOT NULL,
				priority        int(10)             NOT NULL DEFAULT 10,
				UNIQUE KEY permalink_hash (permalink_hash),
				PRIMARY KEY  (id)
			) {$wpdb->get_charset_collate()}"
		);

		$this->assertTrue( $this->queue_table_exists(), 'The fixture queue table could not be created.' );

		for ( $i = 0; $i < $rows; $i++ ) {
			$permalink = home_url( "/page-$i/" );
			$wpdb->insert( $table, [ 'permalink' => $permalink, 'permalink_hash' => hash( 'sha256', $permalink ), 'priority' => 10 ] );
		}
	}

	/** @return string */
	private function queue_table() {
		global $wpdb;

		return $wpdb->prefix . Settings::LEGACY_QUEUE_TABLE_NAME;
	}

	/** @return bool */
	private function queue_table_exists() {
		global $wpdb;

		$table = $this->queue_table();

		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/**
	 * Whether the site holds a row for an option, whatever its value.
	 *
	 * @param string $name
	 * @return bool
	 */
	private function option_exists( $name ) {
		$absent = "\0njr-absent";

		return get_option( $name, $absent ) !== $absent;
	}

	/** @return string Everything the plugin has logged on this site. */
	private function log() {
		$path = Logger::path();

		return file_exists( $path ) ? (string) file_get_contents( $path ) : '';
	}

	/**
	 * The log is on disk rather than in the database, so no rollback reaches
	 * it: the file, its guards and its directory are removed by hand.
	 *
	 * @return void
	 */
	private function remove_log() {
		$legacy = trailingslashit( wp_upload_dir()['basedir'] ) . Logger::LEGACY_FILENAME;
		if ( file_exists( $legacy ) ) unlink( $legacy );

		if ( ! is_dir( Logger::directory() ) ) return;

		$path = Logger::path();
		if ( file_exists( $path ) ) unlink( $path );

		foreach ( array_keys( Logger::GUARDS ) as $guard ) {
			$file = Logger::directory() . '/' . $guard;
			if ( file_exists( $file ) ) unlink( $file );
		}

		@rmdir( Logger::directory() );
	}
}
