<?php

namespace NextJsRevalidate;

use DateTime;
use DateTimeZone;
use NextJsRevalidate\Abstracts\Base;
use NextJsRevalidate\Interfaces\Hookable;
use NextJsRevalidate\Traits\SendbackUrl;
use WP_Error;

/**
 * @property Revalidate $revalidate
 * @property Settings   $settings
 */
class RevalidateQueue extends Base implements Hookable {
	use SendbackUrl;

	const CRON_HOOK_NAME = 'nextjs_revalidate-queue';
	const CRON_TRANSCIENT_NAME = 'nextjs_revalidate-running_queue';

	const MAX_NB_RUNNING_CRON = 4;

	/**
	 * The priority an item is queued at when nobody asked for one. Lower is
	 * sooner, and `0` is a priority like any other — not an absence of one.
	 */
	const DEFAULT_PRIORITY = 10;

	/**
	 * The digest an entry's permalink is identified by.
	 *
	 * The queue holds a permalink once, and that is a database constraint here
	 * rather than a hope: a `UNIQUE KEY` over a fixed-width hash of the
	 * permalink, because one over the `permalink` column itself is not portable.
	 * A `TEXT` column cannot be keyed without a prefix length on standard MySQL,
	 * and a prefix length would refuse two distinct permalinks sharing their
	 * first n characters. See `docs/adr/0029-the-queue-dedups-on-a-hash-of-the-permalink.md`.
	 *
	 * Not a security boundary and never an authenticator — it names a row.
	 * `tests/queue-schema-portability-test.php` pins it against the column's
	 * declared width, which is a hex digest of exactly this algorithm.
	 */
	const PERMALINK_HASH_ALGO = 'sha256';

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
	 * The digest the queue identifies a permalink by.
	 *
	 * @param string $permalink
	 * @return string A lowercase hex digest, of the width the table declares.
	 */
	private static function permalink_hash( $permalink ) {
		return hash( self::PERMALINK_HASH_ALGO, $permalink );
	}

	/**
	 * Create the custom table to hold the queue
	 *
	 * The permalink is stored whole, in a `TEXT` column, and keyed through the
	 * fixed-width `permalink_hash` beside it. Keying the `TEXT` column directly
	 * is what this used to do, and it only ever worked on MariaDB: from 10.4 it
	 * accepts a `UNIQUE` constraint over a `BLOB`/`TEXT` column by maintaining a
	 * hidden hash column behind it, and standard MySQL rejects the statement
	 * outright (error 1170) — so the `CREATE TABLE` failed there, `dbDelta()`
	 * inspects nothing, and the site was left with no queue table at all (#121).
	 * `permalink_hash` is that hidden column, made explicit and portable.
	 *
	 * A prefix length — `permalink(191)`, the conventional WordPress answer —
	 * would also be portable and is not what this does: it keys a *prefix*, so
	 * two distinct permalinks sharing their first 191 characters collide and the
	 * second is refused as a duplicate of a page it is not.
	 * See `docs/adr/0029-the-queue-dedups-on-a-hash-of-the-permalink.md`.
	 *
	 * @return void
	 */
	public function create_table() {
		global $wpdb;

		$table_name = $this->get_table_name();

		// Do not continue if table already exists
		if ( $this->table_exists() ) return;

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE `{$table_name}` (
			id              bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			permalink       text                NOT NULL,
			permalink_hash  char(64)            NOT NULL,
			priority        int(10)             NOT NULL DEFAULT 10,
			UNIQUE KEY permalink_hash (permalink_hash),
			PRIMARY KEY  (id)
		) $charset_collate;";

		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($sql);

		// Kept before the check below, which is a query of its own and would
		// clear it.
		$error = $wpdb->last_error;

		// `dbDelta()` reports nothing it ran, and a refused `CREATE TABLE` is
		// exactly how #121 went unnoticed: a site with no queue table enqueues
		// nothing, and looks like one whose front-end is simply not updating.
		if ( ! $this->table_exists() ) {
			Logger::log(
				sprintf( '⛔ Could not create the queue table %s: %s', $table_name, $error ?: 'no error reported' ),
				__FILE__,
				Logger::ERROR
			);
		}
	}

