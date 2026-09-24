<?php
/**
 * Sanitising a setting on save — the callbacks `Settings::OPTIONS` declares,
 * and the secret read trimmed wherever it is used (#99).
 *
 * Every setting used to be stored exactly as `options.php` received it, so a
 * domain pasted in with a trailing space, or a secret copied out of a `.env`
 * with its line break, left a site that looked configured and failed every
 * revalidation with nothing on screen naming why.
 *
 * Reachable by stubbing a handful of WordPress functions, so it is a standalone
 * script rather than a PHPUnit test — see `docs/adr/0008-two-testing-idioms.md`.
 * The option functions below apply a registered callback the way core's do,
 * including the second pass `update_option()` makes through `add_option()` on a
 * site holding no row (core #21989).
 *
 * Run with `npm run test:php`, or `php tests/settings-sanitize-test.php`.
 */

if ( 'cli' !== PHP_SAPI ) die( 'This file must be run from the command line.' );

// The plugin files bail when this is not defined.
define( 'ABSPATH', __DIR__ . '/' );

/**
 * The fixture site's option rows. option name => stored value.
 * @var array
 */
$GLOBALS['njr_test_options'] = [];

/**
 * The sanitize callback `register_setting()` attached to each option.
 * @var callable[]
 */
$GLOBALS['njr_test_sanitizers'] = [];

/**
 * Every settings error added since the last reset, as [ setting, code ].
 * @var array[]
 */
$GLOBALS['njr_test_settings_errors'] = [];

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

$GLOBALS['njr_test_posts'] = [];

// WordPress stubs
// ====

function add_action( $name, $callback, $priority = 10, $accepted_args = 1 ) {}
function add_filter( $name, $callback, $priority = 10, $accepted_args = 1 ) {}
function apply_filters( $name, $value, ...$args ) { return $value; }
function __( $text, $domain = null ) { return $text; }

function register_setting( $group, $name, $args = [] ) {
	if ( isset( $args['sanitize_callback'] ) ) $GLOBALS['njr_test_sanitizers'][ $name ] = $args['sanitize_callback'];
}

function sanitize_option( $name, $value ) {
	$callback = $GLOBALS['njr_test_sanitizers'][ $name ] ?? null;

	return $callback ? call_user_func( $callback, $value ) : $value;
}

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['njr_test_options'] )
		? $GLOBALS['njr_test_options'][ $name ]
		: $default;
}

function add_option( $name, $value = '' ) {
	$value = sanitize_option( $name, $value );

	if ( array_key_exists( $name, $GLOBALS['njr_test_options'] ) ) return false;

	$GLOBALS['njr_test_options'][ $name ] = $value;
	return true;
}

function update_option( $name, $value ) {
	$value     = sanitize_option( $name, $value );
	$old_value = get_option( $name );

	if ( $value === $old_value ) return false;

	// No row yet: core hands the *sanitised* value to `add_option()`, which
	// sanitises it a second time.
	if ( false === $old_value ) return add_option( $name, $value );

	$GLOBALS['njr_test_options'][ $name ] = $value;
	return true;
}

function add_settings_error( $setting, $code, $message, $type = 'error' ) {
	$GLOBALS['njr_test_settings_errors'][] = [ $setting, $code ];
}

function get_settings_errors( $setting = '', $sanitize = false ) {
	return array_values( array_filter(
		$GLOBALS['njr_test_settings_errors'],
		function ( $error ) use ( $setting ) { return '' === $setting || $error[0] === $setting; }
	) );
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function untrailingslashit( $string ) {
	return rtrim( $string, '/\\' );
}

function wp_make_link_relative( $url ) {
	return (string) preg_replace( '|https?://[^/]+(/.*)|i', '$1', $url );
}

function add_query_arg( $args, $url ) {
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args );
}

function wp_remote_get( $url, $args = [] ) {
	$GLOBALS['njr_test_requests'][] = $url;

	return $GLOBALS['njr_test_response'];
}

/**
 * Every `wp_remote_post()` made since the last reset, as `[ url, args ]`.
 */
