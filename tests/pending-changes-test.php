<?php
/**
 * The pending changes and the v2 request — NextJsRevalidate\PendingChanges.
 *
 * Every producer of a change hands it to `PendingChanges::report()`, and the
 * front-end hears about all of them in one `POST` per site when the request
 * ends. What is pinned here is what a reviewer cannot see by reading the
 * producers: that changes to one subject merge, that a refused or filtered
 * change is never held, that a long request is chunked at the cap, that each
 * site's changes travel with that site's settings whichever site is current at
 * `shutdown`, what the request looks like on the wire, and that one request is
 * one outcome in the failure window however many changes it carried. See
 * `docs/adr/0033-the-plugin-reports-changes-not-tags.md` and
 * `docs/adr/0034-changes-are-delivered-when-the-request-ends.md`.
 *
 * Driven through the real `Settings`, `FailureWindow` and `Logger` over stubbed
 * option and upload functions that keep a store per site, so what a site's
 * delivery reads, records and logs is that site's own.
 *
 * Reachable by stubbing a handful of WordPress functions, so it is a standalone
 * script rather than a PHPUnit test — see `docs/adr/0008-two-testing-idioms.md`.
 *
 * Run with `npm run test:php`, or `php tests/pending-changes-test.php`.
 */

if ( 'cli' !== PHP_SAPI ) die( 'This file must be run from the command line.' );

// The plugin files bail when this is not defined.
define( 'ABSPATH', __DIR__ . '/' );

/**
 * The site currently being served, and the ones `switch_to_blog()` left.
 */
$GLOBALS['njr_test_site']  = 1;
$GLOBALS['njr_test_stack'] = [];

/**
 * Every site's option rows: site ID => option name => stored value.
 * @var array
 */
$GLOBALS['njr_test_options'] = [];

/**
 * Every `wp_remote_post()` since the last reset, as
 * `[ site current at the time, url, args ]`.
 * @var array
 */
$GLOBALS['njr_test_posts'] = [];

/**
 * What the next `wp_remote_post()` answers.
 * @var mixed
 */
$GLOBALS['njr_test_response'] = [ 'response' => [ 'code' => 200 ] ];

/**
 * The callbacks attached to each filter.
 * @var array<string, callable[]>
 */
$GLOBALS['njr_test_filters'] = [];

/**
 * Where each site's uploads directory is, under one temporary root.
 * @var string
 */
$GLOBALS['njr_test_uploads_root'] = sys_get_temp_dir() . '/njr-pending-changes-test-' . uniqid();

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

function untrailingslashit( $string ) { return rtrim( $string, '/\\' ); }

function trailingslashit( $string ) { return rtrim( $string, '/\\' ) . '/'; }

function wp_json_encode( $data ) { return json_encode( $data ); }

function wp_remote_post( $url, $args = [] ) {
	$GLOBALS['njr_test_posts'][] = [ $GLOBALS['njr_test_site'], $url, $args ];

	return $GLOBALS['njr_test_response'];
}

function wp_remote_retrieve_response_code( $response ) {
	return $response['response']['code'] ?? '';
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function get_current_blog_id() { return $GLOBALS['njr_test_site']; }

function switch_to_blog( $site_id ) {
	$GLOBALS['njr_test_stack'][] = $GLOBALS['njr_test_site'];
	$GLOBALS['njr_test_site']    = (int) $site_id;

	return true;
}

function restore_current_blog() {
	$GLOBALS['njr_test_site'] = array_pop( $GLOBALS['njr_test_stack'] );

	return true;
}

function get_option( $name, $default = false ) {
	$site = $GLOBALS['njr_test_site'];

	return array_key_exists( $name, $GLOBALS['njr_test_options'][ $site ] ?? [] )
		? $GLOBALS['njr_test_options'][ $site ][ $name ]
		: $default;
}

function add_option( $name, $value = '' ) {
	if ( false !== get_option( $name ) ) return false;

	$GLOBALS['njr_test_options'][ $GLOBALS['njr_test_site'] ][ $name ] = $value;
	return true;
}

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['njr_test_options'][ $GLOBALS['njr_test_site'] ][ $name ] = $value;
	return true;
}

