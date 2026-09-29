<?php
/**
 * The two REST routes, read through the pending changes they report into —
 * issue #100.
 *
 * These behaviours were checked by hand until this file existed: section J of
 * the extended pass called both routes with curl, and carried the number of this
 * issue because it never should have been a manual step. They need real
 * WordPress state — the options and the plugin's singleton — but nothing about
 * them needs a person at a browser, which is what ADR 0012 refuses to keep in
 * the runbook and what ADR 0008 sends here instead.
 *
 * Requests are built as `WP_REST_Request` and dispatched with
 * `rest_do_request()`, so this suite still needs no listening server: the route
 * is reached through the same `WP_REST_Server` a real request is dispatched
 * through, minus the HTTP.
 *
 * What a route answers with is an **acceptance**, never a delivery — ADR 0010. A
 * 200 here says a path change joined the pending changes; the front-end is told
 * once the request has ended, and nothing in this file can see that far.
 *
 * Each item becomes a **path** change whose `uri` is the path from the domain
 * root, whether the caller sent a permalink or a path. `priority` is still
 * accepted, and ignored — there is no queue left for it to order (ADR 0035).
 *
 * @package NextJsRevalidate
 */

namespace NextJsRevalidate\Tests;

use NextJsRevalidate\Change;
use NextJsRevalidate\RestApi;
use NextJsRevalidate\Settings;
use WP_REST_Request;
use WP_REST_Response;

class RestApiTest extends PendingChangesTestCase {

	/**
	 * A secret that is not the fixture site's.
	 */
	const WRONG_SECRET = 'not-the-fixture-secret';

	// The single route
	// ====

	/**
	 * The accepted case: a caller holding the site's secret names a path, and
	 * the route says so.
	 *
	 * The success is about the acceptance and nothing else (ADR 0010) — the
	 * change is delivered after the response is sent, so a body read as "the
	 * front-end was rebuilt" would be reading a claim this route cannot make.
	 */
	public function test_a_correct_secret_reports_the_path() {
		$this->configure_site();

		$permalink = $this->permalink_of( '/from-the-rest-api/' );

		$response = $this->call_route(
			'/revalidate',
			[
				'secret' => self::FIXTURE_SECRET,
				'path'   => $permalink,
			]
		);

		$this->assertSame( 200, $response->get_status(), 'The item was accepted, so the route answers 200.' );

		$data = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( $permalink, $data['results'][0]['path'] );
		$this->assertTrue( $data['results'][0]['success'] );

		$this->assertPendingChanges( [ $this->path_change( '/from-the-rest-api/' ) ] );
	}

	/**
	 * A permalink and the path it names are one change, and the route composes
	 * nothing around a bare path: it is taken as from the domain root.
	 */
	public function test_a_bare_path_and_its_permalink_are_one_change() {
		$this->configure_site();

		$this->call_route(
			'/revalidate',
			[
				'secret' => self::FIXTURE_SECRET,
				'path'   => '/a-bare-path/',
			]
		);

		$this->assertPendingChanges( [ Change::path( '/a-bare-path/' ) ], 'A bare path is its own `uri`.' );

		$this->call_route(
			'/revalidate',
			[
				'secret' => self::FIXTURE_SECRET,
				'path'   => $this->permalink_of( '/from-the-rest-api/' ),
			]
		);
		$this->call_route(
			'/revalidate',
			[
				'secret' => self::FIXTURE_SECRET,
				'path'   => wp_make_link_relative( $this->permalink_of( '/from-the-rest-api/' ) ),
			]
		);

		$this->assertPendingChanges(
			[ Change::path( '/a-bare-path/' ), $this->path_change( '/from-the-rest-api/' ) ],
			'A permalink and its path merge into one change.'
		);
	}

