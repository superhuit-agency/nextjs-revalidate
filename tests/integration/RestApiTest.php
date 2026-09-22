<?php
/**
 * The two REST routes, read through the queue they enqueue into — issue #100.
 *
 * These behaviours were checked by hand until this file existed: section J of
 * the extended pass called both routes with curl, and carried the number of this
 * issue because it never should have been a manual step. They need real
 * WordPress state — the options and the queue table — but nothing about them
 * needs a person at a browser, which is what ADR 0012 refuses to keep in the
 * runbook and what ADR 0008 sends here instead.
 *
 * Requests are built as `WP_REST_Request` and dispatched with
 * `rest_do_request()`, so this suite still needs no listening server: the route
 * is reached through the same `WP_REST_Server` a real request is dispatched
 * through, minus the HTTP.
 *
 * What a route answers with is an **acceptance**, never a delivery — ADR 0010. A
 * 200 here says the permalink reached the queue; the front-end is asked on a
 * later cron run, and nothing in this file can see that far.
 *
 * On what is sent as `path`: the route hands that parameter to
 * `RevalidateQueue::add_item()` as the **permalink**, verbatim, composing
 * nothing. The tests below therefore send the site's permalink for a path, which
 * is what the queue is defined to hold, and one of them sends a bare path — the
 * form the retired runbook step used — to pin that the route stores what it was
 * given either way.
 *
 * @package NextJsRevalidate
 */

namespace NextJsRevalidate\Tests;

use NextJsRevalidate\RestApi;
use NextJsRevalidate\Settings;
use WP_REST_Request;
use WP_REST_Response;

class RestApiTest extends QueueTestCase {

	/**
	 * A secret that is not the fixture site's.
	 */
	const WRONG_SECRET = 'not-the-fixture-secret';

	// The single route
	// ====

	/**
	 * The accepted case: a caller holding the site's secret enqueues a path, and
	 * the route says so.
	 *
	 * The success is about the enqueue and nothing else (ADR 0010) — the queue
	 * is drained by cron afterwards, so a body read as "the front-end was
	 * rebuilt" would be reading a claim this route cannot make.
	 */
	public function test_a_correct_secret_enqueues_the_path() {
		$this->configure_site();

		$permalink = $this->permalink_of( '/from-the-rest-api/' );

		$response = $this->call_route(
			'/revalidate',
			[
				'secret' => self::FIXTURE_SECRET,
				'path'   => $permalink,
			]
		);

		$this->assertSame( 200, $response->get_status(), 'Every item was accepted, so the route answers 200 rather than the 207 it reports a mixed result with.' );

		$data = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( $permalink, $data['results'][0]['path'] );
		$this->assertTrue( $data['results'][0]['success'] );

		$this->assertQueueRevalidates( [ '/from-the-rest-api/' ] );
	}

	/**
	 * The priority parameter reaches the queue, and absence of it means 10 — the
	 * default the route declares.
	 */
	public function test_the_single_route_enqueues_at_the_priority_it_was_given() {
		$this->configure_site();

		$this->call_route(
			'/revalidate',
			[
				'secret' => self::FIXTURE_SECRET,
				'path'   => $this->permalink_of( '/ordinary/' ),
			]
		);

		$this->call_route(
			'/revalidate',
			[
				'secret'   => self::FIXTURE_SECRET,
				'path'     => $this->permalink_of( '/jumps-the-queue/' ),
				'priority' => 1,
			]
		);

		$this->assertQueueRevalidates( [ '/jumps-the-queue/', '/ordinary/' ] );

		$this->assertQueueRevalidatesAtPriorities(
			[
				'/jumps-the-queue/' => 1,
				'/ordinary/'        => 10,
			]
		);
	}