function wp_upload_dir() {
	return [ 'basedir' => $GLOBALS['njr_test_uploads_root'] . '/site-' . $GLOBALS['njr_test_site'] ];
}

function wp_mkdir_p( $target ) {
	return is_dir( $target ) || mkdir( $target, 0777, true );
}

function wp_generate_password( $length = 12, $special_chars = true ) {
	return 'suffix' . $GLOBALS['njr_test_site'];
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
 * The composition root, holding the real `Settings`: what a delivery sends is
 * pinned against what the site's settings actually answer.
 */
class NextJsRevalidate {
	public $settings;

	private static $instance;

	public static function init() {
		if ( ! isset( self::$instance ) ) self::$instance = new static();
		return self::$instance;
	}

	private function __construct() {
		$this->settings = new NextJsRevalidate\Settings();
	}
}

// The subject
// ====

require_once __DIR__ . '/../include/Interfaces/Hookable.php';
require_once __DIR__ . '/../include/Abstracts/Base.php';
require_once __DIR__ . '/../include/Settings.php';
require_once __DIR__ . '/../include/Logger.php';
require_once __DIR__ . '/../include/Traits/BlockEditorScreen.php';
require_once __DIR__ . '/../include/Traits/FrontEndRequest.php';
require_once __DIR__ . '/../include/FailureWindow.php';
require_once __DIR__ . '/../include/Change.php';
require_once __DIR__ . '/../include/PendingChanges.php';

use NextJsRevalidate\Change;
use NextJsRevalidate\FailureWindow;
use NextJsRevalidate\Logger;
use NextJsRevalidate\PendingChanges;
use NextJsRevalidate\Settings;

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
 * A configured site's option rows, logs on.
 *
 * @param string $domain
 * @param string $secret
 * @return array
 */
function njr_test_configured( $domain = 'https://front-end.test', $secret = 's3cret' ) {
	return [
		Settings::SETTINGS_DOMAIN_NAME => $domain,
		Settings::SETTINGS_SECRET_NAME => $secret,
		Settings::SETTINGS_DEBUG       => [ 'enable-logs' => 'on' ],
	];
}

/**
 * A fresh subject, on fixture sites holding the given rows, with nothing
 * requested, filtered, recorded or logged yet, and site 1 current.
 *
 * @param array $sites site ID => option rows.
 * @return PendingChanges
 */
function njr_test_subject( array $sites = [ 1 => null ] ) {
	foreach ( $sites as $site => $options ) {
		if ( null === $options ) $sites[ $site ] = njr_test_configured();
	}

	njr_test_remove_logs();

	$GLOBALS['njr_test_options']  = $sites;
	$GLOBALS['njr_test_posts']    = [];
	$GLOBALS['njr_test_filters']  = [];
	$GLOBALS['njr_test_response'] = [ 'response' => [ 'code' => 200 ] ];
	$GLOBALS['njr_test_site']     = 1;
	$GLOBALS['njr_test_stack']    = [];

	return new PendingChanges();
}

/**
 * The body of the n-th request, decoded.
 *
 * @param int $n
 * @return array|null
 */
function njr_test_body( $n = 0 ) {
	$body = $GLOBALS['njr_test_posts'][ $n ][2]['body'] ?? null;

	return is_string( $body ) ? json_decode( $body, true ) : null;
}

/**
 * The subjects the n-th request carried, in order.
 *
 * @param int $n
 * @return string[]
 */
function njr_test_subjects( $n = 0 ) {
	return array_column( njr_test_body( $n )['changes'] ?? [], 'subject' );
}

/**
 * Everything the current site has logged.
 *
 * @return string
 */
function njr_test_log() {
	$path = Logger::path();

	return is_file( $path ) ? (string) file_get_contents( $path ) : '';
}

/**
 * Remove every file this script wrote under the uploads root.
 *
 * @return void
 */
function njr_test_remove_logs() {
	$root = $GLOBALS['njr_test_uploads_root'];
	if ( ! is_dir( $root ) ) return;

	$files = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $files as $file ) {
		$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
	}

	rmdir( $root );
}

// A change
// ====

