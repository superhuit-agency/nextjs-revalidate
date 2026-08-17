<?php
/**
 * Degraded revalidation — NextJsRevalidate\FailureWindow.
 *
 * The window is where the operator-facing notice gets its answer from, and the
 * whole of it — bounding, counting, naming a cause — is reachable by stubbing
 * four option functions and `is_wp_error()`. So this is a standalone script in
 * the idiom of `docs/adr/0008-two-testing-idioms.md`: no WordPress, no
 * autoload, no database. It exits non-zero on the first failing expectation.
 *
 * The notice itself is not covered here: it reads capabilities and prints
 * escaped translated markup, none of which is reachable without a WordPress
 * runtime.
 *
 * Run with `npm run test:php`, or `php tests/FailureWindowTest.php`.
 */

if ( 'cli' !== PHP_SAPI ) die( 'This file must be run from the command line.' );

// The plugin files bail when this is not defined.
define( 'ABSPATH', __DIR__ . '/' );

/**
 * Stubs
 * =====
 */

/**
 * The fixture site's option rows. option name => stored value.
 *
 * @var array
 */
$GLOBALS['njr_test_options'] = [];

function add_action( $name, $callback, $priority = 10, $accepted_args = 1 ) {}

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['njr_test_options'] )
		? $GLOBALS['njr_test_options'][ $name ]
		: $default;
}

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['njr_test_options'][ $name ] = $value;
	return true;
}

function delete_option( $name ) {
	unset( $GLOBALS['njr_test_options'][ $name ] );
	return true;
}

class WP_Error {

