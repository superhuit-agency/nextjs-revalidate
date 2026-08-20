<?php
/**
 * The outcome of a revalidation — Revalidate::purge().
 *
 * Delivery is at most once (`docs/adr/0004-at-most-once-revalidation.md`): the
 * drain has already deleted the queue entry by the time `purge()` runs, so what
 * `purge()` answers is the only trace a revalidation which did not succeed ever
 * leaves. These cases pin that answer — one error code per way of failing —
 * because a caller that cannot tell them apart is the bug this file guards.
 *
 * Reachable by stubbing a handful of WordPress functions, so it is a standalone
 * script rather than a PHPUnit test — see `docs/adr/0008-two-testing-idioms.md`.
 *
 * Run with `npm run test:php`, or `php tests/purge-outcome-test.php`.
 */

if ( 'cli' !== PHP_SAPI ) die( 'This file must be run from the command line.' );

// The plugin files bail when this is not defined.
define( 'ABSPATH', __DIR__ . '/' );

/**
 * What the next `wp_remote_get()` answers: an array, a WP_Error, or a callable
 * to run in its place.
 * @var mixed
 */
$GLOBALS['njr_test_response'] = null;

/**
 * The url the last `wp_remote_get()` was called with, or null when it was never
 * called.
 * @var string|null
 */
$GLOBALS['njr_test_requested_url'] = null;

/**
 * The settings a configured fixture site holds. Empty strings for an
 * unconfigured one.
 * @var array
 */
$GLOBALS['njr_test_settings'] = [ 'url' => '', 'secret' => '' ];

// WordPress stubs
// ====

function add_action( $name, $callback, $priority = 10, $accepted_args = 1 ) {}
function add_filter( $name, $callback, $priority = 10, $accepted_args = 1 ) {}
function apply_filters( $name, $value, ...$args ) { return $value; }
function __( $text, $domain = null ) { return $text; }

function wp_make_link_relative( $url ) {
	return (string) preg_replace( '|https?://[^/]+(/.*)|i', '$1', $url );
}

function add_query_arg( $args, $url ) {
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args );
}

function wp_remote_get( $url, $args = [] ) {
	$GLOBALS['njr_test_requested_url'] = $url;

	$response = $GLOBALS['njr_test_response'];

	return is_callable( $response ) ? $response() : $response;
}

