<?php
/**
 * What a probe asks, what it answers, and what it writes — Probe::send().
 *
 * A **probe** is a revalidation the operator asks for directly, in order to
 * observe its outcome. Four claims are pinned here, and each of them is a way
 * the button could lie to the person pressing it:
 *
 *  - **It reports a path change for the path the operator named**, in the v2
 *    request every change travels in — a probe whose request differed from the
 *    ordinary one could disagree with it, and send operators chasing phantoms.
 *  - **It is delivered now, on its own**, while the operator waits, rather than
 *    joining the pending changes the request may already hold.
 *  - **It hands back the error the attempt produced**, code and message both.
 *    Collapsing either into "it failed" is what this whole surface exists to
 *    stop.
 *  - **It never records the outcome in the failure window.** The window samples
 *    the site's ordinary traffic, and a diagnostic that can silence its own
 *    alarm is worse than no diagnostic —
 *    `docs/adr/0013-a-probe-is-not-evidence.md`.
 *
 * Driven through the real `PendingChanges`, `FailureWindow` and `Logger`, over
 * a stubbed transport and settings, so what is asserted is the request that
 * went out and the option that was — or was not — written.
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
 * What the next `wp_remote_post()` answers: a response, or a WP_Error standing
 * for a transport that never got one.
 * @var mixed
 */
$GLOBALS['njr_test_response'] = [ 'response' => [ 'code' => 204 ] ];

/**
 * Every `wp_remote_post()` since the last reset, as `[ url, args ]`.
 * @var array
 */
$GLOBALS['njr_test_posts'] = [];

/**
 * Whether the fixture site is configured.
 * @var bool
 */
$GLOBALS['njr_test_configured'] = true;

/**
 * Every option written since the last reset, keyed by name.
 * @var array
 */
$GLOBALS['njr_test_options_written'] = [];

/**
 * The callbacks attached to each filter.
 * @var array<string, callable[]>
 */
$GLOBALS['njr_test_filters'] = [];

// WordPress stubs
// ====

function add_action( $name, $callback, $priority = 10, $accepted_args = 1 ) {}

function add_filter( $name, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['njr_test_filters'][ $name ][] = $callback;
}

function apply_filters( $name, $value, ...$args ) {
	foreach ( $GLOBALS['njr_test_filters'][ $name ] ?? [] as $callback ) $value = $callback( $value, ...$args );

	return $value;
}

function __( $text, $domain = null ) { return $text; }

function home_url( $path = '' ) {
	return 'https://site.test' . $path;
}

function wp_parse_url( $url, $component = -1 ) {
	return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
}

function wp_json_encode( $data ) { return json_encode( $data ); }

function get_current_blog_id() { return 1; }

function wp_remote_post( $url, $args = [] ) {
	$GLOBALS['njr_test_posts'][] = [ $url, $args ];

	return $GLOBALS['njr_test_response'];
}

function wp_remote_retrieve_response_code( $response ) {
	return $response['response']['code'] ?? '';
}

function wp_upload_dir() {
	return [ 'basedir' => $GLOBALS['njr_test_uploads_dir'] ];
}

function trailingslashit( $string ) {
	return rtrim( $string, '/\\' ) . '/';
}

function wp_mkdir_p( $target ) {
	return is_dir( $target ) || mkdir( $target, 0777, true );
}