	/**
	 * `priority` is accepted as it always was, on both routes, and changes
	 * nothing: `0` and `1` are no more urgent than the default, because there is
	 * no queue left to order (ADR 0035).
	 */
	public function test_a_priority_is_accepted_and_ignored() {
		$this->configure_site();

		$ordinary = $this->call_route(
			'/revalidate',
			[
				'secret' => self::FIXTURE_SECRET,
				'path'   => $this->permalink_of( '/ordinary/' ),
			]
		);

		$urgent = $this->call_route(
			'/revalidate',
			[
				'secret'   => self::FIXTURE_SECRET,
				'path'     => $this->permalink_of( '/most-urgent/' ),
				'priority' => 0,
			]
		);

		$batch = $this->call_route(
			'/revalidate/batch',
			[
				'secret' => self::FIXTURE_SECRET,
				'items'  => [
					[
						'path'     => $this->permalink_of( '/batched/' ),
						'priority' => 1,
					],
				],
			]
		);

		$this->assertSame( 200, $ordinary->get_status() );
		$this->assertSame( 200, $urgent->get_status(), 'A priority the route declared is still accepted.' );
		$this->assertSame( 200, $batch->get_status() );

		$this->assertPendingChanges(
			[
				$this->path_change( '/ordinary/' ),
				$this->path_change( '/most-urgent/' ),
				$this->path_change( '/batched/' ),
			],
			'The changes are held in the order they were reported, whatever priority they were sent with.'
		);
	}

	/**
	 * A path already held is an acceptance, and stays reported as one.
	 *
	 * Two identical changes merge, so the second call adds nothing — and the
	 * revalidation the caller asked for is still going to happen, which is all
	 * an acceptance claims.
	 */
	public function test_a_path_already_held_is_still_reported_as_a_success() {
		$this->configure_site();

		$params = [
			'secret' => self::FIXTURE_SECRET,
			'path'   => $this->permalink_of( '/asked-for-twice/' ),
		];

		$first  = $this->call_route( '/revalidate', $params );
		$second = $this->call_route( '/revalidate', $params );

		$this->assertSame( 200, $first->get_status() );
		$this->assertTrue( $first->get_data()['results'][0]['success'] );

		$this->assertSame( 200, $second->get_status(), 'Already held is not a mixed result.' );
		$this->assertTrue( $second->get_data()['success'] );
		$this->assertTrue( $second->get_data()['results'][0]['success'], 'The change the caller asked for is held; that another call reported it is not the caller\'s failure.' );

		$this->assertPendingChanges( [ $this->path_change( '/asked-for-twice/' ) ], 'Identical changes merge, so the path is held once.' );
	}

	// The secret
	// ====

	/**
	 * A wrong secret is refused, and nothing is reported.
	 *
	 * The half of `check_permission()` a happy-path test cannot see. What this
	 * pins is the refusal itself: `hash_equals()` is also there to compare in
	 * constant time, and no test can observe that from here — but a comparison
	 * loosened into something that accepts the wrong string fails this.
	 */
	public function test_a_wrong_secret_is_refused_and_reports_nothing() {
		$this->configure_site();

		$response = $this->call_route(
			'/revalidate',
			[
				'secret' => self::WRONG_SECRET,
				'path'   => $this->permalink_of( '/never-reported/' ),
			]
		);

		$this->assertRestError( $response, 'rest_forbidden', rest_authorization_required_code() );

		$this->assertNoPendingChanges( 'A caller who failed the permission check reported nothing.' );
	}

