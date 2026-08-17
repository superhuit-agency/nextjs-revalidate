<?php
/**
 * The base case for tests that observe the revalidation queue.
 *
 * @package NextJsRevalidate
 */

namespace NextJsRevalidate\Tests;

use NextJsRevalidate\RevalidateItem;
use NextJsRevalidate\RevalidateQueue;
use NextJsRevalidate\Settings;
use ReflectionMethod;
use WP_UnitTestCase;

/**
 * Isolation, fixtures and assertions for the revalidation queue.
 *
 * `WP_UnitTestCase` isolates a test by opening a transaction and rolling it
 * back, which covers posts, options and transients. It does not cover the
 * queue: `RevalidateQueue::add_item()` issues its own `START TRANSACTION` and
 * `COMMIT`, and MySQL has no nested transactions, so the plugin's `COMMIT`
 * commits the test's transaction too. This case therefore empties the queue by
 * hand on both sides of every test.
 *
 * Everything an enqueue commits is worth knowing before writing a test against
 * this base. `tear_down()` undoes what it can; the rest is bounded by the class:
 *
 *  - Anything a test does *before* it enqueues is committed by the enqueue and
 *    survives the rollback — a post created and then saved into the queue is
 *    still there in the next test. WordPress clears every table between test
 *    *classes*, so the leak is bounded by the class. A test that creates content
 *    and then enqueues should still assert on what it created rather than on the
 *    absence of anything else.
 *  - The two settings rows this case's own fixture writes are committed the same
 *    way, and `tear_down()` deletes them.
 *  - `add_item()` schedules the drain cron *after* its own `COMMIT`, so the
 *    `cron` option is written outside the test's transaction and outlives the
 *    rollback too. `tear_down()` unschedules it, because
 *    `RevalidateQueue::schedule_next_cron()` does nothing when an event is
 *    already scheduled — a leaked one would silently decide the result of the
 *    next test that asserts an enqueue schedules a purge.
 *  - The order is real state, not a coincidence of the test: the queue is read
 *    back in the order the drain consumes it, priority ascending then insertion.
 *
 * On the two ways to read the queue: it **holds permalinks**, and those
 * permalinks **revalidate paths** — `CONTEXT.md` keeps the two words apart
 * because on a network they can disagree. So `assertQueueHolds()` takes
 * permalinks and `assertQueueRevalidates()` takes paths. The path-first one is
 * the everyday assertion, and the permalink stays reachable for the tests where
 * the absolute url is the point.
 *
 * See `docs/adr/0008-two-testing-idioms.md`.
 */
abstract class QueueTestCase extends WP_UnitTestCase {

	/**
	 * The revalidate url a configured fixture site holds.
	 */
	const FIXTURE_URL = 'https://front-end.test/api/revalidate';

	/**
	 * The secret a configured fixture site holds.
	 */
	const FIXTURE_SECRET = 'fixture-secret';

	/**
	 * Empty the queue before the test, which cannot inherit that from the
	 * rollback.
	 */
	public function set_up() {
		parent::set_up();

		$this->reset_queue();
	}

	/**
	 * Empty the queue after the test too, so a failing test leaves nothing for
	 * the next one, and undo the two things an enqueue committed past the
	 * rollback: the fixture's settings and the drain cron.
	 *
	 * The order matters and is not cosmetic. `parent::tear_down()` rolls back,
	 * and `WP_UnitTestCase::set_up()` left `autocommit` at 0, so the plugin's
	 * `COMMIT` did not restore it — every statement after that commit runs in a
	 * fresh implicit transaction that the rollback will undo. Deleting the
	 * options there would delete nothing. `reset_queue()` truncates, and
	 * `TRUNCATE` is DDL, which forces an implicit commit in MySQL: it is what
	 * makes the deletions above it durable. Move it back above them and they
	 * silently stop taking effect.
	 */
	public function tear_down() {
		$this->unconfigure_site();
		$this->queue()->unschedule_cron();
		$this->reset_queue();

		parent::tear_down();
	}

	// The subject
	// ====

	/**
	 * The revalidation queue of the site currently being served.
	 *
	 * Read through the plugin's own singleton, never rebuilt, so a test on a
	 * network reaches the queue of the site it has switched to — the same
	 * discipline `RevalidateQueue::get_table_name()` keeps in production.
	 *
	 * @return RevalidateQueue
	 */
	protected function queue() {
		return \NextJsRevalidate::init()->queue;
	}

	/**
	 * Empty the queue and reset its ids.
	 *
	 * Calls the queue's own reset — the operation the settings page offers —
	 * rather than naming the table here. The table name follows
	 * `switch_to_blog()`, and a copy of the expression that builds it is the
	 * one thing this suite must not own.
	 *
	 * @return void
	 */
	private function reset_queue() {
		$reset = new ReflectionMethod( RevalidateQueue::class, 'reset_queue' );
		$reset->setAccessible( true );
		$reset->invoke( $this->queue() );
	}

	// Fixtures
	// ====

	/**
	 * Make the site being served a configured site.
	 *
	 * An unconfigured site refuses every revalidation, so no event driven test
	 * can enqueue anything without this. Written through the constants the
	 * settings declaration itself uses, rather than through option names spelled
	 * out again here.
	 *
	 * @return void
	 */
	protected function configure_site() {
		update_option( Settings::SETTINGS_URL_NAME, self::FIXTURE_URL );
		update_option( Settings::SETTINGS_SECRET_NAME, self::FIXTURE_SECRET );
	}

