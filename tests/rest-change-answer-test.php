<?php
/**
 * What the REST routes report, and how they read the answer —
 * RestApi::process_items().
 *
 * Each item a route is sent becomes a **path** change in this request's
 * pending changes, and the route answers per item whether it was *accepted*
 * there. Pinned here:
 *
 *  - **what is reported**: one path change per item, its `uri` the path from
 *    the domain root, whether the caller sent a permalink or a path — and the
 *    `priority` v1 took is accepted and changes nothing;
 *  - **how the answer is read**, per item:
 *
 *      - `true`     — held for delivery        — `success: true`
 *      - `WP_Error` — refused, site unconfigured — `success: false`, its message
 *      - `false`    — the site's filter dropped it — `success: false`, a message
 *                     naming the filter
 *
 * The bug the reading guards (#93) was the queue's: a failed insert came back
 * as a bare `false` and was reported as `success: true`. The queue is gone
 * (ADR 0034), and a `false` from the pending changes means the site's own
 * `nextjs_revalidate_change` filter dropped the change — still not an
 * acceptance, and still never reported as one.
 *
 * The status those answers produce is `tests/rest-response-status-test.php`'s
 * subject — the statuses asserted below are only the ones this file's own cases
 * produce.
 *
 * Reading an answer needs no pending changes of the real kind, no options and
 * no database, so this is a standalone script rather than a PHPUnit test — see
 * `docs/adr/0008-two-testing-idioms.md`. That an accepted item is really in the
 * pending changes of a real site afterwards is the integration suite's
 * business: `tests/integration/RestApiTest.php`.
 *
 * Run with `npm run test:php`, or `php tests/rest-change-answer-test.php`.
 */

if ( 'cli' !== PHP_SAPI ) die( 'This file must be run from the command line.' );

// The plugin files bail when this is not defined.
define( 'ABSPATH', __DIR__ . '/' );

// WordPress stubs
// ====

function add_action( $name, $callback, $priority = 10, $accepted_args = 1 ) {}
function add_filter( $name, $callback, $priority = 10, $accepted_args = 1 ) {}
function __( $text, $domain = null ) { return $text; }

