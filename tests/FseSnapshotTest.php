<?php
/**
 * The FSE snapshot, as a producer of `templates` changes — NextJsRevalidate\FseSnapshot.
 *
 * Saving or deleting a template or a template part, or switching the theme,
 * reports one `templates` change into the pending changes, and the front-end
 * hears about it with every other change of the request, in the same request to
 * the same endpoint. What is worth pinning is what a reviewer cannot see by
 * reading the hooks: that however many of them fire, the front-end is told
 * once, with one `templates` change; that a menu or an ordinary post never
 * reaches it; and that an unconfigured site refuses. What the request looks
 * like is `tests/pending-changes-test.php`'s business.
 *
 * Driven through the real `PendingChanges`, `Settings` and `FailureWindow`,
 * with only the transport and the option store stubbed.
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
 * Every `wp_remote_post()` since the last reset, as `[ url, args ]`.
 * @var array
 */
$GLOBALS['njr_test_posts'] = [];

/**
 * The fixture site's option rows. option name => stored value.
 * @var array
 */
$GLOBALS['njr_test_options'] = [];

// WordPress stubs
// ====

function add_action( $name, $callback, $priority = 10, $accepted_args = 1 ) {}

function apply_filters( $name, $value, ...$args ) { return $value; }

function __( $text, $domain = null ) { return $text; }

function untrailingslashit( $string ) { return rtrim( $string, '/\\' ); }

function wp_json_encode( $data ) { return json_encode( $data ); }

function wp_remote_post( $url, $args = [] ) {
	$GLOBALS['njr_test_posts'][] = [ $url, $args ];

	return [ 'response' => [ 'code' => 204 ] ];
}

function wp_remote_retrieve_response_code( $response ) {
	return $response['response']['code'] ?? '';
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function get_current_blog_id() { return 1; }

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['njr_test_options'] ) ? $GLOBALS['njr_test_options'][ $name ] : $default;
}

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['njr_test_options'][ $name ] = $value;
	return true;
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

