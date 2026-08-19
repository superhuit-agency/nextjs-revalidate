<?php
/**
 * Revalidatable post — Revalidate::should_revalidate().
 *
 * The plugin has no test framework and no WordPress to boot, so this is a
 * standalone script: it stubs the handful of WordPress functions the predicate
 * calls, drives it through a fixture set of posts, and exits non-zero on the
 * first failing expectation.
 *
 * Run with `npm run test:php`, or `php tests/revalidatable-post-test.php`.
 */

// The plugin files bail when this is not defined.
define( 'ABSPATH', __DIR__ . '/' );

/**
 * The fixture site. post ID => [type, status, revision_of, autosave, parent]
 * @var array
 */
$GLOBALS['njr_test_posts'] = [];

/**
 * The post types the site would resolve a front-end query for.
 * @var array
 */
$GLOBALS['njr_test_viewable_types'] = [];

/**
 * Registered filter callbacks. filter name => callable[]
 * @var array
 */
$GLOBALS['njr_test_filters'] = [];

// WordPress stubs
// ====

function add_action( $name, $callback, $priority = 10, $accepted_args = 1 ) {}
function remove_action( $name, $callback, $priority = 10 ) {}

function add_filter( $name, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['njr_test_filters'][ $name ][] = $callback;
}

function apply_filters( $name, $value, ...$args ) {
	foreach ( $GLOBALS['njr_test_filters'][ $name ] ?? [] as $callback ) {
		$value = call_user_func_array( $callback, array_merge( [ $value ], $args ) );
	}
	return $value;
}

function njr_test_post( $post_id ) {
	return $GLOBALS['njr_test_posts'][ $post_id ] ?? null;
}

function get_post_type( $post_id ) {
	$post = njr_test_post( $post_id );
	return $post ? $post['type'] : false;
}

function get_post_status( $post_id ) {
	$post = njr_test_post( $post_id );
	if ( ! $post ) return false;

	// Core resolves an attachment's inherited status to its parent's, and to
	// publish when it has no parent.
	if ( 'attachment' === $post['type'] && 'inherit' === $post['status'] ) {
		return isset( $post['parent'] ) ? get_post_status( $post['parent'] ) : 'publish';
	}

	return $post['status'];
}

function is_post_type_viewable( $post_type ) {
	return in_array( $post_type, $GLOBALS['njr_test_viewable_types'], true );
}

function wp_is_post_revision( $post_id ) {
	$post = njr_test_post( $post_id );
	return ( $post && isset( $post['revision_of'] ) ? $post['revision_of'] : false );
}

function wp_is_post_autosave( $post_id ) {
	$post = njr_test_post( $post_id );
	return ( $post && ! empty( $post['autosave'] ) ? $post['revision_of'] : false );
}

class WP_Post {
	public $ID;
	public $post_status;

	public function __construct( $id, $status ) {
		$this->ID          = $id;
		$this->post_status = $status;
	}
}

// The subject
// ====

require_once __DIR__ . '/../include/abstracts/Base.php';
require_once __DIR__ . '/../include/traits/AdminBarMenu.php';
require_once __DIR__ . '/../include/traits/BlockEditorScreen.php';
require_once __DIR__ . '/../include/traits/SendbackUrl.php';
require_once __DIR__ . '/../include/Revalidate.php';

// The fixture site
// ====

$GLOBALS['njr_test_viewable_types'] = [ 'post', 'page', 'attachment' ];