function sanitize_text_field( $str ) {
	return trim( strip_tags( (string) $str ) );
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function absint( $maybeint ) {
	return abs( intval( $maybeint ) );
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

class WP_Error {
	private $code;
	private $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}

/**
 * Enough of the request the handlers read: they ask it for named parameters and
 * nothing else.
 */
class WP_REST_Request {
	private $params;

	public function __construct( array $params = [] ) {
		$this->params = $params;
	}

	public function get_param( $key ) {
		return $this->params[ $key ] ?? null;
	}
}

/**
 * Enough of the response the handlers build: a body and a status, read back.
 */
class WP_REST_Response {
	private $data;
	private $status;

	public function __construct( $data = null, $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}

	public function get_data() { return $this->data; }
	public function get_status() { return $this->status; }
}

/**
 * Pending changes that answer whatever the case under test scripted, in call
 * order, and remember what they were handed.
 *
 * Standing in for `PendingChanges` rather than being one: every answer this
 * suite is about is a *return value*, and what becomes of a change once it is
 * held is `tests/pending-changes-test.php`'s.
 */
class NextJsRevalidate_Test_PendingChanges {

	/**
	 * One answer per `report()` call, in order.
	 * @var array
	 */
	public $answers = [];

	/**
	 * Every change handed over, in order.
	 * @var array[]
	 */
	public $reported = [];

	public function report( array $change ) {
		$this->reported[] = $change;

		if ( empty( $this->answers ) ) {
			die( "njr_test: the pending changes were handed more changes than the case scripted an answer for.\n" );
		}

		return array_shift( $this->answers );
	}
}

class NextJsRevalidate_Test_Settings {
	public function __get( $name ) {
		// The routes under test are reached past `check_permission()`, which is
		// the only thing that reads a setting here.
		return 'secret' === $name ? 'fixture-secret' : null;
	}
}

class NextJsRevalidate {
	public $pendingChanges;
	public $settings;

	private static $instance;

	public static function init() {
		if ( ! isset( self::$instance ) ) self::$instance = new static();
		return self::$instance;
	}

	private function __construct() {
		$this->pendingChanges = new NextJsRevalidate_Test_PendingChanges();
		$this->settings       = new NextJsRevalidate_Test_Settings();
	}
}

// The subject
// ====

require_once __DIR__ . '/../include/Interfaces/Hookable.php';
require_once __DIR__ . '/../include/Abstracts/Base.php';
require_once __DIR__ . '/../include/Change.php';
require_once __DIR__ . '/../include/RestApi.php';

use NextJsRevalidate\RestApi;

// The harness
// ====

$failures = 0;

function njr_test_assert( $condition, $description ) {
	global $failures;

	if ( $condition ) {
		printf( "ok   — %s\n", $description );
		return;
	}

	$failures++;
	printf( "FAIL — %s\n", $description );
}

/**
 * The pending changes the routes reach, scripted with the answers of one case.
 *
 * @param array $answers One answer per expected `report()` call, in order.
 * @return NextJsRevalidate_Test_PendingChanges
 */
function njr_test_pending( array $answers ) {
	$pending = NextJsRevalidate::init()->pendingChanges;

	$pending->answers  = $answers;
	$pending->reported = [];

	return $pending;
}

/**
 * Call the single route with the pending changes scripted to answer this.
 *
 * @param mixed $answer What `report()` answers.
 * @param array $params Optional. Merged over the request's parameters.
 *
 * @return WP_REST_Response
 */
function njr_test_single( $answer, array $params = [] ) {
	njr_test_pending( [ $answer ] );

	$request = new WP_REST_Request(
		array_merge(
			[
				'secret' => 'fixture-secret',
				'path'   => 'https://site.test/hello-world/',
			],
			$params
		)
	);

	return ( new RestApi() )->handle_revalidate( $request );
}

/**
 * Call the batch route with the pending changes scripted to answer these, one
 * per item.
 *
 * @param array $answers One answer per item, in order.
 * @param array $items   Optional. The items sent; defaults to one path per answer.
 *
 * @return WP_REST_Response
 */
function njr_test_batch( array $answers, array $items = null ) {
	njr_test_pending( $answers );

	if ( null === $items ) {
		$items = [];

		foreach ( $answers as $i => $answer ) {
			$items[] = [ 'path' => sprintf( 'https://site.test/item-%d/', $i ) ];
		}
	}

	$request = new WP_REST_Request(
		[
			'secret' => 'fixture-secret',
			'items'  => $items,
		]
	);

	return ( new RestApi() )->handle_revalidate_batch( $request );
}

/**
 * The per-item results of a response, in the order the items were sent.
 *
 * @param WP_REST_Response $response
 * @return array
 */
function njr_test_results( WP_REST_Response $response ) {
	return $response->get_data()['results'];
}

/**
 * The path change a `uri` is reported as.
 *
 * @param string $uri
 * @return array
 */
function njr_test_path( $uri ) {
	return [ 'subject' => 'path', 'uri' => $uri ];
}

$refusal = new WP_Error( 'not_configured', 'Next.js revalidate is not configured for this site.' );

// What is reported
// ====

// A permalink is the path it names, from the domain root: the scheme and the
// host go, and so do a query string and a fragment, which are not a path.
njr_test_single( true );
njr_test_assert(
	[ njr_test_path( '/hello-world/' ) ] === NextJsRevalidate::init()->pendingChanges->reported,
	'the single route reports a path change for the path the permalink names'
);

njr_test_single( true, [ 'path' => '/hello-world/' ] );
njr_test_assert(
	[ njr_test_path( '/hello-world/' ) ] === NextJsRevalidate::init()->pendingChanges->reported,
	'a path sent as a path is the same change as its permalink'
);

njr_test_single( true, [ 'path' => 'https://site.test/campaign/?utm_source=x#top' ] );
njr_test_assert(
	[ njr_test_path( '/campaign/' ) ] === NextJsRevalidate::init()->pendingChanges->reported,
	'a query string and a fragment are not part of the path'
);

// A trailing slash is the caller's, and kept as sent: the front-end keys on the
// exact path, and this route has no site convention to impose on a path it
// was named from outside.
njr_test_single( true, [ 'path' => 'no-leading-slash' ] );
njr_test_assert(
	[ njr_test_path( '/no-leading-slash' ) ] === NextJsRevalidate::init()->pendingChanges->reported,
	'a path sent without its leading slash is given one, and nothing else'
);

// `priority` is accepted, as v1 accepted it, and changes nothing: there is no
// queue left for it to order (ADR 0035).
$response = njr_test_single( true, [ 'priority' => 1 ] );
njr_test_assert( 200 === $response->get_status(), 'a priority is still accepted on the single route' );
njr_test_assert(
	[ njr_test_path( '/hello-world/' ) ] === NextJsRevalidate::init()->pendingChanges->reported,
	'and the change it reports is the one it reports without one'
);

$response = njr_test_batch(
	[ true, true ],
	[
		[ 'path' => 'https://site.test/first/', 'priority' => 20 ],
		[ 'path' => '/second/', 'priority' => 0 ],
	]
);
njr_test_assert( 200 === $response->get_status(), 'priorities are still accepted on the batch route' );
njr_test_assert(
	[ njr_test_path( '/first/' ), njr_test_path( '/second/' ) ] === NextJsRevalidate::init()->pendingChanges->reported,
	'the batch route reports one path change per item, in the order they were sent'
);

// The answer that means the pending changes hold it
// ====

$response = njr_test_single( true );
njr_test_assert( 200 === $response->get_status(), 'the single route answers 200 for an accepted item' );
njr_test_assert( true === $response->get_data()['success'], 'the single route reports success for an accepted item' );
njr_test_assert( true === njr_test_results( $response )[0]['success'], 'the item is reported as a success' );
njr_test_assert( true === njr_test_results( $response )[0]['data'], 'an accepted item still carries `data`, as it did in 1.x, now always true' );

$response = njr_test_batch( [ true ] );
njr_test_assert( 200 === $response->get_status(), 'the batch route answers 200 for an accepted item' );
njr_test_assert( true === njr_test_results( $response )[0]['success'], 'the batch route reports that item as a success' );

// The change the site's filter dropped
// ====

// Not an acceptance: nothing will be delivered for it. The message names the
// filter, which is the one place an operator can go to find out why.
foreach ( [ 'single' => njr_test_single( false ), 'batch' => njr_test_batch( [ false ] ) ] as $route => $response ) {
	$result = njr_test_results( $response )[0];

	njr_test_assert( 500 === $response->get_status(), "the $route route answers 500 for a dropped change" );
	njr_test_assert( false === $response->get_data()['success'], "the $route route reports no success for a dropped change" );
	njr_test_assert( false === $result['success'], "the $route route reports that item as a failure" );
	njr_test_assert( false !== strpos( (string) ( $result['message'] ?? '' ), 'nextjs_revalidate_change' ), "the $route route names the filter that dropped it" );
	njr_test_assert( ! array_key_exists( 'data', $result ), "a dropped item carries no `data`" );
}

// The refusal
// ====

// An unconfigured site is refused with the pending changes' own message
// (ADR 0015), not with the sentence a dropped change gets.
$response = njr_test_single( $refusal );

njr_test_assert( 503 === $response->get_status(), 'the single route answers 503 for a refusal' );
njr_test_assert( false === njr_test_results( $response )[0]['success'], 'a refused item is reported as a failure' );
njr_test_assert( $refusal->get_error_message() === njr_test_results( $response )[0]['message'], 'a refused item carries the refusal\'s own message' );

// A batch, item by item
// ====

// The two routes share `process_items()`, so a mixed batch is where a failure
// could still be hidden — by failing the whole batch, or by stopping at the
// first failure.
$response = njr_test_batch( [ false, true, $refusal ] );
$results  = njr_test_results( $response );

njr_test_assert( 207 === $response->get_status(), 'a batch holding a dropped change is a mixed result' );
njr_test_assert( false === $response->get_data()['success'], 'a batch holding a dropped change is not reported as a success' );
njr_test_assert( 3 === count( $results ), 'every item sent has a result of its own' );
njr_test_assert( false === $results[0]['success'], 'the dropped item is the failed one' );
njr_test_assert( true === $results[1]['success'], 'the item after it is accepted, and the batch did not stop at the failure' );
njr_test_assert( false === $results[2]['success'], 'the refused item is reported as a failure too' );

// What an item names
// ====

// The path is echoed back as it was sent, whatever the answer was: a caller
// sending a batch matches results to items by it.
njr_test_assert( 'https://site.test/item-0/' === $results[0]['path'], 'a failed item names the path it was sent for' );
njr_test_assert( 'https://site.test/item-1/' === $results[1]['path'], 'an accepted item names its path too, as sent rather than as reduced' );

// An item that names no path is reported like a missing one, and never handed
// over: there is no change to make of it.
$response = njr_test_batch( [], [ [ 'path' => '   ' ] ] );
njr_test_assert( 400 === $response->get_status(), 'an item whose path is blank is the caller\'s to fix' );
njr_test_assert( [] === NextJsRevalidate::init()->pendingChanges->reported, 'and nothing is reported for it' );

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
