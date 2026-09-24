<?php
/**
 * A deliberately unconfigured site can silence its notice —
 * Settings::shows_unconfigured_notice() and the two notices that consult it.
 *
 * #79 — the `nextjs_revalidate_show_unconfigured_notice` filter. The unconfigured
 * notice has a stand-in: on a block editor screen, where core hides
 * `admin_notices`, the degraded notice speaks in its place (ADR 0007). A filter
 * that silenced one and not the other would leave a site that asked for silence
 * being told it is unconfigured anyway, so both notices consult the one decision
 * in `Settings`, and this holds them to it.
 *
 * What the filter must *not* reach is here too: a configured site's degraded
 * notice. The refusal it must not reach either is
 * `tests/integration/RestApiTest.php`'s, against a real queue.
 *
 * Reachable by stubbing capabilities, options, the screen and the filter, so it
 * is a standalone script — ADR 0008's rule. It exits non-zero on any failing
 * expectation.
 *
 * Run with `npm run test:php`, or `php tests/unconfigured-notice-test.php`.
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

/**
 * What the fixture reader can do. capability => bool.
 *
 * @var array
 */
$GLOBALS['njr_test_caps'] = [];

/**
 * The screen being rendered, or null for no screen at all.
 *
 * @var NJR_Test_Screen|null
 */
$GLOBALS['njr_test_screen'] = null;

/**
 * The callbacks added to each filter. hook name => callbacks.
 *
 * @var array
 */
$GLOBALS['njr_test_filters'] = [];

/**
 * Every time a filter was applied: its name and everything it was handed.
 *
 * @var array
 */
$GLOBALS['njr_test_applied'] = [];

function add_action( $name, $callback, $priority = 10, $accepted_args = 1 ) {}

function add_filter( $hook_name, $callback ) {
	$GLOBALS['njr_test_filters'][ $hook_name ][] = $callback;
}

function apply_filters( $hook_name, $value, ...$args ) {
	$GLOBALS['njr_test_applied'][] = array_merge( [ $hook_name, $value ], $args );

	foreach ( $GLOBALS['njr_test_filters'][ $hook_name ] ?? [] as $callback ) {
		$value = $callback( $value, ...$args );
	}

	return $value;
}

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

function current_user_can( $capability ) {
	return ! empty( $GLOBALS['njr_test_caps'][ $capability ] );
}

function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
}