	/**
	 * A priority of `0` is the priority the caller asked for, and not an absence
	 * of one — issue #110.
	 *
	 * `0` is the most urgent priority there is, so the failure it guards against
	 * is a silent one: the handler used to read the parameter for truthiness and
	 * hand `10` to the queue, and the route still answered 200 with
	 * `success: true`. The caller is told the enqueue happened, which it did, and
	 * nothing in the response says it happened anywhere but where it was asked
	 * for — only the queue can tell, which is what this asserts on.
	 */
	public function test_the_single_route_enqueues_at_a_priority_of_zero() {
		$this->configure_site();

		$response = $this->call_route(
			'/revalidate',
			[
				'secret'   => self::FIXTURE_SECRET,
				'path'     => $this->permalink_of( '/most-urgent/' ),
				'priority' => 0,
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );

		$this->assertQueueRevalidatesAtPriorities(
			[ '/most-urgent/' => 0 ],
			'An explicit `0` reaches the queue as `0`, rather than falling through to the route\'s default of 10.'
		);
	}

	/**
	 * A caller escalating a path it needs fresh now re-submits it at a more
	 * urgent priority, and the entry it already had is promoted — issue #119.
	 *
	 * The route answers 200 with `success: true` on both sides of that fix,
	 * and honestly so: the permalink is in the queue, which is all an
	 * acceptance claims (ADR 0010). Only the queue can say whether the priority
	 * the caller sent took, so that is what this asserts on.
	 *
	 * The queue-level cases — the demotion that must not happen, the `id` order
	 * a promotion keeps — are in `QueuePriorityTest`. This one is the reach:
	 * the priority survives REST dispatch, the handler and `add_item()`'s dedup
	 * branch together.
	 */
	public function test_re_submitting_a_queued_path_at_a_more_urgent_priority_promotes_it() {
		$this->configure_site();

		$permalink = $this->permalink_of( '/needed-fresh-now/' );

		$this->call_route(
			'/revalidate',
			[
				'secret' => self::FIXTURE_SECRET,
				'path'   => $permalink,
			]
		);

		$this->call_route(
			'/revalidate',
			[
				'secret'   => self::FIXTURE_SECRET,
				'path'     => $this->permalink_of( '/bulk-work/' ),
				'priority' => 5,
			]
		);

		$response = $this->call_route(
			'/revalidate',
			[
				'secret'   => self::FIXTURE_SECRET,
				'path'     => $permalink,
				'priority' => 1,
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );

		$this->assertQueueRevalidates(
			[ '/needed-fresh-now/', '/bulk-work/' ],
			'The re-submitted path drains first, and the route did not queue it twice.'
		);

		$this->assertQueueRevalidatesAtPriorities(
			[
				'/needed-fresh-now/' => 1,
				'/bulk-work/'        => 5,
			],
			'The priority sent with the second call reached the entry the queue already held.'
		);
	}

	/**
	 * The queue holds the string the caller sent, and the route composes nothing
	 * around it — a bare path is stored as a bare path.
	 *
	 * This is the form the runbook's curl step used, and it is the reason the
	 * tests above send permalinks rather than relying on the route to build one:
	 * there is no composition here to rely on.
	 */
	public function test_the_single_route_stores_the_path_it_was_given_verbatim() {
		$this->configure_site();

		$this->call_route(
			'/revalidate',
			[
				'secret' => self::FIXTURE_SECRET,
				'path'   => '/a-bare-path/',
			]
		);

		$this->assertQueueHolds( [ '/a-bare-path/' ], 'The `path` parameter reaches `add_item()` as the permalink, verbatim.' );
	}

	// The secret
	// ====

	/**
	 * A wrong secret is refused, and nothing is enqueued.
	 *
	 * The half of `check_permission()` a happy-path test cannot see. What this
	 * pins is the refusal itself: `hash_equals()` is also there to compare in
	 * constant time, and no test can observe that from here — but a comparison
	 * loosened into something that accepts the wrong string fails this.
	 */
	public function test_a_wrong_secret_is_refused_and_enqueues_nothing() {
		$this->configure_site();

		$response = $this->call_route(
			'/revalidate',
			[
				'secret' => self::WRONG_SECRET,
				'path'   => $this->permalink_of( '/never-enqueued/' ),
			]
		);

		$this->assertRestError( $response, 'rest_forbidden', rest_authorization_required_code() );

		$this->assertQueueIsEmpty( 'A caller who failed the permission check enqueued nothing.' );
	}

	/**
	 * A site holding no secret answers `missing_secret` with status 500, on
	 * either route, rather than accepting the call.
	 *
	 * The one branch where an unconfigured site is answered by the *permission
	 * callback* rather than by a refusal at enqueue: `check_permission()` looks
	 * at the secret before it compares anything, so the call never reaches
	 * `add_item()` and never becomes one of the refusals
	 * `docs/adr/0015-an-unconfigured-site-refuses-loudly.md` describes.
	 */
	public function test_a_site_with_no_secret_answers_missing_secret_on_either_route() {
		// Deliberately not configured: this site holds neither setting.
		$permalink = $this->permalink_of( '/no-secret-here/' );

		$this->assertRestError(
			$this->call_route(
				'/revalidate',
				[
					'secret' => self::FIXTURE_SECRET,
					'path'   => $permalink,
				]
			),
			'missing_secret',
			500,
			'The single route on a site holding no secret.'
		);

		$this->assertRestError(
			$this->call_route(
				'/revalidate/batch',
				[
					'secret' => self::FIXTURE_SECRET,
					'items'  => [ [ 'path' => $permalink ] ],
				]
			),
			'missing_secret',
			500,
			'The batch route on a site holding no secret.'
		);

		$this->assertQueueIsEmpty( 'Neither call was accepted, so neither reached the queue.' );
	}

	/**
	 * A site holding a secret but no revalidate domain is not a configured site,
	 * and the route must not report the call as accepted.
	 *
	 * `check_permission()` reads the secret and nothing else, so a half
	 * configured site gets past it — the refusal comes from
	 * `RevalidateQueue::add_item()` instead, and lands in the per-item result
	 * with the 207 `process_items()` answers a mixed batch with.
	 */
	public function test_a_site_holding_a_secret_but_no_domain_is_refused_at_the_enqueue() {
		update_option( Settings::SETTINGS_SECRET_NAME, self::FIXTURE_SECRET );

		$response = $this->call_route(
			'/revalidate',
			[
				'secret' => self::FIXTURE_SECRET,
				'path'   => $this->permalink_of( '/half-configured/' ),
			]
		);

		$this->assertSame( 207, $response->get_status(), 'A refused item is a failed item, and the route reports that per item.' );

		$data = $response->get_data();

		$this->assertFalse( $data['success'], 'Reporting success here would make a refusal indistinguishable from an acceptance.' );
		$this->assertFalse( $data['results'][0]['success'] );
		$this->assertSame(
			\NextJsRevalidate::init()->settings->not_configured_error()->get_error_message(),
			$data['results'][0]['message'],
			'The per-item message is the queue\'s own refusal, not a message this route invented.'
		);

		$this->assertQueueIsEmpty( 'A refusal does not reach the queue.' );
	}

	// The batch route
	// ====

	/**
	 * Every item of a batch is enqueued, in the order it was sent.
	 */
	public function test_the_batch_route_enqueues_every_item_in_the_order_it_was_sent() {
		$this->configure_site();

		$response = $this->call_route(
			'/revalidate/batch',
			[
				'secret' => self::FIXTURE_SECRET,
				'items'  => [
					[ 'path' => $this->permalink_of( '/first/' ) ],
					[ 'path' => $this->permalink_of( '/second/' ) ],
				],
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );

		$this->assertQueueRevalidates( [ '/first/', '/second/' ] );
	}

	/**
	 * Each item is enqueued at its own priority, which is what orders the drain
	 * — an item sent second is revalidated first when it asks to be.
	 */
	public function test_the_batch_route_enqueues_each_item_at_its_own_priority() {
		$this->configure_site();

		$this->call_route(
			'/revalidate/batch',
			[
				'secret' => self::FIXTURE_SECRET,
				'items'  => [
					[
						'path'     => $this->permalink_of( '/ordinary/' ),
						'priority' => 20,
					],
					[
						'path'     => $this->permalink_of( '/jumps-the-queue/' ),
						'priority' => 1,
					],
				],
			]
		);

		$this->assertQueueRevalidates( [ '/jumps-the-queue/', '/ordinary/' ] );

		$this->assertQueueRevalidatesAtPriorities(
			[
				'/jumps-the-queue/' => 1,
				'/ordinary/'        => 20,
			]
		);
	}

	/**
	 * A batch item asking for priority `0` is enqueued at `0` too.
	 *
	 * The batch handler reads its priority with `isset()` and so never had the
	 * defect the single route carried — which is exactly why this case is here:
	 * the two routes read the same parameter through different code, and the
	 * pair of tests is what stops them drifting apart again.
	 */
	public function test_the_batch_route_enqueues_an_item_at_a_priority_of_zero() {
		$this->configure_site();

		$response = $this->call_route(
			'/revalidate/batch',
			[
				'secret' => self::FIXTURE_SECRET,
				'items'  => [
					[
						'path'     => $this->permalink_of( '/most-urgent/' ),
						'priority' => 0,
					],
				],
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );

		$this->assertQueueRevalidatesAtPriorities(
			[ '/most-urgent/' => 0 ],
			'An explicit `0` reaches the queue as `0` on the batch route as well as on the single one.'
		);
	}

	/**
	 * A batch item that asks for no priority is enqueued at 10, the same default
	 * the single route declares.
	 */
	public function test_the_batch_route_enqueues_an_item_without_a_priority_at_the_default() {
		$this->configure_site();

		$this->call_route(
			'/revalidate/batch',
			[
				'secret' => self::FIXTURE_SECRET,
				'items'  => [
					[ 'path' => $this->permalink_of( '/ordinary/' ) ],
				],
			]
		);

		$this->assertQueueRevalidatesAtPriorities( [ '/ordinary/' => 10 ] );
	}

	/**
	 * A batch in which one item fails reports that item as failed and the other
	 * as accepted, and enqueues the one it accepted.
	 *
	 * The refusal test above sends a single item, so a 207 there cannot tell a
	 * per-item result from a whole batch marked failed. This can: the item
	 * missing its path fails on its own, and the item sent after it still
	 * reaches the queue — sent first, so a batch that stops at its first failure
	 * fails this too.
	 */
	public function test_a_mixed_batch_reports_each_item_on_its_own() {
		$this->configure_site();

		$permalink = $this->permalink_of( '/accepted/' );

		$response = $this->call_route(
			'/revalidate/batch',
			[
				'secret' => self::FIXTURE_SECRET,
				'items'  => [
					[ 'priority' => 1 ],
					[ 'path' => $permalink ],
				],
			]
		);

		$this->assertSame( 207, $response->get_status(), 'One item failed, so the batch is a mixed result.' );

		$data = $response->get_data();

		$this->assertFalse( $data['success'], 'A batch with a failed item is not reported as a success.' );
		$this->assertCount( 2, $data['results'], 'Every item sent has a result, the failed one included.' );

		$this->assertNull( $data['results'][0]['path'] );
		$this->assertFalse( $data['results'][0]['success'] );

		$this->assertSame( $permalink, $data['results'][1]['path'] );
		$this->assertTrue( $data['results'][1]['success'], 'The failure before it does not fail this item, nor stop the batch.' );

		$this->assertQueueRevalidates( [ '/accepted/' ], 'The accepted item was enqueued despite the failed one.' );
	}

	// What the queue answered
	// ====

	/**
	 * An insert that did not happen is not an enqueue, and the single route says
	 * so — issue #93.
	 *
	 * `RevalidateQueue::add_item()` answers a bare `false` when its own
	 * `$wpdb->insert()` fails, and the route used to read every answer that was
	 * not a `WP_Error` as an acceptance: the caller was told `success: true`
	 * with a 200, the body carrying `"data": false` as the only trace, while
	 * nothing was queued and nothing would ever be revalidated. These routes are
	 * how a deploy hook or a CI job asks for a revalidation, and they have no
	 * other feedback channel — the response is the whole contract.
	 */
	public function test_the_single_route_reports_a_failed_insert_as_a_failure() {
		$this->configure_site();

		$permalink = $this->permalink_of( '/the-insert-fails/' );

		$response = $this->with_a_failing_insert_on(
			$permalink,
			function () use ( $permalink ) {
				return $this->call_route(
					'/revalidate',
					[
						'secret' => self::FIXTURE_SECRET,
						'path'   => $permalink,
					]
				);
			}
		);

		$this->assertSame( 207, $response->get_status(), 'The item failed, so the route answers the same 207 it reports a refusal with, rather than a 200.' );

		$data = $response->get_data();

		$this->assertFalse( $data['success'] );
		$this->assertSame( $permalink, $data['results'][0]['path'] );
		$this->assertFalse( $data['results'][0]['success'], 'Nothing was queued, so nothing was accepted.' );

		$this->assertArrayHasKey( 'message', $data['results'][0], 'A failed item carries a message, because a bare false brings none of its own.' );
		$this->assertNotSame( '', $data['results'][0]['message'] );

		$this->assertQueueIsEmpty( 'The insert did not happen, which is the whole premise of this test.' );
	}

	/**
	 * The batch route reads the same answers through the same
	 * `process_items()`, and reports the item whose insert failed without
	 * failing the item next to it — issue #93.
	 */
	public function test_the_batch_route_reports_a_failed_insert_on_the_item_it_happened_to() {
		$this->configure_site();

		$failing  = $this->permalink_of( '/the-insert-fails/' );
		$accepted = $this->permalink_of( '/accepted/' );

		$response = $this->with_a_failing_insert_on(
			$failing,
			function () use ( $failing, $accepted ) {
				return $this->call_route(
					'/revalidate/batch',
					[
						'secret' => self::FIXTURE_SECRET,
						'items'  => [
							[ 'path' => $failing ],
							[ 'path' => $accepted ],
						],
					]
				);
			}
		);

		$this->assertSame( 207, $response->get_status(), 'One item failed, so the batch is a mixed result.' );

		$data = $response->get_data();

		$this->assertFalse( $data['success'] );

		$this->assertSame( $failing, $data['results'][0]['path'] );
		$this->assertFalse( $data['results'][0]['success'] );
		$this->assertArrayHasKey( 'message', $data['results'][0] );

		$this->assertSame( $accepted, $data['results'][1]['path'] );
		$this->assertTrue( $data['results'][1]['success'], 'The failed insert before it does not fail this item, nor stop the batch.' );

		$this->assertQueueRevalidates( [ '/accepted/' ], 'Only the item whose insert happened is in the queue.' );
	}

	/**
	 * A permalink already waiting in the queue is an acceptance, and stays
	 * reported as one.
	 *
	 * The queue answers `1` for a row it inserted and `true` for one already
	 * there, and both mean it holds the permalink — #50 settled that for the
	 * public API and it is the same answer here. The pair of calls is what keeps
	 * the failed-insert tests above from being read as "any answer but `1` is a
	 * failure".
	 */
	public function test_a_permalink_already_waiting_is_still_reported_as_a_success() {
		$this->configure_site();

		$permalink = $this->permalink_of( '/asked-for-twice/' );

		$params = [
			'secret' => self::FIXTURE_SECRET,
			'path'   => $permalink,
		];

		$first  = $this->call_route( '/revalidate', $params );
		$second = $this->call_route( '/revalidate', $params );

		$this->assertSame( 200, $first->get_status() );
		$this->assertTrue( $first->get_data()['results'][0]['success'] );

		$this->assertSame( 200, $second->get_status(), 'Already queued is not a mixed result.' );
		$this->assertTrue( $second->get_data()['success'] );
		$this->assertTrue( $second->get_data()['results'][0]['success'], 'The revalidation the caller asked for is queued; that another call queued it is not the caller\'s failure.' );

		$this->assertQueueRevalidates( [ '/asked-for-twice/' ], 'The queue holds the permalink once — its column is UNIQUE.' );
	}

	// Taking an insert away
	// ====

	/**
	 * Call something with the queue's insert of one permalink made not to
	 * happen, and hand back what it returned.
	 *
	 * There is no way to make the real `$wpdb->insert()` fail from a test that
	 * is not also a way to break the queue table for the rest of the suite —
	 * the wall `PublicApiTest::test_a_scheduled_purge_whose_write_fails_is_not_registered()`
	 * hit with `update_option()`. So the write is taken away instead: the
	 * `query` filter rewrites that one insert into a `SELECT` matching no rows,
	 * which writes nothing, touches no table and hands `$wpdb->insert()` back
	 * the same falsy answer a failed insert gives it.
	 *
	 * Narrow on purpose, and by permalink rather than by table: an insert
	 * naming this permalink is the only statement touched — the queue's own
	 * transaction statements, its duplicate check and any insert of another
	 * item run untouched — and nothing here spells out the queue's table name,
	 * which `QueueTestCase` explains is the one expression this suite must not
	 * own. The filter is removed before the assertions read the queue back.
	 *
	 * @param string   $permalink The permalink whose insert must not happen.
	 * @param callable $call      Called with the insert taken away.
	 *
	 * @return mixed Whatever $call returned.
	 */
	private function with_a_failing_insert_on( $permalink, callable $call ) {
		$take_the_write_away = function ( $query ) use ( $permalink ) {
			$is_the_insert = stripos( $query, 'INSERT INTO' ) !== false
				&& strpos( $query, $permalink ) !== false;

			return $is_the_insert
				? 'SELECT 1 FROM DUAL WHERE 1 = 0'
				: $query;
		};

		add_filter( 'query', $take_the_write_away );

		try {
			return $call();
		} finally {
			remove_filter( 'query', $take_the_write_away );
		}
	}

	// Calling a route
	// ====

	/**
	 * Dispatch a POST to one of this plugin's routes and hand back what the
	 * server answered.
	 *
	 * The body is JSON because that is what a caller with an `items` array
	 * sends, and because it is one code path for both routes. `rest_do_request()`
	 * always answers with a `WP_REST_Response`: an error from the permission
	 * callback arrives converted, with its code in the body and its status on
	 * the response.
	 *
	 * @param string $route  The route, below the namespace, as `/revalidate`.
	 * @param array  $params The request body.
	 *
	 * @return WP_REST_Response
	 */
	private function call_route( $route, array $params ) {
		$request = new WP_REST_Request( 'POST', '/' . RestApi::NAMESPACE . $route );

		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $params ) );

		return rest_do_request( $request );
	}

	/**
	 * Assert the route answered with this error code, at this status.
	 *
	 * @param WP_REST_Response $response The dispatched response.
	 * @param string           $code     The expected error code.
	 * @param int              $status   The expected HTTP status.
	 * @param string           $message  Optional.
	 *
	 * @return void
	 */
	private function assertRestError( WP_REST_Response $response, $code, $status, $message = '' ) {
		$this->assertTrue( $response->is_error(), $message );

		$data = $response->get_data();

		$this->assertSame( $code, $data['code'], $message );
		$this->assertSame( $status, $response->get_status(), $message );
	}
}
