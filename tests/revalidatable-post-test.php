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

function has_filter( $name ) {
	return ! empty( $GLOBALS['njr_test_filters'][ $name ] );
}

function remove_all_filters( $name ) {
	unset( $GLOBALS['njr_test_filters'][ $name ] );
}

function apply_filters( $name, $value, ...$args ) {
	foreach ( $GLOBALS['njr_test_filters'][ $name ] ?? [] as $callback ) {
		$value = call_user_func_array( $callback, array_merge( [ $value ], $args ) );
	}
	return $value;
}

/**
 * As core has it: a hook nobody is on is passed over in silence, and one
 * somebody is on is run after `_deprecated_hook()` has said so.
 */
function apply_filters_deprecated( $name, $args, $version, $replacement = '', $message = '' ) {
	if ( ! has_filter( $name ) ) return $args[0];

	_deprecated_hook( $name, $version, $replacement, $message );

	return apply_filters( $name, ...$args );
}

/**
 * As core has it, less the `deprecated_hook_run` action and the filter that can
 * silence it: under `WP_DEBUG`, a deprecation notice naming the replacement.
 */
function _deprecated_hook( $hook, $version, $replacement = '', $message = '' ) {
	if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) return;

	trigger_error(
		sprintf( 'Hook %1$s is deprecated since version %2$s! Use %3$s instead.', $hook, $version, $replacement ) . ' ' . $message,
		E_USER_DEPRECATED
	);
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

require_once __DIR__ . '/../include/Interfaces/Hookable.php';
require_once __DIR__ . '/../include/Abstracts/Base.php';
require_once __DIR__ . '/../include/Traits/AdminBarMenu.php';
require_once __DIR__ . '/../include/Traits/BlockEditorScreen.php';
require_once __DIR__ . '/../include/Traits/FrontEndRequest.php';
require_once __DIR__ . '/../include/Traits/SendbackUrl.php';
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
	6  => [ 'type' => 'post', 'status' => 'future' ],

	// A status an editorial workflow plugin registered. The status axis knows
	// nothing about it, which is the point — see #78.
	7  => [ 'type' => 'post', 'status' => 'njr_awaiting_legal' ],

	// A type the front-end holds no page for — what #25 is about.
	10 => [ 'type' => 'acf-field-group', 'status' => 'publish' ],
	11 => [ 'type' => 'acf-field-group', 'status' => 'draft' ],
	12 => [ 'type' => 'acf-field-group', 'status' => 'pending' ],

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
$private   = new WP_Post( 0, 'private' );
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
	[ 'a scheduled post is not revalidatable', false, 6, null ],
	[ 'a post of a custom status is not revalidatable', false, 7, null ],

	[ 'a published post of a non viewable type is not revalidatable', false, 10, null ],
	[ 'a draft of a non viewable type is not revalidatable', false, 11, null ],

	// Leaving the front-end: the status axis admitted the post before the save
	// and does not admit it after. Every destination at once, rather than the
	// allowlist of draft and trash #78 found here.
	[ 'a post that just left publish for draft is revalidatable', true, 3, $published ],
	[ 'a post that just left publish for trash is revalidatable', true, 4, $published ],
	[ 'a post that just left publish for pending is revalidatable', true, 5, $published ],
	[ 'a post that just left publish for future is revalidatable', true, 6, $published ],
	[ 'a post that just left publish for a custom status is revalidatable', true, 7, $published ],
	[ 'a post that just left private for trash is revalidatable — private is on the front-end too', true, 4, $private ],
	[ 'a post that went publish → private is revalidatable by the status axis', true, 2, $published ],
	[ 'a post that left draft for pending is not revalidatable', false, 5, $draft ],
	[ 'the type axis gates the carve out too', false, 11, $published ],
	[ 'the type axis gates it for every destination, not just draft', false, 12, $published ],

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

/**
 * Run the given cases through the gate.
 *
 * @param array $cases [ description, expected, post_id, post_before ][]
 * @return void
 */
function njr_test_cases( array $cases ) {
	global $revalidate, $failures;

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
}

/**
 * A headless site admitting its own types, and declining one post.
 */
function njr_test_site_filter( $should, $post_id ) {
	if ( 10 === $post_id ) return true;  // a headless site admits its own types
	if ( 1 === $post_id )  return false; // and may decline any post
	return $should;
}

add_filter( 'nextjs_revalidate_should_revalidate_post', 'njr_test_site_filter', 10, 2 );

njr_test_cases( [
	[ 'the filter admits a post of a non viewable type', true, 10, null ],
	[ 'the filter declines a published post', false, 1, null ],
	[ 'the filter leaves the other posts alone', true, 2, null ],
] );

remove_all_filters( 'nextjs_revalidate_should_revalidate_post' );

// The v1 name, still applied — deprecated since 2.0.0
// ====

define( 'WP_DEBUG', true );

$notices = [];
set_error_handler( function( $level, $message ) use ( &$notices ) {
	$notices[] = $message;
	return true;
}, E_USER_DEPRECATED );

// Nobody on the old name, nothing said about it.
njr_test_cases( [
	[ 'with nothing on the v1 name, the gate answers as before', true, 1, null ],
] );

if ( [] === $notices ) {
	printf( "ok   — a site not hooking the v1 name hears no deprecation\n" );
}
else {
	$failures++;
	printf( "FAIL — a site not hooking the v1 name hears no deprecation (got %s)\n", json_encode( $notices ) );
}

add_filter( 'nextjs_revalidate_purge_should_revalidate_post_on_save', 'njr_test_site_filter', 10, 2 );

njr_test_cases( [
	[ 'a callback on the v1 name still admits a post of a non viewable type', true, 10, null ],
	[ 'a callback on the v1 name still declines a published post', false, 1, null ],
] );

$named = array_filter( $notices, function( $notice ) {
	return false !== strpos( $notice, 'nextjs_revalidate_purge_should_revalidate_post_on_save' )
		&& false !== strpos( $notice, 'Use nextjs_revalidate_should_revalidate_post instead' )
		&& false !== strpos( $notice, '2.0.0' );
} );

if ( count( $named ) === count( $notices ) && count( $notices ) > 0 ) {
	printf( "ok   — under WP_DEBUG, the v1 name raises a deprecation notice naming the new filter\n" );
}
else {
	$failures++;
	printf( "FAIL — under WP_DEBUG, the v1 name raises a deprecation notice naming the new filter (got %s)\n", json_encode( $notices ) );
}

// Both names hooked: the new one is applied last, so it has the last word.
add_filter( 'nextjs_revalidate_should_revalidate_post', function( $should, $post_id ) {
	return ( 1 === $post_id ) ? true : $should;
}, 10, 2 );

njr_test_cases( [
	[ 'the new name has the last word over the v1 name', true, 1, null ],
] );

restore_error_handler();

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