	private $code;
	private $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

/**
 * The subject
 * ===========
 */

require_once __DIR__ . '/../include/abstracts/Base.php';
require_once __DIR__ . '/../include/FailureWindow.php';

use NextJsRevalidate\FailureWindow;

/**
 * Test harness
 * ============
 */

$failures = 0;

/**
 * Start every case from a site that has never attempted anything.
 */
function reset_site() {
	$GLOBALS['njr_test_options'] = [];
}

/**
 * Record a run of outcomes: `true` for a success, and an error code — or `''`
 * for a failure naming no cause — for a failure.
 */
function record_all( array $outcomes ) {
	foreach ( $outcomes as $outcome ) {
		FailureWindow::record( true === $outcome ? true : new WP_Error( (string) $outcome, 'test' ) );
	}
}

function assert_same( $description, $expected, $actual ) {
	global $failures;

	if ( $expected === $actual ) {
		printf( "ok   — %s\n", $description );
		return;
	}

	$failures++;
	printf( "FAIL — %s (expected %s, got %s)\n", $description, json_encode( $expected ), json_encode( $actual ) );
}

/**
 * The expectations
 * ================
 */

// A site that has attempted nothing is not degraded. The front-end's health is
// unknown, which is not the same as bad — this is what makes clearing the
// window on deactivation safe.
reset_site();
assert_same( 'a site with no attempts on record is not degraded', false, FailureWindow::is_degraded() );
assert_same( 'a site with no attempts on record has an empty window', [], FailureWindow::outcomes() );

// Below the threshold, and at it.
reset_site();
record_all( [ 'unreachable', 'unreachable' ] );
assert_same( 'two failures are not degraded', false, FailureWindow::is_degraded() );

reset_site();
record_all( [ 'unreachable', 'unreachable', 'unreachable' ] );
assert_same( 'three failures are degraded', true, FailureWindow::is_degraded() );

// The window degrades correctly at low volume: three out of four is
// unambiguous, and waiting for a full window would stay silent through it.
reset_site();
record_all( [ true, 'unreachable', 'unreachable', 'unreachable' ] );
assert_same( 'three failures out of four attempts are degraded', true, FailureWindow::is_degraded() );

// A flaky front-end never accumulates three *consecutive* failures. It is
// exactly the case a run-length counter is blind to.
reset_site();
record_all( [ 'http_500', true, 'http_500', true, 'http_500', true ] );
assert_same( 'failures alternating with successes are degraded', true, FailureWindow::is_degraded() );

// The window is bounded, and old failures roll out of it. This is what makes
// the notice disappear on its own once the front-end recovers, with nothing to
// dismiss and no expiry to schedule.
reset_site();
record_all( [ 'unreachable', 'unreachable', 'unreachable' ] );
record_all( array_fill( 0, FailureWindow::LENGTH, true ) );
assert_same( 'a full window of successes clears the condition', false, FailureWindow::is_degraded() );
assert_same( 'the window holds no more than its length', FailureWindow::LENGTH, count( FailureWindow::outcomes() ) );

reset_site();
record_all( array_fill( 0, 40, true ) );
assert_same( 'a long run of successes does not grow the window', FailureWindow::LENGTH, count( FailureWindow::outcomes() ) );

// Three failures that have since scrolled out are no longer evidence.
reset_site();
record_all( [ 'unreachable', 'unreachable', 'unreachable' ] );
record_all( array_fill( 0, FailureWindow::LENGTH - 1, true ) );
assert_same( 'the oldest failure rolls out first', 1, count( FailureWindow::failures() ) );

// A failure arriving without a code still counts: the condition is about
// failure, not about diagnosis.
reset_site();
record_all( [ '', '', '' ] );
assert_same( 'failures naming no cause still degrade the site', true, FailureWindow::is_degraded() );
assert_same( 'a failure naming no cause names no cause', '', FailureWindow::last_failure_code() );

// Anything that is not `true` is a failure, including the `false` older code
// answered with.
reset_site();
FailureWindow::record( false );
FailureWindow::record( false );
FailureWindow::record( false );
assert_same( 'an outcome that is not true is a failure', true, FailureWindow::is_degraded() );

// The notice names the most recent code, and says only that others occurred.
reset_site();
record_all( [ 'unreachable', 'http_401', 'http_500' ] );
assert_same( 'the most recent code is the last failure to name one', 'http_500', FailureWindow::last_failure_code() );
assert_same( 'the distinct causes are counted, not listed', 3, count( FailureWindow::failure_codes() ) );

reset_site();
record_all( [ 'http_401', 'http_401', 'http_401' ] );
assert_same( 'one cause repeated is one cause', [ 'http_401' ], FailureWindow::failure_codes() );

// A success after a failure does not erase the failure's code.
reset_site();
record_all( [ 'http_401', true, 'unreachable', true ] );
assert_same( 'a success carries no code of its own', 'unreachable', FailureWindow::last_failure_code() );
assert_same( 'successes are not failures', 2, count( FailureWindow::failures() ) );

// The most recent *code*, which is not always the most recent failure's: a
// failure naming nothing must not hide the cause of the one before it.
reset_site();
record_all( [ 'http_401', '' ] );
assert_same( 'a failure naming no cause does not hide the previous cause', 'http_401', FailureWindow::last_failure_code() );

// Deactivation forgets the window. On reactivation the front-end's health is
// unknown, not bad.
reset_site();
record_all( [ 'unreachable', 'unreachable', 'unreachable' ] );
FailureWindow::clear();
assert_same( 'clearing the window clears the condition', false, FailureWindow::is_degraded() );
assert_same( 'clearing the window leaves nothing behind', [], FailureWindow::outcomes() );

// A site can hold a row of any shape. Anything that is not an outcome is not
// evidence, and must not be counted as a failure.
reset_site();
$GLOBALS['njr_test_options'][ FailureWindow::OPTION_NAME ] = 'not an array at all';
assert_same( 'a row that is not an array reads as an empty window', [], FailureWindow::outcomes() );

reset_site();
$GLOBALS['njr_test_options'][ FailureWindow::OPTION_NAME ] = [ 'nonsense', 42, [], [ 'failed' => true, 'code' => 'http_500' ] ];
assert_same( 'entries that are not outcomes are dropped', 1, count( FailureWindow::outcomes() ) );
assert_same( 'the outcome among them is kept', 'http_500', FailureWindow::last_failure_code() );

reset_site();
$GLOBALS['njr_test_options'][ FailureWindow::OPTION_NAME ] = [ [ 'failed' => true, 'code' => [ 'nope' ] ] ];
assert_same( 'a code that is not a string reads as no cause', '', FailureWindow::last_failure_code() );
assert_same( 'a code that is not a string still leaves a failure', 1, count( FailureWindow::failures() ) );

// Recording onto a row of the wrong shape starts a fresh window rather than
// throwing, so a bad row cannot silence the notice for good.
reset_site();
$GLOBALS['njr_test_options'][ FailureWindow::OPTION_NAME ] = 'not an array at all';
record_all( [ 'unreachable', 'unreachable', 'unreachable' ] );
assert_same( 'recording over a bad row starts a fresh window', true, FailureWindow::is_degraded() );

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