// The six subjects of ADR 0033, each in the shape the table gives it.
njr_test_assert(
	[ 'subject' => 'post', 'id' => 12, 'type' => 'page', 'before' => null, 'after' => [ 'uri' => '/about/' ] ] === Change::post( 12, 'page', null, '/about/' ),
	'a post change carries its id, type, and the uri before and after — null where it is not on the front-end'
);
njr_test_assert( [ 'subject' => 'redirect', 'uri' => '/old/' ] === Change::redirect( '/old/' ), 'a redirect change carries its source uri' );
njr_test_assert( [ 'subject' => 'path', 'uri' => '/named/' ] === Change::path( '/named/' ), 'a path change carries its uri' );
njr_test_assert( [ 'subject' => 'menu', 'id' => 3, 'locations' => [ 'primary', 'footer' ] ] === Change::menu( 3, [ 'primary', 'footer' ] ), 'a menu change carries its id and locations' );
njr_test_assert( [ 'subject' => 'templates' ] === Change::templates(), 'a templates change carries nothing but its subject' );
njr_test_assert( [ 'subject' => 'all' ] === Change::all(), 'revalidate all of the whole site carries nothing but its subject' );
njr_test_assert(
	[ 'subject' => 'all', 'type' => 'post', 'taxonomies' => [ 'category', 'post_tag' ] ] === Change::all( 'post', [ 'category', 'post_tag' ] ),
	'revalidate all of one post type carries the type and its taxonomies'
);

// Merging
// ====

// A post saved three times in a request is one change: the state before the
// first save and the state after the last.
$pending = njr_test_subject();
$pending->report( Change::post( 12, 'post', '/draft-title/', '/first-title/' ) );
$pending->report( Change::post( 12, 'post', '/first-title/', '/second-title/' ) );
$pending->report( Change::post( 12, 'post', '/second-title/', '/final-title/' ) );
$held = $pending->pending();
njr_test_assert( 1 === count( $held ), 'three changes to one post are held as one' );
njr_test_assert( [ 'uri' => '/draft-title/' ] === ( $held[0]['before'] ?? null ), 'the merged change keeps the state before the first' );
njr_test_assert( [ 'uri' => '/final-title/' ] === ( $held[0]['after'] ?? null ), 'the merged change keeps the state after the last' );

// A post published and then unpublished in one request was never on the
// front-end on either side of it: there is nothing to tell anybody.
$pending = njr_test_subject();
$pending->report( Change::post( 13, 'post', null, '/brief/' ) );
$pending->report( Change::post( 13, 'post', '/brief/', null ) );
njr_test_assert( [] === $pending->pending(), 'a post published and unpublished in one request merges into no change at all' );
$pending->deliver();
njr_test_assert( [] === $GLOBALS['njr_test_posts'], 'and nothing is sent for it' );

// A subject without sides collapses only when the changes are identical.
$pending = njr_test_subject();
$pending->report( Change::templates() );
$pending->report( Change::templates() );
$pending->report( Change::path( '/a/' ) );
$pending->report( Change::path( '/a/' ) );
$pending->report( Change::path( '/b/' ) );
$pending->report( Change::post( 12, 'post', '/a/', '/a/' ) );
njr_test_assert(
	[ 'templates', 'path', 'path', 'post' ] === array_column( $pending->pending(), 'subject' ),
	'identical changes collapse, different ones are each kept, and the order is the order they were first produced in'
);

// A refusal
// ====

$pending = njr_test_subject( [ 1 => [ Settings::SETTINGS_DOMAIN_NAME => 'https://front-end.test', Settings::SETTINGS_DEBUG => [ 'enable-logs' => 'on' ] ] ] );
$asked   = 0;
add_filter( 'nextjs_revalidate_change', function ( $change ) use ( &$asked ) { $asked++; return $change; } );

$answer = $pending->report( Change::templates() );
njr_test_assert( is_wp_error( $answer ) && 'not_configured' === $answer->get_error_code(), 'an unconfigured site refuses the change with `not_configured`' );
njr_test_assert( [] === $pending->pending(), 'a refused change is never held' );
njr_test_assert( 0 === $asked, 'a refused change is not handed to the filter either' );
njr_test_assert( false !== strpos( njr_test_log(), '⛔ Refused a templates change — site not configured (missing: secret)' ), 'the refusal is logged, naming the subject and what is missing' );
$pending->deliver();
njr_test_assert( [] === $GLOBALS['njr_test_posts'], 'an unconfigured site asks the front-end nothing' );
njr_test_assert( [] === FailureWindow::outcomes(), 'and a refusal is no evidence about the front-end' );

