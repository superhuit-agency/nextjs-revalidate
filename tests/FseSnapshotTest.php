<?php
/**
 * The FSE snapshot invalidation — NextJsRevalidate\FseSnapshot.
 *
 * An FSE change is not a revalidation: nothing is enqueued, no permalink is
 * composed, and the front-end is told once that the whole snapshot is stale.
 * What that leaves worth pinning is what a reviewer cannot see by reading the
 * hooks — that the request is made once per request however many hooks fire,
 * that it goes to the FSE endpoint with the secret and nothing else, that a
 * menu or an ordinary post never reaches it, and that the setting can stop it.
 *
 * Reachable by stubbing a handful of WordPress functions, so it is a standalone
 * script rather than a PHPUnit test — see `docs/adr/0008-two-testing-idioms.md`.
 *
 * Run with `npm run test:php`, or `php tests/FseSnapshotTest.php`.
 */

if ( 'cli' !== PHP_SAPI ) die( 'This file must be run from the command line.' );

// The plugin files bail when this is not defined.
define( 'ABSPATH', __DIR__ . '/' );

/**
 * Every url `wp_remote_get()` was asked for since the last reset, in order.
 * @var string[]
 */
$GLOBALS['njr_test_requests'] = [];

/**
 * What the next `wp_remote_get()` answers.
 * @var mixed
 */
$GLOBALS['njr_test_response'] = [ 'response' => [ 'code' => 200 ] ];

/**
 * Every callback attached to `shutdown` since the last reset.
 * @var callable[]
 */
$GLOBALS['njr_test_shutdown'] = [];

/**
 * What the fixture site holds, as `Settings` would answer it.
 * @var array
 */
$GLOBALS['njr_test_settings'] = [];

// WordPress stubs
// ====

function add_action( $name, $callback, $priority = 10, $accepted_args = 1 ) {
	if ( 'shutdown' === $name ) $GLOBALS['njr_test_shutdown'][] = $callback;
}

function __( $text, $domain = null ) { return $text; }

function add_query_arg( $args, $url ) {
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args );
}

function wp_remote_get( $url, $args = [] ) {
	$GLOBALS['njr_test_requests'][] = $url;

	return $GLOBALS['njr_test_response'];
}

