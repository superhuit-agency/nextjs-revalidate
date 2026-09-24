<?php
/**
 * The status the REST routes answer with — RestApi::process_items(), issue #118.
 *
 * The bug this guards: every request holding a failed item answered **207
 * Multi-Status**, whether one item of ten failed or every item did. 207 is a
 * *success* class — `fetch`'s `res.ok` is true for it,
 * `WP_REST_Response::is_error()` is false, and most HTTP clients'
 * raise-on-error helper stays quiet — so a caller doing the ordinary thing,
 * issuing the request and checking the status, was told everything was fine
 * while nothing had been accepted. #93 made the *body* honest; a caller reading
 * only the status still had the lie. And on the single route 207 did not fit at
 * all: one item has one outcome, and there is nothing for a multi-status to
 * disambiguate.
 *
 * The rule under test, decided by the outcomes rather than by the route:
 *
 *  - every item accepted                    — 200
 *  - some accepted, some not                — 207, and only here
 *  - none accepted, the caller's fault      — 400
 *  - none accepted, this site's doing       — 500
 *  - none accepted, the site unconfigured   — 503
 *  - none accepted, kinds disagreeing       — 503 over 500 over 400
 *
 * See `docs/adr/0027-a-wholly-failed-request-answers-a-failure-status.md`. Until
 * v2 the 500 also covered a queue write that did not happen; there is no queue
 * write left to fail (ADR 0034), so that case is gone from here, and the 500 is
 * what is left of it: something threw, or the site's own
 * `nextjs_revalidate_change` filter dropped the change.
 *
 * What the routes hand to the pending changes, and what a failed item carries
 * in the body, is `tests/rest-change-answer-test.php`. This file asserts on
 * statuses and stays out of the body.
 *
 * A status is computed from return values, so this needs no pending changes of
 * the real kind, no options and no database: a standalone script rather than a
 * PHPUnit test, per `docs/adr/0008-two-testing-idioms.md`. That the statuses
 * come out of a real dispatch, with real settings behind them, is the
 * integration suite's business: `tests/integration/RestApiTest.php`.
 *
 * Run with `npm run test:php`, or `php tests/rest-response-status-test.php`.
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
 * order.
 *
 * An answer that is an `Exception` is thrown rather than returned: the handler
 * catches those too, and what a thrown item does to the request's status is one
 * of the cases here.
 */
class NextJsRevalidate_Test_PendingChanges {

	/**
	 * One answer per `report()` call, in order.
	 * @var array
	 */
	public $answers = [];

	/**
	 * How many times the pending changes were handed anything.
	 * @var int
	 */
	public $calls = 0;