// A site configured when the change was produced and not when it is delivered
// is refused at delivery — the same answer, given later.
$pending = njr_test_subject();
$pending->report( Change::templates() );
$GLOBALS['njr_test_options'][1][ Settings::SETTINGS_SECRET_NAME ] = '';
$pending->deliver();
njr_test_assert( [] === $GLOBALS['njr_test_posts'], 'a site unconfigured before the request ends sends nothing' );
njr_test_assert( false !== strpos( njr_test_log(), '⛔ Refused 1 change (templates) — site not configured (missing: secret)' ), 'and logs the refusal at delivery' );
njr_test_assert( [] === FailureWindow::outcomes(), 'a refusal at delivery does not enter the failure window either' );

// The filter
// ====

// Dropping.
$pending = njr_test_subject();
add_filter( 'nextjs_revalidate_change', function ( $change ) { return 'path' === $change['subject'] ? false : $change; } );
njr_test_assert( false === $pending->report( Change::path( '/private/' ) ), 'a change the filter answers `false` for is dropped' );
njr_test_assert( true === $pending->report( Change::templates() ), 'and one it returns is held' );
njr_test_assert( [ 'templates' ] === array_column( $pending->pending(), 'subject' ), 'the dropped change is not among the pending changes' );

// Altering: what the filter returns is what is sent.
$pending = njr_test_subject();
add_filter( 'nextjs_revalidate_change', function ( $change ) {
	if ( 'path' === $change['subject'] ) $change['uri'] = '/en' . $change['uri'];
	return $change;
} );
$pending->report( Change::path( '/hello/' ) );
$pending->deliver();
njr_test_assert( [ [ 'subject' => 'path', 'uri' => '/en/hello/' ] ] === ( njr_test_body()['changes'] ?? null ), 'a change the filter altered is sent as altered' );

// Changes the filter makes identical are identical.
$pending = njr_test_subject();
add_filter( 'nextjs_revalidate_change', function ( $change ) { return Change::path( '/everything/' ); } );
$pending->report( Change::path( '/a/' ) );
$pending->report( Change::path( '/b/' ) );
njr_test_assert( 1 === count( $pending->pending() ), 'changes are merged as the filter left them, not as they were produced' );

// Something that is not a change cannot be sent.
$pending = njr_test_subject();
add_filter( 'nextjs_revalidate_change', function ( $change ) { return 'nonsense'; } );
njr_test_assert( false === $pending->report( Change::templates() ), 'a filter answering something that is not a change drops it' );
njr_test_assert( [] === $pending->pending(), 'and nothing is held for it' );

// The cap
// ====

$pending = njr_test_subject();
for ( $i = 1; $i < PendingChanges::CAP; $i++ ) $pending->report( Change::path( "/page-$i/" ) );
njr_test_assert( [] === $GLOBALS['njr_test_posts'], 'a site holding one change fewer than the cap has sent nothing yet' );

$pending->report( Change::path( '/the-last-straw/' ) );
njr_test_assert( 1 === count( $GLOBALS['njr_test_posts'] ), 'reaching the cap sends the pending changes immediately' );
njr_test_assert( PendingChanges::CAP === count( njr_test_body()['changes'] ?? [] ), 'in one request carrying all of them' );
njr_test_assert( [] === $pending->pending(), 'and collection starts again' );

$pending->report( Change::path( '/after/' ) );
$pending->deliver();
njr_test_assert( 2 === count( $GLOBALS['njr_test_posts'] ), 'the end of the request sends what was collected after the chunk' );
njr_test_assert( [ [ 'subject' => 'path', 'uri' => '/after/' ] ] === ( njr_test_body( 1 )['changes'] ?? null ), 'and only that' );

// The cap counts changes, not reports: a post saved a thousand times is one.
$pending = njr_test_subject();
for ( $i = 0; $i < 3 * PendingChanges::CAP; $i++ ) $pending->report( Change::post( 12, 'post', '/a/', '/a/' ) );
njr_test_assert( [] === $GLOBALS['njr_test_posts'], 'merged changes do not count towards the cap' );