function wp_remote_retrieve_response_code( $response ) {
	return $response['response']['code'] ?? '';
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
	public function missing_settings() {
		$missing = [];
		if ( empty( $GLOBALS['njr_test_settings']['url'] ) )    $missing[] = 'url';
		if ( empty( $GLOBALS['njr_test_settings']['secret'] ) ) $missing[] = 'secret';

		return $missing;
	}

	public function is_configured() {
		return empty( $this->missing_settings() );
	}

	public function not_configured_error() {
		return new WP_Error(
			'not_configured',
			'Next.js revalidate is not configured for this site: the revalidate URL and secret are both required before anything can be revalidated.'
		);
	}

	public function __get( $name ) {
		return $GLOBALS['njr_test_settings'][ $name ] ?? null;
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

require_once __DIR__ . '/../include/Interfaces/Hookable.php';
require_once __DIR__ . '/../include/Abstracts/Base.php';
require_once __DIR__ . '/../include/Traits/AdminBarMenu.php';
require_once __DIR__ . '/../include/Traits/SendbackUrl.php';
require_once __DIR__ . '/../include/Traits/BlockEditorScreen.php';
require_once __DIR__ . '/../include/Revalidate.php';

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
 * Run one purge against a fixture site.
 *
 * @param array $settings What the site holds — `url` and `secret`.
 * @param mixed $response What `wp_remote_get()` answers.
 * @return true|WP_Error
 */
function njr_test_purge( $settings, $response ) {
	$GLOBALS['njr_test_settings']      = $settings;
	$GLOBALS['njr_test_response']      = $response;
	$GLOBALS['njr_test_requested_url'] = null;

	$revalidate = new NextJsRevalidate\Revalidate();

	return $revalidate->purge( 'https://example.test/hello-world/' );
}

/**
 * The error code of an outcome, or `true` when it succeeded.
 *
 * @param true|WP_Error $outcome
 * @return string|true
 */
function njr_test_code( $outcome ) {
	return is_wp_error( $outcome ) ? $outcome->get_error_code() : $outcome;
}

$configured   = [ 'url' => 'https://front-end.test/api/revalidate', 'secret' => 's3cret' ];
$unconfigured = [ 'url' => '', 'secret' => '' ];

function njr_test_response( $code ) {
	return [ 'response' => [ 'code' => $code ] ];
}

// The cases
// ====

// A site missing either setting could not deliver, and says so without asking
// the front-end anything.
$outcome = njr_test_purge( $unconfigured, njr_test_response( 200 ) );
njr_test_assert( 'not_configured' === njr_test_code( $outcome ), 'an unconfigured site refuses with `not_configured`' );
njr_test_assert( null === $GLOBALS['njr_test_requested_url'], 'an unconfigured site asks the front-end nothing at all' );

$outcome = njr_test_purge( [ 'url' => 'https://front-end.test/api/revalidate', 'secret' => '' ], njr_test_response( 200 ) );
njr_test_assert( 'not_configured' === njr_test_code( $outcome ), 'half configured is unconfigured' );

// The one way a revalidation succeeds.
$outcome = njr_test_purge( $configured, njr_test_response( 200 ) );
njr_test_assert( true === $outcome, 'a 200 is the success, and it is `true` rather than truthy' );
njr_test_assert(
	false !== strpos( (string) $GLOBALS['njr_test_requested_url'], 'path=%2Fhello-world%2F' ),
	'the front-end is asked for the path of the permalink'
);

// A string status is what several transports hand back.
$outcome = njr_test_purge( $configured, [ 'response' => [ 'code' => '200' ] ] );
njr_test_assert( true === $outcome, 'a 200 answered as a string is still the success' );

// The front-end was never reached: DNS, TLS, a timeout.
$outcome = njr_test_purge( $configured, new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );
njr_test_assert( 'unreachable' === njr_test_code( $outcome ), 'a transport error is `unreachable`' );
njr_test_assert(
	false !== strpos( $outcome->get_error_message(), 'cURL error 28' ),
	'the transport keeps its say in the message'
);

// The front-end answered, and refused. The status is in the code because 401
// and 500 send an operator to completely different places.
foreach ( [ 401, 403, 404, 500 ] as $status ) {
	$outcome = njr_test_purge( $configured, njr_test_response( $status ) );
	njr_test_assert( "http_$status" === njr_test_code( $outcome ), "a $status is `http_$status`" );
}

// An answer with no status line at all.
$outcome = njr_test_purge( $configured, [ 'body' => '' ] );
njr_test_assert( 'no_response' === njr_test_code( $outcome ), 'an answer without a status is `no_response`, never `http_0`' );

// Anything thrown inside the attempt is caught: the drain runs this in a loop
// and holds a running-cron count while it does.
$outcome = njr_test_purge( $configured, function() { throw new \RuntimeException( 'a filter blew up' ); } );
njr_test_assert( 'exception' === njr_test_code( $outcome ), 'a throw is caught and named `exception`' );
njr_test_assert(
	false !== strpos( $outcome->get_error_message(), 'a filter blew up' ),
	'the exception keeps its say in the message'
);

// Every outcome is one of the two shapes a caller may assume, and no falsy
// success can be mistaken for a failure.
njr_test_assert(
	true === njr_test_purge( $configured, njr_test_response( 200 ) )
	&& is_wp_error( njr_test_purge( $configured, njr_test_response( 500 ) ) ),
	'purge() answers `true` or a WP_Error, and nothing else'
);

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
