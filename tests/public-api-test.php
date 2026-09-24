<?php
/**
 * The public API, read through what it answers and what it reports —
 * `nextjs_revalidate_path()`, `nextjs_revalidate_schedule_path()`, and the two
 * v1 names that are now wrappers around them.
 *
 * Third-party code has no other way to find out what happened than these
 * functions' return values, so those are the contract (ADR 0010): whether a
 * change was *accepted* into the pending changes, never whether the front-end
 * took it. Pinned here:
 *
 *  - `nextjs_revalidate_path()` reports a **path** change and answers true on a
 *    configured site, and false on an unconfigured one, which refuses it;
 *  - a URL and the path it names are one `uri`, from the domain root;
 *  - `nextjs_revalidate_schedule_path()` registers a scheduled purge and reports
 *    nothing yet;
 *  - the v1 names delegate to the v2 ones, accept `$priority` and ignore it, and
 *    go through `_deprecated_function()` — which warns under `WP_DEBUG` and
 *    fires `deprecated_function_run` either way (ADR 0035).
 *
 * The functions are the real ones, read out of the plugin's main file with the
 * composition root behind them, over stubbed options: what is asserted is
 * what the real `PendingChanges` and `Settings` answer. That file returns
 * before it builds anything when `vendor/` is missing, but PHP declares its
 * top-level functions and classes when the file is compiled rather than when
 * the line is reached, so the functions are there either way — and the root
 * is then built by the first call, from the classes this file loads.
 *
 * `_deprecated_function()` is stubbed after core's: what it does is core's
 * contract, and the claim here is only that the wrappers reach it, naming
 * themselves, the version and the replacement.
 *
 * A standalone script rather than a PHPUnit test — see
 * `docs/adr/0008-two-testing-idioms.md`. The same contract against a real
 * WordPress is `tests/integration/PublicApiTest.php`.
 *
 * Run with `npm run test:php`, or `php tests/public-api-test.php`.
 */

if ( 'cli' !== PHP_SAPI ) die( 'This file must be run from the command line.' );

// The plugin files bail when this is not defined.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );

// Whether `_deprecated_function()` warns, as core decides it.
define( 'WP_DEBUG', true );

/**
 * The fixture site's option rows: option name => stored value.
 * @var array
 */
$GLOBALS['njr_test_options'] = [];

/**
 * Every `_deprecated_function()` call since the last reset, as
 * `[ function, version, replacement ]`.
 * @var array
 */
$GLOBALS['njr_test_deprecated'] = [];

/**
 * Every `deprecated_function_run` action since the last reset.
 * @var array
 */
$GLOBALS['njr_test_deprecated_run'] = [];

// WordPress stubs
// ====

function add_action( $name, $callback, $priority = 10, $accepted_args = 1 ) {}
function add_filter( $name, $callback, $priority = 10, $accepted_args = 1 ) {}
function apply_filters( $name, $value, ...$args ) { return $value; }
function did_action( $name ) { return 0; }

function do_action( $name, ...$args ) {
	if ( 'deprecated_function_run' === $name ) $GLOBALS['njr_test_deprecated_run'][] = $args;
}

function register_activation_hook( $file, $callback ) {}
function register_deactivation_hook( $file, $callback ) {}
function register_uninstall_hook( $file, $callback ) {}
function plugin_dir_url( $file ) { return 'https://site.test/wp-content/plugins/nextjs-revalidate/'; }

function get_file_data( $file, $fields, $context = '' ) {
	return array_map( function() { return ''; }, $fields );
}

function __( $text, $domain = null ) { return $text; }
function _e( $text, $domain = null ) { echo $text; }

function untrailingslashit( $string ) { return rtrim( $string, '/\\' ); }
function trailingslashit( $string ) { return rtrim( $string, '/\\' ) . '/'; }

function wp_parse_url( $url, $component = -1 ) {
	return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
}

function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

function get_current_blog_id() { return 1; }

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['njr_test_options'] ) ? $GLOBALS['njr_test_options'][ $name ] : $default;
}

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['njr_test_options'][ $name ] = $value;
	return true;
}

function wp_next_scheduled( $hook, $args = [] ) { return false; }
function wp_schedule_single_event( $timestamp, $hook, $args = [] ) { return true; }

/**
 * Core's `_deprecated_function()`, reduced to what it does: fire
 * `deprecated_function_run` whatever the site's settings, and raise an
 * `E_USER_DEPRECATED` naming the replacement only under `WP_DEBUG`.
 */
