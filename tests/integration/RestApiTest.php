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