	/**
	 * Take the site back to holding neither setting.
	 *
	 * Run on teardown because an enqueue commits the transaction that would
	 * otherwise have rolled these two rows back. See `tear_down()` for why the
	 * call has to sit above the queue reset to have any effect.
	 *
	 * @return void
	 */
	protected function unconfigure_site() {
		delete_option( Settings::SETTINGS_URL_NAME );
		delete_option( Settings::SETTINGS_SECRET_NAME );
	}

	/**
	 * Enqueue a revalidation of the given path.
	 *
	 * @param string $path     The path to revalidate, as `/hello-world/`.
	 * @param int    $priority Optional. Default 10.
	 *
	 * @return bool|\WP_Error What the queue answered.
	 */
	protected function enqueue( $path, $priority = 10 ) {
		return $this->queue()->add_item( $this->permalink_of( $path ), $priority );
	}

	// Paths and permalinks
	// ====

	/**
	 * The permalink the queue would hold for a path of this site.
	 *
	 * @param string $path A path, as `/hello-world/`.
	 * @return string
	 */
	protected function permalink_of( $path ) {
		return home_url( $path );
	}

	/**
	 * The path a permalink of this site names.
	 *
	 * A permalink pointing elsewhere is returned whole rather than trimmed into
	 * something that reads like a path: on a network the queue entry and the
	 * permalink written into it can legitimately belong to different sites, and
	 * a test should see that rather than have it hidden.
	 *
	 * @param string $permalink An absolute url.
	 * @return string
	 */
	protected function path_of( $permalink ) {
		$home = home_url();

		return strpos( $permalink, $home ) === 0
			? substr( $permalink, strlen( $home ) )
			: $permalink;
	}

	// Reading the queue
	// ====

	/**
	 * Every entry the queue holds, in the order the drain consumes them.
	 *
	 * @return RevalidateItem[]
	 */
	protected function queue_entries() {
		return array_map(
			function ( $row ) {
				return new RevalidateItem( $row );
			},
			$this->queue()->get_queue()
		);
	}

	/**
	 * The permalinks the queue holds, in order — the raw stored value.
	 *
	 * @return string[]
	 */
	protected function queue_permalinks() {
		return array_map(
			function ( $entry ) {
				return $entry->permalink;
			},
			$this->queue_entries()
		);
	}

	/**
	 * The paths the queue's entries revalidate, in order.
	 *
	 * The queue holds permalinks; these are the paths those permalinks
	 * revalidate, which is the everyday way to read the queue in a test.
	 *
	 * @return string[]
	 */
	protected function revalidated_paths() {
		return array_map( [ $this, 'path_of' ], $this->queue_permalinks() );
	}

	/**
	 * The priority each revalidation sits at, in order. path => priority.
	 *
	 * @return int[]
	 */
	protected function revalidated_priorities() {
		$priorities = [];

		foreach ( $this->queue_entries() as $entry ) {
			$priorities[ $this->path_of( $entry->permalink ) ] = (int) $entry->priority;
		}

		return $priorities;
	}

	// Assertions
	// ====

	/**
	 * Assert the queue revalidates exactly these paths, in this order.
	 *
	 * The everyday assertion. A revalidation is of a path, so this is the one
	 * that reads like the domain; it normalises against the site's home url so
	 * a test does not break when `testsPort` changes.
	 *
	 * @param string[] $paths   The expected paths, drain order first.
	 * @param string   $message Optional.
	 *
	 * @return void
	 */
	protected function assertQueueRevalidates( array $paths, $message = '' ) {
		$this->assertSame( $paths, $this->revalidated_paths(), $message );
	}

	/**
	 * Assert the queue holds exactly these permalinks, in this order.
	 *
	 * What an entry literally stores. Reach for this over
	 * `assertQueueRevalidates()` when the absolute url is the point — a
	 * permalink resolves against whichever site is current while the table it
	 * lands in follows `switch_to_blog()`, and the two can disagree.
	 *
	 * @param string[] $permalinks The expected permalinks, drain order first.
	 * @param string   $message    Optional.
	 *
	 * @return void
	 */
	protected function assertQueueHolds( array $permalinks, $message = '' ) {
		$this->assertSame( $permalinks, $this->queue_permalinks(), $message );
	}

	/**
	 * Assert the queue holds nothing.
	 *
	 * @param string $message Optional.
	 * @return void
	 */
	protected function assertQueueIsEmpty( $message = '' ) {
		$this->assertSame( [], $this->queue_permalinks(), $message );
	}

	/**
	 * Assert the queue revalidates exactly these paths at these priorities, in
	 * this order.
	 *
	 * Keyed by path, which is lossless only because the queue's `permalink`
	 * column is UNIQUE: no two entries of one site can revalidate the same path,
	 * so no key can collapse onto another. Assert with
	 * `assertQueueRevalidates()` as well when the count matters to the test.
	 *
	 * @param int[]  $priorities The expected path => priority map, drain order first.
	 * @param string $message    Optional.
	 *
	 * @return void
	 */
	protected function assertQueueRevalidatesAtPriorities( array $priorities, $message = '' ) {
		$this->assertSame( $priorities, $this->revalidated_priorities(), $message );
	}
}
