<?php
/**
 * Revalidatable taxonomy — Revalidate::should_revalidate_taxonomy() and
 * RevalidateAll::revalidatable_taxonomies().
 *
 * The plugin has no test framework and no WordPress to boot, so this is a
 * standalone script: it stubs the handful of WordPress functions the predicate
 * and the selector call, drives them through a fixture site of taxonomies, and
 * exits non-zero on the first failing expectation.
 *
 * The two directions #54 is about are both here, because the selector and the
 * gate have to agree: a `public` taxonomy that is not `publicly_queryable` is
 * selected by neither, and a `publicly_queryable` one that is not `public` is
 * selected by both.
 *
 * The selector's stub of `get_taxonomies()` still honours a `public` argument,
 * deliberately — it is what makes this fixture notice a selector that goes back
 * to asking for one. See ADR 0022 on why nothing may pre-filter the gate.
 *
 * Run with `npm run test:php`, or `php tests/revalidatable-taxonomy-test.php`.
 */

// The plugin files bail when this is not defined.
define( 'ABSPATH', __DIR__ . '/' );

/**
 * The fixture site's taxonomies. name => WP_Taxonomy
 * @var array
 */
$GLOBALS['njr_test_taxonomies'] = [];

/**
 * Registered filter callbacks. filter name => callable[]
 * @var array
 */
$GLOBALS['njr_test_filters'] = [];

// WordPress stubs
// ====

class WP_Error {
	public $code;

	public function __construct( $code = '' ) {
		$this->code = $code;
	}
}

class WP_Taxonomy {
	public $name;
	public $public;
	public $publicly_queryable;
	public $object_type;

