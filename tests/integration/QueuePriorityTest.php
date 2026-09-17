<?php
/**
 * What a re-submitted permalink does to the priority it is already queued at —
 * issue #119.
 *
 * Deduplication is not in question here: a permalink the queue already holds is
 * still never queued twice, and every test below asserts the entry count as well
 * as the order. What is in question is the **priority** that arrives with the
 * second submission — the queue used to discard it, and answer the caller with a
 * success that described a queue it was not in.
 *
 * Priority is what orders the drain (`ORDER BY priority ASC, id ASC`), so these
 * tests read it the way the drain does: through the order the entries come back
 * in, and through `assertQueueRevalidatesAtPriorities()` for the number itself.
 *
 * They need real WordPress state — the queue table, and the settings that make
 * the site a **configured site** — which is what sends them here rather than to
 * a standalone script. See `docs/adr/0008-two-testing-idioms.md`.
 *
 * @package NextJsRevalidate
 */

namespace NextJsRevalidate\Tests;

class QueuePriorityTest extends QueueTestCase {

	/**
	 * The escalation the issue is about: a path waiting behind a bulk
	 * revalidate-all is re-submitted at a more urgent priority, and it jumps the
	 * queue.
	 */
	public function test_re_submitting_a_queued_permalink_at_a_more_urgent_priority_promotes_it() {
		$this->configure_site();

		$this->enqueue( '/needed-fresh-now/', 10 );
		$this->enqueue( '/bulk-work/', 5 );

		$this->assertQueueRevalidates(
			[ '/bulk-work/', '/needed-fresh-now/' ],
			'Before the escalation, the path sits behind the work queued more urgently than it.'
		);

		$this->assertNotWPError( $this->enqueue( '/needed-fresh-now/', 1 ) );

		$this->assertQueueRevalidates(
			[ '/needed-fresh-now/', '/bulk-work/' ],
			'The re-submitted path now drains first, and was not queued a second time.'
		);

		$this->assertQueueRevalidatesAtPriorities(
			[
				'/needed-fresh-now/' => 1,
				'/bulk-work/'        => 5,
			]
		);
	}

	/**
	 * A promotion to `0` is a promotion like any other.
	 *
	 * `0` is the most urgent priority there is and the one that is falsy, which
	 * is how it was lost once already on the single REST route (#110). A
	 * comparison written for truthiness rather than for the number fails this
	 * and passes everything else here.
	 */
	public function test_a_re_submission_at_a_priority_of_zero_promotes_the_entry() {
		$this->configure_site();

		$this->enqueue( '/most-urgent/', 10 );

		$this->assertNotWPError( $this->enqueue( '/most-urgent/', 0 ) );

		$this->assertQueueRevalidatesAtPriorities(
			[ '/most-urgent/' => 0 ],
			'An explicit `0` is a more urgent priority than 10, not an absence of one.'
		);
	}

	/**
	 * The other direction is refused, silently and on purpose: a re-submission
	 * at a less urgent priority leaves the entry where it is.
	 *
	 * Taking the minimum of the two is the whole rule. An editor saving a post
	 * an external system has already escalated must not be able to slow that
	 * work back down, and the save is not asking to — it is asking for the path
	 * to be revalidated, which it already will be, sooner.
	 */
	public function test_re_submitting_at_a_less_urgent_priority_does_not_demote_the_entry() {
		$this->configure_site();

		$this->enqueue( '/escalated/', 1 );

		$this->assertNotWPError(
			$this->enqueue( '/escalated/', 20 ),
			'The permalink is queued afterwards, which is what an acceptance means — see ADR 0010.'
		);

		$this->assertQueueRevalidates( [ '/escalated/' ] );

		$this->assertQueueRevalidatesAtPriorities(
			[ '/escalated/' => 1 ],
			'The entry keeps the more urgent of the two priorities.'
		);
	}

	/**
	 * Re-submitting at the priority the entry already sits at changes nothing
	 * and queues nothing — the dedup case as it always behaved.
	 */
	public function test_re_submitting_at_the_same_priority_changes_nothing() {
		$this->configure_site();

		$this->enqueue( '/queued-twice/', 5 );

		$this->assertNotWPError( $this->enqueue( '/queued-twice/', 5 ) );

		$this->assertQueueRevalidates( [ '/queued-twice/' ], 'The permalink is held once, not twice.' );

		$this->assertQueueRevalidatesAtPriorities( [ '/queued-twice/' => 5 ] );
	}

	/**
	 * A promoted entry brings its own `id`, so insertion order still decides
	 * between entries sitting at the same priority.
	 *
	 * The three entries below are queued in an order the ids record and the
	 * priorities hide: the promoted one drains behind the entry that was already
	 * waiting at priority 1, and ahead of the one queued after it. Re-queueing a
	 * promotion — deleting the row and inserting it again at the new priority —
	 * would put it last of the three, behind work it predates.
	 */
	public function test_a_promoted_entry_keeps_its_place_among_the_entries_it_joins() {
		$this->configure_site();

		$this->enqueue( '/queued-first/', 1 );
		$this->enqueue( '/promoted/', 10 );
		$this->enqueue( '/queued-last/', 1 );

		$this->enqueue( '/promoted/', 1 );

		$this->assertQueueRevalidates( [ '/queued-first/', '/promoted/', '/queued-last/' ] );

		$this->assertQueueRevalidatesAtPriorities(
			[
				'/queued-first/' => 1,
				'/promoted/'     => 1,
				'/queued-last/'  => 1,
			]
		);
	}

	/**
	 * Promotion is per permalink: escalating one leaves every other entry at the
	 * priority it was queued at.
	 *
	 * The `WHERE` clause the promotion runs through carries both the permalink
	 * and the priority, and a promotion that dropped the permalink from it would
	 * pass every test above — each has one entry to promote — while flattening
	 * the queue of every site that ever escalates anything.
	 */
	public function test_promoting_one_permalink_leaves_the_rest_of_the_queue_alone() {
		$this->configure_site();

		$this->enqueue( '/escalated/', 10 );
		$this->enqueue( '/ordinary/', 10 );
		$this->enqueue( '/last/', 20 );

		$this->enqueue( '/escalated/', 1 );

		$this->assertQueueRevalidatesAtPriorities(
			[
				'/escalated/' => 1,
				'/ordinary/'  => 10,
				'/last/'      => 20,
			]
		);
	}
}
