<?php
/**
 * What the drain writes about one item — RevalidateQueue::log_purge_outcome().
 *
 * The bug this guards (#49): the drain logged `✅ Revalidated` whatever the
 * front-end answered, and since the item is deleted from the queue before the
 * attempt, nothing survived to say otherwise. Someone reading that log to find
 * out why a page is stale is told every revalidation succeeded — worse than no
 * log, because it rules out the true cause.
 *
 * Reachable by stubbing a handful of WordPress functions, so it is a standalone
 * script rather than a PHPUnit test — see `docs/adr/0008-two-testing-idioms.md`.
 * Only the line written is under test here; that the drain reaches this at all
 * is the integration suite's business.
 *
 * Run with `npm run test:php`, or `php tests/drain-outcome-log-test.php`.
 */

if ( 'cli' !== PHP_SAPI ) die( 'This file must be run from the command line.' );

// The plugin files bail when this is not defined.
define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['njr_test_uploads_dir'] = '';

// WordPress stubs
// ====

function add_action( $name, $callback, $priority = 10, $accepted_args = 1 ) {}
function add_filter( $name, $callback, $priority = 10, $accepted_args = 1 ) {}
function __( $text, $domain = null ) { return $text; }

function wp_upload_dir() {
	return [ 'basedir' => $GLOBALS['njr_test_uploads_dir'] ];
}

function trailingslashit( $string ) {
	return rtrim( $string, '/\\' ) . '/';
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

class NextJsRevalidate_Test_Settings {
	public function __get( $name ) {
		// Logs on: this suite is about what gets written, and nothing is
		// written at all while the setting is off.
		return 'debug' === $name ? [ 'enable-logs' => 'on' ] : null;
	}
}

class NextJsRevalidate {
	public $settings;

	private static $instance;

	public static function init() {
		if ( ! isset( self::$instance ) ) self::$instance = new static();
		return self::$instance;
	}

	private function __construct() {
		$this->settings = new NextJsRevalidate_Test_Settings();
	}
}

// The subject
// ====

require_once __DIR__ . '/../include/Logger.php';
require_once __DIR__ . '/../include/abstracts/Base.php';
require_once __DIR__ . '/../include/traits/SendbackUrl.php';
require_once __DIR__ . '/../include/RevalidateItem.php';
require_once __DIR__ . '/../include/RevalidateQueue.php';

use NextJsRevalidate\RevalidateItem;
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
 * The log line the drain writes for one item and one outcome.
 *
 * @param true|WP_Error $outcome What `Revalidate::purge()` answered.
 * @return string
 */
function njr_test_log_line( $outcome ) {
	$dir = sys_get_temp_dir() . '/njr-drain-log-test-' . uniqid();
	mkdir( $dir );
	$GLOBALS['njr_test_uploads_dir'] = $dir;

	$item = new RevalidateItem( (object) [
		'id'        => 1,
		'permalink' => 'https://example.test/hello-world/',
		'priority'  => 10,
	] );

	$log = new ReflectionMethod( RevalidateQueue::class, 'log_purge_outcome' );
	$log->setAccessible( true );
	$log->invoke( new RevalidateQueue(), 'abc123', $item, $outcome, 0.5 );

	$logFile  = $dir . '/' . NextJsRevalidate\Logger::FILENAME;
	$contents = file_exists( $logFile ) ? (string) file_get_contents( $logFile ) : '';

	if ( file_exists( $logFile ) ) unlink( $logFile );
	rmdir( $dir );

	return $contents;
}

// The cases
// ====

$line = njr_test_log_line( true );
njr_test_assert( false !== strpos( $line, '✅ Revalidated' ), 'a delivered revalidation is logged as the success it is' );
njr_test_assert( false !== strpos( $line, 'https://example.test/hello-world/' ), 'the success names the permalink' );
njr_test_assert( false !== strpos( $line, '[INFO]' ), 'the success is not an error' );

// The bug itself: every one of these used to come back as ✅.
$line = njr_test_log_line( new WP_Error( 'http_401', 'The front-end answered 401.' ) );
njr_test_assert( false === strpos( $line, '✅' ), 'a failure is never logged as a success' );
njr_test_assert( false !== strpos( $line, '❌' ), 'a failure is logged as a failure' );
njr_test_assert( false !== strpos( $line, 'http_401' ), 'the failure names the error code, not just that it failed' );
njr_test_assert( false !== strpos( $line, 'The front-end answered 401.' ), 'the failure carries the message with it' );
njr_test_assert( false !== strpos( $line, '[ERROR]' ), 'a failure is logged at ERROR' );
njr_test_assert( false !== strpos( $line, 'https://example.test/hello-world/' ), 'the failure names the permalink that stayed stale' );

$line = njr_test_log_line( new WP_Error( 'unreachable', 'cURL error 28: Operation timed out' ) );
njr_test_assert( false !== strpos( $line, 'unreachable' ), 'a transport failure names its own code' );

// A refusal is not a failure: nothing was attempted against the front-end.
$line = njr_test_log_line( new WP_Error( 'not_configured', 'Next.js revalidate is not configured for this site.' ) );
njr_test_assert( false !== strpos( $line, '⛔' ), 'an unconfigured site is a refusal, not a failure' );
njr_test_assert( false === strpos( $line, '❌' ), 'a refusal is not dressed up as a failure' );
njr_test_assert( false !== strpos( $line, '[ERROR]' ), 'a refusal is worth an operator’s attention too' );

// One item, one line, whichever way it went.
foreach ( [ 'a success' => true, 'a failure' => new WP_Error( 'http_500', 'boom' ) ] as $description => $outcome ) {
	$line = njr_test_log_line( $outcome );
	njr_test_assert(
		1 === count( array_filter( explode( "\n", $line ) ) ),
		"$description writes exactly one line"
	);
}

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