function wp_remote_retrieve_response_code( $response ) {
	return $response['response']['code'] ?? '';
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

/**
 * The type of a post the fixture never actually holds — reached only on the
 * WordPress versions whose `deleted_post` passes no post object.
 */
function get_post_type( $post_id ) { return false; }

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

class WP_Post {
	public $ID;
	public $post_type;

	public function __construct( $id, $post_type ) {
		$this->ID        = $id;
		$this->post_type = $post_type;
	}
}

/**
 * `Settings`, as far as this subject uses it. The real one is pinned by
 * `tests/SettingsTest.php` and `tests/RevalidateEndpointsTest.php`; what
 * matters here is only what it answers.
 */
class NextJsRevalidate_Test_Settings {

	public function __get( $name ) {
		return $GLOBALS['njr_test_settings'][ $name ] ?? '';
	}

	/**
	 * The real `Settings` has one of these, and a stub without it answers
	 * "empty" for every setting a configured site holds — see ADR-0010.
	 */
	public function __isset( $name ) {
		return ! empty( $this->__get( $name ) );
	}

	public function missing_settings() {
		$missing = [];
		if ( empty( $this->domain ) ) $missing[] = 'domain';
		if ( empty( $this->secret ) ) $missing[] = 'secret';

		return $missing;
	}

	public function is_configured() {
		return empty( $this->missing_settings() );
	}

	public function not_configured_error() {
		return new WP_Error( 'not_configured', 'Next.js revalidate is not configured for this site.' );
	}

	public function fse_endpoint_url() {
		$domain = rtrim( (string) $this->domain, '/' );

		return '' === $domain ? '' : $domain . '/api/revalidate-fse';
	}

	public function revalidates_on_fse_save() {
		return filter_var( trim( (string) $this->revalidate_on_fse_save ), FILTER_VALIDATE_BOOLEAN );
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
require_once __DIR__ . '/../include/Traits/FrontEndRequest.php';
require_once __DIR__ . '/../include/Logger.php';
require_once __DIR__ . '/../include/FseSnapshot.php';

use NextJsRevalidate\FseSnapshot;

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
 * A fresh subject over a fixture site, with nothing recorded yet.
 *
 * @param array $settings What the site holds.
 * @return FseSnapshot
 */
function njr_test_subject( array $settings = [ 'domain' => 'https://front-end.test', 'secret' => 's3cret', 'revalidate_on_fse_save' => 'on' ] ) {
	$GLOBALS['njr_test_settings'] = $settings;
	$GLOBALS['njr_test_requests'] = [];
	$GLOBALS['njr_test_shutdown'] = [];

	return new FseSnapshot();
}

/**
 * End the fixture request: run whatever was deferred to `shutdown`.
 *
 * @return void
 */
function njr_test_shutdown() {
	$callbacks = $GLOBALS['njr_test_shutdown'];

	$GLOBALS['njr_test_shutdown'] = [];

	foreach ( $callbacks as $callback ) call_user_func( $callback );
}

// The cases
// ====

// Saving a template fires exactly one request, and it is not sent from inside
// the save: the front-end is told once the request that changed the snapshot is
// over, which is what makes the coalescing below whole.
$fse = njr_test_subject();
$fse->on_template_save( 12 );
njr_test_assert( [] === $GLOBALS['njr_test_requests'], 'the save itself asks the front-end nothing' );

njr_test_shutdown();
njr_test_assert( 1 === count( $GLOBALS['njr_test_requests'] ), 'saving a template fires exactly one request' );

// The URL: the FSE endpoint, the secret as a query arg, and no path — the
// snapshot is not held at one.
$url = $GLOBALS['njr_test_requests'][0];
njr_test_assert( 0 === strpos( $url, 'https://front-end.test/api/revalidate-fse?' ), 'the request goes to the FSE endpoint' );
njr_test_assert( false !== strpos( $url, 'secret=s3cret' ), 'the secret travels as a query arg, as it does for a revalidation' );
njr_test_assert( false === strpos( $url, 'path=' ), 'nothing carries a path: the snapshot is not held at one' );

// Coalescing. A single site-editor save can reach several of these hooks — a
// template saved, a part saved, a part reset to its theme default — and the
// front-end needs telling once.
$fse = njr_test_subject();
$fse->on_template_save( 12 );
$fse->on_template_save( 13 );
$fse->on_post_delete( 14, new WP_Post( 14, 'wp_template_part' ) );
$fse->on_theme_switch();
$deferred = count( $GLOBALS['njr_test_shutdown'] );
njr_test_shutdown();
njr_test_assert( 1 === $deferred, 'four changes in one request defer exactly one telling' );
njr_test_assert( 1 === count( $GLOBALS['njr_test_requests'] ), 'four changes in one request are coalesced into a single request' );

// …and a second request is a second telling: the coalescing is per request, not
// a latch that fires once for the life of the process.
$fse->on_template_save( 15 );
njr_test_shutdown();
njr_test_assert( 2 === count( $GLOBALS['njr_test_requests'] ), 'a later request tells the front-end again' );

// "Reset to theme default" deletes the post rather than saving it, and there is
// no `save_post` for that.
$fse = njr_test_subject();
$fse->on_post_delete( 21, new WP_Post( 21, 'wp_template' ) );
njr_test_shutdown();
njr_test_assert( 1 === count( $GLOBALS['njr_test_requests'] ), 'deleting a template fires the invalidation' );

// Switching themes changes every template at once.
$fse = njr_test_subject();
$fse->on_theme_switch();
njr_test_shutdown();
njr_test_assert( 1 === count( $GLOBALS['njr_test_requests'] ), 'switching themes fires the invalidation' );

// Deleting anything else does not. A menu is the case worth naming: menu items
// are fetched at request time by the front-end and are absent from the snapshot
// by design, so telling it they changed would be pure waste.
foreach ( [ 'wp_navigation', 'nav_menu_item', 'post', 'page' ] as $post_type ) {
	$fse = njr_test_subject();
	$fse->on_post_delete( 31, new WP_Post( 31, $post_type ) );
	njr_test_shutdown();
	njr_test_assert( [] === $GLOBALS['njr_test_requests'], "deleting a $post_type fires nothing" );
}

// The setting is the escape hatch for a front-end that does not serve the
// endpoint yet: off, and nothing is asked of it at all.
$fse = njr_test_subject( [ 'domain' => 'https://front-end.test', 'secret' => 's3cret', 'revalidate_on_fse_save' => 'off' ] );
$fse->on_template_save( 41 );
njr_test_shutdown();
njr_test_assert( [] === $GLOBALS['njr_test_requests'], 'the setting switched off asks the front-end nothing' );
njr_test_assert( [] === $GLOBALS['njr_test_shutdown'], 'the setting switched off defers nothing either' );

// Only a site that says so invalidates. The empty row is the site that upgraded
// into this release without opting in — and it is *the same row* as the site
// that has just switched the gate off, which is why neither can invalidate: of
// the two, the one whose front-end may not serve the endpoint at all is the one
// this has to be safe for. `Settings::define_settings()` is what gives a new
// install the `on`, and `tests/SettingsTest.php` is what pins that.
foreach ( [ 'on' => 1, '1' => 1, 'true' => 1, '' => 0, 'off' => 0, '  ' => 0, 'banana' => 0 ] as $stored => $expected ) {
	$fse = njr_test_subject( [ 'domain' => 'https://front-end.test', 'secret' => 's3cret', 'revalidate_on_fse_save' => (string) $stored ] );
	$fse->on_template_save( 42 );
	njr_test_shutdown();
	njr_test_assert(
		$expected === count( $GLOBALS['njr_test_requests'] ),
		sprintf(
			'a setting stored as %s %s',
			'' === trim( (string) $stored ) ? "'$stored'" : "`$stored`",
			$expected ? 'invalidates' : 'asks the front-end nothing'
		)
	);
}

// An unconfigured site refuses: it could not deliver, so it asks nothing.
$fse = njr_test_subject( [ 'domain' => '', 'secret' => '' ] );
$outcome = $fse->invalidate();
njr_test_assert( is_wp_error( $outcome ) && 'not_configured' === $outcome->get_error_code(), 'an unconfigured site refuses with `not_configured`' );
njr_test_assert( [] === $GLOBALS['njr_test_requests'], 'an unconfigured site asks the front-end nothing at all' );

// The outcome is named the way a revalidation's is — one code per way of
// failing — because both come back through the same transport.
$outcomes = [
	[ [ 'response' => [ 'code' => 200 ] ],                    true,          'a 200 is the success, and it is `true` rather than truthy' ],
	[ [ 'response' => [ 'code' => 404 ] ],                    'http_404',    'a 404 — the front-end has no such route — is `http_404`' ],
	[ [ 'response' => [ 'code' => 401 ] ],                    'http_401',    'a 401 is `http_401`' ],
	[ [ 'body' => '' ],                                       'no_response', 'an answer without a status is `no_response`' ],
	[ new WP_Error( 'http_request_failed', 'timed out' ),     'unreachable', 'a transport error is `unreachable`' ],
];

foreach ( $outcomes as [ $response, $expected, $description ] ) {
	$fse = njr_test_subject();
	$GLOBALS['njr_test_response'] = $response;

	$outcome = $fse->invalidate();
	$code    = is_wp_error( $outcome ) ? $outcome->get_error_code() : $outcome;

	njr_test_assert( $expected === $code, $description );
}

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