	public function report( array $change ) {
		$this->calls++;

		if ( empty( $this->answers ) ) {
			die( "njr_test: the pending changes were handed more changes than the case scripted an answer for.\n" );
		}

		$answer = array_shift( $this->answers );

		if ( $answer instanceof Exception ) throw $answer;

		return $answer;
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

	$pending->answers = $answers;
	$pending->calls   = 0;

	return $pending;
}

/**
 * Call the single route with the pending changes scripted to answer this.
 *
 * @param mixed $answer What `report()` answers, or an `Exception` it throws.
 * @param array $params Optional. Merged over the request's parameters.
 *
 * @return WP_REST_Response
 */
function njr_test_single( $answer, array $params = [] ) {
	njr_test_pending( null === $answer ? [] : [ $answer ] );

	$request = new WP_REST_Request(
		array_merge(
			[
				'secret'   => 'fixture-secret',
				'path'     => 'https://site.test/hello-world/',
				'priority' => 10,
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

// The answers, by kind
// ====

// The site is unconfigured, so it declines to deliver rather than trying and
// missing — a **refusal**, ADR 0015. Nothing is wrong with the request and
// nothing broke.
$refusal = new WP_Error( 'not_configured', 'Next.js revalidate is not configured for this site.' );

// A `WP_Error` the pending changes do not produce today. It is this site
// failing rather than declining, and must not be read as a configuration an
// operator could go and fix.
$other_error = new WP_Error( 'unexpected', 'Something else went wrong.' );

// The site's own `nextjs_revalidate_change` filter dropped the change: nothing
// the caller could send differently, and nothing an operator could configure.
$dropped = false;

// Something in the path of the change threw — a filter callback, most likely.
$thrown = new Exception( 'a filter threw' );

// Everything was accepted
// ====

njr_test_assert( 200 === njr_test_single( true )->get_status(), 'the single route answers 200 for an accepted item' );
njr_test_assert( 200 === njr_test_batch( [ true, true ] )->get_status(), 'the batch route answers 200 when every item was accepted' );

// Nothing was accepted
// ====

// The whole of #118: a request in which nothing was accepted answers with a
// failure status, and a caller reading only the status now learns that.
$wholly_failed = [
	'a change the filter dropped' => [ $dropped, 500 ],
	'a thrown exception'          => [ $thrown, 500 ],
	'a refusal'                   => [ $refusal, 503 ],
	'a WP_Error of another kind'  => [ $other_error, 500 ],
];

foreach ( $wholly_failed as $description => $case ) {
	list( $answer, $expected ) = $case;

	$response = njr_test_single( $answer );

	njr_test_assert( $expected === $response->get_status(), "the single route answers $expected for $description" );
	njr_test_assert( false === $response->get_data()['success'], "the single route reports no success for $description" );

	$response = njr_test_batch( [ $answer, $answer ] );

	njr_test_assert( $expected === $response->get_status(), "a batch whose every item is $description answers $expected" );
}

// An item this route cannot read is the caller's to fix, and 400-shaped. The
// single route answers it before the pending changes are handed anything; the
// batch route reaches `process_items()` with the item, which is where the
// status is decided.
$response = njr_test_single( null, [ 'path' => '' ] );

njr_test_assert( 400 === $response->get_status(), 'the single route answers 400 for a missing path' );
njr_test_assert( 0 === NextJsRevalidate::init()->pendingChanges->calls, 'a missing path reports nothing' );

$response = njr_test_batch( [], [ [ 'priority' => 1 ], [ 'priority' => 2 ] ] );

njr_test_assert( 400 === $response->get_status(), 'the batch route answers 400 when every item is missing its path' );
njr_test_assert( false === $response->get_data()['success'], 'a batch in which nothing was accepted is not reported as a success' );
njr_test_assert( 2 === count( $response->get_data()['results'] ), 'every item sent still has a result of its own' );
njr_test_assert( 0 === NextJsRevalidate::init()->pendingChanges->calls, 'none of those items was reported' );

// 207 is for a body one code cannot describe
// ====

// A mixed batch is the one case the status earns its keep (RFC 4918 §13): some
// items were accepted, some were not, and the per-item `success` fields are the
// only place the rest of the answer can be read.
$response = njr_test_batch( [ true, $thrown ] );

njr_test_assert( 207 === $response->get_status(), 'a batch holding an accepted item and a failed one answers 207' );
njr_test_assert( false === $response->get_data()['success'], 'a mixed batch is not reported as a success' );

njr_test_assert( 207 === njr_test_batch( [ $refusal, true ] )->get_status(), 'the accepted item can be the second one' );
njr_test_assert( 207 === njr_test_batch( [ $dropped, true, $refusal ] )->get_status(), 'two kinds of failure beside an acceptance are still a mixed result' );

// The single route sends one item, so there is never a body for 207 to
// describe. Every answer it can give must therefore be something else.
foreach ( [ true, $dropped, $refusal, $other_error, $thrown ] as $i => $answer ) {
	njr_test_assert(
		207 !== njr_test_single( $answer )->get_status(),
		sprintf( 'the single route never answers 207 (answer %d)', $i )
	);
}

njr_test_assert( 207 !== njr_test_single( null, [ 'path' => '' ] )->get_status(), 'nor for a missing path' );

// Failures of different kinds in one request
// ====

// Nothing was accepted and the failures disagree about why. A refusal outranks
// the rest because it describes the site rather than the item — an unconfigured
// site refuses everything it is sent — and both 5xx kinds outrank 400, because
// a caller told its request was bad goes looking at a request that was fine.
njr_test_assert(
	503 === njr_test_batch( [ $refusal ], [ [ 'priority' => 1 ], [ 'path' => 'https://site.test/refused/' ] ] )->get_status(),
	'a refusal outranks the missing path beside it'
);

njr_test_assert(
	500 === njr_test_batch( [ $thrown ], [ [ 'priority' => 1 ], [ 'path' => 'https://site.test/threw/' ] ] )->get_status(),
	'a thrown exception outranks the missing path beside it'
);

njr_test_assert(
	500 === njr_test_batch( [ $dropped ], [ [ 'priority' => 1 ], [ 'path' => 'https://site.test/dropped/' ] ] )->get_status(),
	'so does a change the filter dropped'
);

njr_test_assert(
	503 === njr_test_batch( [ $thrown, $refusal ] )->get_status(),
	'a refusal outranks a thrown exception'
);

njr_test_assert(
	503 === njr_test_batch( [ $refusal, $dropped ] )->get_status(),
	'and a dropped change, whichever order the two arrived in'
);

// The request the route could not read at all
// ====

// This never reaches `process_items()`: there are no items to report on, so it
// is the handler's own answer and it was 400 before #118 too. Pinned because
// the rule above must not have moved it.
$request = new WP_REST_Request( [ 'secret' => 'fixture-secret' ] );

njr_test_assert(
	400 === ( new RestApi() )->handle_revalidate_batch( $request )->get_status(),
	'a batch with no `items` array answers 400'
);

// An entry that is not an object is an item with no path, and is reported like
// one. It used to be skipped, which left the body a result short and let a
// batch that lost an item answer 200 as if everything sent had been accepted.
$response = njr_test_batch( [], [ 'not-an-item' ] );

njr_test_assert( 400 === $response->get_status(), 'a batch holding nothing that could be an item answers 400' );
njr_test_assert( 1 === count( $response->get_data()['results'] ), 'an entry that is not an object still has a result of its own' );
njr_test_assert( false === $response->get_data()['results'][0]['success'], 'an entry that is not an object is reported as a failure' );

$response = njr_test_batch( [ true ], [ '/not-an-object/', [ 'path' => 'https://site.test/accepted/' ] ] );

njr_test_assert( 207 === $response->get_status(), 'an entry that is not an object beside an accepted item is a mixed result, not a 200' );
njr_test_assert( 2 === count( $response->get_data()['results'] ), 'one result per entry sent, the unreadable one included' );
njr_test_assert( false === $response->get_data()['results'][0]['success'], 'the results keep the order the entries were sent in' );

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
