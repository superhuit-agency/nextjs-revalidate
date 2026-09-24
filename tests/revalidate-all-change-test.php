<?php
/**
 * Revalidate all and a menu save, as producers of one change each —
 * NextJsRevalidate\RevalidateAll.
 *
 * Revalidate all reports one `all` change: bare for the whole site, and naming
 * the post type and its revalidatable taxonomies for one type. A menu save
 * reports one `menu` change naming the theme locations the menu is assigned
 * to. Neither names a page. What is worth pinning is what a reviewer cannot see
 * by reading the method: that the whole site's change carries no `type` at all,
 * that a type's `taxonomies` are exactly the ones the gate admits — its filter
 * included — that no post and no term is read however many the site holds, and
 * that an unconfigured site refuses both. What the request looks like is
 * `tests/pending-changes-test.php`'s business, and the gate's own verdicts are
 * `tests/revalidatable-taxonomy-test.php`'s.
 *
 * Driven through the real `PendingChanges`, `Settings`, `FailureWindow` and the
 * gate on `Revalidate`, with only the transport, the option store and the
 * site's taxonomies and menu locations stubbed.
 *
 * Reachable by stubbing a handful of WordPress functions, so it is a standalone
 * script rather than a PHPUnit test — see `docs/adr/0008-two-testing-idioms.md`.
 *
 * Run with `npm run test:php`, or `php tests/revalidate-all-change-test.php`.
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

/**
 * Registered filter callbacks. filter name => callable[]
 * @var array
 */
$GLOBALS['njr_test_filters'] = [];

/**
 * The fixture site's taxonomies, in registration order. name => WP_Taxonomy
 * @var array
 */
$GLOBALS['njr_test_taxonomies'] = [];

/**
 * The fixture theme's menu locations. location => menu ID
 * @var array
 */
$GLOBALS['njr_test_menu_locations'] = [];

/**
 * Every question about a post or a term the site was asked, as the function's
 * name. Revalidate all asks none: it names no page.
 * @var string[]
 */
$GLOBALS['njr_test_content_reads'] = [];

// WordPress stubs
// ====

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

class WP_Taxonomy {
	public $name;
	public $publicly_queryable;
	public $object_type;

	public function __construct( $name, $publicly_queryable, array $object_type ) {
		$this->name               = $name;
		$this->publicly_queryable = $publicly_queryable;
		$this->object_type        = $object_type;
	}
}

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

function get_taxonomy( $taxonomy ) {
	return $GLOBALS['njr_test_taxonomies'][ $taxonomy ] ?? false;
}

/**
 * Core's own selection, reduced to the one argument revalidate all passes.
 */
function get_taxonomies( $args = [], $output = 'names' ) {
	$names = [];

	foreach ( $GLOBALS['njr_test_taxonomies'] as $name => $taxonomy ) {
		if ( isset( $args['object_type'] ) && ! array_intersect( (array) $args['object_type'], $taxonomy->object_type ) ) continue;

		$names[ $name ] = $name;
	}

	return $names;
}

function is_taxonomy_viewable( $taxonomy ) {
	if ( is_string( $taxonomy ) ) $taxonomy = get_taxonomy( $taxonomy );

	return $taxonomy instanceof WP_Taxonomy ? $taxonomy->publicly_queryable : false;
}

function get_nav_menu_locations() {
	return $GLOBALS['njr_test_menu_locations'];
}

// What revalidate all read until v2, once per post and once per term. Stubbed
// so that reading one is recorded rather than fatal.
function get_posts( $args = [] )        { $GLOBALS['njr_test_content_reads'][] = 'get_posts';     return [ 1, 2, 3 ]; }
function get_terms( $args = [] )        { $GLOBALS['njr_test_content_reads'][] = 'get_terms';     return [ 7, 8 ]; }
function get_permalink( $post = 0 )     { $GLOBALS['njr_test_content_reads'][] = 'get_permalink'; return 'https://example.test/p/'; }
function get_term_link( $term )         { $GLOBALS['njr_test_content_reads'][] = 'get_term_link'; return 'https://example.test/t/'; }

