<?php
/**
 * The two documented API functions, read through their return values.
 *
 * Everything else in this suite asserts on what the queue holds. These tests
 * assert on what the plugin *told the caller*, because that is the whole of the
 * public contract: third-party code has no other way to find out what happened,
 * and for a long time the answer was the constant `true` (#50).
 *
 * What the contract is, and what it deliberately is not, is in
 * `docs/adr/0010-the-public-api-reports-acceptance-not-delivery.md`. In short:
 * these functions answer whether a revalidation was *accepted*, and no test here
 * can assert on a delivery, because the delivery happens on a later cron run.
 *
 * @package NextJsRevalidate
 */

namespace NextJsRevalidate\Tests;

use NextJsRevalidate\Cron\ScheduledPurges;

class PublicApiTest extends QueueTestCase {

	/**
	 * A date time far enough ahead that the scheduled purges cron will not have
	 * come due while the suite runs. Fixed rather than computed: a relative one
	 * would make the option key the assertions read change per run.
	 */
	const FIXTURE_DATETIME = '2099-01-01 09:00:00';

	/**
	 * Undo what a scheduled purge commits, above the queue reset.
	 *
	 * `schedule_purge()` writes an option and schedules a cron event, and this
	 * suite's teardown ends in a `TRUNCATE` — DDL, which forces an implicit
	 * commit and carries both past `WP_UnitTestCase`'s rollback. The order is
	 * the one `QueueTestCase::tear_down()` explains: undo first, truncate last,
	 * or the undo is itself rolled back.
	 */
	public function tear_down() {
		ScheduledPurges::delete_scheduled_purges();
		ScheduledPurges::unschedule_cron();

		parent::tear_down();
	}

	// nextjs_revalidate_purge_url()
	// ====

	/**
	 * The accepted case: a configured site takes the revalidation on, and says
	 * so.
	 */
	public function test_purging_a_url_on_a_configured_site_is_accepted() {
		$this->configure_site();

		$this->assertTrue(
			\nextjs_revalidate_purge_url( $this->permalink_of( '/hello-world/' ) ),
			'A configured site accepted the revalidation and should have said so.'
		);

		$this->assertQueueRevalidates( [ '/hello-world/' ] );
	}

	/**
	 * The refused case, and the reason this function's return value exists at
	 * all: an unconfigured site queues nothing, and must not report success.
	 */
	public function test_purging_a_url_on_an_unconfigured_site_is_refused() {
		$this->assertFalse(
			\nextjs_revalidate_purge_url( $this->permalink_of( '/hello-world/' ) ),
			'An unconfigured site refuses the revalidation; reporting true would make a refusal indistinguishable from an acceptance.'
		);

		$this->assertQueueIsEmpty( 'A refusal does not reach the queue.' );
	}

	/**
	 * A URL already waiting is accepted, and the queue still holds it once.
	 *
	 * The `permalink` column is UNIQUE and `add_item()` treats a permalink it
	 * already holds as added, so the second call is an acceptance rather than a
	 * failed insert. A caller asking twice is asking for the same page to be
	 * fresh, and it will be.
	 */
	public function test_a_url_already_in_the_queue_is_accepted_without_being_queued_twice() {
		$this->configure_site();

		$permalink = $this->permalink_of( '/asked-for-twice/' );

		$this->assertTrue( \nextjs_revalidate_purge_url( $permalink ) );
		$this->assertTrue(
			\nextjs_revalidate_purge_url( $permalink ),
			'A permalink the queue already holds is accepted, not rejected.'
		);

		$this->assertQueueRevalidates( [ '/asked-for-twice/' ] );
	}

	/**
	 * The priority argument reaches the queue, so a caller can order its own
	 * revalidations against the ones the plugin makes on its own.
	 */
	public function test_the_priority_argument_reaches_the_queue() {
		$this->configure_site();

		\nextjs_revalidate_purge_url( $this->permalink_of( '/ordinary/' ) );
		\nextjs_revalidate_purge_url( $this->permalink_of( '/jumps-the-queue/' ), 1 );

		$this->assertQueueRevalidates( [ '/jumps-the-queue/', '/ordinary/' ] );

		$this->assertQueueRevalidatesAtPriorities(
			[
				'/jumps-the-queue/' => 1,
				'/ordinary/'        => 10,
			]
		);
	}

	// nextjs_revalidate_schedule_purge_url()
	// ====

	/**
	 * Registering a scheduled purge, and the second half of the sibling's
	 * contract: a URL already registered for that date time registers nothing,
	 * which is what the `false` means. The schedule itself stands either way.
	 */
	public function test_scheduling_the_same_purge_twice_registers_it_once() {
		$permalink = $this->permalink_of( '/scheduled/' );

		$this->assertTrue(
			\nextjs_revalidate_schedule_purge_url( self::FIXTURE_DATETIME, $permalink ),
			'The first call registers the scheduled purge.'
		);

		$this->assertFalse(
			\nextjs_revalidate_schedule_purge_url( self::FIXTURE_DATETIME, $permalink ),
			'The second call adds nothing to the schedule, and false is what says so.'
		);

		$this->assertSame(
			[ $permalink ],
			$this->scheduled_permalinks(),
			'The schedule holds the permalink once.'
		);
	}

	/**
	 * A scheduled purge is not an enqueued revalidation.
	 *
	 * Nothing reaches the queue until the date time passes — which is why this
	 * function's true is a promise to enqueue later, and why a site configured
	 * now can still refuse the revalidation when it comes due.
	 */
	public function test_scheduling_a_purge_enqueues_nothing_yet() {
		$this->configure_site();

		\nextjs_revalidate_schedule_purge_url( self::FIXTURE_DATETIME, $this->permalink_of( '/due-in-2099/' ) );

		$this->assertQueueIsEmpty( 'A scheduled purge reaches the queue at its due time, not when it is registered.' );
	}

	/**
	 * Isolation of what a scheduled purge commits, second half — this test is
	 * paired with the one above it and only means anything run after it, for
	 * the reason `QueueHarnessTest`'s pairs give: the leak appears on the
	 * *next* test and is invisible in a source review.
	 */
	public function test_no_scheduled_purge_survives_into_the_next_test() {
		$this->assertSame(
			[],
			$this->scheduled_permalinks(),
			'The previous test\'s scheduled purge survived into this one: tear_down is not undoing it.'
		);

		$this->assertFalse(
			wp_next_scheduled( ScheduledPurges::CRON_HOOK_NAME ),
			'The previous test\'s scheduled purges cron survived into this one.'
		);
	}

	// Reading the schedule
	// ====

	/**
	 * Every permalink registered for a future purge, in schedule order.
	 *
	 * The option is a date time => permalinks map; a test here cares which
	 * permalinks are registered rather than how they are keyed.
	 *
	 * @return string[]
	 */
	private function scheduled_permalinks() {
		$permalinks = [];

		foreach ( get_option( ScheduledPurges::OPTION_NAME, [] ) as $entries ) {
			foreach ( $entries as $permalink ) {
				$permalinks[] = $permalink;
			}
		}

		return $permalinks;
	}
}
