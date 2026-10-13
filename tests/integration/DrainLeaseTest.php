<?php
/**
 * That the cap on concurrent drains counts drains that are running — issue #210.
 *
 * The cap used to be a counter in a transient, read and rewritten by each drain
 * as it started and finished. It drifted upward and stayed there: a long drain
 * decremented from the stale copy its own request held, two drains could read
 * the same value and both write it back moved by one, a drain killed part-way
 * never decremented at all, and every write pushed the expiry out by another
 * hour. A counter stuck at the cap turned every drain away with nothing running.
 *
 * Each drain now holds a **drain lease** — a row of its own, on one of the
 * cap's slots, expiring a little past its time budget — and the cap is the
 * number of slots. These need real options, real SQL and the queue table, which
 * is what sends them here rather than to a standalone script. See
 * `docs/adr/0008-two-testing-idioms.md` and
 * `docs/adr/0043-a-drain-holds-a-lease-on-one-of-the-caps-slots.md`.
 *
 * @package NextJsRevalidate
 */

namespace NextJsRevalidate\Tests;

use NextJsRevalidate\FailureWindow;
use NextJsRevalidate\RevalidateQueue;
use NextJsRevalidate\Settings;
use ReflectionMethod;

class DrainLeaseTest extends QueueTestCase {

	public function set_up() {
		parent::set_up();

		// A drain asks the front-end. Answer it here, so no test leaves the
		// container and every attempt is a success.
		add_filter( 'pre_http_request', [ $this, 'answer_the_front_end' ] );
	}

	/**
	 * Undo what a drain commits past the rollback, ahead of the parent's queue
	 * reset — the `TRUNCATE` that makes these deletions durable. See
	 * `QueueTestCase::tear_down()` for why they must sit above it.
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', [ $this, 'answer_the_front_end' ] );
		delete_transient( RevalidateQueue::CRON_TRANSCIENT_NAME );
		FailureWindow::clear();

		parent::tear_down();
	}

	/**
	 * @return array A front-end that revalidated.
	 */
	public function answer_the_front_end() {
		return [
			'headers'  => [],
			'body'     => '{"revalidated":true}',
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'cookies'  => [],
			'filename' => null,
		];
	}

	// The cap
	// ====

	/**
	 * As many drains as the cap allows each get a slot, and the next one does
	 * not — and a slot given back is a slot the next drain gets.
	 */
	public function test_the_cap_holds_with_as_many_live_leases_as_it_allows() {
		$leases = [];

		for ( $i = 0; $i < RevalidateQueue::MAX_NB_RUNNING_CRON; $i++ ) {
			$lease = $this->claim();

			$this->assertNotFalse( $lease, sprintf( 'Drain %d of %d was turned away below the cap.', $i + 1, RevalidateQueue::MAX_NB_RUNNING_CRON ) );
			$leases[] = $lease;
		}

		$this->assertFalse( $this->claim(), 'A drain over the cap was given a lease.' );

		$this->release( $leases[0] );

		$this->assertNotFalse( $this->claim(), 'A slot given back by a finishing drain was not given to the next one.' );
		$this->assertFalse( $this->claim(), 'Giving one slot back let two drains in.' );
	}

	// What used to hold a slot for nobody
	// ====

	/**
	 * A drain killed part-way — a fatal, a timeout, a recycled worker — never
	 * gives its lease back. Its lease expires instead, and the slot is free.
	 */
	public function test_an_expired_lease_frees_its_slot() {
		$this->configure_site();
		$this->enqueue( '/waiting/' );

		// Every slot held by a drain that was killed, long enough ago that its
		// lease has run out.
		$this->hold_every_drain_slot( time() - 1 );

		$this->queue()->run_cron();

		$this->assertQueueIsEmpty( 'The leases of killed drains turned a drain away after they expired.' );

		$this->assertSame(
			[],
			$this->drain_lease_rows(),
			'The expired leases were left behind, or the drain did not give its own back.'
		);
	}

	/**
	 * The drift #210 was about, as an upgraded site still holds it: the counter
	 * an earlier release kept, stuck at the cap with no drain running. Nothing
	 * reads it any more, so it turns nothing away — on a site no admin has
	 * visited since the upgrade, whose migration has not run.
	 */
	public function test_a_counter_left_at_the_cap_by_an_earlier_release_turns_no_drain_away() {
		$this->configure_site();
		$this->enqueue( '/waiting/' );

		set_transient( RevalidateQueue::CRON_TRANSCIENT_NAME, RevalidateQueue::MAX_NB_RUNNING_CRON, HOUR_IN_SECONDS );

		$this->queue()->run_cron();

		$this->assertQueueIsEmpty( 'A running-drain counter left at the cap by an earlier release turned a drain away.' );
	}