function _deprecated_function( $function_name, $version, $replacement = '' ) {
	$GLOBALS['njr_test_deprecated'][] = [ $function_name, $version, $replacement ];

	do_action( 'deprecated_function_run', $function_name, $replacement, $version );

	if ( WP_DEBUG ) {
		trigger_error(
			sprintf( 'Function %1$s is deprecated since version %2$s! Use %3$s instead.', $function_name, $version, $replacement ),
			E_USER_DEPRECATED
		);
	}
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

// The subject
// ====

// Every class the composition root builds, so it can be built without the
// composer autoloader too.
require_once __DIR__ . '/../include/Interfaces/Hookable.php';
require_once __DIR__ . '/../include/Abstracts/Base.php';
require_once __DIR__ . '/../include/Traits/AdminBarMenu.php';
require_once __DIR__ . '/../include/Traits/SendbackUrl.php';
require_once __DIR__ . '/../include/Traits/BlockEditorScreen.php';
require_once __DIR__ . '/../include/Traits/FrontEndRequest.php';
require_once __DIR__ . '/../include/Logger.php';
require_once __DIR__ . '/../include/I18n.php';
require_once __DIR__ . '/../include/Assets.php';
require_once __DIR__ . '/../include/Settings.php';
require_once __DIR__ . '/../include/FailureWindow.php';
require_once __DIR__ . '/../include/Change.php';
require_once __DIR__ . '/../include/PendingChanges.php';
require_once __DIR__ . '/../include/Revalidate.php';
require_once __DIR__ . '/../include/Probe.php';
require_once __DIR__ . '/../include/Cron/ScheduledPurges.php';
require_once __DIR__ . '/../include/RevalidateAll.php';
require_once __DIR__ . '/../include/FseSnapshot.php';
require_once __DIR__ . '/../include/RevalidateQueue.php';
require_once __DIR__ . '/../include/RestApi.php';
require_once __DIR__ . '/../include/Integrations/Redirection.php';

require_once __DIR__ . '/../nextjs-revalidate.php';

use NextJsRevalidate\Cron\ScheduledPurges;
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
 * The deprecation notices raised since the last reset.
 * @var string[]
 */
$GLOBALS['njr_test_notices'] = [];

set_error_handler( function ( $level, $message ) {
	if ( E_USER_DEPRECATED !== $level ) return false;

	$GLOBALS['njr_test_notices'][] = $message;
	return true;
} );

/**
 * Take the fixture site to a known state: configured or not, holding no
 * scheduled purge, and with no pending changes and no deprecation recorded.
 *
 * @param bool $configured
 * @return void
 */
function njr_test_site( $configured ) {
	$GLOBALS['njr_test_options'] = $configured
		? [ Settings::SETTINGS_DOMAIN_NAME => 'https://front-end.test', Settings::SETTINGS_SECRET_NAME => 's3cret' ]
		: [];

	$pending = new ReflectionProperty( \NextJsRevalidate\PendingChanges::class, 'pending' );
	$pending->setAccessible( true );
	$pending->setValue( NextJsRevalidate::init()->pendingChanges, [] );

	$GLOBALS['njr_test_deprecated']     = [];
	$GLOBALS['njr_test_deprecated_run'] = [];
	$GLOBALS['njr_test_notices']        = [];
}

/**
 * The changes the fixture site holds.
 *
 * @return array[]
 */
function njr_test_pending() {
	return NextJsRevalidate::init()->pendingChanges->pending();
}

/**
 * The path change a `uri` is reported as.
 *
 * @param string $uri
 * @return array
 */
function njr_test_path( $uri ) {
	return [ 'subject' => 'path', 'uri' => $uri ];
}

// nextjs_revalidate_path()
// ====

// A configured site accepts the change, holds it, and says so.
njr_test_site( true );
njr_test_assert( true === nextjs_revalidate_path( 'https://site.test/hello-world/' ), 'a configured site accepts a path, and answers true' );
njr_test_assert( [ njr_test_path( '/hello-world/' ) ] === njr_test_pending(), 'the path is held as a path change' );

// An unconfigured site refuses it: the answer is false, and nothing is held.
njr_test_site( false );
njr_test_assert( false === nextjs_revalidate_path( 'https://site.test/hello-world/' ), 'an unconfigured site refuses a path, and answers false rather than the refusal' );
njr_test_assert( [] === njr_test_pending(), 'a refused change is never held' );

// Half-configured is unconfigured.
njr_test_site( false );
$GLOBALS['njr_test_options'][ Settings::SETTINGS_SECRET_NAME ] = 's3cret';
njr_test_assert( false === nextjs_revalidate_path( '/hello-world/' ), 'a site holding a secret and no domain refuses too' );

// A URL and the path it names are one `uri`: the path from the domain root.
njr_test_site( true );
nextjs_revalidate_path( 'https://site.test/hello-world/' );
nextjs_revalidate_path( '/hello-world/' );
njr_test_assert( [ njr_test_path( '/hello-world/' ) ] === njr_test_pending(), 'a URL and its path are the same change, held once' );

njr_test_site( true );
nextjs_revalidate_path( 'https://site.test/blog/a-post/?utm_source=newsletter#comments' );
njr_test_assert( [ njr_test_path( '/blog/a-post/' ) ] === njr_test_pending(), 'a URL is reduced to its path, directory and all, without its query string or fragment' );

njr_test_site( true );
nextjs_revalidate_path( 'no-leading-slash' );
nextjs_revalidate_path( 'https://site.test' );
njr_test_assert( [ njr_test_path( '/no-leading-slash' ), njr_test_path( '/' ) ] === njr_test_pending(), 'a path is given its leading slash, and a bare domain is the root' );

njr_test_site( true );
njr_test_assert( false === nextjs_revalidate_path( '' ), 'an empty url names no path, and is not accepted' );
njr_test_assert( false === nextjs_revalidate_path( null ), 'nor is something that is not a string' );
njr_test_assert( [] === njr_test_pending(), 'and nothing is held for either' );

// nextjs_revalidate_schedule_path()
// ====

// Registering a scheduled purge reports nothing yet: the path is reported by
// the cron request that finds it due.
njr_test_site( true );
njr_test_assert( true === nextjs_revalidate_schedule_path( '2099-01-01 09:00:00', 'https://site.test/due-in-2099/' ), 'a scheduled purge is registered, and the answer says so' );
njr_test_assert(
	[ 'https://site.test/due-in-2099/' ] === array_merge( ...array_values( $GLOBALS['njr_test_options'][ ScheduledPurges::OPTION_NAME ] ?? [ [] ] ) ),
	'the url is registered as it was given'
);
njr_test_assert( [] === njr_test_pending(), 'and nothing is reported until it is due' );
njr_test_assert( false === nextjs_revalidate_schedule_path( '2099-01-01 09:00:00', 'https://site.test/due-in-2099/' ), 'the same url at the same date time registers nothing the second time' );

// The same registration on an unconfigured site: registering is not reporting,
// so there is nothing yet to refuse.
njr_test_site( false );
njr_test_assert( true === nextjs_revalidate_schedule_path( '2099-01-01 09:00:00', '/due-in-2099/' ), 'an unconfigured site still registers a scheduled purge' );

// The v1 names
// ====

// A wrapper answers what the function it wraps answers, and warns.
njr_test_site( true );
njr_test_assert( true === nextjs_revalidate_purge_url( 'https://site.test/v1-caller/' ), 'nextjs_revalidate_purge_url() answers what nextjs_revalidate_path() answers' );
njr_test_assert( [ njr_test_path( '/v1-caller/' ) ] === njr_test_pending(), 'and reports the same path change' );
njr_test_assert(
	[ [ 'nextjs_revalidate_purge_url', '2.0.0', 'nextjs_revalidate_path()' ] ] === $GLOBALS['njr_test_deprecated'],
	'it goes through _deprecated_function(), since 2.0.0, naming its replacement'
);
njr_test_assert( 1 === count( $GLOBALS['njr_test_deprecated_run'] ), 'which fires deprecated_function_run' );
njr_test_assert(
	1 === count( $GLOBALS['njr_test_notices'] ) && false !== strpos( $GLOBALS['njr_test_notices'][0], 'nextjs_revalidate_path()' ),
	'and, under WP_DEBUG, warns naming the replacement'
);

njr_test_site( false );
njr_test_assert( false === nextjs_revalidate_purge_url( 'https://site.test/v1-caller/' ), 'on an unconfigured site the wrapper answers false, as the v2 function does' );

// `$priority` is accepted and changes nothing: there is no queue to order.
njr_test_site( true );
nextjs_revalidate_purge_url( 'https://site.test/urgent/', 1 );
nextjs_revalidate_purge_url( 'https://site.test/ordinary/' );
njr_test_assert(
	[ njr_test_path( '/urgent/' ), njr_test_path( '/ordinary/' ) ] === njr_test_pending(),
	'a priority is accepted and ignored: the changes are held in the order they were reported'
);

njr_test_site( true );
njr_test_assert( true === nextjs_revalidate_schedule_purge_url( '2099-06-01 09:00:00', 'https://site.test/v1-schedule/' ), 'nextjs_revalidate_schedule_purge_url() answers what nextjs_revalidate_schedule_path() answers' );
njr_test_assert(
	in_array( 'https://site.test/v1-schedule/', array_merge( ...array_values( $GLOBALS['njr_test_options'][ ScheduledPurges::OPTION_NAME ] ?? [ [] ] ) ), true ),
	'and registers the same scheduled purge'
);
njr_test_assert(
	[ [ 'nextjs_revalidate_schedule_purge_url', '2.0.0', 'nextjs_revalidate_schedule_path()' ] ] === $GLOBALS['njr_test_deprecated'],
	'it goes through _deprecated_function(), since 2.0.0, naming its replacement'
);
njr_test_assert( 1 === count( $GLOBALS['njr_test_notices'] ), 'and warns under WP_DEBUG' );

// The v2 names are not deprecated.
njr_test_site( true );
nextjs_revalidate_path( '/v2-caller/' );
nextjs_revalidate_schedule_path( '2099-01-01 09:00:00', '/v2-caller/' );
njr_test_assert( [] === $GLOBALS['njr_test_deprecated'] && [] === $GLOBALS['njr_test_notices'], 'the v2 functions warn about nothing' );

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