	/**
	 * A site holding no secret answers `missing_secret` with status 500, on
	 * either route, rather than accepting the call.
	 *
	 * The one branch where an unconfigured site is answered by the *permission
	 * callback* rather than by a refusal: `check_permission()` looks at the
	 * secret before it compares anything, so the call never reaches the pending
	 * changes and never becomes one of the refusals
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

		$this->assertNoPendingChanges( 'Neither call was accepted, so neither reported anything.' );
	}

	/**
	 * A site holding a secret but no revalidate domain is not a configured site,
	 * and the route must not report the call as accepted.
	 *
	 * `check_permission()` reads the secret and nothing else, so a half
	 * configured site gets past it — the refusal comes from
	 * `PendingChanges::report()` instead, and lands in the per-item result.
	 *
	 * The status is 503: nothing was accepted, and the reason is neither the
	 * caller's request nor anything breaking here — the site has no revalidate
	 * domain, so nothing sent to it can be revalidated until an operator
	 * supplies one. It answered 207 until #118, which is a *success* class and
	 * told a caller checking the status that a revalidation it never got was
	 * fine. See `docs/adr/0027-a-wholly-failed-request-answers-a-failure-status.md`.
	 */
	public function test_a_site_holding_a_secret_but_no_domain_is_refused() {
		update_option( Settings::SETTINGS_SECRET_NAME, self::FIXTURE_SECRET );

		$response = $this->call_route(
			'/revalidate',
			[
				'secret' => self::FIXTURE_SECRET,
				'path'   => $this->permalink_of( '/half-configured/' ),
			]
		);

		$this->assertSame( 503, $response->get_status(), 'Nothing was accepted, and an unconfigured site is why — so the status is not a 2xx of any kind.' );
		$this->assertTrue( $response->is_error(), 'A caller that checks only whether the response is an error learns that nothing was accepted.' );

		$data = $response->get_data();

		$this->assertFalse( $data['success'], 'Reporting success here would make a refusal indistinguishable from an acceptance.' );
		$this->assertFalse( $data['results'][0]['success'] );
		$this->assertSame(
			\NextJsRevalidate::init()->settings->not_configured_error()->get_error_message(),
			$data['results'][0]['message'],
			'The per-item message is the refusal\'s own, not a message this route invented.'
		);

		$this->assertNoPendingChanges( 'A refused change never joins the pending changes.' );
	}

	/**
	 * A site that silences its unconfigured notice silences the notice and
	 * nothing else (#79). The filter is about who is *told*; the refusal, its
	 * log line and the status a caller reads are the site's truth, and stay it.
	 */
	public function test_a_site_that_silences_its_notice_is_still_refused_and_still_logs_it() {
		update_option( Settings::SETTINGS_SECRET_NAME, self::FIXTURE_SECRET );
		add_filter( 'nextjs_revalidate_show_unconfigured_notice', '__return_false' );

		$this->reset_log();
		$this->enable_logs();

		$refusal = $this->pending_changes()->report( Change::path( '/silenced/' ) );

		$this->assertWPError( $refusal );
		$this->assertSame( 'not_configured', $refusal->get_error_code(), 'The filter reached the refusal.' );
		$this->assertStringContainsString( '⛔ Refused a path change', $this->log(), 'The filter reached the refusal\'s log line.' );

		$response = $this->call_route(
			'/revalidate',
			[
				'secret' => self::FIXTURE_SECRET,
				'path'   => $this->permalink_of( '/silenced/' ),
			]
		);

		$this->assertSame( 503, $response->get_status(), 'The filter reached the status a REST caller is answered with.' );
		$this->assertNoPendingChanges( 'A silenced site refuses at the door like any other unconfigured one.' );

		$this->reset_log();
	}

	// The batch route
	// ====

	/**
	 * Every item of a batch is reported, in the order it was sent.
	 */
	public function test_the_batch_route_reports_every_item_in_the_order_it_was_sent() {
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

		$this->assertPendingChanges( [ $this->path_change( '/first/' ), $this->path_change( '/second/' ) ] );
	}

