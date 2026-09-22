<?php
/**
 * What a probe asks, what it answers, and what it writes — Probe::send().
 *
 * A **probe** is a revalidation the operator asks for directly, in order to
 * observe its outcome. Three claims are pinned here, and each of them is a way
 * the button could lie to the person pressing it:
 *
 *  - **It asks about the path the operator named**, composed the way the queue
 *    composes a permalink — a probe whose answer can disagree with what a real
 *    revalidation would have done sends operators chasing phantoms.
 *  - **It hands back the error the attempt produced**, code and message both.
 *    Collapsing either into "it failed" is what this whole surface exists to
 *    stop.
 *  - **It never records the outcome in the failure window.** The window samples
 *    the queue's own traffic, and a diagnostic that can silence its own alarm
 *    is worse than no diagnostic —
 *    `docs/adr/0013-a-probe-is-not-evidence.md`.
 *
 * Reachable by stubbing a handful of WordPress functions, so it is a standalone
 * script rather than a PHPUnit test — see `docs/adr/0008-two-testing-idioms.md`.
 * The form, the sendback and the notice need an admin request and are the
 * runbook's business.
 *
 * Run with `npm run test:php`, or `php tests/probe-outcome-test.php`.
 */

if ( 'cli' !== PHP_SAPI ) die( 'This file must be run from the command line.' );

// The plugin files bail when this is not defined.
define( 'ABSPATH', __DIR__ . '/' );

/**
 * What the next `Revalidate::purge()` answers.
 * @var true|WP_Error
 */
$GLOBALS['njr_test_outcome'] = true;

/**
 * The permalink the last `Revalidate::purge()` was asked for, or null when it
 * was never called.
 * @var string|null
 */
$GLOBALS['njr_test_purged'] = null;

/**
 * Every option written since the last reset, keyed by name.
 * @var array
 */
$GLOBALS['njr_test_options_written'] = [];

// WordPress stubs
// ====

function add_action( $name, $callback, $priority = 10, $accepted_args = 1 ) {}
function add_filter( $name, $callback, $priority = 10, $accepted_args = 1 ) {}
function __( $text, $domain = null ) { return $text; }

function home_url( $path = '' ) {
	return 'https://site.test' . $path;
}

function wp_parse_url( $url, $component = -1 ) {
	return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
}

function wp_upload_dir() {
	return [ 'basedir' => $GLOBALS['njr_test_uploads_dir'] ];
}

function trailingslashit( $string ) {
	return rtrim( $string, '/\\' ) . '/';
}

function get_option( $name, $default = false ) {
	return $default;
}

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['njr_test_options_written'][ $name ] = $value;

	return true;
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
		// Logs on: what the probe writes is half of what is under test here,
		// and nothing is written at all while the setting is off.
		return 'debug' === $name ? [ 'enable-logs' => 'on' ] : null;
	}
}

class NextJsRevalidate_Test_Revalidate {
	public function purge( $permalink ) {
		$GLOBALS['njr_test_purged'] = $permalink;

		return $GLOBALS['njr_test_outcome'];
	}
}

class NextJsRevalidate {
	public $settings;
	public $revalidate;

	private static $instance;

	public static function init() {
		if ( ! isset( self::$instance ) ) self::$instance = new static();
		return self::$instance;
	}

	private function __construct() {
		$this->settings   = new NextJsRevalidate_Test_Settings();
		$this->revalidate = new NextJsRevalidate_Test_Revalidate();
	}
}

// The subject
// ====

require_once __DIR__ . '/../include/Logger.php';
require_once __DIR__ . '/../include/Interfaces/Hookable.php';
require_once __DIR__ . '/../include/Abstracts/Base.php';
require_once __DIR__ . '/../include/Traits/BlockEditorScreen.php';
// The real failure window, so that "a probe never records an outcome" is a
// claim about what it would have written rather than about a stub.
require_once __DIR__ . '/../include/FailureWindow.php';
require_once __DIR__ . '/../include/Probe.php';

use NextJsRevalidate\FailureWindow;
use NextJsRevalidate\Logger;
use NextJsRevalidate\Probe;

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
 * Run one probe against a fixture front-end.
 *
 * @param string        $typed   What the operator typed in the field.
 * @param true|WP_Error $outcome What `Revalidate::purge()` answers.
 *
 * @return array{result: array, log: string} What the operator is told, and
 *                                           what was written to the log.
 */
function njr_test_probe( $typed, $outcome ) {
	$dir = sys_get_temp_dir() . '/njr-probe-test-' . uniqid();
	mkdir( $dir );

	$GLOBALS['njr_test_uploads_dir']     = $dir;
	$GLOBALS['njr_test_outcome']         = $outcome;
	$GLOBALS['njr_test_purged']          = null;
	$GLOBALS['njr_test_options_written'] = [];

	$probe  = new Probe();
	$result = $probe->send( Probe::path( $typed ) );

	$logFile  = $dir . '/' . Logger::FILENAME;
	$contents = file_exists( $logFile ) ? (string) file_get_contents( $logFile ) : '';

	if ( file_exists( $logFile ) ) unlink( $logFile );
	rmdir( $dir );

	return [ 'result' => $result, 'log' => $contents ];
}

