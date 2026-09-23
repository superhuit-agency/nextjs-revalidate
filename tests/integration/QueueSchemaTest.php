<?php
/**
 * The shape of the queue table, and what an upgrade does to one — issue #121.
 *
 * The queue deduplicates by permalink, and until this the database was asked to
 * enforce that with `UNIQUE KEY permalink (permalink)` over a `TEXT` column.
 * Only MariaDB accepts such a key, through the "long unique" feature it
 * implements with a hidden hash column; standard MySQL rejects the statement
 * (error 1170), `dbDelta()` inspects nothing, and the site ends up with no queue
 * table at all. The key is now on `permalink_hash`, which is that hidden column
 * made explicit — see `docs/adr/0027-the-queue-dedups-on-a-hash-of-the-permalink.md`.
 *
 * These tests read a real table's own metadata and run a real migration over it,
 * which is what sends them here rather than to a standalone script. The
 * companion that *does* run in the gate is
 * `tests/queue-schema-portability-test.php`, which holds the `CREATE TABLE` this
 * plugin composes to the rule the two engines disagree about — it needs no
 * database, and so cannot observe any of what is below.
 * See `docs/adr/0008-two-testing-idioms.md`.
 *
 * They are also the one place in this suite that rebuilds the table underneath
 * itself. `tear_down()` puts one back before anything else runs, because a test
 * that dies between the `DROP` and the `CREATE` would otherwise take every later
 * test with it.
 *
 * @package NextJsRevalidate
 */

namespace NextJsRevalidate\Tests;

use NextJsRevalidate\RevalidateQueue;

class QueueSchemaTest extends QueueTestCase {

	/**
	 * Leave a queue table behind whatever happened above.
	 *
	 * `create_table()` returns without doing anything when the table is there,
	 * so this costs one `SHOW TABLES` in every test that did not touch it.
	 */
	public function tear_down() {
		$this->queue()->create_table();

		parent::tear_down();
	}

	// The shape a fresh install is created with
	// ====

	/**
	 * The constraint the dedup rests on, read back off the table: one unique
	 * key, on the hash column, over the whole of it.
	 *
	 * `Sub_part` is the assertion that matters as much as `Non_unique`. A key
	 * with a prefix length would report a number there, and a prefix key is the
	 * portable answer this deliberately did not take: it collapses two distinct
	 * permalinks that share their first n characters.
	 */
	public function test_the_queue_table_is_keyed_uniquely_on_the_permalink_hash() {
		$key = $this->index_named( 'permalink_hash' );

		$this->assertNotNull( $key, 'The queue table carries no `permalink_hash` key at all.' );
		$this->assertSame( '0', (string) $key->Non_unique, 'The `permalink_hash` key is not UNIQUE.' );
		$this->assertSame( 'permalink_hash', $key->Column_name );
		$this->assertNull( $key->Sub_part, 'The `permalink_hash` key carries a prefix length; it should key the column whole.' );
	}

	/**
	 * And nothing keys the `TEXT` column any more — the declaration standard
	 * MySQL refuses.
	 */
	public function test_no_key_names_the_permalink_column() {
		$keyed = array_map(
			function ( $index ) {
				return $index->Column_name;
			},
			$this->indexes()
		);

		$this->assertNotContains( 'permalink', $keyed );
	}

	/**
	 * The permalink is stored whole, and the hash beside it is a digest of
	 * exactly what was stored — not of a normalised, trimmed or truncated
	 * version of it, which is the drift that would make the key stop meaning
	 * what the lookup means.
	 */
	public function test_an_entry_holds_the_permalink_whole_and_its_digest_beside_it() {
		global $wpdb;

		$this->configure_site();

		$this->enqueue( '/hello-world/' );

		$table = $this->queue_table();
		$entry = $wpdb->get_row( "SELECT `permalink`, `permalink_hash` FROM `$table` LIMIT 1" );

		$this->assertSame( $this->permalink_of( '/hello-world/' ), $entry->permalink );
		$this->assertSame( hash( RevalidateQueue::PERMALINK_HASH_ALGO, $entry->permalink ), $entry->permalink_hash );
	}