	/**
	 * A request's object cache can hold a copy of a lease that is gone from
	 * the database — the staleness that made the counter drift. The leases are
	 * read from the database every time, so a copy turns nothing away.
	 */
	public function test_a_lease_only_the_object_cache_still_holds_turns_no_drain_away() {
		$this->configure_site();
		$this->enqueue( '/waiting/' );

		for ( $slot = 0; $slot < RevalidateQueue::MAX_NB_RUNNING_CRON; $slot++ ) {
			wp_cache_set( RevalidateQueue::DRAIN_LEASE_PREFIX . $slot, (string) ( time() + HOUR_IN_SECONDS ), 'options' );
		}

		$this->queue()->run_cron();

		for ( $slot = 0; $slot < RevalidateQueue::MAX_NB_RUNNING_CRON; $slot++ ) {
			wp_cache_delete( RevalidateQueue::DRAIN_LEASE_PREFIX . $slot, 'options' );
		}

		$this->assertQueueIsEmpty( 'A lease only the object cache held turned a drain away.' );
	}

	// A drain's own lease
	// ====

	/**
	 * A drain that finishes gives its lease back, so the slot is free at once
	 * and not only once the lease expires.
	 */
	public function test_a_drain_gives_its_lease_back_when_it_finishes() {
		$this->configure_site();
		$this->enqueue( '/waiting/' );

		$this->queue()->run_cron();

		$this->assertQueueIsEmpty( 'The drain did not run.' );
		$this->assertSame( [], $this->drain_lease_rows(), 'A finished drain kept its lease.' );
	}

	/**
	 * A lease outlasts the drain holding it: it expires a little after the
	 * drain's whole time budget, so a drain that is still working is never
	 * counted out of the cap.
	 */
	public function test_a_lease_outlasts_the_drains_time_budget() {
		$budget = ini_get( 'max_execution_time' );
		ini_set( 'max_execution_time', '300' );

		$lease = $this->claim();

		ini_set( 'max_execution_time', (string) $budget );

		$this->assertNotFalse( $lease, 'A drain with every slot free was turned away.' );
		$this->assertGreaterThan(
			time() + 300,
			$lease['expiry'],
			'A lease expires before the time budget of the drain holding it is spent.'
		);
	}

	/**
	 * A drain that outlived its lease — a last purge that hung — finishes after
	 * another drain has taken its slot. It gives back its own lease, which is
	 * gone, and not the lease of the drain now holding that slot.
	 */
	public function test_a_drain_that_outlived_its_lease_leaves_its_successors_alone() {
		$outlived = $this->hold_drain_slot( 0, time() - 1 );

		$successor = $this->claim();
		$this->assertNotFalse( $successor, 'An expired lease did not free its slot.' );
		$this->assertSame( $outlived['name'], $successor['name'], 'The successor did not take the expired slot.' );

		$this->release( $outlived );

		$this->assertSame(
			[ $successor['name'] => (string) $successor['expiry'] ],
			$this->drain_lease_rows(),
			'A drain that outlived its lease gave back the lease of the drain holding its slot since.'
		);
	}

	// Upgrade
	// ====

	/**
	 * A site migrating from the release that kept the counter forgets it, so
	 * nothing it held outlives the upgrade.
	 */
	public function test_migrating_from_the_running_drain_counter_forgets_it() {
		$ledger = get_option( Settings::DB_VERSION_OPTION_NAME );

		set_transient( RevalidateQueue::CRON_TRANSCIENT_NAME, RevalidateQueue::MAX_NB_RUNNING_CRON, HOUR_IN_SECONDS );
		update_option( Settings::DB_VERSION_OPTION_NAME, '1.7.0' );

		\NextJsRevalidate::init()->settings->migrate_db();

		$forgotten = get_transient( RevalidateQueue::CRON_TRANSCIENT_NAME );

		if ( false === $ledger ) delete_option( Settings::DB_VERSION_OPTION_NAME );
		else update_option( Settings::DB_VERSION_OPTION_NAME, $ledger );

		$this->assertFalse( $forgotten, 'Migrating from 1.7.0 left the running-drain counter behind.' );
	}

	/**
	 * Uninstalling takes the leases with it: a lease is a row of the site's
	 * options, and left behind it is one nothing would ever read again.
	 */
	public function test_deleting_the_leases_leaves_none_behind() {
		$this->hold_drain_slot( 0, time() + HOUR_IN_SECONDS );
		$this->hold_drain_slot( 1, time() - 1 );
		set_transient( RevalidateQueue::CRON_TRANSCIENT_NAME, 2, HOUR_IN_SECONDS );

		$this->queue()->delete_drain_leases();

		$this->assertSame( [], $this->drain_lease_rows(), 'A lease outlived the uninstall.' );
		$this->assertFalse( get_transient( RevalidateQueue::CRON_TRANSCIENT_NAME ), 'The running-drain counter outlived the uninstall.' );
	}

	// The leases, from outside
	// ====

	/**
	 * Claim a lease, as a drain starting does.
	 *
	 * @return array{name: string, expiry: int}|false
	 */
	private function claim() {
		$claim = new ReflectionMethod( RevalidateQueue::class, 'claim_drain_lease' );
		$claim->setAccessible( true );

		return $claim->invoke( $this->queue() );
	}

	/**
	 * Give a lease back, as a drain finishing does.
	 *
	 * @param array{name: string, expiry: int} $lease
	 * @return void
	 */
	private function release( $lease ) {
		$release = new ReflectionMethod( RevalidateQueue::class, 'release_drain_lease' );
		$release->setAccessible( true );

		$release->invoke( $this->queue(), $lease );
	}
}