function wp_remote_post( $url, $args = [] ) {
	$GLOBALS['njr_test_posts'][] = [ $url, $args ];

	return $GLOBALS['njr_test_response'];
}

function wp_json_encode( $data ) { return json_encode( $data ); }

function get_current_blog_id() { return 1; }

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

class WP_REST_Request {
	private $params;

	public function __construct( array $params = [] ) {
		$this->params = $params;
	}

	public function get_param( $key ) {
		return $this->params[ $key ] ?? null;
	}
}

/**
 * The composition root, holding the real `Settings`: the secret's consumers
 * are pinned here against what the settings actually answer, not a double.
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
require_once __DIR__ . '/../include/Traits/AdminBarMenu.php';
require_once __DIR__ . '/../include/Traits/SendbackUrl.php';
require_once __DIR__ . '/../include/Traits/BlockEditorScreen.php';
require_once __DIR__ . '/../include/Traits/FrontEndRequest.php';
require_once __DIR__ . '/../include/Logger.php';
require_once __DIR__ . '/../include/Revalidate.php';
require_once __DIR__ . '/../include/FailureWindow.php';
require_once __DIR__ . '/../include/Change.php';
require_once __DIR__ . '/../include/PendingChanges.php';
require_once __DIR__ . '/../include/RevalidateQueue.php';
require_once __DIR__ . '/../include/RestApi.php';

use NextJsRevalidate\Settings;

const DOMAIN   = Settings::SETTINGS_DOMAIN_NAME;
const PATH_OPT = Settings::SETTINGS_ENDPOINT_PATH_NAME;
const SECRET   = Settings::SETTINGS_SECRET_NAME;
const SWITCHES = [
	Settings::SETTINGS_ALLOW_REVALIDATE_ALL_NAME,
	Settings::SETTINGS_REVALIDATE_ON_MENU_SAVE,
	Settings::SETTINGS_DEBUG,
];

$settings = NextJsRevalidate::init()->settings;
$settings->register_settings();

// The harness
// ====

$failures = 0;

function check_same( $expected, $actual, $description ) {
	global $failures;

	if ( $expected === $actual ) {
		printf( "ok   — %s\n", $description );
		return;
	}

	$failures++;
	printf( "FAIL — %s (expected %s, got %s)\n", $description, json_encode( $expected ), json_encode( $actual ) );
}

/** Put the fixture site in a known state, with no settings error pending. */
function site( array $options ) {
	$GLOBALS['njr_test_options']         = $options;
	$GLOBALS['njr_test_settings_errors'] = [];
	$GLOBALS['njr_test_requests']        = [];
}

/**
 * What a save of the settings screen stores for one option.
 *
 * Through `update_option()`, as `options.php` does, so a site holding no row
 * takes core's second pass through the callback.
 */
function save( $name, $value ) {
	update_option( $name, $value );

	return get_option( $name, "\0absent" );
}

// Registration
// ====

// One loop, and every setting in it carries a callback: a setting added to the
// table without one is stored exactly as typed, which is this bug over again.
check_same(
	[ DOMAIN, PATH_OPT, SECRET, Settings::SETTINGS_ALLOW_REVALIDATE_ALL_NAME, Settings::SETTINGS_REVALIDATE_ON_MENU_SAVE, Settings::SETTINGS_DEBUG ],
	array_keys( $GLOBALS['njr_test_sanitizers'] ),
	'all six settings are registered with a sanitize callback'
);

// The domain
// ====