	/**
	 * The case a prefix key would have got wrong, and the reason the hash is
	 * worth a column: two permalinks that differ only past the 191st character
	 * are two pages, and the queue holds them both.
	 */
	public function test_two_permalinks_differing_only_past_a_prefix_are_two_entries() {
		$this->configure_site();

		$long  = '/' . str_repeat( 'a-very-long-slug/', 20 );
		$one   = $long . 'one/';
		$other = $long . 'other/';

		$this->assertGreaterThan( 191, strlen( $this->permalink_of( $long ) ), 'The fixture is not long enough to be the case this is about.' );

		$this->enqueue( $one );
		$this->enqueue( $other );

		$this->assertQueueRevalidates( [ $one, $other ] );
	}

	/**
	 * And the dedup it must not get wrong either: the same permalink twice is
	 * still one entry.
	 */
	public function test_the_same_permalink_twice_is_one_entry() {
		$this->configure_site();

		$this->enqueue( '/hello-world/' );
		$this->enqueue( '/hello-world/' );

		$this->assertQueueRevalidates( [ '/hello-world/' ] );
	}

	// What an upgrade does
	// ====

	/**
	 * A site upgrading from a release whose table was keyed on the `TEXT`
	 * column — which is to say, every MariaDB site there is.
	 *
	 * Skipped where the database refuses to create that table, because that
	 * refusal *is* the bug: on such a stack there is no legacy table to migrate,
	 * there never was one, and the population this covers does not exist.
	 */
	public function test_migrating_a_table_keyed_on_the_text_column_moves_the_key_onto_the_hash() {
		global $wpdb;

		if ( ! $this->rebuild_as_legacy_table( true ) ) {
			$this->markTestSkipped( 'This database refuses a UNIQUE KEY over a TEXT column, so no site on it has the table this migrates.' );
		}

		$table = $this->queue_table();

		$wpdb->insert( $table, [ 'permalink' => $this->permalink_of( '/waiting/' ), 'priority' => 3 ] );

		$this->queue()->migrate_table();

		$this->assertNull( $this->index_named( 'permalink' ), 'The key over the TEXT column survived the migration.' );
		$this->assertNotNull( $this->index_named( 'permalink_hash' ), 'The migration left the table without its new key.' );

		$entry = $wpdb->get_row( "SELECT `permalink`, `permalink_hash`, `priority` FROM `$table` LIMIT 1" );

		$this->assertSame( $this->permalink_of( '/waiting/' ), $entry->permalink, 'The entry waiting in the queue did not survive the migration.' );
		$this->assertSame( hash( RevalidateQueue::PERMALINK_HASH_ALGO, $entry->permalink ), $entry->permalink_hash, 'The entry was not backfilled with its digest.' );
		$this->assertSame( '3', (string) $entry->priority, 'The entry lost the priority it was waiting at.' );
	}

	/**
	 * A migrated table and a created one are the same table, so the next thing
	 * enqueued into it behaves the same way.
	 */
	public function test_a_migrated_table_dedups_like_a_created_one() {
		if ( ! $this->rebuild_as_legacy_table( true ) ) {
			$this->markTestSkipped( 'This database refuses a UNIQUE KEY over a TEXT column, so no site on it has the table this migrates.' );
		}

		$this->queue()->migrate_table();

		$this->configure_site();

		$this->enqueue( '/hello-world/', 10 );
		$this->enqueue( '/hello-world/', 1 );

		$this->assertQueueRevalidatesAtPriorities( [ '/hello-world/' => 1 ] );
	}

