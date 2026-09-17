<?php
/**
 * Revalidatable term — Revalidate::should_revalidate_term(),
 * Revalidate::get_term_permalink() and RevalidateAll::revalidatable_taxonomies().
 *
 * The plugin has no test framework and no WordPress to boot, so this is a
 * standalone script: it stubs the handful of WordPress functions the predicate
 * and the selector call, drives them through a fixture site of taxonomies and
 * terms, and exits non-zero on the first failing expectation.
 *
 * The two directions #54 is about are both here, because the selector and the
 * gate have to agree: a `public` taxonomy that is not `publicly_queryable` is
 * revalidated by neither, and a `publicly_queryable` one that is not `public`
 * is revalidated by both.
 *
 * Run with `npm run test:php`, or `php tests/revalidatable-term-test.php`.
 */

// The plugin files bail when this is not defined.
define( 'ABSPATH', __DIR__ . '/' );

/**
 * The fixture site's taxonomies. name => [public, publicly_queryable, object_type]
 * @var array
 */
$GLOBALS['njr_test_taxonomies'] = [];

/**
 * The fixture site's terms. term ID => [taxonomy, slug]
 * @var array
 */
$GLOBALS['njr_test_terms'] = [];

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

function njr_test_term( $term ) {
	$term_id = is_object( $term ) ? $term->term_id : $term;

	return $GLOBALS['njr_test_terms'][ $term_id ] ?? null;
}

/**
 * Core's own selection, reduced to the two arguments this plugin passes it:
 * `object_type`, and the `public` the fix stopped passing. Honouring `public`
 * is deliberate — it is what makes this fixture notice a selector that goes
 * back to asking for it.
 */
function get_taxonomies( $args = [], $output = 'names' ) {
	$names = [];

	foreach ( $GLOBALS['njr_test_taxonomies'] as $name => $taxonomy ) {
		if ( isset( $args['public'] ) && $taxonomy['public'] !== $args['public'] ) continue;

		if ( isset( $args['object_type'] ) && ! array_intersect( (array) $args['object_type'], $taxonomy['object_type'] ) ) continue;

		$names[ $name ] = $name;
	}

	return $names;
}

// A bare `publicly_queryable`, with none of the `_builtin && public` fallback
// `is_post_type_viewable()` applies — core's own asymmetry, and the whole of
// what #54 is about.
function is_taxonomy_viewable( $taxonomy ) {
	$taxonomy = $GLOBALS['njr_test_taxonomies'][ $taxonomy ] ?? null;

	return $taxonomy ? $taxonomy['publicly_queryable'] : false;
}

function is_term_publicly_viewable( $term ) {
	$term = njr_test_term( $term );
	if ( ! $term ) return false;

	return is_taxonomy_viewable( $term['taxonomy'] );
}

function get_term_link( $term, $taxonomy = '' ) {
	$found = njr_test_term( $term );
	if ( ! $found ) return new WP_Error( 'invalid_term' );

	return sprintf( 'https://example.test/%s/%s/', $found['taxonomy'], $found['slug'] );
}

class WP_Term {
	public $term_id;

	public function __construct( $term_id ) {
		$this->term_id = $term_id;
	}
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
	'category'    => [ 'public' => true,  'publicly_queryable' => true,  'object_type' => [ 'post' ] ],

	// Public, and not queryable. Every one of its terms used to be enqueued.
	'editor_note' => [ 'public' => true,  'publicly_queryable' => false, 'object_type' => [ 'post' ] ],

	// Queryable, and not public. Its archive page was missed entirely.
	'headless_tag'=> [ 'public' => false, 'publicly_queryable' => true,  'object_type' => [ 'post' ] ],

	// Neither, and attached to another post type.
	'nav_menu'    => [ 'public' => false, 'publicly_queryable' => false, 'object_type' => [ 'nav_menu_item' ] ],

	// Queryable, on a post type of its own.
	'product_cat' => [ 'public' => true,  'publicly_queryable' => true,  'object_type' => [ 'product' ] ],
];

$GLOBALS['njr_test_terms'] = [
	1 => [ 'taxonomy' => 'category',     'slug' => 'news' ],
	2 => [ 'taxonomy' => 'editor_note',  'slug' => 'to-review' ],
	3 => [ 'taxonomy' => 'headless_tag', 'slug' => 'featured' ],
	4 => [ 'taxonomy' => 'nav_menu',     'slug' => 'main' ],
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

$revalidate    = new NextJsRevalidate\Revalidate();
$revalidate_all = new NextJsRevalidate\RevalidateAll();

// The gate
// ====

$cases = [
	// [ description, expected, term ]
	[ 'a term of a viewable taxonomy is revalidatable', true, 1 ],
	[ 'a term of a taxonomy that is public but not queryable is not revalidatable', false, 2 ],
	[ 'a term of a taxonomy that is queryable but not public is revalidatable', true, 3 ],
	[ 'a term of a taxonomy that is neither is not revalidatable', false, 4 ],
	[ 'a term that does not exist is not revalidatable', false, 999 ],
];

foreach ( $cases as [ $description, $expected, $term ] ) {
	njr_test_expect( $description, $expected, $revalidate->should_revalidate_term( $term ) );
}

njr_test_expect(
	'the gate takes the term itself as readily as its ID',
	true,
	$revalidate->should_revalidate_term( new WP_Term( 1 ) )
);

// The permalink
// ====

njr_test_expect(
	'a revalidatable term yields the permalink of its archive',
	'https://example.test/category/news/',
	$revalidate->get_term_permalink( 1 )
);

njr_test_expect(
	'a term that is not revalidatable yields no permalink',
	false,
	$revalidate->get_term_permalink( 2 )
);

njr_test_expect(
	'the gate can be waived, the way the post permalink waives its own',
	'https://example.test/editor_note/to-review/',
	$revalidate->get_term_permalink( 2, false )
);

njr_test_expect(
	'a term that has gone away yields no permalink rather than a WP_Error',
	false,
	$revalidate->get_term_permalink( 999, false )
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

// The site has the last word.
// ====

add_filter( 'nextjs_revalidate_should_revalidate_term', function( $should, $term ) {
	$term_id = is_object( $term ) ? $term->term_id : $term;

	if ( 2 === $term_id ) return true;  // a headless site admits its own taxonomies
	if ( 1 === $term_id ) return false; // and may decline any term
	return $should;
}, 10, 2 );

njr_test_expect( 'the filter admits a term of a non viewable taxonomy', true, $revalidate->should_revalidate_term( 2 ) );
njr_test_expect( 'the filter declines a term of a viewable one', false, $revalidate->should_revalidate_term( 1 ) );
njr_test_expect( 'the filter leaves the other terms alone', true, $revalidate->should_revalidate_term( 3 ) );

njr_test_expect(
	'a term the filter admits has a permalink to revalidate',
	'https://example.test/editor_note/to-review/',
	$revalidate->get_term_permalink( 2 )
);

njr_test_expect(
	'a term the filter declines has none',
	false,
	$revalidate->get_term_permalink( 1 )
);

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