	/**
	 * A batch in which one item fails reports that item as failed and the other
	 * as accepted, and reports the one it accepted.
	 *
	 * This is the one shape 207 Multi-Status describes: one code cannot cover a
	 * body in which one item was accepted and another was not (RFC 4918 §13),
	 * and the per-item `success` fields are where the caller reads the rest.
	 * The item missing its path fails on its own, and the item sent after it is
	 * still reported — sent first, so a batch that stops at its first failure
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

		$this->assertPendingChanges( [ $this->path_change( '/accepted/' ) ], 'The accepted item was reported despite the failed one.' );
	}

	/**
	 * A batch in which *no* item was accepted answers a failure status, not the
	 * 207 a mixed one gets — issue #118.
	 *
	 * The distinction 207 could not make: every item here was refused, nothing
	 * is held, and a caller that checks the status is entitled to learn that
	 * from the status alone. These routes are how a deploy hook or a CI job asks
	 * for a revalidation and they have no other feedback channel — they cannot
	 * see the log or the delivery.
	 */
	public function test_a_batch_in_which_no_item_was_accepted_answers_a_failure_status() {
		// A secret and no revalidate domain: the call gets past
		// `check_permission()` and is refused item by item.
		update_option( Settings::SETTINGS_SECRET_NAME, self::FIXTURE_SECRET );

		$response = $this->call_route(
			'/revalidate/batch',
			[
				'secret' => self::FIXTURE_SECRET,
				'items'  => [
					[ 'path' => $this->permalink_of( '/refused/' ) ],
					[ 'path' => $this->permalink_of( '/refused-too/' ) ],
				],
			]
		);

		$this->assertSame( 503, $response->get_status(), 'Nothing was accepted, and every item was refused for the same reason — so that reason is the request\'s answer.' );
		$this->assertTrue( $response->is_error(), 'A 207 here was a success class: `res.ok`, and `is_error()`, both said the request was fine.' );

		$data = $response->get_data();

		$this->assertFalse( $data['success'] );
		$this->assertCount( 2, $data['results'], 'Every item still has a result of its own.' );
		$this->assertFalse( $data['results'][0]['success'] );
		$this->assertFalse( $data['results'][1]['success'] );

		$this->assertNoPendingChanges( 'Nothing was accepted, which is what the status now says.' );
	}

	/**
	 * A batch whose every item this route could not read answers 400: the items
	 * are the caller's to fix, and nothing on this site failed.
	 */
	public function test_a_batch_whose_every_item_is_missing_its_path_answers_400() {
		$this->configure_site();

		$response = $this->call_route(
			'/revalidate/batch',
			[
				'secret' => self::FIXTURE_SECRET,
				'items'  => [
					[ 'priority' => 1 ],
					[ 'priority' => 2 ],
				],
			]
		);

		$this->assertSame( 400, $response->get_status(), 'Nothing was accepted, and the items are why.' );

		$data = $response->get_data();

		$this->assertFalse( $data['success'] );
		$this->assertCount( 2, $data['results'], 'An item with no path is reported rather than dropped.' );

		$this->assertNoPendingChanges();
	}

	/**
	 * An entry of `items` that is not an object is reported as an item with no
	 * path, never skipped.
	 *
	 * Skipping it left the body a result short, and a batch that lost an item
	 * beside an accepted one answered 200 — telling a caller checking the status
	 * that everything it sent had been accepted.
	 */
	public function test_an_entry_that_is_not_an_object_is_reported_rather_than_dropped() {
		$this->configure_site();

		$permalink = $this->permalink_of( '/accepted/' );

		$response = $this->call_route(
			'/revalidate/batch',
			[
				'secret' => self::FIXTURE_SECRET,
				'items'  => [
					'/not-an-object/',
					[ 'path' => $permalink ],
				],
			]
		);

		$this->assertSame( 207, $response->get_status(), 'One entry could not be read and one was accepted, so the batch is a mixed result — not a 200.' );

		$data = $response->get_data();

		$this->assertCount( 2, $data['results'], 'Every entry sent has a result, the unreadable one included.' );
		$this->assertNull( $data['results'][0]['path'] );
		$this->assertFalse( $data['results'][0]['success'] );
		$this->assertTrue( $data['results'][1]['success'] );

		$this->assertPendingChanges( [ $this->path_change( '/accepted/' ) ] );
	}

