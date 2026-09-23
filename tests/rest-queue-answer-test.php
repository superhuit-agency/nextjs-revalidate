<?php
/**
 * How the REST routes read the queue's answer — RestApi::process_items().
 *
 * The bug this guards (#93): `RevalidateQueue::add_item()` answers four
 * different things, and the routes read everything that was not a `WP_Error` as
 * an acceptance. So the one answer that means *the insert failed* — a bare
 * `false` — was reported to the caller as `success: true` with a 200, the body
 * carrying `"data": false` as the only trace, while nothing was queued and
 * nothing would ever be revalidated. A deploy hook, a CI job or an external CMS
 * has no other feedback channel: the response is the whole contract, and it
 * could not tell "queued" from "the insert failed".
 *
 * The mapping under test, per item:
 *
 *  - `WP_Error` — refused, site unconfigured — `success: false`, its message
 *  - `1`        — inserted                   — `success: true`
 *  - `true`     — already queued             — `success: true`
 *  - `false`    — the insert failed          — `success: false`
 *
 * Reading an answer needs no queue, no options and no database, so this is a
 * standalone script rather than a PHPUnit test — see
 * `docs/adr/0008-two-testing-idioms.md`. That an insert can really come back
 * falsy, and that nothing is in the queue afterwards, is the integration
 * suite's business: `tests/integration/RestApiTest.php`.
 *
 * Run with `npm run test:php`, or `php tests/rest-queue-answer-test.php`.
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
 * A queue that answers whatever the case under test scripted, in call order,
 * and remembers what it was asked.
 *
 * Standing in for `RevalidateQueue` rather than being one: every answer this
 * suite is about is a *return value*, and the real queue can only produce the
 * falsy one by having its insert fail.
 */
class NextJsRevalidate_Test_Queue {

	/**
	 * One answer per `add_item()` call, in order.
	 * @var array
	 */
	public $answers = [];

	/**
	 * What each call was asked for — `[ permalink, priority ]`, in order.
	 * @var array
	 */
	public $asked = [];

