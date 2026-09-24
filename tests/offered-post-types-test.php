<?php
/**
 * Offered post type — Revalidate::offered_post_types(), and the two
 * selections that ask it.
 *
 * The plugin has no test framework and no WordPress to boot, so this is a
 * standalone script: it stubs the handful of WordPress functions the selection
 * and its callers reach for, drives them through a fixture site of post types,
 * and exits non-zero on the first failing expectation.
 *
 * The fixture holds both directions #53 is about, because an offer and the gate
 * have to agree about a type: one registered `public => true,
 * publicly_queryable => false`, which used to be offered a bulk action that
 * purged nothing and two switches that did nothing, and one registered the
 * other way round, which has a real front-end page and was offered none of it.
 *
 * The stub of `get_post_types()` still honours a `public` argument,
 * deliberately — it is what makes this fixture notice a selection that goes
 * back to asking for one.
 *
 * Run with `npm run test:php`, or `php tests/offered-post-types-test.php`.
 */

// The plugin files bail when this is not defined.
define( 'ABSPATH', __DIR__ . '/' );

/**
 * The fixture site's post types. name => WP_Post_Type
 * @var array
 */
$GLOBALS['njr_test_post_types'] = [];

/**
 * Registered filter callbacks. filter name => callable[]
 * @var array
 */
$GLOBALS['njr_test_filters'] = [];

// WordPress stubs
// ====

class WP_Post_Type {
	public $name;
	public $public;
	public $publicly_queryable;
	public $_builtin;
	public $labels;

	public function __construct( $name, $public, $publicly_queryable, $builtin = false ) {
		$this->name               = $name;
		$this->public             = $public;
		$this->publicly_queryable = $publicly_queryable;
		$this->_builtin           = $builtin;
		$this->labels             = (object) [ 'name' => ucfirst( $name ) ];
	}
}

class WP_Admin_Bar {
	public $nodes = [];

	public function add_menu( $node ) {
		$this->nodes[ $node['id'] ] = $node;
	}

	public function add_node( $node ) {
		$this->nodes[ $node['id'] ] = $node;
	}

	public function get_node( $id ) {
		return $this->nodes[ $id ] ?? null;
	}
}

function add_action( $name, $callback, $priority = 10, $accepted_args = 1 ) {}

function add_filter( $name, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['njr_test_filters'][ $name ][] = $callback;
}

function apply_filters( $name, $value, ...$args ) {
	foreach ( $GLOBALS['njr_test_filters'][ $name ] ?? [] as $callback ) {
		$value = call_user_func_array( $callback, array_merge( [ $value ], $args ) );
	}
	return $value;
}

function __( $text, $domain = null ) { return $text; }
function _x( $text, $context, $domain = null ) { return $text; }
function esc_url( $url ) { return $url; }
function add_query_arg( $args, $url = '' ) { return $url; }
function wp_nonce_url( $url, $action = -1 ) { return $url; }

function get_post_type_object( $post_type ) {
	return $GLOBALS['njr_test_post_types'][ $post_type ] ?? null;
}

/**
 * Core's own selection, reduced to the one argument that could reach it: the
 * `public` the fix stopped passing.
 */
function get_post_types( $args = [], $output = 'names' ) {
	$names = [];

	foreach ( $GLOBALS['njr_test_post_types'] as $name => $post_type ) {
		if ( isset( $args['public'] ) && $post_type->public !== $args['public'] ) continue;

		$names[ $name ] = $name;
	}

	return $names;
}

// `publicly_queryable`, or `public` for a type core registered itself — the
// fallback `is_taxonomy_viewable()` does not apply, and the reason this asks
// core rather than reading the property.
function is_post_type_viewable( $post_type ) {
	if ( is_string( $post_type ) ) $post_type = get_post_type_object( $post_type );

	if ( ! $post_type instanceof WP_Post_Type ) return false;

	return $post_type->publicly_queryable || ( $post_type->_builtin && $post_type->public );
}

// The plugin, reduced to the collaborators these selections ask it for.
// ====

class NextJsRevalidate_Test_Settings {
	public $allow_revalidate_all = [];

	public function is_configured() { return true; }
}

class NextJsRevalidate {
	public $revalidate;
	public $settings;

	private static $instance;