	/**
	 * Bring this site's queue table to the shape the running code expects.
	 *
	 * Guarded on the table's own columns rather than on the migration ledger,
	 * and not by preference: every site predating the ledger is backfilled to
	 * the release that introduces this, so a version gate would be read after
	 * the site had already been stamped past it and would never fire for
	 * anybody. `Settings::backfill_db_version()` has the full reasoning, and
	 * the settings split and the log move are guarded the same way.
	 *
	 * It has two jobs, because #121 has two populations:
	 *
	 *  - A site whose `CREATE TABLE` MySQL refused has **no queue table at
	 *    all**, and every enqueue it has ever made failed. It gets one here.
	 *  - A site on MariaDB has the table, with the unique key over the `TEXT`
	 *    column. The key moves onto a hash of the permalink, which every engine
	 *    can keep. It compares permalinks byte for byte, where the key it
	 *    replaces compared them under the column's collation: two permalinks
	 *    differing only in case are now two entries, as they are two paths.
	 *
	 * Run from `Settings::migrate_db()` on every admin request, and from
	 * `add_item()` whenever a write fails on a table not yet in this shape —
	 * which is how an upgraded site's cron, REST and scheduled enqueues get
	 * past an upgrade no admin has loaded a page since.
	 *
	 * Idempotent, and safe to interrupt: it re-derives every hash from the
	 * permalink beside it, so a request that dies part-way leaves rows a later
	 * one rewrites to the same values.
	 *
	 * @return void
	 */
	public function migrate_table() {
		global $wpdb;

		$table_name = $this->get_table_name();

		// No table: the site is one whose CREATE TABLE was refused, or one the
		// setup never reached. `create_table()` asks the same question again,
		// and answering it here is what keeps this from creating a table over
		// one that exists.
		if ( ! $this->table_exists() ) {
			$this->create_table();
			return;
		}

		// Already the current shape. The steady-state cost of this migration on
		// every admin request, and the reason it is two metadata reads rather
		// than ALTERs that would be no-ops.
		//
		// The *key* is what is read for, and not the column: the column arrives
		// first and the key last, so a request that died between them left a
		// table with a hash column and no constraint on it — which reads as
		// finished to a guard on the column, and is the one state this must not
		// declare done.
		if ( $this->table_has_index( 'permalink_hash' ) ) return;

		// Added with a default, which the final shape does not carry: a column
		// declared NOT NULL without one has to be given a value for every row
		// already there, and what a server does about that depends on its SQL
		// mode. The default is dropped again once the rows are hashed.
		if ( ! $this->table_has_permalink_hash() ) {
			$wpdb->query( "ALTER TABLE `$table_name` ADD COLUMN `permalink_hash` char(64) NOT NULL DEFAULT '' AFTER `permalink`" );
			$error = $wpdb->last_error;

			// The ALTER did not take. Everything below would write into a column
			// that is not there, so this stops, and the next admin request or
			// failed enqueue retries.
			if ( ! $this->table_has_permalink_hash() ) {
				$this->log_migration_failure( $error );
				return;
			}
		}

		$duplicates = $this->hash_existing_entries();

		// A table whose unique key was absent — nothing this plugin creates,
		// but the shape a hand-made table or a restored dump can have — may
		// hold a permalink twice, and the key below would then refuse to be
		// created at all, on every admin request, forever.
		if ( !empty($duplicates) ) {
			$wpdb->query( "DELETE FROM `$table_name` WHERE `id` IN (" . implode( ',', $duplicates ) . ")" );
		}

		// One statement, so it lands whole or not at all. As three, a request
		// dying after the first would leave a table with no unique key of either
		// kind — and one dying after the second would keep the column's
		// temporary default for good, because the guard above reads the key and
		// would call that table finished.
		$alterations = [];

		// The key this replaces, on the sites that have it. Dropped by name:
		// it is `permalink` on every table this plugin created, and a table
		// that never had it is left alone rather than erroring.
		if ( $this->table_has_index( 'permalink' ) ) {
			$alterations[] = 'DROP INDEX `permalink`';
		}

		$alterations[] = 'ADD UNIQUE KEY `permalink_hash` (`permalink_hash`)';

		// The shape a fresh install is created with, so a migrated table and a
		// created one are the same table.
		$alterations[] = 'MODIFY COLUMN `permalink_hash` char(64) NOT NULL';

		$wpdb->query( "ALTER TABLE `$table_name` " . implode( ', ', $alterations ) );
		$error = $wpdb->last_error;

		if ( ! $this->table_has_index( 'permalink_hash' ) ) $this->log_migration_failure( $error );
	}