foreach ( [
	[ ' https://example.com ',                       'https://example.com',               'a domain is trimmed' ],
	[ "https://example.com\n",                       'https://example.com',               'a trailing line break is trimmed' ],
	[ 'https://u:p@example.com:8080/sub?x=1#y',      'https://u:p@example.com:8080/sub',  'credentials, a port and a subdirectory are kept, and the query and fragment dropped' ],
	[ 'http://host.docker.internal:8083',            'http://host.docker.internal:8083',  'a plain http domain with a port is kept as typed' ],
	[ 'https://example.com/',                        'https://example.com/',              'a trailing slash is left to composition' ],
	[ 'HTTPS://Example.com',                         'HTTPS://Example.com',               'the scheme is matched whatever its case, and the value kept as typed' ],
	[ '   ',                                         '',                                  'a whitespace-only domain stores the empty value' ],
	[ '',                                            '',                                  'an empty domain stores the empty value' ],
	// What `options.php` saves for a field the form did not post.
	[ null,                                          '',                                  'a domain the form left out stores the empty value' ],
] as [ $input, $expected, $description ] ) {
	site( [ DOMAIN => 'https://before.test' ] );
	check_same( $expected, save( DOMAIN, $input ), $description );
	check_same( [], $GLOBALS['njr_test_settings_errors'], "$description, with no settings error" );
}

// Refused: the domain held before is kept, and the operator is told once.
foreach ( [
	[ 'example.com',       'a domain with no scheme' ],
	[ 'ftp://example.com', 'a domain whose scheme is not http or https' ],
	[ 'https://',          'a scheme with no host' ],
	[ 'https:///api',      'a scheme with an empty host' ],
	[ [ 'https://x.test' ], 'a value that is not a string' ],
] as [ $input, $what ] ) {
	site( [ DOMAIN => 'https://before.test' ] );
	check_same( 'https://before.test', save( DOMAIN, $input ), "$what keeps the domain held before" );
	check_same( [ [ DOMAIN, 'invalid_domain' ] ], $GLOBALS['njr_test_settings_errors'], "$what adds exactly one settings error, naming the domain" );
}

// A first entry that is refused leaves the site as unconfigured as it was — and
// still one error, although core runs the callback twice for a missing row.
site( [] );
check_same( '', save( DOMAIN, 'example.com' ), 'a refused first domain stores the empty value' );
check_same( [ [ DOMAIN, 'invalid_domain' ] ], $GLOBALS['njr_test_settings_errors'], 'a refused first domain adds exactly one settings error' );
check_same( [ 'domain', 'secret' ], $settings->missing_settings(), 'so the site stays unconfigured, and the notice says so' );

// However often the callback is run on one save.
site( [ DOMAIN => 'https://before.test' ] );
Settings::sanitize_domain( 'example.com' );
Settings::sanitize_domain( 'example.com' );
check_same( 1, count( $GLOBALS['njr_test_settings_errors'] ), 'running the domain callback twice on one bad value adds one error' );

// A valid first entry takes core's second pass and is unchanged by it.
site( [] );
check_same( 'https://example.com', save( DOMAIN, ' https://example.com?x=1 ' ), 'a first domain survives the second pass core makes through the callback' );

// The endpoint path
// ====

foreach ( [ PATH_OPT ] as $option ) {
	foreach ( [
		[ ' /api/revalidate?secret=x ', '/api/revalidate',  'a path is trimmed and its query dropped' ],
		[ '/api/revalidate#top',        '/api/revalidate',  'a path loses its fragment' ],
		[ 'api/revalidate/',            'api/revalidate/',  'a path is otherwise stored as typed — slashes are composition’s business' ],
		[ '   ',                        '',                 'a whitespace-only path stores the empty value' ],
		[ [ '/api' ],                   '',                 'a path that is not a string stores the empty value' ],
	] as [ $input, $expected, $description ] ) {
		site( [ $option => '' ] );
		check_same( $expected, save( $option, $input ), "$option: $description" );
	}
}

// The secret
// ====

foreach ( [
	[ "  my secret/+%\n", 'my secret/+%',      'a secret is trimmed' ],
	[ "a<b>&c\"d'e\\f",    "a<b>&c\"d'e\\f",   'nothing inside a secret is stripped' ],
	[ "in\tside",          "in\tside",         'whitespace inside a secret is kept' ],
	[ "\t \n",             '',                 'a whitespace-only secret stores the empty value' ],
	[ [ 's3cret' ],        '',                 'a secret that is not a string stores the empty value' ],
] as [ $input, $expected, $description ] ) {
	site( [ SECRET => 'before' ] );
	check_same( $expected, save( SECRET, $input ), $description );
}

// The switch sets
// ====