function get_option( $name, $default = false ) {
	// A site whose log already has its suffix, so the path is stable between
	// the probe writing the log and this file reading it back.
	if ( NextJsRevalidate\Logger::SUFFIX_OPTION_NAME === $name ) return 'probetest';

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

/**
 * The settings a probe reads: saved, and configured or not as the case says.
 */
class NextJsRevalidate_Test_Settings {
	public function __get( $name ) {
		// Logs on: what the probe writes is half of what is under test here,
		// and nothing is written at all while the setting is off.
		if ( 'debug' === $name ) return [ 'enable-logs' => 'on' ];
		if ( 'secret' === $name ) return 'fixture-secret';

		return null;
	}

	public function is_configured() { return $GLOBALS['njr_test_configured']; }
	public function missing_settings() { return $GLOBALS['njr_test_configured'] ? [] : [ 'secret' ]; }
	public function endpoint_url() { return 'https://front-end.test/api/revalidate'; }

	public function not_configured_error() {
		return new WP_Error( 'not_configured', 'Next.js revalidate is not configured for this site. Missing: secret.' );
	}
}

class NextJsRevalidate {
	public $settings;
	public $pendingChanges;

	private static $instance;

	public static function init() {
		if ( ! isset( self::$instance ) ) self::$instance = new static();
		return self::$instance;
	}

	private function __construct() {
		$this->settings       = new NextJsRevalidate_Test_Settings();
		$this->pendingChanges = new NextJsRevalidate\PendingChanges();
	}
}

// The subject
// ====

require_once __DIR__ . '/../include/Logger.php';
require_once __DIR__ . '/../include/Interfaces/Hookable.php';
require_once __DIR__ . '/../include/Abstracts/Base.php';
require_once __DIR__ . '/../include/Traits/BlockEditorScreen.php';
require_once __DIR__ . '/../include/Traits/FrontEndRequest.php';
// The real failure window, so that "a probe never records an outcome" is a
// claim about what it would have written rather than about a stub.
require_once __DIR__ . '/../include/FailureWindow.php';
require_once __DIR__ . '/../include/Change.php';
require_once __DIR__ . '/../include/PendingChanges.php';
require_once __DIR__ . '/../include/Probe.php';

use NextJsRevalidate\Change;
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
 * @param string $typed      What the operator typed in the field.
 * @param mixed  $response   What `wp_remote_post()` answers.
 * @param bool   $configured Optional. Whether the site is configured. Default true.
 *
 * @return array{result: array, log: string} What the operator is told, and
 *                                           what was written to the log.
 */
function njr_test_probe( $typed, $response, $configured = true ) {
	$dir = sys_get_temp_dir() . '/njr-probe-test-' . uniqid();
	mkdir( $dir );

	$GLOBALS['njr_test_uploads_dir']     = $dir;
	$GLOBALS['njr_test_response']        = $response;
	$GLOBALS['njr_test_configured']      = $configured;
	$GLOBALS['njr_test_posts']           = [];
	$GLOBALS['njr_test_options_written'] = [];

	$probe  = new Probe();
	$result = $probe->send( Probe::path( $typed ) );

	$logFile  = Logger::path();
	$contents = file_exists( $logFile ) ? (string) file_get_contents( $logFile ) : '';

	// The log, its guards and the directory holding them.
	foreach ( array_merge( [ $logFile ], array_map( function( $guard ) { return Logger::directory() . '/' . $guard; }, array_keys( Logger::GUARDS ) ) ) as $file ) {
		if ( file_exists( $file ) ) unlink( $file );
	}
	if ( is_dir( Logger::directory() ) ) rmdir( Logger::directory() );
	rmdir( $dir );

	return [ 'result' => $result, 'log' => $contents ];
}

/**
 * The body of the one request the last probe sent, decoded, or null.
 *
 * @return array|null
 */
function njr_test_sent_body() {
	if ( 1 !== count( $GLOBALS['njr_test_posts'] ) ) return null;

	return json_decode( $GLOBALS['njr_test_posts'][0][1]['body'], true );
}

$answered = function ( $code ) {
	return [ 'response' => [ 'code' => $code ] ];
};

// The cases
// ====

// The path the operator names, as a path change in the v2 request — one
// request, carrying that one change, to the endpoint every change goes to.
$probe = njr_test_probe( '/hello-world/', $answered( 204 ) );
njr_test_assert( 1 === count( $GLOBALS['njr_test_posts'] ), 'a probe sends exactly one request' );
njr_test_assert( 'https://front-end.test/api/revalidate' === ( $GLOBALS['njr_test_posts'][0][0] ?? null ), 'to the endpoint every change is sent to' );
njr_test_assert(
	[ 'version' => 2, 'changes' => [ [ 'subject' => 'path', 'uri' => '/hello-world/' ] ] ] === njr_test_sent_body(),
	'carrying one path change, for the path named, in the v2 request'
);
njr_test_assert(
	'Bearer fixture-secret' === ( $GLOBALS['njr_test_posts'][0][1]['headers']['Authorization'] ?? null ),
	'with the secret as a bearer token, as every v2 request carries it'
);
njr_test_assert(
	\NextJsRevalidate\PendingChanges::REQUEST_TIMEOUT === ( $GLOBALS['njr_test_posts'][0][1]['timeout'] ?? null ),
	'within the same timeout an ordinary delivery waits'
);

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

// A probe is delivered on its own, and leaves whatever the request already
// holds for the request's own delivery.
$pending = NextJsRevalidate::init()->pendingChanges;
$pending->report( Change::templates() );

$probe = njr_test_probe( '/hello-world/', $answered( 204 ) );
njr_test_assert(
	[ [ 'subject' => 'path', 'uri' => '/hello-world/' ] ] === ( njr_test_sent_body()['changes'] ?? null ),
	'a probe carries its own change and none of the pending ones'
);
njr_test_assert( [ Change::templates() ] === $pending->pending(), 'the pending changes are left as they were, for their own delivery' );

$reflected = new ReflectionProperty( \NextJsRevalidate\PendingChanges::class, 'pending' );
$reflected->setAccessible( true );
$reflected->setValue( $pending, [] );

// The success, as the operator reads it. Any 2xx is one, as for every v2
// request.
foreach ( [ 200, 202, 204 ] as $code ) {
	$probe = njr_test_probe( '/hello-world/', $answered( $code ) );
	njr_test_assert( 'success' === $probe['result']['status'], "a $code is a success" );
}
njr_test_assert( false !== strpos( $probe['result']['message'], 'https://site.test/hello-world/' ), 'the success names the permalink that was probed' );

// The failure, as the operator reads it: the issue asks for the error message,
// and the code is what a log line and a search match on.
$probe = njr_test_probe( '/hello-world/', $answered( 401 ) );
njr_test_assert( 'error' === $probe['result']['status'], 'a failed attempt is an error' );
njr_test_assert( false !== strpos( $probe['result']['message'], 'The front-end answered 401.' ), 'the failure carries the message the attempt produced' );
njr_test_assert( false !== strpos( $probe['result']['message'], 'http_401' ), 'the failure names the error code too' );

$probe = njr_test_probe( '/hello-world/', new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );
njr_test_assert( false !== strpos( $probe['result']['message'], 'cURL error 28' ), 'a transport failure keeps the transport’s own words' );
njr_test_assert( false !== strpos( $probe['result']['message'], 'unreachable' ), 'and is named as the front-end being unreachable' );

// A refusal is not a failure: nothing was asked of the front-end at all.
$probe = njr_test_probe( '/hello-world/', $answered( 204 ), false );
njr_test_assert( [] === $GLOBALS['njr_test_posts'], 'an unconfigured site sends nothing' );
njr_test_assert( 'error' === $probe['result']['status'], 'a refusal is an error the operator has to see' );
njr_test_assert( false !== strpos( $probe['result']['message'], 'not configured' ), 'the refusal says which site is at fault, not which front-end' );
njr_test_assert( false !== strpos( $probe['log'], '⛔' ), 'a refusal is logged as a refusal' );
njr_test_assert( false === strpos( $probe['log'], '❌' ), 'a refusal is not dressed up as a failure' );
njr_test_assert( 1 === count( array_filter( explode( "\n", $probe['log'] ) ) ), 'a refused probe writes exactly one line, its own' );

// The site's own filter applies to a probe as to any change — it is what the
// front-end would have been sent — and a probe it drops sends nothing, and
// says why.
add_filter( 'nextjs_revalidate_change', function ( $change ) { return false; } );

$probe = njr_test_probe( '/hello-world/', $answered( 204 ) );
njr_test_assert( [] === $GLOBALS['njr_test_posts'], 'a probe the filter drops sends nothing' );
njr_test_assert( 'error' === $probe['result']['status'], 'and is an error the operator has to see' );
njr_test_assert( false !== strpos( $probe['result']['message'], 'nextjs_revalidate_change' ), 'naming the filter that dropped it' );
njr_test_assert( false !== strpos( $probe['log'], '🔎 Probe: 🚫' ), 'and its line in the log says so' );

$GLOBALS['njr_test_filters'] = [];

add_filter( 'nextjs_revalidate_change', function ( $change ) { $change['uri'] = '/fr' . $change['uri']; return $change; } );

njr_test_probe( '/hello-world/', $answered( 204 ) );
njr_test_assert(
	[ [ 'subject' => 'path', 'uri' => '/fr/hello-world/' ] ] === ( njr_test_sent_body()['changes'] ?? null ),
	'a probe sends the change as the filter altered it'
);

$GLOBALS['njr_test_filters'] = [];

// The log line: marked as the thing an operator asked for, and naming the
// permalink rather than counting changes.
$probe = njr_test_probe( '/hello-world/', $answered( 204 ) );
njr_test_assert( false !== strpos( $probe['log'], '🔎' ), 'a probe line says it is a probe' );
njr_test_assert( false !== strpos( $probe['log'], '✅ Revalidated' ), 'a delivered probe is logged as the success it is' );
njr_test_assert( false !== strpos( $probe['log'], 'https://site.test/hello-world/' ), 'the line names the permalink probed' );
njr_test_assert( false !== strpos( $probe['log'], '[INFO]' ), 'the success is not an error' );
njr_test_assert( false === strpos( $probe['log'], 'priority' ), 'a probe has no priority to log: nothing was ordered against anything' );
njr_test_assert( 1 === count( array_filter( explode( "\n", $probe['log'] ) ) ), 'one probe writes exactly one line' );

$probe = njr_test_probe( '/hello-world/', $answered( 500 ) );
njr_test_assert( false !== strpos( $probe['log'], '❌' ), 'a failed probe is logged as a failure' );
njr_test_assert( false !== strpos( $probe['log'], 'http_500' ), 'the logged failure names the error code' );
njr_test_assert( false !== strpos( $probe['log'], '[ERROR]' ), 'a failed probe is logged at ERROR' );

// ADR 0013, and the reason it is the decision most likely to be undone by
// someone who has not read it: the window is a sample of the site's ordinary
// traffic, and a probe enters it at a rate set by how worried the operator is.
foreach ( [ 'a success' => $answered( 204 ), 'a failure' => $answered( 401 ), 'an unreachable' => new WP_Error( 'http_request_failed', 'nope' ) ] as $description => $response ) {
	njr_test_probe( '/hello-world/', $response );

	njr_test_assert(
		! array_key_exists( FailureWindow::OPTION_NAME, $GLOBALS['njr_test_options_written'] ),
		"$description probe never enters the failure window"
	);
}

// The window itself still records what a delivery hands it — the exclusion is
// the probe's, not a hole in the window.
$GLOBALS['njr_test_options_written'] = [];
FailureWindow::record( new WP_Error( 'http_401', 'nope' ) );
njr_test_assert(
	array_key_exists( FailureWindow::OPTION_NAME, $GLOBALS['njr_test_options_written'] ),
	'the failure window still records an outcome it is handed'
);

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
