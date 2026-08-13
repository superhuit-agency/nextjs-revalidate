<?php
/**
 * The harness proving itself.
 *
 * Every test here exercises a capability of QueueTestCase rather than a claim
 * about the plugin: that WordPress boots with the plugin active, that the queue
 * table exists, that its state does not cross a test boundary, and that the
 * assertions read what the drain would read. Tests asserting the plugin is
 * *correct* belong to the issues that own those behaviours.
 *
 * @package NextJsRevalidate
 */

namespace NextJsRevalidate\Tests;

class QueueHarnessTest extends QueueTestCase {

	/**
	 * The environment: WordPress is up, with the plugin loaded and its table
	 * created by bootstrap.
	 */
	public function test_the_plugin_is_loaded_and_its_queue_is_reachable() {
		$this->assertTrue( function_exists( 'nextjs_revalidate_purge_url' ) );
		$this->assertInstanceOf( \NextJsRevalidate\RevalidateQueue::class, $this->queue() );
		$this->assertQueueIsEmpty( 'The queue starts every test empty.' );
	}

	/**
	 * Proving test 1 — enqueue a path, the queue holds exactly it.
	 */
	public function test_the_queue_revalidates_a_path_that_was_enqueued() {
		$this->configure_site();

		// The queue answers with what it did, not with a bool: an insert of one
		// row, or the WP_Error an unconfigured site refuses with.
		$this->assertNotWPError( $this->enqueue( '/hello-world/' ) );

		$this->assertQueueRevalidates( [ '/hello-world/' ] );
	}

	/**
	 * Isolation, first half.
	 *
	 * This test and the next are a pair, and only mean anything run together in
	 * this order: the queue's rows survive `WP_UnitTestCase`'s rollback, so the
	 * failure they guard against appears on the *second* test and is invisible
	 * in a source review.
	 */
	public function test_isolation_first_test_leaves_an_entry_behind() {
		$this->configure_site();

		$this->enqueue( '/the-first-test/' );

		$this->assertQueueRevalidates( [ '/the-first-test/' ] );
	}

	/**
	 * Isolation, second half — the previous test's entry is gone.
	 */
	public function test_isolation_second_test_sees_only_its_own_entry() {
		$this->configure_site();

		$this->enqueue( '/the-second-test/' );

		$this->assertQueueRevalidates(
			[ '/the-second-test/' ],
			'The previous test\'s entry survived into this one: the queue is not being reset.'
		);
	}

	/**
	 * Order: priority ascending, then insertion — what the drain consumes.
	 */
	public function test_the_queue_is_read_in_drain_order() {
		$this->configure_site();

		$this->enqueue( '/queued-first/', 10 );
		$this->enqueue( '/queued-second/', 10 );
		$this->enqueue( '/jumps-the-queue/', 1 );
		$this->enqueue( '/queued-last/', 20 );

		$this->assertQueueRevalidates(
			[ '/jumps-the-queue/', '/queued-first/', '/queued-second/', '/queued-last/' ]
		);

		$this->assertQueueRevalidatesAtPriorities(
			[
				'/jumps-the-queue/' => 1,
				'/queued-first/'    => 10,
				'/queued-second/'   => 10,
				'/queued-last/'     => 20,
			]
		);
	}

	/**
	 * The raw permalink is reachable, not only the path.
	 *
	 * Permalink level bugs are real here — the table an entry lands in follows
	 * `switch_to_blog()` while the permalink resolves against the current site —
	 * so a test must be able to see the absolute url that was stored.
	 */
	public function test_the_stored_permalink_is_reachable() {
		$this->configure_site();

		$this->enqueue( '/a-page/' );

		$this->assertQueueHolds( [ home_url( '/a-page/' ) ] );
		$this->assertSame( home_url( '/a-page/' ), $this->queue_entries()[0]->permalink );
	}

	/**
	 * A permalink of another site is reported whole rather than trimmed.
	 */
	public function test_a_foreign_permalink_is_not_read_as_a_path() {
		$this->configure_site();

		$this->queue()->add_item( 'https://elsewhere.test/a-page/' );

		$this->assertQueueRevalidates( [ 'https://elsewhere.test/a-page/' ] );
	}

	/**
	 * Proving test 2 — the fixture path is reachable: a configured site
	 * publishes a revalidatable post and the queue holds that post's permalink.
	 */
	public function test_publishing_a_post_on_a_configured_site_enqueues_its_permalink() {
		$this->configure_site();

		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$this->assertQueueHolds( [ get_permalink( $post_id ) ] );
	}
}
