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
 * The script registry, as much of one as the editor notice needs:
 * handles registered by `Assets`, and what has been localized onto them.
 *
 * @var array
 */
$GLOBALS['njr_test_scripts'] = [ 'registered' => [], 'enqueued' => [], 'data' => [] ];

function wp_script_is( $handle, $list = 'enqueued' ) {
	return in_array( $handle, $GLOBALS['njr_test_scripts'][ $list ], true );
}

function wp_localize_script( $handle, $object_name, $l10n ) {
	$GLOBALS['njr_test_scripts']['data'][ $object_name ] = $l10n;
	return true;
}

function wp_enqueue_script( $handle ) {
	$GLOBALS['njr_test_scripts']['enqueued'][] = $handle;
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
require_once __DIR__ . '/../include/Assets.php';
require_once __DIR__ . '/../include/Settings.php';
require_once __DIR__ . '/../include/FailureWindow.php';

use NextJsRevalidate\Assets;
use NextJsRevalidate\FailureWindow;
use NextJsRevalidate\Settings;

NextJsRevalidate::init()->settings = new Settings();

/**
 * Test harness
 * ============
 */

$failures = 0;

/**
 * Start every case from a site that has never attempted anything, read by
 * nobody, on no screen in particular.
 */
function reset_site() {
	$GLOBALS['njr_test_options'] = [];
	$GLOBALS['njr_test_caps']    = [];
	$GLOBALS['njr_test_screen']  = null;
	$GLOBALS['njr_test_scripts'] = [ 'registered' => [], 'enqueued' => [], 'data' => [] ];

	$_GET = [];
}

/**
 * Let `Assets` have registered the block editor script, as it does on a site
 * whose assets have been built.
 */
function register_editor_script() {
	$GLOBALS['njr_test_scripts']['registered'][] = Assets::EDITOR_SCRIPT_HANDLE;
}

/**
 * Give the fixture site the two settings a revalidation cannot be delivered
 * without. The notice yields to the unconfigured one on a site without them.
 */
function configure_site() {
	$GLOBALS['njr_test_options'][ Settings::SETTINGS_URL_NAME ]    = 'https://front-end.test/api/revalidate';
	$GLOBALS['njr_test_options'][ Settings::SETTINGS_SECRET_NAME ] = 's3cret';
}

/**
 * Read the site as somebody holding the given capabilities.
 */
function read_as( array $capabilities ) {
	$GLOBALS['njr_test_caps'] = array_fill_keys( $capabilities, true );
}

/**
 * Put the reader on a block editor screen, or on an ordinary admin one.
 */
function on_block_editor_screen( $block_editor = true ) {
	$GLOBALS['njr_test_screen'] = new NJR_Test_Screen( $block_editor );
}

/**
 * A degraded site, configured, read by an administrator.
 */
function degraded_site() {
	reset_site();
	configure_site();
	read_as( ['manage_options', 'edit_posts'] );
	record_all( [ 'http_401', 'http_401', 'http_401' ] );
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

function assert_contains( $description, $needle, $haystack ) {
	global $failures;

	if ( is_string( $haystack ) && false !== strpos( $haystack, $needle ) ) {
		printf( "ok   — %s\n", $description );
		return;
	}

	$failures++;
	printf( "FAIL — %s (expected %s in %s)\n", $description, json_encode( $needle ), json_encode( $haystack ) );
}

/**
 * What `degraded_notice()` prints on the current screen.
 */
function rendered_notice() {
	$window = new FailureWindow();

	ob_start();
	$window->degraded_notice();

	return (string) ob_get_clean();
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

/**
 * The notice
 * ==========
 *
 * What a screen says, rather than the markup it says it in: the same answer
 * feeds the classic notice and the one the block editor renders.
 */

// A site that is not degraded has nothing to say, however configured it is and
// whoever is reading.
reset_site();
configure_site();
read_as( ['manage_options'] );
assert_same( 'a site that is not degraded says nothing', null, ( new FailureWindow() )->get_degraded_notice() );

// The notice yields to the unconfigured one: on a site missing a setting the
// window is evidence about a configuration that no longer exists.
reset_site();
read_as( ['manage_options'] );
record_all( [ 'http_401', 'http_401', 'http_401' ] );
assert_same( 'an unconfigured site says nothing, and yields to its own notice', null, ( new FailureWindow() )->get_degraded_notice() );

// But not on a block editor screen, where the unconfigured notice is hidden:
// yielding to a notice nobody can see leaves the reader told nothing at all.
reset_site();
read_as( ['manage_options'] );
on_block_editor_screen();
record_all( [ 'http_401', 'http_401', 'http_401' ] );
$notice = ( new FailureWindow() )->get_degraded_notice();
assert_same( 'an unconfigured site does not yield where the other notice is hidden', false, is_null( $notice ) );
assert_contains( 'and points at the settings screen the missing setting is on', 'page=' . Settings::PAGE_NAME, $notice['action_url'] );

// Whoever can do something about it is told what, and pointed at the settings.
degraded_site();
$notice = ( new FailureWindow() )->get_degraded_notice();
assert_contains( 'the notice counts the failures against the attempts', '3 of the last 3 revalidations failed', $notice['message'] );
assert_contains( 'the notice names the most recent cause', 'the front-end rejected the secret (http_401)', $notice['message'] );
assert_same( 'one cause is not reported as several', false, strpos( $notice['message'], 'Other errors' ) );
assert_contains( 'an administrator is pointed at the settings screen', 'page=' . Settings::PAGE_NAME, $notice['action_url'] );

// More than one cause is counted, never listed.
degraded_site();
record_all( [ 'unreachable' ] );
assert_contains( 'several causes are reported as several', 'Other errors occurred as well.', ( new FailureWindow() )->get_degraded_notice()['message'] );

// Nothing to link to from the screen the link leads to.
degraded_site();
$_GET['page'] = Settings::PAGE_NAME;
$notice = ( new FailureWindow() )->get_degraded_notice();
assert_same( 'the settings screen is not linked to itself', '', $notice['action_url'] );
assert_same( 'and offers no label either', '', $notice['action_label'] );

// The audience: whoever can fix it, and whoever's edits are being dropped.
degraded_site();
read_as( ['edit_posts'] );
$notice = ( new FailureWindow() )->get_degraded_notice();
assert_contains( 'an author is told, and sent to an administrator', 'Please contact a site administrator.', $notice['message'] );
assert_same( 'an author is offered no settings link', '', $notice['action_url'] );

degraded_site();
read_as( ['read'] );
assert_same( 'a reader who could do nothing about it is not bothered', null, ( new FailureWindow() )->get_degraded_notice() );

/**
 * Where it is rendered
 * ====================
 *
 * A block editor screen hides classic notices, and the post edit screen is
 * where the author whose saves are being dropped actually is. There the notice
 * travels through `core/notices` instead, and is printed exactly once — never
 * both ways, never neither.
 */

degraded_site();
on_block_editor_screen( false );
assert_contains( 'an ordinary admin screen prints the notice', 'nextjs-revalidate-degraded__notice', rendered_notice() );
assert_same( 'and hands the block editor nothing', null, ( new FailureWindow() )->get_block_editor_degraded_notice() );

degraded_site();
on_block_editor_screen();
assert_same( 'a block editor screen prints nothing', '', rendered_notice() );

$notice = ( new FailureWindow() )->get_block_editor_degraded_notice();
assert_same( 'and is handed the notice to dispatch instead', 'error', $notice['status'] );
assert_contains( 'carrying the same words', 'revalidations failed', $notice['message'] );
assert_same( 'and the settings link as an action', 1, count( $notice['actions'] ) );

// A screen with nothing to say says nothing there either.
reset_site();
configure_site();
read_as( ['manage_options'] );
on_block_editor_screen();
assert_same( 'a block editor screen on a healthy site is handed nothing', null, ( new FailureWindow() )->get_block_editor_degraded_notice() );

// The markup escapes what it prints.
degraded_site();
$_GET['page'] = Settings::PAGE_NAME;
assert_same( 'the printed notice closes its own markup', 1, substr_count( rendered_notice(), '</div>' ) );

// The notice reaches the editor screen on the script `Assets` registers, and
// nothing is localized onto a handle that was never registered — a site whose
// assets have never been built has no editor notice, not a fatal.
degraded_site();
on_block_editor_screen();
register_editor_script();
( new FailureWindow() )->enqueue_editor_notice();
assert_same( 'the editor script is enqueued to carry the notice', [ Assets::EDITOR_SCRIPT_HANDLE ], $GLOBALS['njr_test_scripts']['enqueued'] );
assert_contains(
	'the notice travels with it',
	'revalidations failed',
	$GLOBALS['njr_test_scripts']['data']['nextjs_revalidate_degraded_notice']['message']
);

degraded_site();
on_block_editor_screen();
( new FailureWindow() )->enqueue_editor_notice();
assert_same( 'an unregistered handle carries nothing', [], $GLOBALS['njr_test_scripts']['enqueued'] );
assert_same( 'and is localized nothing', [], $GLOBALS['njr_test_scripts']['data'] );

degraded_site();
on_block_editor_screen( false );
register_editor_script();
( new FailureWindow() )->enqueue_editor_notice();
assert_same( 'an ordinary admin screen enqueues nothing of its own', [], $GLOBALS['njr_test_scripts']['enqueued'] );

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
