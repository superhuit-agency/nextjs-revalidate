<?php
/**
 * That a queue holding entries always has a drain scheduled — issue #208.
 *
 * The drain chain is kept alive by drains *starting*: each one arms the next
 * event as it begins. Two things used to end it while entries were still
 * waiting, and the queue then sat until something new was enqueued:
 *
 *  - a drain turned away by `MAX_NB_RUNNING_CRON` returned without scheduling
 *    anything, though the event that fired it was already consumed;
 *  - a drain finishing after a long run asked `wp_next_scheduled()` through its
 *    own stale copy of the `cron` option, which still held the event it armed
 *    at its start, long since fired elsewhere — so it rescheduled nothing.
 *
 * Both need real cron, options and the queue table, which is what sends them
 * here rather than to a standalone script. See
 * `docs/adr/0008-two-testing-idioms.md`.
 *
 * @package NextJsRevalidate
 */

namespace NextJsRevalidate\Tests;

use NextJsRevalidate\FailureWindow;
use NextJsRevalidate\RevalidateQueue;

class QueueSchedulingTest extends QueueTestCase {

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

	/**
	 * The fifth tick of a long drain: four drains are running, so this one is
	 * turned away — and the queue must stay scheduled, because the event that
	 * fired it is gone.
	 */
	public function test_a_drain_turned_away_by_the_cap_keeps_the_queue_scheduled() {
		$this->configure_site();
		$this->enqueue( '/waiting/' );

		// The tick that fires a drain has already consumed its event.
		$this->queue()->unschedule_cron();
		$this->hold_every_drain_slot( time() + HOUR_IN_SECONDS );

		$this->queue()->run_cron();

		$this->assertQueueRevalidates(
			[ '/waiting/' ],
			'A drain turned away by the cap drains nothing.'
		);

		$this->assertNotFalse(
			wp_next_scheduled( RevalidateQueue::CRON_HOOK_NAME ),
			'A drain turned away by the cap left a queue holding entries with no drain scheduled.'
		);
	}

	/**
	 * A drain that ran long finishes with entries still waiting, while the
	 * event it armed at its start has fired in another request since. The
	 * database holds no event; this request's copy of `cron` still shows one.
	 */
	public function test_a_drain_finishing_with_a_stale_cron_copy_reschedules_from_the_database() {
		$this->configure_site();
		$this->enqueue( '/drained/' );

		// What other requests do while this drain runs, staged at the last
		// moment it can be — as the drain gives its lease back, after its loop
		// has found the queue empty: one enqueues a path, while the armed event
		// is still pending and so arms nothing, and then that event fires and
		// is gone.
		$staged = function () {
			$this->enqueue( '/arrived-late/' );
			$this->consume_the_event_behind_this_requests_back();
		};
		$watch = $this->when_the_drain_gives_its_lease_back( $staged );

		try {
			$this->queue()->run_cron();
		} finally {
			remove_filter( 'query', $watch );
		}

		$this->assertQueueRevalidates(
			[ '/arrived-late/' ],
			'The drain consumed the path it found, and the late one is still waiting.'
		);

		$this->assertTrue(
			$this->drain_is_scheduled_in_the_database(),
			'A drain finishing against a stale copy of `cron` left a queue holding entries with no drain scheduled.'
		);
	}

	// Another request
	// ====

	/**
	 * Run `$staged` once, just before the drain's statement giving its lease
	 * back reaches the database.
	 *
	 * The lease is a row written and deleted by plain SQL, which fires no hook
	 * of its own, so the statement is the only moment there is to catch. It is
	 * told apart from the expired-lease sweep a drain runs as it starts by the
	 * lease's own value in its `WHERE`: the sweep matches by name pattern only.
	 *
	 * @param callable $staged
	 * @return callable The filter, for the test to remove: the test library
	 *                  keeps filters of its own on `query`, so removing them
	 *                  all is not an option.
	 */
	private function when_the_drain_gives_its_lease_back( callable $staged ) {
		$fired = false;

		$watch = function ( $query ) use ( $staged, &$fired ) {
			if ( $fired ) return $query;

			if ( 0 !== stripos( ltrim( $query ), 'DELETE' ) ) return $query;
			if ( false === strpos( $query, RevalidateQueue::DRAIN_LEASE_PREFIX ) ) return $query;
			if ( false === strpos( $query, '`option_value` =' ) ) return $query;

			// Set before staging, which runs queries of its own.
			$fired = true;
			$staged();

			return $query;
		};

		add_filter( 'query', $watch );

		return $watch;
	}

	/**
	 * Remove the drain event from the `cron` row itself, leaving this request's
	 * cached copy of the option as it was — what another request running the
	 * event looks like from inside this one.
	 *
	 * @return void
	 */
	private function consume_the_event_behind_this_requests_back() {
		global $wpdb;

		$cron = $this->cron_in_the_database();

		foreach ( $cron as $timestamp => $hooks ) {
			if ( !is_array( $hooks ) ) continue;

			unset( $cron[ $timestamp ][ RevalidateQueue::CRON_HOOK_NAME ] );
			if ( empty( $cron[ $timestamp ] ) ) unset( $cron[ $timestamp ] );
		}

		$wpdb->update(
			$wpdb->options,
			[ 'option_value' => maybe_serialize( $cron ) ],
			[ 'option_name' => 'cron' ]
		);
	}

	/**
	 * Whether the `cron` row — not this request's copy of it — holds a drain.
	 *
	 * @return bool
	 */
	private function drain_is_scheduled_in_the_database() {
		foreach ( $this->cron_in_the_database() as $hooks ) {
			if ( is_array( $hooks ) && isset( $hooks[ RevalidateQueue::CRON_HOOK_NAME ] ) ) return true;
		}

		return false;
	}

	/**
	 * @return array The `cron` option as the database holds it.
	 */
	private function cron_in_the_database() {
		global $wpdb;

		$cron = maybe_unserialize(
			$wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'cron' ) )
		);

		return is_array( $cron ) ? $cron : [];
	}
}