// Per site
// ====

// A change belongs to the site that was current when it was produced, and is
// sent with that site's endpoint and secret.
$pending = njr_test_subject( [
	1 => njr_test_configured( 'https://one.test', 'secret-one' ),
	2 => njr_test_configured( 'https://two.test', 'secret-two' ),
	3 => njr_test_configured( 'https://three.test', 'secret-three' ),
] );
$pending->report( Change::templates() );
switch_to_blog( 2 );
$pending->report( Change::path( '/on-two/' ) );
njr_test_assert( [ 'path' ] === array_column( $pending->pending(), 'subject' ), 'each site holds only its own changes' );
restore_current_blog();

// The request ends on a third site, which produced nothing.
switch_to_blog( 3 );
$pending->deliver();

$requests = [];
foreach ( $GLOBALS['njr_test_posts'] as $n => [ $site, $url, $args ] ) {
	$requests[ $site ] = [ $url, $args['headers']['Authorization'] ?? '', njr_test_subjects( $n ) ];
}
njr_test_assert( 2 === count( $GLOBALS['njr_test_posts'] ), 'two sites with pending changes are sent one request each' );
njr_test_assert(
	[ 'https://one.test/api/revalidate', 'Bearer secret-one', [ 'templates' ] ] === ( $requests[1] ?? null ),
	'the first site’s changes go to its own endpoint with its own secret, though another site is current at the end of the request'
);
njr_test_assert(
	[ 'https://two.test/api/revalidate', 'Bearer secret-two', [ 'path' ] ] === ( $requests[2] ?? null ),
	'the second site’s changes go to its own endpoint with its own secret'
);
njr_test_assert( ! isset( $requests[3] ), 'the site current at the end of the request, holding nothing, sends nothing' );
njr_test_assert( 3 === get_current_blog_id() && [ 1 ] === $GLOBALS['njr_test_stack'], 'delivery leaves the current site as it found it' );

restore_current_blog();
foreach ( [ 1 => 'templates', 2 => 'path' ] as $site => $subject ) {
	switch_to_blog( $site );
	njr_test_assert( 1 === count( FailureWindow::outcomes() ), "site $site records its own request in its own failure window" );
	njr_test_assert( false !== strpos( njr_test_log(), "✅ Revalidated 1 change ($subject)" ), "site $site logs its own request in its own log" );
	restore_current_blog();
}
switch_to_blog( 3 );
njr_test_assert( [] === FailureWindow::outcomes(), 'the site that sent nothing records nothing' );
restore_current_blog();

// The request
// ====

$pending = njr_test_subject();
$pending->report( Change::post( 12, 'post', null, '/hello/' ) );
$pending->report( Change::templates() );
$pending->deliver();

[ $site, $url, $args ] = $GLOBALS['njr_test_posts'][0];
$body = njr_test_body();

njr_test_assert( 1 === count( $GLOBALS['njr_test_posts'] ), 'the end of the request sends one POST' );
njr_test_assert( 'https://front-end.test/api/revalidate' === $url, 'to the endpoint URL, with no secret and no query in it' );
njr_test_assert( 'Bearer s3cret' === ( $args['headers']['Authorization'] ?? null ), 'carrying the secret as a bearer token' );
njr_test_assert( 'application/json' === ( $args['headers']['Content-Type'] ?? null ), 'declaring a JSON body' );
njr_test_assert( 5 === ( $args['timeout'] ?? null ), 'waiting five seconds for the answer' );
njr_test_assert( 0 === ( $args['redirection'] ?? null ), 'and following no redirect, which would turn it into a GET of something else' );
njr_test_assert( [ 'version', 'changes' ] === array_keys( (array) $body ), 'the body is `version` and `changes`, and nothing else' );
njr_test_assert( 2 === ( $body['version'] ?? null ), 'the version is 2' );
njr_test_assert(
	[
		[ 'subject' => 'post', 'id' => 12, 'type' => 'post', 'before' => null, 'after' => [ 'uri' => '/hello/' ] ],
		[ 'subject' => 'templates' ],
	] === ( $body['changes'] ?? null ),
	'the changes are a list of every pending change, in the order they were produced'
);
njr_test_assert( false !== strpos( (string) $args['body'], '{"subject":"templates"}' ), 'a change with no fields is sent as an object, not a list' );