class NextJsRevalidate {
	public $settings;
	public $revalidate;
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
require_once __DIR__ . '/../include/Traits/AdminBarMenu.php';
require_once __DIR__ . '/../include/Traits/BlockEditorScreen.php';
require_once __DIR__ . '/../include/Traits/FrontEndRequest.php';
require_once __DIR__ . '/../include/Traits/SendbackUrl.php';
require_once __DIR__ . '/../include/FailureWindow.php';
require_once __DIR__ . '/../include/Change.php';
require_once __DIR__ . '/../include/PendingChanges.php';
require_once __DIR__ . '/../include/Revalidate.php';
require_once __DIR__ . '/../include/RevalidateAll.php';

use NextJsRevalidate\PendingChanges;
use NextJsRevalidate\Revalidate;
use NextJsRevalidate\RevalidateAll;
use NextJsRevalidate\Settings;

NextJsRevalidate::init()->revalidate = new Revalidate();

// The fixture site
// ====

$GLOBALS['njr_test_taxonomies'] = [
	// Viewable, on `post`: the ordinary case.
	'category'      => new WP_Taxonomy( 'category', true, [ 'post' ] ),

	// Not viewable, on `post`, and admitted by the filter below — the
	// headless case the filter exists for.
	'headless_tag'  => new WP_Taxonomy( 'headless_tag', false, [ 'post' ] ),

	// Not viewable, on `post`, and left alone by the filter.
	'internal_note' => new WP_Taxonomy( 'internal_note', false, [ 'post' ] ),

	// Viewable, on `post`, and declined by the filter.
	'post_format'   => new WP_Taxonomy( 'post_format', true, [ 'post' ] ),

	// Viewable, and registered for another post type.
	'product_cat'   => new WP_Taxonomy( 'product_cat', true, [ 'product' ] ),
];

add_filter( 'nextjs_revalidate_should_revalidate_taxonomy', function( $should, $name ) {
	if ( 'headless_tag' === $name ) return true;
	if ( 'post_format' === $name )  return false;
	return $should;
} );

$GLOBALS['njr_test_menu_locations'] = [
	'primary' => 5,
	'social'  => 7,
	'footer'  => 5,
];

// The harness
// ====

$failures = 0;

function njr_test_expect( $description, $expected, $actual ) {
	global $failures;

	if ( $actual === $expected ) {
		printf( "ok   — %s\n", $description );
		return;
	}

	$failures++;
	printf( "FAIL — %s\n       expected %s, got %s\n", $description, var_export( $expected, true ), var_export( $actual, true ) );
}

/**
 * A fresh request on the fixture site: new pending changes, nothing sent.
 *
 * @param bool $configured Whether the site holds its domain and its secret.
 * @return RevalidateAll
 */
function njr_test_subject( $configured = true ) {
	$GLOBALS['njr_test_options'] = $configured
		? [ Settings::SETTINGS_DOMAIN_NAME => 'https://front-end.test', Settings::SETTINGS_SECRET_NAME => 's3cret' ]
		: [ Settings::SETTINGS_DOMAIN_NAME => 'https://front-end.test' ];
	$GLOBALS['njr_test_posts']         = [];
	$GLOBALS['njr_test_content_reads'] = [];

	NextJsRevalidate::init()->pendingChanges = new PendingChanges();

	return new RevalidateAll();
}

/**
 * The changes the current request holds.
 *
 * @return array[]
 */
function njr_test_pending() {
	return NextJsRevalidate::init()->pendingChanges->pending();
}

/**
 * End the fixture request, and answer the changes the n-th request carried,
 * as the front-end decodes them.
 *
 * @param int $n
 * @return array|null
 */
function njr_test_delivered( $n = 0 ) {
	NextJsRevalidate::init()->pendingChanges->deliver();

	$body = json_decode( (string) ( $GLOBALS['njr_test_posts'][ $n ][1]['body'] ?? '' ), true );

	return $body['changes'] ?? null;
}

// Revalidate all, of the whole site
// ====

$revalidate_all = njr_test_subject();
$answer = $revalidate_all->revalidate_all();

njr_test_expect( 'revalidate all of the whole site answers true', true, $answer );
njr_test_expect( 'and reports exactly one change, with no type and no taxonomies', [ [ 'subject' => 'all' ] ], njr_test_pending() );
njr_test_expect( 'having read no post and no term', [], $GLOBALS['njr_test_content_reads'] );
njr_test_expect( 'the front-end is told the same, with no type on the wire', [ [ 'subject' => 'all' ] ], njr_test_delivered() );
njr_test_expect( 'in one request', 1, count( $GLOBALS['njr_test_posts'] ) );

// Revalidate all, of one post type
// ====

$revalidate_all = njr_test_subject();
$answer = $revalidate_all->revalidate_all( 'post' );

njr_test_expect( 'revalidate all of one post type answers true', true, $answer );
njr_test_expect(
	'and reports one change naming the type and exactly the taxonomies the gate admits for it — the one admitted only by the filter included',
	[ [ 'subject' => 'all', 'type' => 'post', 'taxonomies' => [ 'category', 'headless_tag' ] ] ],
	njr_test_pending()
);
njr_test_expect( 'having read no post and no term', [], $GLOBALS['njr_test_content_reads'] );
njr_test_expect(
	'the taxonomies travel as a list, not as the map the selection returns',
	[ [ 'subject' => 'all', 'type' => 'post', 'taxonomies' => [ 'category', 'headless_tag' ] ] ],
	njr_test_delivered()
);

$revalidate_all = njr_test_subject();
$revalidate_all->revalidate_all( 'page' );

njr_test_expect(
	'a type with no revalidatable taxonomy names none, and still names its type',
	[ [ 'subject' => 'all', 'type' => 'page', 'taxonomies' => [] ] ],
	njr_test_pending()
);

// Pressing the same entry twice in one request is still one change.
$revalidate_all = njr_test_subject();
$revalidate_all->revalidate_all( 'post' );
$revalidate_all->revalidate_all( 'post' );
$revalidate_all->revalidate_all();

njr_test_expect( 'the same revalidate all twice is one change, and the whole site another', 2, count( njr_test_pending() ) );

// A menu save
// ====

$revalidate_all = njr_test_subject();
$revalidate_all->on_menu_update( 5 );

njr_test_expect(
	'saving a menu assigned to two locations reports one menu change naming both',
	[ [ 'subject' => 'menu', 'id' => 5, 'locations' => [ 'primary', 'footer' ] ] ],
	njr_test_pending()
);
njr_test_expect( 'having read no post and no term', [], $GLOBALS['njr_test_content_reads'] );
njr_test_expect(
	'and the front-end is told exactly that',
	[ [ 'subject' => 'menu', 'id' => 5, 'locations' => [ 'primary', 'footer' ] ] ],
	njr_test_delivered()
);

$revalidate_all = njr_test_subject();
$revalidate_all->on_menu_update( 9 );

njr_test_expect(
	'saving a menu assigned to no location reports it all the same, with none',
	[ [ 'subject' => 'menu', 'id' => 9, 'locations' => [] ] ],
	njr_test_pending()
);
njr_test_delivered();
njr_test_expect(
	'and its locations reach the front-end as an empty list, not an object',
	true,
	false !== strpos( (string) ( $GLOBALS['njr_test_posts'][0][1]['body'] ?? '' ), '{"subject":"menu","id":9,"locations":[]}' )
);

// WordPress hands the hook the menu's ID; nothing promises its type.
$revalidate_all = njr_test_subject();
$revalidate_all->on_menu_update( '7' );

njr_test_expect(
	'a menu ID handed over as a string is reported as the integer it is',
	[ [ 'subject' => 'menu', 'id' => 7, 'locations' => [ 'social' ] ] ],
	njr_test_pending()
);

// The menus screen can fire the hook more than once for one save.
$revalidate_all = njr_test_subject();
$revalidate_all->on_menu_update( 5 );
$revalidate_all->on_menu_update( 5 );

njr_test_expect( 'one menu saved twice in a request is one change', 1, count( njr_test_pending() ) );

// There is no setting any more: a site still holding the v1 switches, all
// off, reports its menu like any other.
$revalidate_all = njr_test_subject();
$GLOBALS['njr_test_options'][ Settings::LEGACY_REVALIDATE_ON_MENU_SAVE ] = [ 'post' => 'off', 'all' => 'off' ];
$revalidate_all->on_menu_update( 5 );

njr_test_expect( 'a leftover v1 menu setting, all off, no longer stops the change', 1, count( njr_test_pending() ) );

// An unconfigured site refuses both
// ====

foreach ( [ 'all', 'post' ] as $type ) {
	$revalidate_all = njr_test_subject( false );
	$answer = $revalidate_all->revalidate_all( $type );

	njr_test_expect( "an unconfigured site refuses revalidate all ($type), answering false", false, $answer );
	njr_test_expect( "and holds no change for it ($type)", [], njr_test_pending() );
	njr_test_delivered();
	njr_test_expect( "and asks the front-end nothing ($type)", [], $GLOBALS['njr_test_posts'] );
}

$revalidate_all = njr_test_subject( false );
$revalidate_all->on_menu_update( 5 );

njr_test_expect( 'an unconfigured site holds no menu change', [], njr_test_pending() );
njr_test_delivered();
njr_test_expect( 'and asks the front-end nothing', [], $GLOBALS['njr_test_posts'] );

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