	/**
	 * Say that the queue table could not be brought to its current shape.
	 *
	 * Left unsaid, a failed migration is retried on every admin request and
	 * every failed enqueue, and reported by nothing — the silence #121 was
	 * about.
	 *
	 * @param string $error The server's own error, taken straight after the
	 *                      statement that failed: `$wpdb` clears it on the next
	 *                      query, and the check that notices the failure is one.
	 *
	 * @return void
	 */
	private function log_migration_failure( $error ) {
		Logger::log(
			sprintf( '⛔ Could not migrate the queue table %s: %s', $this->get_table_name(), $error ?: 'no error reported' ),
			__FILE__,
			Logger::ERROR
		);
	}

	/**
	 * Whether this site has a queue table at all.
	 *
	 * Impure, for the reason the two reads below are: the migration asks it
	 * again after acting on its answer.
	 *
	 * @phpstan-impure
	 *
	 * @return bool
	 */
	private function table_exists() {
		global $wpdb;

		$table_name = $this->get_table_name();

		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;
	}

	/**
	 * Whether this site's queue table carries the hash column.
	 *
	 * Impure, and `migrate_table()` depends on it being so: it asks twice, with
	 * an `ALTER TABLE` in between, and the second answer is the one that says
	 * whether the ALTER took.
	 *
	 * @phpstan-impure
	 *
	 * @return bool
	 */
	private function table_has_permalink_hash() {
		global $wpdb;

		$table_name = $this->get_table_name();

		return null !== $wpdb->get_var( "SHOW COLUMNS FROM `$table_name` LIKE 'permalink_hash'" );
	}

	/**
	 * Whether this site's queue table carries an index of this name.
	 *
	 * Impure for the same reason as the column read above: what it answers is a
	 * property of the table as it stands, and this migration changes that.
	 *
	 * @phpstan-impure
	 *
	 * @param string $key_name
	 * @return bool
	 */
	private function table_has_index( $key_name ) {
		global $wpdb;

		$table_name = $this->get_table_name();

		return null !== $wpdb->get_var(
			$wpdb->prepare( "SHOW INDEX FROM `$table_name` WHERE `Key_name` = %s", $key_name )
		);
	}