	/**
	 * A wholly-failed batch whose items failed for different reasons answers the
	 * refusal, not the unreadable item beside it.
	 *
	 * A caller told 400 goes looking at what it sent, and what it sent is not the
	 * whole story here: this site revalidates nothing at all until it is
	 * configured, and the item that was well-formed would have failed too. So the
	 * refusal outranks it — the rule
	 * `docs/adr/0027-a-wholly-failed-request-answers-a-failure-status.md` records.
	 */
	public function test_a_wholly_failed_batch_answers_the_refusal_over_the_unreadable_item() {
		update_option( Settings::SETTINGS_SECRET_NAME, self::FIXTURE_SECRET );

		$response = $this->call_route(
			'/revalidate/batch',
			[
				'secret' => self::FIXTURE_SECRET,
				'items'  => [
					[ 'priority' => 1 ],
					[ 'path' => $this->permalink_of( '/refused/' ) ],
				],
			]
		);

		$this->assertSame( 503, $response->get_status(), 'The refusal describes the site, so it is the truth about the whole request.' );

		$this->assertNoPendingChanges();
	}

	// What the site's filter decided
	// ====

	/**
	 * A change the site's own `nextjs_revalidate_change` filter drops was not
	 * accepted, and the route says so rather than answering 200 for a
	 * revalidation that will never be sent.
	 *
	 * 500 rather than 400: nothing about the request was wrong, and the answer
	 * sends a caller to the site — where the filter is — rather than to its own
	 * payload. The status that used to mean a queue write that did not happen
	 * here now means a change this site did not take (ADR 0027, amended).
	 */
	public function test_a_change_the_filter_drops_is_reported_as_not_accepted() {
		$this->configure_site();

		add_filter( 'nextjs_revalidate_change', '__return_false' );

		$permalink = $this->permalink_of( '/dropped/' );

		$response = $this->call_route(
			'/revalidate',
			[
				'secret' => self::FIXTURE_SECRET,
				'path'   => $permalink,
			]
		);

		$this->assertSame( 500, $response->get_status() );
		$this->assertTrue( $response->is_error(), 'A caller that checks only whether the response is an error learns that nothing was accepted.' );

		$data = $response->get_data();

		$this->assertFalse( $data['success'] );
		$this->assertSame( $permalink, $data['results'][0]['path'] );
		$this->assertFalse( $data['results'][0]['success'] );
		$this->assertStringContainsString( 'nextjs_revalidate_change', $data['results'][0]['message'], 'The message names the filter, which is where an operator goes next.' );

		$this->assertNoPendingChanges();
	}

	/**
	 * The batch route reads the same answers through the same
	 * `process_items()`, and reports the item the filter dropped without
	 * failing the item next to it.
	 */
	public function test_the_batch_route_reports_a_dropped_change_on_the_item_it_happened_to() {
		$this->configure_site();

		add_filter(
			'nextjs_revalidate_change',
			function ( $change ) {
				return ( is_array( $change ) && isset( $change['uri'] ) && false !== strpos( $change['uri'], '/dropped/' ) ) ? false : $change;
			}
		);

		$dropped  = $this->permalink_of( '/dropped/' );
		$accepted = $this->permalink_of( '/accepted/' );

		$response = $this->call_route(
			'/revalidate/batch',
			[
				'secret' => self::FIXTURE_SECRET,
				'items'  => [
					[ 'path' => $dropped ],
					[ 'path' => $accepted ],
				],
			]
		);

		$this->assertSame( 207, $response->get_status(), 'One item was dropped, so the batch is a mixed result.' );

		$data = $response->get_data();

		$this->assertFalse( $data['success'] );

		$this->assertSame( $dropped, $data['results'][0]['path'] );
		$this->assertFalse( $data['results'][0]['success'] );
		$this->assertArrayHasKey( 'message', $data['results'][0] );

		$this->assertSame( $accepted, $data['results'][1]['path'] );
		$this->assertTrue( $data['results'][1]['success'], 'The dropped item before it does not fail this item, nor stop the batch.' );

		$this->assertPendingChanges( [ $this->path_change( '/accepted/' ) ], 'Only the item the filter let through is held.' );
	}

	// Calling a route
	// ====

	/**
	 * The path change a path of this site is reported as: its path from the
	 * domain root, which on a test site served from a directory includes it.
	 *
	 * @param string $path A path of this site, as `/hello-world/`.
	 * @return array
	 */
	private function path_change( $path ) {
		return Change::path( (string) Change::uri_of( $this->permalink_of( $path ) ) );
	}

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