$pending->deliver();
njr_test_assert( 1 === count( $GLOBALS['njr_test_posts'] ), 'delivered changes are not delivered again' );

// Outcomes
// ====

$statuses = [ 200 => true, 202 => true, 204 => true, 301 => 'http_301', 401 => 'http_401', 500 => 'http_500' ];

foreach ( $statuses as $status => $expected ) {
	$pending = njr_test_subject();
	$GLOBALS['njr_test_response'] = [ 'response' => [ 'code' => $status ] ];

	$pending->report( Change::templates() );
	$pending->deliver();

	$outcomes = FailureWindow::outcomes();
	$recorded = ( 1 === count( $outcomes ) ) ? ( $outcomes[0]['failed'] ? $outcomes[0]['code'] : true ) : null;

	njr_test_assert(
		$expected === $recorded,
		true === $expected ? "a $status is a success" : "a $status is a failure, recorded as `$expected`"
	);
}

foreach ( [
	'unreachable' => new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ),
	'no_response' => [ 'body' => '' ],
] as $expected => $response ) {
	$pending = njr_test_subject();
	$GLOBALS['njr_test_response'] = $response;

	$pending->report( Change::templates() );
	$pending->deliver();

	njr_test_assert( [ [ 'failed' => true, 'code' => $expected ] ] === FailureWindow::outcomes(), "the transport’s own outcome names reach the window — `$expected`" );
}

// One request, one outcome
// ====

$pending = njr_test_subject();
$GLOBALS['njr_test_response'] = [ 'response' => [ 'code' => 500 ] ];
for ( $i = 0; $i < 50; $i++ ) $pending->report( Change::path( "/page-$i/" ) );
$pending->report( Change::templates() );
$pending->deliver();

njr_test_assert( [ [ 'failed' => true, 'code' => 'http_500' ] ] === FailureWindow::outcomes(), 'a failed request of 51 changes is one failure in the window' );
njr_test_assert( [] === $pending->pending(), 'a failure drops every change it carried' );
njr_test_assert(
	false !== strpos( njr_test_log(), '❌ Failed to revalidate 51 changes (path ×50, templates) — http_500: The front-end answered 500.' ),
	'the failure is logged with its outcome, the number of changes and their subjects'
);

$GLOBALS['njr_test_response'] = [ 'response' => [ 'code' => 200 ] ];
$pending->deliver();
njr_test_assert( 1 === count( $GLOBALS['njr_test_posts'] ), 'nothing a failed request carried is retried' );

$pending = njr_test_subject();
$pending->report( Change::post( 1, 'post', '/a/', '/a/' ) );
$pending->report( Change::post( 2, 'post', '/b/', '/b/' ) );
$pending->report( Change::templates() );
$pending->deliver();
njr_test_assert( [ [ 'failed' => false, 'code' => '' ] ] === FailureWindow::outcomes(), 'a successful request of three changes is one success in the window' );
njr_test_assert( false !== strpos( njr_test_log(), '✅ Revalidated 3 changes (post ×2, templates)' ), 'the success is logged with the number of changes and their subjects' );

// Redaction
// ====
//
// The secret travels in a header now, and a transport is free to quote a header
// back. What it says reaches the log, so the by-value pass is what keeps it out
// — through the real `Settings`, reading the real secret. The rule itself is
// `tests/transport-redaction-test.php`; ADR 0023.
$pending = njr_test_subject();
$GLOBALS['njr_test_response'] = new WP_Error( 'http_request_failed', 'refused: Authorization: Bearer s3cret' );
$pending->report( Change::templates() );
$pending->deliver();
njr_test_assert( false === strpos( njr_test_log(), 's3cret' ), 'a transport message quoting the Authorization header does not carry the secret into the log' );
njr_test_assert( false !== strpos( njr_test_log(), 'Bearer ***' ), 'and the rest of the message is still the diagnostic' );

njr_test_remove_logs();

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