// The cases
// ====

// The path the operator names, composed into a permalink of this site — the
// same shape the queue holds, so the front-end is asked exactly what a real
// revalidation would have asked it.
$probe = njr_test_probe( '/hello-world/', true );
njr_test_assert( 'https://site.test/hello-world/' === $GLOBALS['njr_test_purged'], 'the front-end is asked for the permalink of the path named' );

// Whatever the operator typed, what reaches `home_url()` is a path.
$paths = [
	''                             => '/',
	'/'                            => '/',
	'hello-world'                  => '/hello-world',
	'/hello-world/'                => '/hello-world/',
	'  /hello-world/  '            => '/hello-world/',
	'///hello-world/'              => '/hello-world/',
	'https://site.test/hello/'     => '/hello/',
	'/hello-world/?preview=true'   => '/hello-world/',
];
foreach ( $paths as $typed => $expected ) {
	njr_test_assert( $expected === Probe::path( $typed ), sprintf( '“%s” is the path %s', $typed, $expected ) );
}

// The success, as the operator reads it.
$probe = njr_test_probe( '/hello-world/', true );
njr_test_assert( 'success' === $probe['result']['status'], 'a rebuilt page is a success' );
njr_test_assert( false !== strpos( $probe['result']['message'], 'https://site.test/hello-world/' ), 'the success names the permalink that was rebuilt' );

// The failure, as the operator reads it: the issue asks for the error message,
// and the code is what a log line and a search match on.
$probe = njr_test_probe( '/hello-world/', new WP_Error( 'http_401', 'The front-end answered 401.' ) );
njr_test_assert( 'error' === $probe['result']['status'], 'a failed attempt is an error' );
njr_test_assert( false !== strpos( $probe['result']['message'], 'The front-end answered 401.' ), 'the failure carries the message the attempt produced' );
njr_test_assert( false !== strpos( $probe['result']['message'], 'http_401' ), 'the failure names the error code too' );

$probe = njr_test_probe( '/hello-world/', new WP_Error( 'unreachable', 'cURL error 28: Operation timed out' ) );
njr_test_assert( false !== strpos( $probe['result']['message'], 'cURL error 28' ), 'a transport failure keeps the transport’s own words' );

// A refusal is not a failure: nothing was asked of the front-end at all.
$probe = njr_test_probe( '/hello-world/', new WP_Error( 'not_configured', 'Next.js revalidate is not configured for this site.' ) );
njr_test_assert( 'error' === $probe['result']['status'], 'a refusal is an error the operator has to see' );
njr_test_assert( false !== strpos( $probe['result']['message'], 'not configured' ), 'the refusal says which site is at fault, not which front-end' );
njr_test_assert( false !== strpos( $probe['log'], '⛔' ), 'a refusal is logged as a refusal' );
njr_test_assert( false === strpos( $probe['log'], '❌' ), 'a refusal is not dressed up as a failure' );

// The log line: the drain's vocabulary, minus the queue id and the priority a
// probe has not got, and marked as the thing an operator asked for.
$probe = njr_test_probe( '/hello-world/', true );
njr_test_assert( false !== strpos( $probe['log'], '🔎' ), 'a probe line says it is a probe' );
njr_test_assert( false !== strpos( $probe['log'], '✅ Revalidated' ), 'a delivered probe is logged as the success it is' );
njr_test_assert( false !== strpos( $probe['log'], '[INFO]' ), 'the success is not an error' );
njr_test_assert( false === strpos( $probe['log'], 'priority' ), 'a probe has no priority to log: nothing was ordered against anything' );
njr_test_assert( 1 === count( array_filter( explode( "\n", $probe['log'] ) ) ), 'one probe writes exactly one line' );

$probe = njr_test_probe( '/hello-world/', new WP_Error( 'http_500', 'The front-end answered 500.' ) );
njr_test_assert( false !== strpos( $probe['log'], '❌' ), 'a failed probe is logged as a failure' );
njr_test_assert( false !== strpos( $probe['log'], 'http_500' ), 'the logged failure names the error code' );
njr_test_assert( false !== strpos( $probe['log'], '[ERROR]' ), 'a failed probe is logged at ERROR' );

// ADR 0013, and the reason it is the decision most likely to be undone by
// someone who has not read it: the window is a sample of the queue's own
// traffic, and a probe enters it at a rate set by how worried the operator is.
foreach ( [ 'a success' => true, 'a failure' => new WP_Error( 'http_401', 'nope' ) ] as $description => $outcome ) {
	njr_test_probe( '/hello-world/', $outcome );

	njr_test_assert(
		! array_key_exists( FailureWindow::OPTION_NAME, $GLOBALS['njr_test_options_written'] ),
		"$description probe never enters the failure window"
	);
}

// The window itself still records what the drain hands it — the exclusion is
// the probe's, not a hole in the window.
$GLOBALS['njr_test_options_written'] = [];
FailureWindow::record( new WP_Error( 'http_401', 'nope' ) );
njr_test_assert(
	array_key_exists( FailureWindow::OPTION_NAME, $GLOBALS['njr_test_options_written'] ),
	'the failure window still records an outcome it is handed'
);

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