class NextJsRevalidate {
	public $settings;
	public $pendingChanges;

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
require_once __DIR__ . '/../include/FseSnapshot.php';

use NextJsRevalidate\FailureWindow;
use NextJsRevalidate\FseSnapshot;
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
 * A fresh request on a fixture site: new pending changes, nothing sent.
 *
 * @param array $options What the site holds. Configured by default.
 * @return FseSnapshot
 */
function njr_test_subject( array $options = [ Settings::SETTINGS_DOMAIN_NAME => 'https://front-end.test', Settings::SETTINGS_SECRET_NAME => 's3cret' ] ) {
	$GLOBALS['njr_test_options'] = $options;
	$GLOBALS['njr_test_posts']   = [];

	NextJsRevalidate::init()->pendingChanges = new PendingChanges();

	return new FseSnapshot();
}

/**
 * End the fixture request.
 *
 * @return void
 */
function njr_test_end_request() {
	NextJsRevalidate::init()->pendingChanges->deliver();
}

/**
 * The changes the n-th request carried.
 *
 * @param int $n
 * @return array|null
 */
function njr_test_changes( $n = 0 ) {
	$body = json_decode( (string) ( $GLOBALS['njr_test_posts'][ $n ][1]['body'] ?? '' ), true );

	return $body['changes'] ?? null;
}

// The cases
// ====

// Saving a template reports a change, and asks the front-end nothing until the
// request that changed the snapshot is over.
$fse = njr_test_subject();
$fse->on_template_save( 12 );
njr_test_assert( [ [ 'subject' => 'templates' ] ] === NextJsRevalidate::init()->pendingChanges->pending(), 'saving a template reports one templates change' );
njr_test_assert( [] === $GLOBALS['njr_test_posts'], 'the save itself asks the front-end nothing' );

njr_test_end_request();
njr_test_assert( 1 === count( $GLOBALS['njr_test_posts'] ), 'the end of the request sends it' );
njr_test_assert( 'https://front-end.test/api/revalidate' === ( $GLOBALS['njr_test_posts'][0][0] ?? null ), 'to the one endpoint every change goes to — there is no FSE endpoint any more' );
njr_test_assert( [ [ 'subject' => 'templates' ] ] === njr_test_changes(), 'carrying a templates change that names no template' );
njr_test_assert( 1 === count( FailureWindow::outcomes() ), 'and it is a revalidation like any other, entering the failure window' );

// The acceptance case: two templates saved and the theme switched in one
// request are one request, carrying one templates change.
$fse = njr_test_subject();
$fse->on_template_save( 12 );
$fse->on_template_save( 13 );
$fse->on_theme_switch();
njr_test_end_request();
njr_test_assert( 1 === count( $GLOBALS['njr_test_posts'] ), 'two template saves and a theme switch in one request send exactly one request' );
njr_test_assert( [ [ 'subject' => 'templates' ] ] === njr_test_changes(), 'carrying exactly one templates change' );

// A site-editor save can reach every one of these hooks.
$fse = njr_test_subject();
$fse->on_template_save( 12 );
$fse->on_post_delete( 14, new WP_Post( 14, 'wp_template_part' ) );
$fse->on_theme_switch();
njr_test_end_request();
njr_test_assert( [ [ 'subject' => 'templates' ] ] === njr_test_changes(), 'a save, a reset and a switch in one request are one templates change' );

// …and a second request is a second telling: nothing latches for the life of
// the process.
$fse->on_template_save( 15 );
njr_test_end_request();
njr_test_assert( 2 === count( $GLOBALS['njr_test_posts'] ), 'a later request tells the front-end again' );

// "Reset to theme default" deletes the post rather than saving it, and there is
// no `save_post` for that.
foreach ( [ 'wp_template', 'wp_template_part' ] as $post_type ) {
	$fse = njr_test_subject();
	$fse->on_post_delete( 21, new WP_Post( 21, $post_type ) );
	njr_test_end_request();
	njr_test_assert( [ [ 'subject' => 'templates' ] ] === njr_test_changes(), "deleting a $post_type reports a templates change" );
}

// Switching themes changes every template at once.
$fse = njr_test_subject();
$fse->on_theme_switch();
njr_test_end_request();
njr_test_assert( [ [ 'subject' => 'templates' ] ] === njr_test_changes(), 'switching themes reports a templates change' );

// Deleting anything else does not. A menu is the case worth naming: menu items
// are fetched at request time by the front-end and are absent from the snapshot
// by design.
foreach ( [ 'wp_navigation', 'nav_menu_item', 'post', 'page' ] as $post_type ) {
	$fse = njr_test_subject();
	$fse->on_post_delete( 31, new WP_Post( 31, $post_type ) );
	njr_test_end_request();
	njr_test_assert( [] === $GLOBALS['njr_test_posts'], "deleting a $post_type reports nothing" );
}

// On a WordPress whose `deleted_post` passes no post, the type cannot be read
// after the delete — and nothing is guessed.
$fse = njr_test_subject();
$fse->on_post_delete( 32 );
njr_test_end_request();
njr_test_assert( [] === $GLOBALS['njr_test_posts'], 'a delete whose type cannot be read reports nothing' );

// An unconfigured site refuses: it could not deliver, so it holds nothing and
// asks nothing.
$fse = njr_test_subject( [ Settings::SETTINGS_DOMAIN_NAME => 'https://front-end.test' ] );
$fse->on_template_save( 41 );
njr_test_assert( [] === NextJsRevalidate::init()->pendingChanges->pending(), 'an unconfigured site holds no templates change' );
njr_test_end_request();
njr_test_assert( [] === $GLOBALS['njr_test_posts'], 'and asks the front-end nothing at all' );

// There is no switch any more: a site still holding the v1 gate switched off
// reports its templates like any other, because the front-end serving the
// contract ignores a subject it does not cache (ADR 0034).
$fse = njr_test_subject( [
	Settings::SETTINGS_DOMAIN_NAME          => 'https://front-end.test',
	Settings::SETTINGS_SECRET_NAME          => 's3cret',
	Settings::LEGACY_REVALIDATE_ON_FSE_SAVE => '',
] );
$fse->on_template_save( 42 );
njr_test_end_request();
njr_test_assert( 1 === count( $GLOBALS['njr_test_posts'] ), 'a leftover v1 switch, off, no longer stops the change' );

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
