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
 * made explicit — see `docs/adr/0029-the-queue-dedups-on-a-hash-of-the-permalink.md`.
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
 * itself, and that takes switching off what the test library does to DDL. It
 * rewrites every `CREATE TABLE` a test issues into `CREATE TEMPORARY TABLE`, and
 * every `DROP TABLE` into `DROP TEMPORARY TABLE` — so, left on, a test that drops
 * the queue table leaves the real one exactly where it was, and a legacy table
 * built "over" it is a temporary one shadowing it for the rest of the run. The
 * tests below would pass without ever reaching the code they name. So:
 *
 *  - `set_up()` switches the rewrite off, and every DDL here is real.
 *  - A test that drops the table asserts that it is gone before relying on it.
 *  - `tear_down()` drops whatever is there and creates the current table, so a
 *    test that dies between a `DROP` and a `CREATE`, or leaves a legacy shape
 *    behind, does not take every later test with it.
 *
 * Real DDL commits: whatever a test wrote before it is past the rollback. The
 * settings, the cron and the log are undone by hand on the way out, as they are
 * after any enqueue; see `QueueTestCase`.
 *
 * @package NextJsRevalidate
 */

namespace NextJsRevalidate\Tests;

use NextJsRevalidate\RevalidateQueue;

class QueueSchemaTest extends QueueTestCase {

	/**
	 * How many times `blind_the_next_lookup()` has actually blinded one.
	 *
	 * @var int
	 */
	private $blinded_lookups = 0;

	/**
	 * Make this class's DDL real. See the file's docblock.
	 */
	public function set_up() {
		parent::set_up();

		remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );

		$this->blinded_lookups = 0;
	}

	/**
	 * Leave the current queue table behind, whatever happened above.
	 *
	 * The log goes first, because the `DROP` below is what commits its setting's
	 * deletion — as `QueueTestCase::tear_down()` explains for its own.
	 */
	public function tear_down() {
		global $wpdb;

		$this->reset_log();

		$table = $this->queue_table();

		$wpdb->query( "DROP TABLE IF EXISTS `$table`" );
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

	/**
	 * The digest compares bytes, where the key it replaces compared permalinks
	 * under the column's collation. Two permalinks differing only in case are
	 * two paths to a front-end that routes case-sensitively, and two entries.
	 */
	public function test_two_permalinks_differing_only_in_case_are_two_entries() {
		$this->configure_site();

		$this->enqueue( '/Hello-World/' );
		$this->enqueue( '/hello-world/' );

		$this->assertQueueRevalidates( [ '/Hello-World/', '/hello-world/' ] );
	}

	// What the key settles
	// ====

	/**
	 * The race ADR 0021 left open: two enqueues of a permalink the queue does
	 * not hold both read nothing, and both insert. The key refuses the second,
	 * and the second promotes the entry the first wrote rather than reporting a
	 * failure for a permalink that is queued.
	 *
	 * A second request cannot be run alongside this one, so the race is staged
	 * from the loser's side: its lookup is made to miss the entry that is there,
	 * which is exactly what a read that ran just before the winner committed
	 * looks like from inside.
	 */
	public function test_an_enqueue_refused_by_the_key_promotes_the_entry_it_raced() {
		$this->configure_site();

		$this->enqueue( '/raced/', 10 );

		$this->blind_the_next_lookup();

		$accepted = $this->quietly( function () {
			return $this->enqueue( '/raced/', 1 );
		} );

		$this->assertSame( 1, $this->blinded_lookups, 'The lookup was never blinded, so no race was staged.' );
		$this->assertTrue( $accepted, 'The loser of the race reported a permalink that is queued as a failure.' );
		$this->assertQueueRevalidatesAtPriorities( [ '/raced/' => 1 ], 'The loser of the race did not promote the entry it lost to.' );
	}

	/**
	 * And a loser asking for less urgency than the winner leaves the winner's
	 * priority alone: escalate, never demote, whichever side of the race.
	 */
	public function test_an_enqueue_refused_by_the_key_at_a_less_urgent_priority_leaves_the_entry_alone() {
		$this->configure_site();

		$this->enqueue( '/raced/', 2 );

		$this->blind_the_next_lookup();

		$accepted = $this->quietly( function () {
			return $this->enqueue( '/raced/', 10 );
		} );

		$this->assertSame( 1, $this->blinded_lookups, 'The lookup was never blinded, so no race was staged.' );
		$this->assertTrue( $accepted );
		$this->assertQueueRevalidatesAtPriorities( [ '/raced/' => 2 ] );
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

		$this->rebuild_as_legacy_table_or_skip();

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
		$this->rebuild_as_legacy_table_or_skip();

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

		$this->assertTrue( $this->rebuild_as_legacy_table( false ), 'Every engine accepts the legacy table without its key, and this one did not.' );

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
		$this->drop_queue_table();

		$this->queue()->migrate_table();

		$this->assertTrue( $this->queue_table_exists(), 'The site was left without a queue table.' );
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

	// What an enqueue does before any admin request has
	// ====

	/**
	 * An upgraded MariaDB site whose first enqueue comes from cron, a REST
	 * client or a scheduled post, before anyone has opened wp-admin. The table
	 * has no `permalink_hash` column yet, the write fails on it, and the enqueue
	 * migrates the table and writes again rather than failing a site that was
	 * revalidating fine before the upgrade.
	 */
	public function test_an_enqueue_into_a_table_keyed_on_the_text_column_migrates_it_first() {
		$this->rebuild_as_legacy_table_or_skip();

		$this->configure_site();

		$accepted = $this->quietly( function () {
			return $this->enqueue( '/after-the-upgrade/', 3 );
		} );

		$this->assertNotFalse( $accepted, 'An enqueue on a table not yet migrated failed.' );
		$this->assertNotNull( $this->index_named( 'permalink_hash' ), 'The enqueue did not migrate the table it failed on.' );
		$this->assertQueueRevalidatesAtPriorities( [ '/after-the-upgrade/' => 3 ] );
	}

	/**
	 * And a MySQL site whose `CREATE TABLE` was refused gets its table from its
	 * first enqueue, rather than failing that one too.
	 */
	public function test_an_enqueue_on_a_site_with_no_queue_table_creates_one() {
		$this->drop_queue_table();

		$this->configure_site();

		$accepted = $this->quietly( function () {
			return $this->enqueue( '/first/' );
		} );

		$this->assertNotFalse( $accepted, 'An enqueue on a site with no queue table failed.' );
		$this->assertTrue( $this->queue_table_exists(), 'The enqueue did not create the table it failed on.' );
		$this->assertQueueRevalidates( [ '/first/' ] );
	}

	// What a failure says
	// ====

	/**
	 * A `CREATE TABLE` the server refuses is written to the log. `dbDelta()`
	 * says nothing, and that silence is how #121 went unnoticed.
	 *
	 * The refusal is staged by pointing the key at a column that does not
	 * exist, which every engine refuses.
	 */
	public function test_a_queue_table_that_cannot_be_created_is_logged() {
		$this->drop_queue_table();
		$this->enable_logs();

		$this->refusing( 'UNIQUE KEY permalink_hash (permalink_hash)', 'UNIQUE KEY permalink_hash (no_such_column)', function () {
			$this->queue()->create_table();
		} );

		$this->assertFalse( $this->queue_table_exists(), 'The staged refusal did not refuse anything.' );
		$this->assertStringContainsString( 'Could not create the queue table', $this->log() );
		$this->assertStringContainsString( 'no_such_column', $this->log(), 'The log line does not carry the error the server gave.' );
	}

	/**
	 * A migration whose key the server refuses is written to the log too, since
	 * it is otherwise retried on every admin request and reported by nothing.
	 *
	 * On the table without its key, because every engine accepts that one.
	 */
	public function test_a_migration_that_cannot_create_the_key_is_logged() {
		$this->rebuild_as_legacy_table( false );
		$this->enable_logs();

		$this->refusing( 'ADD UNIQUE KEY `permalink_hash` (`permalink_hash`)', 'ADD UNIQUE KEY `permalink_hash` (`no_such_column`)', function () {
			$this->queue()->migrate_table();
		} );

		$this->assertNull( $this->index_named( 'permalink_hash' ), 'The staged refusal did not refuse anything.' );
		$this->assertStringContainsString( 'Could not migrate the queue table', $this->log() );
		$this->assertStringContainsString( 'no_such_column', $this->log(), 'The log line does not carry the error the server gave.' );
	}

	// Staging
	// ====

	/**
	 * Make the queue's next lookup miss whatever it is looking for, once.
	 *
	 * @return void
	 */
	private function blind_the_next_lookup() {
		$blind = function ( $query ) use ( &$blind ) {
			if ( false === strpos( $query, 'SELECT `priority` FROM' ) ) return $query;

			remove_filter( 'query', $blind );
			$this->blinded_lookups++;

			return str_replace( 'WHERE `permalink_hash` =', 'WHERE 1 = 0 AND `permalink_hash` =', $query );
		};

		add_filter( 'query', $blind );
	}

	/**
	 * Run something with one of its statements rewritten into one the server
	 * refuses, and only for as long as it runs.
	 *
	 * Scoped rather than left to the library's hook restore, which comes after
	 * `tear_down()` has already rebuilt the table — through the same rewrite.
	 *
	 * @param string   $search  A fragment of the statement to break.
	 * @param string   $replace What to break it with.
	 * @param callable $act
	 * @return void
	 */
	private function refusing( $search, $replace, callable $act ) {
		$rewrite = function ( $query ) use ( $search, $replace ) {
			return str_replace( $search, $replace, $query );
		};

		add_filter( 'query', $rewrite );

		try {
			$this->quietly( $act );
		} finally {
			remove_filter( 'query', $rewrite );
		}
	}

	/**
	 * Run something whose failing queries are the point, without `$wpdb`
	 * printing them into the test's output.
	 *
	 * @param callable $act
	 * @return mixed What `$act` returned.
	 */
	private function quietly( callable $act ) {
		global $wpdb;

		$suppressed = $wpdb->suppress_errors( true );

		try {
			return $act();
		} finally {
			$wpdb->suppress_errors( $suppressed );
		}
	}

	/**
	 * Drop the queue table, and prove it went.
	 *
	 * The proof is what this class got wrong before it switched the library's
	 * rewrite off: the `DROP` answered without error, dropped nothing, and every
	 * test built on it passed without reaching its subject.
	 *
	 * @return void
	 */
	private function drop_queue_table() {
		global $wpdb;

		$table = $this->queue_table();

		$wpdb->query( "DROP TABLE IF EXISTS `$table`" );

		$this->assertFalse( $this->queue_table_exists(), 'The queue table survived its DROP, so this test would not reach what it names.' );
	}

	/**
	 * Replace the queue table with the one releases up to 1.6.9 created, or skip
	 * the test on an engine that refuses it — standard MySQL, where no site has
	 * that table to migrate.
	 *
	 * @return void
	 */
	private function rebuild_as_legacy_table_or_skip() {
		if ( ! $this->rebuild_as_legacy_table( true ) ) {
			$this->markTestSkipped( 'This database refuses a UNIQUE KEY over a TEXT column, so no site on it has the table this migrates.' );
		}
	}

	// Reading the table's own metadata
	// ====

	/**
	 * Whether the site has a queue table at all.
	 *
	 * @return bool
	 */
	private function queue_table_exists() {
		global $wpdb;

		$table = $this->queue_table();

		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

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
	 * @return bool Whether the table was created. When it was not, the current
	 *              table is put back, so the suite is never left without one.
	 */
	private function rebuild_as_legacy_table( $with_key ) {
		global $wpdb;

		$table = $this->queue_table();
		$key   = $with_key ? 'UNIQUE KEY permalink (permalink),' : '';

		$this->drop_queue_table();

		// The refusal is the expected answer on a standard MySQL, and it is this
		// test's business rather than the suite's error reporting.
		$this->quietly( function () use ( $wpdb, $table, $key ) {
			$wpdb->query(
				"CREATE TABLE `$table` (
					id         bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					permalink  text                NOT NULL,
					priority   int(10)             NOT NULL DEFAULT 10,
					$key
					PRIMARY KEY  (id)
				) " . $wpdb->get_charset_collate()
			);
		} );

		if ( $this->queue_table_exists() ) return true;

		$this->queue()->create_table();

		return false;
	}
}