	public function add_item( $permalink, $priority = 10 ) {
		$this->asked[] = [ $permalink, $priority ];

		if ( empty( $this->answers ) ) {
			die( "njr_test: the queue was asked more often than the case scripted an answer for.\n" );
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
	public $queue;
	public $settings;

	private static $instance;

	public static function init() {
		if ( ! isset( self::$instance ) ) self::$instance = new static();
		return self::$instance;
	}

	private function __construct() {
		$this->queue    = new NextJsRevalidate_Test_Queue();
		$this->settings = new NextJsRevalidate_Test_Settings();
	}
}

// The subject
// ====

require_once __DIR__ . '/../include/Interfaces/Hookable.php';
require_once __DIR__ . '/../include/Abstracts/Base.php';
require_once __DIR__ . '/../include/Traits/SendbackUrl.php';
require_once __DIR__ . '/../include/RevalidateQueue.php';
require_once __DIR__ . '/../include/RestApi.php';

use NextJsRevalidate\RestApi;
use NextJsRevalidate\RevalidateQueue;

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
 * The queue the routes reach, scripted with the answers of one case.
 *
 * @param array $answers One answer per expected `add_item()` call, in order.
 * @return NextJsRevalidate_Test_Queue
 */
function njr_test_queue( array $answers ) {
	$queue = NextJsRevalidate::init()->queue;

	$queue->answers = $answers;
	$queue->asked   = [];

	return $queue;
}

/**
 * Call the single route with the queue scripted to answer this.
 *
 * @param mixed $answer What `add_item()` answers.
 * @param array $params Optional. Merged over the request's parameters.
 *
 * @return WP_REST_Response
 */
function njr_test_single( $answer, array $params = [] ) {
	njr_test_queue( [ $answer ] );

	$request = new WP_REST_Request(
		array_merge(
			[
				'secret'   => 'fixture-secret',
				'path'     => 'https://site.test/hello-world/',
				'priority' => RevalidateQueue::DEFAULT_PRIORITY,
			],
			$params
		)
	);

	return ( new RestApi() )->handle_revalidate( $request );
}

/**
 * Call the batch route with the queue scripted to answer these, one per item.
 *
 * @param array $answers One answer per item, in order.
 * @param array $items   Optional. The items sent; defaults to one path per answer.
 *
 * @return WP_REST_Response
 */
function njr_test_batch( array $answers, array $items = null ) {
	njr_test_queue( $answers );

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

// The answers that mean the queue holds the permalink
// ====

// `1` is what `$wpdb->insert()` answers for a row it wrote, and `true` is what
// the queue answers for a permalink already waiting. #50 settled that both are
// honest acceptances, and this fix must not turn the second one into a failure.
foreach ( [ 'an inserted row' => 1, 'a permalink already queued' => true ] as $description => $answer ) {

	$response = njr_test_single( $answer );

	njr_test_assert( 200 === $response->get_status(), "the single route answers 200 for $description" );
	njr_test_assert( true === $response->get_data()['success'], "the single route reports success for $description" );
	njr_test_assert( true === njr_test_results( $response )[0]['success'], "the single route reports that item as a success for $description" );

	$response = njr_test_batch( [ $answer ] );

	njr_test_assert( 200 === $response->get_status(), "the batch route answers 200 for $description" );
	njr_test_assert( true === njr_test_results( $response )[0]['success'], "the batch route reports that item as a success for $description" );
}

// The insert that failed
// ====

// The whole of #93: a falsy answer is a failure, on both routes, because
// nothing was queued. `0` as well as `false` — the reading is of falsiness
// rather than of one exact value, so a rows-affected `0` cannot slip past it.
foreach ( [ 'a failed insert' => false, 'a write that affected nothing' => 0 ] as $description => $answer ) {

	$response = njr_test_single( $answer );

	njr_test_assert( 207 === $response->get_status(), "the single route answers 207 for $description" );
	njr_test_assert( false === $response->get_data()['success'], "the single route reports no success for $description" );
	njr_test_assert( false === njr_test_results( $response )[0]['success'], "the single route reports that item as a failure for $description" );
	njr_test_assert( ! empty( njr_test_results( $response )[0]['message'] ), "the single route carries a message for $description" );

	$response = njr_test_batch( [ $answer ] );

	njr_test_assert( 207 === $response->get_status(), "the batch route answers 207 for $description" );
	njr_test_assert( false === njr_test_results( $response )[0]['success'], "the batch route reports that item as a failure for $description" );
	njr_test_assert( ! empty( njr_test_results( $response )[0]['message'] ), "the batch route carries a message for $description" );
}

// The message is the route's own fixed sentence and not the database's: a
// failed insert leaves its reason in `$wpdb->last_error`, which is the honest
// answer and the wrong thing to hand back over a route.
$message = njr_test_results( njr_test_single( false ) )[0]['message'] ?? '';

njr_test_assert( is_string( $message ) && '' !== $message, 'a failed insert is reported with a message of its own' );
njr_test_assert( false === strpos( $message, 'SQL' ) && false === strpos( $message, 'INSERT' ), 'that message says nothing about the statement that failed' );

// The response of a failed item says so in one field, and does not also carry
// the raw answer that used to be the only trace of it.
njr_test_assert(
	! array_key_exists( 'data', njr_test_results( njr_test_single( false ) )[0] ),
	'a failed item carries no `data`, so `"data": false` is no longer what a caller has to notice'
);

// The refusal
// ====

// Unchanged by #93, and pinned here because the fix reads the same return
// value: an unconfigured site is refused with the queue's own message
// (ADR 0015), not with the sentence a failed insert gets.
$refusal  = new WP_Error( 'not_configured', 'Next.js revalidate is not configured for this site.' );
$response = njr_test_single( $refusal );

njr_test_assert( 207 === $response->get_status(), 'the single route answers 207 for a refusal' );
njr_test_assert( false === njr_test_results( $response )[0]['success'], 'a refused item is reported as a failure' );
njr_test_assert( $refusal->get_error_message() === njr_test_results( $response )[0]['message'], 'a refused item carries the queue\'s own message' );

// A batch, item by item
// ====

// The two routes share `process_items()`, so a mixed batch is where a failed
// insert could still be hidden — by failing the whole batch, or by stopping at
// the first failure.
$response = njr_test_batch( [ false, 1, $refusal ] );
$results  = njr_test_results( $response );

njr_test_assert( 207 === $response->get_status(), 'a batch holding a failed insert is a mixed result' );
njr_test_assert( false === $response->get_data()['success'], 'a batch holding a failed insert is not reported as a success' );
njr_test_assert( 3 === count( $results ), 'every item sent has a result of its own' );
njr_test_assert( false === $results[0]['success'], 'the item whose insert failed is the failed one' );
njr_test_assert( true === $results[1]['success'], 'the item after it is accepted, and the batch did not stop at the failure' );
njr_test_assert( false === $results[2]['success'], 'the refused item is reported as a failure too' );

njr_test_assert(
	[
		[ 'https://site.test/item-0/', RevalidateQueue::DEFAULT_PRIORITY ],
		[ 'https://site.test/item-1/', RevalidateQueue::DEFAULT_PRIORITY ],
		[ 'https://site.test/item-2/', RevalidateQueue::DEFAULT_PRIORITY ],
	] === NextJsRevalidate::init()->queue->asked,
	'each item of the batch was asked of the queue, in the order it was sent'
);

// What a failed item names
// ====

// The path is echoed back whatever the answer was: a caller sending a batch
// matches results to items by it, and #93's whole cost is a caller that cannot
// tell which of its revalidations did not happen.
$results = njr_test_results( njr_test_batch( [ false, 1 ] ) );

njr_test_assert( 'https://site.test/item-0/' === $results[0]['path'], 'a failed item names the path it was sent for' );
njr_test_assert( 'https://site.test/item-1/' === $results[1]['path'], 'an accepted item names its path too' );

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