foreach ( SWITCHES as $option ) {
	site( [ $option => [] ] );
	check_same( [ 'post' => 'on', 3 => 'on' ], save( $option, [ 'post' => 'on', 'page' => 'yes', 3 => 'on' ] ), "$option keeps only the switches that are on" );

	site( [ $option => [ 'post' => 'on' ] ] );
	check_same( [], save( $option, 'on' ), "$option stores the empty value for a value that is not a set" );

	// A key is not checked against the post types registered now: one that
	// registers late, or only on some requests, keeps its switch.
	site( [ $option => [] ] );
	check_same( [ 'not_registered_yet' => 'on' ], save( $option, [ 'not_registered_yet' => 'on' ] ), "$option keeps a switch for a post type it cannot see" );
}

// Every callback answers its own output unchanged
// ====
//
// Core can run one twice on a single save, and a callback that moved its own
// output would store something different depending on whether the row existed.

foreach ( [
	[ 'sanitize_domain',        [ ' https://u:p@example.com:8080/sub?x=1#y ', 'https://example.com', '   ' ] ],
	[ 'sanitize_path',          [ ' /api/revalidate?secret=x ', '/a#b?c', '' ] ],
	[ 'sanitize_secret',        [ "  my secret/+%\n", '' ] ],
	[ 'sanitize_switch_set',    [ [ 'post' => 'on', 'page' => 'yes', 3 => 'on' ], 'on', [] ] ],
] as [ $callback, $inputs ] ) {
	foreach ( $inputs as $input ) {
		site( [] );
		$once  = call_user_func( [ Settings::class, $callback ], $input );
		$twice = call_user_func( [ Settings::class, $callback ], $once );
		check_same( $once, $twice, sprintf( '%s answers its own output for %s unchanged', $callback, json_encode( $input ) ) );
	}
}

// Seeding goes through the callbacks too, and stores what it always did
// ====

site( [] );
$settings->define_settings();
check_same( '', get_option( DOMAIN ), 'a new install is seeded with an empty domain' );
check_same( [], get_option( Settings::SETTINGS_DEBUG ), 'and an empty set of switches' );
check_same( [], $GLOBALS['njr_test_settings_errors'], 'seeding a new install adds no settings error' );

// The secret, read trimmed wherever it is used
// ====
//
// A row stored before saving trimmed it — written straight into the options
// table here, past every callback — must not keep failing.

site( [ DOMAIN => 'https://front-end.test', SECRET => "  s3cret\n" ] );

check_same( 's3cret', $settings->secret, 'a stored secret with surrounding whitespace reads trimmed' );

$revalidate = new NextJsRevalidate\Revalidate();
check_same(
	'https://front-end.test/api/revalidate?path=%2Fhello%2F&secret=s3cret',
	$revalidate->build_revalidate_uri( 'https://example.test/hello/' ),
	'the revalidate endpoint is sent the trimmed secret'
);

$pending = new NextJsRevalidate\PendingChanges();
$pending->report( NextJsRevalidate\Change::templates() );
$pending->deliver();
check_same(
	'Bearer s3cret',
	$GLOBALS['njr_test_posts'][0][1]['headers']['Authorization'] ?? null,
	'the pending changes are sent the trimmed secret'
);

$rest = new NextJsRevalidate\RestApi();
check_same( true,  $rest->check_permission( new WP_REST_Request( [ 'secret' => 's3cret' ] ) ), 'the REST routes accept a caller sending the trimmed secret' );
check_same( false, $rest->check_permission( new WP_REST_Request( [ 'secret' => "  s3cret\n" ] ) ), 'and do not accept the untrimmed one' );

// The redaction matches on the value the URL was built with. A message quoting
// the secret with no `secret=` arg around it is only caught by value.
$GLOBALS['njr_test_response'] = new WP_Error( 'http_request_failed', 'the front-end said s3cret was wrong' );
$outcome = $revalidate->purge( 'https://example.test/hello/' );
check_same( 'the front-end said *** was wrong', $outcome->get_error_message(), 'the transport redacts the trimmed secret out of what it hands back' );

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