function __( $text, $domain = 'default' ) {
	return $text;
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function esc_html__( $text, $domain = 'default' ) {
	return esc_html( $text );
}

function esc_url( $url ) {
	return (string) $url;
}

/**
 * The screen `get_current_screen()` answers with, as much of one as the
 * block editor question needs.
 */
class NJR_Test_Screen {

	public $block_editor;

	public function __construct( $block_editor = false ) {
		$this->block_editor = $block_editor;
	}

	public function is_block_editor() {
		return $this->block_editor;
	}
}

function get_current_screen() {
	return $GLOBALS['njr_test_screen'];
}

/**
 * The composition root, as `Base::__get()` reaches it — the one property the
 * failure window asks it for is the settings.
 */
class NextJsRevalidate {

	public $settings;

	private static $instance;

	public static function init() {
		if ( ! isset( self::$instance ) ) self::$instance = new self();

		return self::$instance;
	}
}

/**
 * The subject
 * ===========
 */

require_once __DIR__ . '/../include/Interfaces/Hookable.php';
require_once __DIR__ . '/../include/Abstracts/Base.php';
require_once __DIR__ . '/../include/Traits/BlockEditorScreen.php';
require_once __DIR__ . '/../include/Settings.php';
require_once __DIR__ . '/../include/FailureWindow.php';

use NextJsRevalidate\FailureWindow;
use NextJsRevalidate\Settings;

NextJsRevalidate::init()->settings = new Settings();

const FILTER = 'nextjs_revalidate_show_unconfigured_notice';

/**
 * Test harness
 * ============
 */

$failures = 0;

/**
 * Start every case from a site holding no settings and no attempts, read by an
 * administrator on an ordinary admin screen, with nothing hooked to the filter.
 */
function reset_site() {
	$GLOBALS['njr_test_options'] = [];
	$GLOBALS['njr_test_caps']    = [ 'manage_options' => true, 'edit_posts' => true ];
	$GLOBALS['njr_test_screen']  = new NJR_Test_Screen( false );
	$GLOBALS['njr_test_filters'] = [];
	$GLOBALS['njr_test_applied'] = [];

	$_GET = [];
}

function configure_site() {
	$GLOBALS['njr_test_options'][ Settings::SETTINGS_DOMAIN_NAME ] = 'https://front-end.test';
	$GLOBALS['njr_test_options'][ Settings::SETTINGS_SECRET_NAME ] = 's3cret';
}

/**
 * Three failures, which is what degrades a site.
 */
function degrade_site() {
	foreach ( [ 'http_401', 'http_401', 'http_401' ] as $code ) {
		FailureWindow::record( new WP_Error( $code, 'test' ) );
	}
}

function read_as( array $capabilities ) {
	$GLOBALS['njr_test_caps'] = array_fill_keys( $capabilities, true );
}

function on_block_editor_screen( $block_editor = true ) {
	$GLOBALS['njr_test_screen'] = new NJR_Test_Screen( $block_editor );
}

/**
 * What a site that asked for silence hooks — an mu-plugin, say.
 */
function silence_the_notice() {
	add_filter( FILTER, '__return_false' );
}

function __return_false() {
	return false;
}

/**
 * The calls made to the filter so far, each as [ value, ...arguments ].
 */
function filter_calls() {
	return array_values( array_map(
		function ( $call ) { return array_slice( $call, 1 ); },
		array_filter( $GLOBALS['njr_test_applied'], function ( $call ) { return FILTER === $call[0]; } )
	) );
}

/**
 * What `unconfigured_notice()` prints on the current screen.
 */
function rendered_unconfigured_notice() {
	ob_start();
	NextJsRevalidate::init()->settings->unconfigured_notice();

	return (string) ob_get_clean();
}

/**
 * What `degraded_notice()` prints on the current screen.
 */
function rendered_degraded_notice() {
	ob_start();
	( new FailureWindow() )->degraded_notice();

	return (string) ob_get_clean();
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

function assert_contains( $description, $needle, $haystack ) {
	global $failures;

	if ( is_string( $haystack ) && false !== strpos( $haystack, $needle ) ) {
		printf( "ok   — %s\n", $description );
		return;
	}

	$failures++;
	printf( "FAIL — %s (expected %s in %s)\n", $description, json_encode( $needle ), json_encode( $haystack ) );
}

$settings = NextJsRevalidate::init()->settings;

/**
 * The shared decision
 * ===================
 */

// With nothing hooked, an unconfigured site shows its notice to whoever is in
// its audience — the loud default ADR 0015 argues for.
reset_site();
assert_same( 'an unconfigured site shows its notice to an administrator', true, $settings->shows_unconfigured_notice() );

reset_site();
read_as( ['edit_posts'] );
assert_same( 'and to an author, whose edits are being dropped', true, $settings->shows_unconfigured_notice() );

// The filter is asked last. A configured site, or a reader outside the
// audience, never reaches it — silencing the notice is one decision, not a
// way around the capability check.
reset_site();
configure_site();
assert_same( 'a configured site shows no unconfigured notice', false, $settings->shows_unconfigured_notice() );
assert_same( 'and never asks the filter', [], filter_calls() );

reset_site();
read_as( ['read'] );
assert_same( 'a reader outside the audience is shown nothing', false, $settings->shows_unconfigured_notice() );
assert_same( 'and the filter is never asked about them', [], filter_calls() );

// The filter is handed what `missing_settings()` answers.
reset_site();
$settings->shows_unconfigured_notice();
assert_same( 'the filter defaults to true, and receives the missing settings', [ [ true, [ 'domain', 'secret' ] ] ], filter_calls() );

reset_site();
$GLOBALS['njr_test_options'][ Settings::SETTINGS_DOMAIN_NAME ] = 'https://front-end.test';
$settings->shows_unconfigured_notice();
assert_same( 'a half-configured site hands it the one setting missing', [ [ true, [ 'secret' ] ] ], filter_calls() );

reset_site();
silence_the_notice();
assert_same( 'a site that filters it off shows no unconfigured notice', false, $settings->shows_unconfigured_notice() );

// The answer is a boolean, whatever a callback returns.
reset_site();
add_filter( FILTER, function () { return 0; } );
assert_same( 'a falsy answer reads as false', false, $settings->shows_unconfigured_notice() );

/**
 * The unconfigured notice
 * =======================
 */

reset_site();
assert_contains( 'with nothing hooked, the notice renders', 'nextjs-revalidate-unconfigured__notice', rendered_unconfigured_notice() );

reset_site();
read_as( ['edit_posts'] );
assert_contains( 'to an author too, sent to an administrator', 'Please contact a site administrator.', rendered_unconfigured_notice() );

foreach ( [
	'an administrator' => [ 'manage_options', 'edit_posts' ],
	'an author'        => [ 'edit_posts' ],
] as $reader => $capabilities ) {
	foreach ( [ 'an ordinary admin screen' => false, 'a block editor screen' => true ] as $screen => $block_editor ) {
		reset_site();
		read_as( $capabilities );
		on_block_editor_screen( $block_editor );
		silence_the_notice();
		assert_same( "filtered off, $reader on $screen is printed nothing", '', rendered_unconfigured_notice() );
	}
}

reset_site();
$_GET['page'] = Settings::PAGE_NAME;
silence_the_notice();
assert_same( 'filtered off, not even the settings screen prints it', '', rendered_unconfigured_notice() );

/**
 * Its stand-in on the block editor
 * ================================
 *
 * Core hides `admin_notices` on a block editor screen, so there an unconfigured
 * site whose window is degraded hears from the degraded notice instead. That
 * notice is the unconfigured one in another voice, and is silenced with it.
 */

reset_site();
degrade_site();
on_block_editor_screen();
assert_same( 'unhooked, the degraded notice still stands in on a block editor screen', false, is_null( ( new FailureWindow() )->get_block_editor_degraded_notice() ) );

reset_site();
degrade_site();
on_block_editor_screen();
silence_the_notice();
assert_same( 'filtered off, an unconfigured degraded site says nothing there', null, ( new FailureWindow() )->get_degraded_notice() );
assert_same( 'and hands the block editor nothing to dispatch', null, ( new FailureWindow() )->get_block_editor_degraded_notice() );

reset_site();
degrade_site();
read_as( ['edit_posts'] );
on_block_editor_screen();
silence_the_notice();
assert_same( 'nor to an author', null, ( new FailureWindow() )->get_block_editor_degraded_notice() );

// On an ordinary screen it yielded before the filter existed, and still does.
reset_site();
degrade_site();
silence_the_notice();
assert_same( 'filtered off, an ordinary admin screen prints no degraded notice either', '', rendered_degraded_notice() );

/**
 * A configured site
 * =================
 *
 * The filter is about being unconfigured. A configured site's degraded notice
 * is a different condition, and it is identical whatever the filter answers.
 */

foreach ( [ 'an ordinary admin screen' => false, 'a block editor screen' => true ] as $screen => $block_editor ) {
	reset_site();
	configure_site();
	degrade_site();
	on_block_editor_screen( $block_editor );
	$unhooked_notice  = ( new FailureWindow() )->get_degraded_notice();
	$unhooked_printed = rendered_degraded_notice();
	$unhooked_editor  = ( new FailureWindow() )->get_block_editor_degraded_notice();

	silence_the_notice();
	assert_same( "a configured degraded site on $screen says the same filtered off", $unhooked_notice, ( new FailureWindow() )->get_degraded_notice() );
	assert_same( 'prints the same', $unhooked_printed, rendered_degraded_notice() );
	assert_same( 'and hands the block editor the same', $unhooked_editor, ( new FailureWindow() )->get_block_editor_degraded_notice() );
	assert_same( 'without the filter ever being asked', [], filter_calls() );
}

reset_site();
configure_site();
degrade_site();
on_block_editor_screen();
silence_the_notice();
assert_same( 'and it does speak', false, is_null( ( new FailureWindow() )->get_block_editor_degraded_notice() ) );

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