	/**
	 * Write a hash for every entry the table already holds, and answer the ids
	 * of the entries a hash shows to be duplicates.
	 *
	 * Read in drain order, so that where two entries do hold one permalink the
	 * survivor is the one that would have drained first — the more urgent of
	 * the two priorities, and the earlier arrival within it.
	 *
	 * Hashed in PHP rather than by the server's own `SHA2()`, which is not
	 * portable in the way this whole change is about: a MySQL built without SSL
	 * support answers `NULL`, and the column is `NOT NULL`.
	 *
	 * @return int[] The ids to delete, in no particular order.
	 */
	private function hash_existing_entries() {
		global $wpdb;

		$table_name = $this->get_table_name();

		$entries = $wpdb->get_results( "SELECT `id`, `permalink` FROM `$table_name` ORDER BY `priority` ASC, `id` ASC" );

		$seen       = [];
		$duplicates = [];

		foreach ( $entries as $entry ) {
			$hash = self::permalink_hash( $entry->permalink );

			if ( isset($seen[$hash]) ) {
				$duplicates[] = intval( $entry->id );
				continue;
			}

			$seen[$hash] = true;

			$wpdb->update( $table_name, [ 'permalink_hash' => $hash ], [ 'id' => $entry->id ] );
		}

		return $duplicates;
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
	 * A permalink already waiting in the queue is not queued twice — the entry
	 * it already has *is* the revalidation, and a second row would only rebuild
	 * the same path again. Re-submitting one at a more urgent priority
	 * **promotes** that entry instead of discarding the priority the caller
	 * asked for; see `promote_item()` for why it can only ever move it earlier.
	 *
	 * Two callers enqueuing a permalink the queue does *not* hold can both find
	 * nothing and both insert. The unique key refuses one of them, and that one
	 * promotes the row the other wrote rather than reporting a failure for a
	 * permalink that is, after all, queued. See
	 * `docs/adr/0029-the-queue-dedups-on-a-hash-of-the-permalink.md`.
	 *
	 * A write that fails on a table not yet in its current shape migrates the
	 * table and is tried once more. The migration otherwise waits for an admin
	 * request, and until then every enqueue on an upgraded site — from cron, a
	 * REST client, a scheduled post going live — would fail on a column that is
	 * not there, and every enqueue on a site whose `CREATE TABLE` was refused
	 * would go on failing on a table that is not there.
	 *
	 * @param string $permalink
	 * @param int    $priority   Optional. Used to specify the order in which
	 *                           the url are purged. Lower numbers correspond
	 *                           with earlier purge, and urls with the same
	 *                           priority are executed in the order in which
	 *                           they were added. Default 10.
	 *
	 * @return int|bool|WP_Error Whether the queue holds the permalink at the
	 *                       priority asked for, or at a more urgent one.
	 *                       Truthy in four spellings, and callers must read it
	 *                       as such rather than compare it to one of them: `1`
	 *                       for a row this inserted, `true` for a permalink
	 *                       already waiting or promoted, `false` when the write
	 *                       itself failed, and a `not_configured` WP_Error when
	 *                       the site is unconfigured and the revalidation is
	 *                       refused.
	 */
	public function add_item( $permalink, $priority = self::DEFAULT_PRIORITY ) {
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

		$accepted = $this->write_item( $permalink, $priority );

		// Asked only once a write has failed, so a site in its current shape
		// pays nothing for it.
		if ( false === $accepted && ! $this->table_has_index( 'permalink_hash' ) ) {
			$this->migrate_table();

			$accepted = $this->write_item( $permalink, $priority );
		}

		$this->schedule_next_cron();

		return $accepted;
	}

	/**
	 * Queue a permalink, or promote the entry already holding it.
	 *
	 * @param string $permalink
	 * @param int    $priority
	 *
	 * @return int|bool `1` for a row this inserted, `true` for a permalink
	 *                  already waiting or promoted, `false` when the write
	 *                  itself failed. See `add_item()`.
	 */
	private function write_item( $permalink, $priority ) {
		global $wpdb;

		$table_name = $this->get_table_name();
		$hash       = self::permalink_hash( $permalink );

		$wpdb->query("START TRANSACTION");

		// The priority the permalink is already queued at, and `null` when it is
		// not queued at all — which is the question this used to ask with a
		// `COUNT(*)`. The answer is now needed either way: a re-submission is
		// only discarded once it is known not to be an escalation.
		$queued_priority = $this->queued_priority( $hash );

		// Read for `null` rather than for falsiness: `0` is a priority like any
		// other, and the most urgent one there is.
		if ( null !== $queued_priority ) {
			$accepted = $this->promote_item( $permalink, intval($priority), $queued_priority );

			$wpdb->query("COMMIT");

			return $accepted;
		}

		$inserted = $wpdb->insert(
			$table_name,
			[
				'permalink'      => $permalink,
				'permalink_hash' => $hash,
				'priority'       => $priority
			]
		);

		$wpdb->query("COMMIT");

		if ( false !== $inserted ) return $inserted;

		// The read above takes no lock, so two enqueues of a permalink the
		// queue does not hold can both find nothing and both insert. The unique
		// key settles that — one of them is refused — and this is the refused
		// one reading the row the other wrote, and treating it as what it is:
		// the permalink is queued, and the priority this caller asked for is
		// still owed.
		//
		// Read after the `COMMIT`, not inside the transaction, and for two
		// reasons. Inside it, a plain read answers from the snapshot the first
		// read opened, under which the winner's row does not exist. And a
		// refused insert holds a shared lock on the row it collided with until
		// the transaction ends: a locking read to get past the snapshot would
		// need an exclusive one, so two losers of the same race would each wait
		// on the other's shared lock — a deadlock, and a `false` for one of
		// them. Ended, the transaction holds nothing, and the read is fresh.
		//
		// Read afresh rather than assumed: a `false` from an insert is not only
		// ever a duplicate, and a caller told "queued" over a write that failed
		// for some other reason would be told something untrue.
		$queued_priority = $this->queued_priority( $hash );

		if ( null === $queued_priority ) return false;

		return $this->promote_item( $permalink, intval($priority), $queued_priority );
	}

	/**
	 * The priority the queue holds this permalink at, or `null` when it holds
	 * it at none — which is to say, not at all.
	 *
	 * Asked of the hash rather than of the permalink, because the hash is what
	 * the unique key is on: the lookup, the constraint and the promotion all
	 * read the queue's identity of an entry the same way, and cannot disagree
	 * about whether two entries are one.
	 *
	 * Prepared rather than interpolated, although what it carries is now this
	 * plugin's own hex digest. A permalink is not this plugin's own string — it
	 * arrives from the REST route, which sanitises with `sanitize_text_field()`
	 * and leaves a quote intact, and from the Redirection integration, whose
	 * source paths are whatever an editor typed — and the statement that used to
	 * carry it whole was the one raw statement in the queue carrying anything
	 * but a table name. Nothing here composes a permalink into SQL any more;
	 * `$wpdb->insert()` escapes the only statement that still stores one.
	 *
	 * A plain read, never a locking one. Taking a lock over a row that is not
	 * there locks the gap the permalink would sort into, which would serialise
	 * every enqueue this one has nothing to do with; and the one caller that
	 * needs a fresher answer than its transaction's snapshot gets it by ending
	 * the transaction first. See `write_item()`.
	 *
	 * @param string $hash The digest of the permalink, per `permalink_hash()`.
	 *
	 * @return int|null
	 */
	private function queued_priority( $hash ) {
		global $wpdb;

		$table_name = $this->get_table_name();

		$priority = $wpdb->get_var(
			$wpdb->prepare( "SELECT `priority` FROM `$table_name` WHERE `permalink_hash` = %s ORDER BY `priority` ASC LIMIT 1", $hash )
		);

		return null === $priority ? null : intval( $priority );
	}

	/**
	 * Move a permalink the queue already holds to a more urgent priority.
	 *
	 * Priority is what orders the drain, so a caller re-submitting a permalink
	 * at a lower number is asking it to jump the queue. Deduplication used to
	 * answer that with the entry it already had, at the priority it was first
	 * queued at, and report success — the queue was then not in the state the
	 * caller had been told it was in.
	 *
	 * It promotes and never demotes. The minimum of the two priorities is what
	 * the caller most plausibly expects, and it is the only rule under which a
	 * second, less urgent caller — an editor saving a post already escalated by
	 * an external system — cannot slow down work something else deemed urgent.
	 *
	 * The entry keeps its `id`, so insertion order still decides between entries
	 * sitting at the same priority: a promoted entry drains ahead of everything
	 * queued after it, and behind what was already waiting there. Nothing
	 * re-queues it at the back of its new priority, which would move it behind
	 * work it predates.
	 *
	 * @param string $permalink       A permalink the queue holds.
	 * @param int    $priority        The priority the caller asked for.
	 * @param int    $queued_priority The priority the entry is queued at.
	 *
	 * @return bool Whether the queue holds the permalink at the priority asked
	 *              for, or at a more urgent one.
	 */
	private function promote_item( $permalink, $priority, $queued_priority ) {
		global $wpdb;

		// Nothing to do, and nothing failed: the entry is already draining at
		// least as soon as the caller asked for.
		if ( $priority >= $queued_priority ) return true;

		$table_name = $this->get_table_name();

		// Found by hash, for the reason `queued_priority()` gives: the entry the
		// read identified is the entry this writes, under one definition of
		// which entries are the same entry.
		//
		// The comparison is repeated in the `WHERE` rather than left to the read
		// above: two callers escalating the same permalink at once would
		// otherwise be able to write the less urgent of the two priorities last.
		$promoted = $wpdb->query(
			$wpdb->prepare(
				"UPDATE `$table_name` SET `priority` = %d WHERE `permalink_hash` = %s AND `priority` > %d",
				$priority,
				self::permalink_hash( $permalink ),
				$priority
			)
		);

		if ( false === $promoted ) return false;

		// Matching no row is not a failure: another caller promoted the entry to
		// at least this priority between the read and the write, which is the
		// state this asked for. Nothing moved, so nothing is logged.
		if ( $promoted > 0 ) {
			Logger::log(
				sprintf( '🔼 Promoted %s from priority %d to %d', $permalink, $queued_priority, $priority ),
				__FILE__
			);
		}

		return true;
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