	/**
	 * A table with no unique key at all — nothing this plugin creates, but what
	 * a hand-made table or a dump restored without its keys looks like, and the
	 * one shape that can hold a permalink twice.
	 *
	 * Left alone, the duplicate would make the new key impossible to create, on
	 * every admin request, forever. The survivor is the entry that would have
	 * drained first.
	 */
	public function test_migrating_a_table_holding_a_permalink_twice_keeps_the_entry_that_drains_first() {
		global $wpdb;

		$this->rebuild_as_legacy_table( false );

		$table     = $this->queue_table();
		$permalink = $this->permalink_of( '/twice/' );

		$wpdb->insert( $table, [ 'permalink' => $permalink, 'priority' => 10 ] );
		$wpdb->insert( $table, [ 'permalink' => $permalink, 'priority' => 2 ] );
		$wpdb->insert( $table, [ 'permalink' => $this->permalink_of( '/once/' ), 'priority' => 10 ] );

		$this->queue()->migrate_table();

		$this->assertNotNull( $this->index_named( 'permalink_hash' ), 'The duplicate stopped the new key from being created.' );

		$this->assertQueueRevalidatesAtPriorities(
			[
				'/twice/' => 2,
				'/once/'  => 10,
			],
			'The surviving duplicate is not the one the drain would have reached first.'
		);
	}

	/**
	 * The louder half of #121: a site whose `CREATE TABLE` was refused has no
	 * queue table, and has been failing every enqueue since it was installed.
	 * The migration is where it gets one.
	 */
	public function test_migrating_a_site_with_no_queue_table_creates_one() {
		global $wpdb;

		$table = $this->queue_table();

		$wpdb->query( "DROP TABLE IF EXISTS `$table`" );

		$this->queue()->migrate_table();

		$this->assertSame( $table, $wpdb->get_var( "SHOW TABLES LIKE '$table'" ), 'The site was left without a queue table.' );
		$this->assertNotNull( $this->index_named( 'permalink_hash' ) );
	}

	/**
	 * Running it again changes nothing. It runs on every admin request, so this
	 * is the ordinary case rather than a defensive one.
	 */
	public function test_migrating_a_table_that_is_already_current_leaves_it_alone() {
		$this->configure_site();

		$this->enqueue( '/hello-world/', 4 );

		$this->queue()->migrate_table();
		$this->queue()->migrate_table();

		$this->assertQueueRevalidatesAtPriorities( [ '/hello-world/' => 4 ] );
		$this->assertNotNull( $this->index_named( 'permalink_hash' ) );
	}

	// Reading the table's own metadata
	// ====

	/**
	 * Every index row the queue table reports.
	 *
	 * @return object[]
	 */
	private function indexes() {
		global $wpdb;

		$table = $this->queue_table();

		return $wpdb->get_results( "SHOW INDEX FROM `$table`" );
	}

	/**
	 * The index row of this name, or null when the table carries no such key.
	 *
	 * @param string $key_name
	 * @return object|null
	 */
	private function index_named( $key_name ) {
		foreach ( $this->indexes() as $index ) {
			if ( $key_name === $index->Key_name ) return $index;
		}

		return null;
	}

	/**
	 * Replace the queue table with the shape releases up to 1.6.9 created.
	 *
	 * The statement is that release's, verbatim, which is the point of it being
	 * written out here rather than derived from the current one.
	 *
	 * @param bool $with_key Whether to declare the unique key over the TEXT
	 *                       column — the declaration only MariaDB accepts.
	 *
	 * @return bool Whether the table was created.
	 */
	private function rebuild_as_legacy_table( $with_key ) {
		global $wpdb;

		$table = $this->queue_table();
		$key   = $with_key ? 'UNIQUE KEY permalink (permalink),' : '';

		$wpdb->query( "DROP TABLE IF EXISTS `$table`" );

		// The refusal is the expected answer on a standard MySQL, and it is this
		// test's business rather than the suite's error reporting.
		$suppressed = $wpdb->suppress_errors( true );

		$wpdb->query(
			"CREATE TABLE `$table` (
				id         bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				permalink  text                NOT NULL,
				priority   int(10)             NOT NULL DEFAULT 10,
				$key
				PRIMARY KEY  (id)
			) " . $wpdb->get_charset_collate()
		);

		$wpdb->suppress_errors( $suppressed );

		$created = $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) === $table;

		// Never leave the suite without a table, whatever the answer was.
		if ( ! $created ) $this->queue()->create_table();

		return $created;
	}
}