	public function __construct( $name, $public, $publicly_queryable, array $object_type ) {
		$this->name               = $name;
		$this->public             = $public;
		$this->publicly_queryable = $publicly_queryable;
		$this->object_type        = $object_type;
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

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function get_taxonomy( $taxonomy ) {
	return $GLOBALS['njr_test_taxonomies'][ $taxonomy ] ?? false;
}

/**
 * Core's own selection, reduced to the two arguments that could reach it:
 * `object_type`, and the `public` the fix stopped passing.
 */
function get_taxonomies( $args = [], $output = 'names' ) {
	$names = [];

	foreach ( $GLOBALS['njr_test_taxonomies'] as $name => $taxonomy ) {
		if ( isset( $args['public'] ) && $taxonomy->public !== $args['public'] ) continue;

		if ( isset( $args['object_type'] ) && ! array_intersect( (array) $args['object_type'], $taxonomy->object_type ) ) continue;

		$names[ $name ] = $name;
	}

	return $names;
}

// A bare `publicly_queryable`, with none of the `_builtin && public` fallback
// `is_post_type_viewable()` applies — core's own asymmetry, and the whole of
// what #54 is about.
function is_taxonomy_viewable( $taxonomy ) {
	if ( is_string( $taxonomy ) ) $taxonomy = get_taxonomy( $taxonomy );

	return $taxonomy instanceof WP_Taxonomy ? $taxonomy->publicly_queryable : false;
}

// The plugin singleton, reduced to the one collaborator the selector asks it for.
class NextJsRevalidate {
	public $revalidate;

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

$GLOBALS['njr_test_taxonomies'] = [
	// The ordinary case: the two settings agree.
	'category'     => new WP_Taxonomy( 'category', true, true, [ 'post' ] ),

	// Public, and not queryable. Every one of its terms used to be enqueued.
	'editor_note'  => new WP_Taxonomy( 'editor_note', true, false, [ 'post' ] ),

	// Queryable, and not public. Its archive pages were missed entirely.
	'headless_tag' => new WP_Taxonomy( 'headless_tag', false, true, [ 'post' ] ),

	// Neither, and attached to another post type.
	'nav_menu'     => new WP_Taxonomy( 'nav_menu', false, false, [ 'nav_menu_item' ] ),

	// Queryable, on a post type of its own.
	'product_cat'  => new WP_Taxonomy( 'product_cat', true, true, [ 'product' ] ),
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

// `RevalidateAll` reaches the gate through the plugin singleton, the way every
// other collaborator in this plugin does.
NextJsRevalidate::init()->revalidate = $revalidate;

// The gate
// ====

$cases = [
	// [ description, expected, taxonomy ]
	[ 'a viewable taxonomy is revalidatable', true, 'category' ],
	[ 'a taxonomy that is public but not queryable is not revalidatable', false, 'editor_note' ],
	[ 'a taxonomy that is queryable but not public is revalidatable', true, 'headless_tag' ],
	[ 'a taxonomy that is neither is not revalidatable', false, 'nav_menu' ],
	[ 'a taxonomy nothing registered is not revalidatable', false, 'no_such_taxonomy' ],
];

foreach ( $cases as [ $description, $expected, $taxonomy ] ) {
	njr_test_expect( $description, $expected, $revalidate->should_revalidate_taxonomy( $taxonomy ) );
}

njr_test_expect(
	'the gate takes the taxonomy itself as readily as its name',
	true,
	$revalidate->should_revalidate_taxonomy( get_taxonomy( 'category' ) )
);

// The selection
// ====

njr_test_expect(
	'revalidate all selects the taxonomies whose archives the front-end holds',
	[ 'category' => 'category', 'headless_tag' => 'headless_tag', 'product_cat' => 'product_cat' ],
	$revalidate_all->revalidatable_taxonomies()
);

njr_test_expect(
	'a named post type narrows the selection to its own taxonomies',
	[ 'category' => 'category', 'headless_tag' => 'headless_tag' ],
	$revalidate_all->revalidatable_taxonomies( 'post' )
);

njr_test_expect(
	'a post type with no viewable taxonomy selects nothing',
	[],
	$revalidate_all->revalidatable_taxonomies( 'nav_menu_item' )
);

// The site has the last word
// ====

$received = [];

add_filter( 'nextjs_revalidate_should_revalidate_taxonomy', function( $should, $name, $taxonomy ) use ( &$received ) {
	$received[ $name ] = $taxonomy;

	if ( 'editor_note' === $name ) return true;  // a headless site admits its own taxonomies
	if ( 'category' === $name )    return false; // and may decline any other
	return $should;
}, 10, 3 );

njr_test_expect( 'the filter admits a taxonomy that is not viewable', true, $revalidate->should_revalidate_taxonomy( 'editor_note' ) );
njr_test_expect( 'the filter declines a viewable one', false, $revalidate->should_revalidate_taxonomy( 'category' ) );
njr_test_expect( 'the filter leaves the other taxonomies alone', true, $revalidate->should_revalidate_taxonomy( 'headless_tag' ) );

njr_test_expect(
	'the filter receives the taxonomy name',
	true,
	array_key_exists( 'editor_note', $received )
);

njr_test_expect(
	'the filter receives the taxonomy object alongside its name',
	$GLOBALS['njr_test_taxonomies']['editor_note'],
	$received['editor_note'] ?? null
);

njr_test_expect(
	'the filter is told when nothing is registered under the name',
	false,
	$revalidate->should_revalidate_taxonomy( 'no_such_taxonomy' )
);

// Nothing pre-filters the gate: a taxonomy the filter admits is selected, and
// one it declines is not. A selector that asked for `public` again would fail
// both of these — see ADR 0022.
njr_test_expect(
	'a taxonomy the filter admits is selected, whatever its settings say',
	true,
	in_array( 'editor_note', $revalidate_all->revalidatable_taxonomies( 'post' ), true )
);

njr_test_expect(
	'a taxonomy the filter declines is not selected',
	false,
	in_array( 'category', $revalidate_all->revalidatable_taxonomies( 'post' ), true )
);

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