$GLOBALS['njr_test_posts'] = [
	// A viewable type, one post per status.
	1  => [ 'type' => 'post', 'status' => 'publish' ],
	2  => [ 'type' => 'post', 'status' => 'private' ],
	3  => [ 'type' => 'post', 'status' => 'draft' ],
	4  => [ 'type' => 'post', 'status' => 'trash' ],
	5  => [ 'type' => 'post', 'status' => 'pending' ],

	// A type the front-end holds no page for — what #25 is about.
	10 => [ 'type' => 'acf-field-group', 'status' => 'publish' ],
	11 => [ 'type' => 'acf-field-group', 'status' => 'draft' ],

	// Revisions and autosaves.
	20 => [ 'type' => 'revision', 'status' => 'inherit', 'revision_of' => 1 ],
	21 => [ 'type' => 'revision', 'status' => 'inherit', 'revision_of' => 3 ],
	22 => [ 'type' => 'revision', 'status' => 'inherit', 'revision_of' => 10 ],
	23 => [ 'type' => 'revision', 'status' => 'inherit', 'revision_of' => 1, 'autosave' => true ],

	// An attachment inherits its status.
	30 => [ 'type' => 'attachment', 'status' => 'inherit' ],
	31 => [ 'type' => 'attachment', 'status' => 'inherit', 'parent' => 3 ],
];

$published = new WP_Post( 0, 'publish' );
$draft     = new WP_Post( 0, 'draft' );

// The expectations
// ====

$revalidate = new NextJsRevalidate\Revalidate();

$cases = [
	// [ description, expected, post_id, post_before ]
	[ 'a published post of a viewable type is revalidatable', true, 1, null ],
	[ 'a private post is revalidatable', true, 2, null ],
	[ 'a draft is not revalidatable', false, 3, null ],
	[ 'a trashed post is not revalidatable on its own', false, 4, null ],
	[ 'a pending post is not revalidatable', false, 5, null ],

	[ 'a published post of a non viewable type is not revalidatable', false, 10, null ],
	[ 'a draft of a non viewable type is not revalidatable', false, 11, null ],

	[ 'a post that just left publish for draft is revalidatable', true, 3, $published ],
	[ 'a post that just left publish for trash is revalidatable', true, 4, $published ],
	[ 'a post that left draft for pending is not revalidatable', false, 5, $draft ],
	[ 'the type axis gates the carve out too', false, 11, $published ],

	[ 'a revision of a published post is revalidatable', true, 20, null ],
	[ 'a revision of a draft is not revalidatable', false, 21, null ],
	[ 'a revision of a non viewable type is not revalidatable', false, 22, null ],
	[ 'an autosave is never revalidatable', false, 23, null ],

	[ 'an attachment without parent inherits publish', true, 30, null ],
	[ 'an attachment of a draft inherits draft', false, 31, null ],

	[ 'a post that does not exist is not revalidatable', false, 999, null ],
];

$failures = 0;
foreach ( $cases as [ $description, $expected, $post_id, $post_before ] ) {
	$actual = $revalidate->should_revalidate( $post_id, $post_before );

	if ( $actual === $expected ) {
		printf( "ok   — %s\n", $description );
	}
	else {
		$failures++;
		printf( "FAIL — %s (expected %s, got %s)\n", $description, var_export( $expected, true ), var_export( $actual, true ) );
	}
}

// The site has the last word.
// ====

add_filter( 'nextjs_revalidate_purge_should_revalidate_post_on_save', function( $should, $post_id ) {
	if ( 10 === $post_id ) return true;  // a headless site admits its own types
	if ( 1 === $post_id )  return false; // and may decline any post
	return $should;
}, 10, 2 );

$filtered_cases = [
	[ 'the filter admits a post of a non viewable type', true, 10, null ],
	[ 'the filter declines a published post', false, 1, null ],
	[ 'the filter leaves the other posts alone', true, 2, null ],
];

foreach ( $filtered_cases as [ $description, $expected, $post_id, $post_before ] ) {
	$actual = $revalidate->should_revalidate( $post_id, $post_before );

	if ( $actual === $expected ) {
		printf( "ok   — %s\n", $description );
	}
	else {
		$failures++;
		printf( "FAIL — %s (expected %s, got %s)\n", $description, var_export( $expected, true ), var_export( $actual, true ) );
	}
}

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