	public static function init() {
		if ( ! isset( self::$instance ) ) self::$instance = new static();
		return self::$instance;
	}

	private function __construct() {}
}

// The subject
// ====

require_once __DIR__ . '/../include/Interfaces/Hookable.php';
require_once __DIR__ . '/../include/Abstracts/Base.php';
require_once __DIR__ . '/../include/Traits/AdminBarMenu.php';
require_once __DIR__ . '/../include/Traits/BlockEditorScreen.php';
require_once __DIR__ . '/../include/Traits/FrontEndRequest.php';
require_once __DIR__ . '/../include/Traits/SendbackUrl.php';
require_once __DIR__ . '/../include/Revalidate.php';
require_once __DIR__ . '/../include/RevalidateAll.php';

// The fixture site
// ====

$GLOBALS['njr_test_post_types'] = [
	// The ordinary case: the two settings agree.
	'post'          => new WP_Post_Type( 'post', true, true, true ),
	'page'          => new WP_Post_Type( 'page', true, true, true ),

	// Viewable, and never offered: an uploaded file is not a Next.js route.
	'attachment'    => new WP_Post_Type( 'attachment', true, true, true ),

	// Public, and not queryable. Every action this plugin has was offered for
	// it, and the gate declines every one of its posts.
	'editor_note'   => new WP_Post_Type( 'editor_note', true, false ),

	// Queryable, and not public. It has a front-end page and was offered
	// nothing at all.
	'headless_doc'  => new WP_Post_Type( 'headless_doc', false, true ),

	// Neither.
	'nav_menu_item' => new WP_Post_Type( 'nav_menu_item', false, false, true ),

	// Not queryable, and viewable all the same: for a type core registered
	// itself, `public` stands in. What asking core rather than reading the
	// property is worth.
	'builtin_note'  => new WP_Post_Type( 'builtin_note', true, false, true ),
];

// The expectations
// ====

$failures = 0;

/**
 * @param string $description
 * @param mixed  $expected
 * @param mixed  $actual
 */
function njr_test_expect( $description, $expected, $actual ) {
	global $failures;

	if ( $actual === $expected ) {
		printf( "ok   — %s\n", $description );
		return;
	}

	$failures++;
	printf( "FAIL — %s (expected %s, got %s)\n", $description, var_export( $expected, true ), var_export( $actual, true ) );
}

$revalidate     = new NextJsRevalidate\Revalidate();
$revalidate_all = new NextJsRevalidate\RevalidateAll();

$settings = new NextJsRevalidate_Test_Settings();

NextJsRevalidate::init()->revalidate = $revalidate;
NextJsRevalidate::init()->settings   = $settings;

// What is offered
// ====

$offered = [
	'post'         => 'post',
	'page'         => 'page',
	'headless_doc' => 'headless_doc',
	'builtin_note' => 'builtin_note',
];

njr_test_expect(
	'the post types offered are the viewable ones, attachments aside',
	$offered,
	$revalidate->offered_post_types()
);

// The bulk action
// ====

$revalidate->register_bulk_actions();

$bulk_action_screens = [];
foreach ( array_keys( $GLOBALS['njr_test_filters'] ) as $filter ) {
	if ( 0 !== strpos( $filter, 'bulk_actions-edit-' ) ) continue;
	$bulk_action_screens[] = substr( $filter, strlen( 'bulk_actions-edit-' ) );
}

njr_test_expect(
	'the Purge caches bulk action is offered on the list screen of every offered post type',
	array_values( $offered ),
	$bulk_action_screens
);

// The admin bar entries, and the toggles behind them
// ====

$settings->allow_revalidate_all = [
	'post'         => 'on',
	'page'         => 'off',
	// Ticked while the settings page still offered it, and left in the option
	// afterwards — the row is the operator's, and the next save of that page
	// drops it.
	'editor_note'  => 'on',
	// Ticked for a post type nothing registers any more.
	'gone_for_good' => 'on',
	'all'          => 'on',
];

$admin_bar = new WP_Admin_Bar();
$revalidate_all->admin_top_bar_menu( $admin_bar );

njr_test_expect(
	'a revalidate all entry is offered for every ticked post type this plugin offers',
	[ 'nextjs-revalidate', 'nextjs-revalidate-all-post', 'nextjs-revalidate-all-all' ],
	array_keys( $admin_bar->nodes )
);

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
