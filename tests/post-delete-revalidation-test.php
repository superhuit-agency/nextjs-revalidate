<?php
/**
 * A permanent delete reports one change — Revalidate::on_post_delete().
 *
 * `wp_delete_post()` fires no save hook, so nothing used to be enqueued when a
 * post was deleted outright and the front-end went on serving its page
 * forever (#77). What this file pins is the handler's decisions: which posts
 * reaching `before_delete_post` produce a change, and what it says — the URI
 * the post has as it stands just before it is gone, and no `after`, since the
 * page is gone either way (ADR 0033).
 *
 * Reachable by stubbing a handful of WordPress functions, so it is a standalone
 * script rather than a PHPUnit test — see `docs/adr/0008-two-testing-idioms.md`.
 * What only the integration suite can see is a *real* URI, composed from a
 * row that still exists at the moment the delete starts:
 * `tests/integration/PostDeletionRevalidationTest.php` has that half.
 *
 * Run with `npm run test:php`, or `php tests/post-delete-revalidation-test.php`.
 */

if ( 'cli' !== PHP_SAPI ) die( 'This file must be run from the command line.' );

// The plugin files bail when this is not defined.
define( 'ABSPATH', __DIR__ . '/' );

/**
 * The fixture site. post ID => [type, status, revision_of, autosave]
 * @var array
 */
$GLOBALS['njr_test_posts'] = [];

/**
 * The post types the site would resolve a front-end query for.
 * @var array
 */
$GLOBALS['njr_test_viewable_types'] = [ 'post', 'page', 'attachment' ];

/**
 * Registered filter callbacks. filter name => callable[]
 * @var array
 */
$GLOBALS['njr_test_filters'] = [];

// WordPress stubs
// ====

function add_action( $name, $callback, $priority = 10, $accepted_args = 1 ) {}
function __( $text, $domain = null ) { return $text; }
function get_current_blog_id() { return 1; }
function __return_true() { return true; }

function add_filter( $name, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['njr_test_filters'][ $name ][] = $callback;
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

function apply_filters_deprecated( $name, $args, $version, $replacement = '', $message = '' ) {
	return apply_filters( $name, ...$args );
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
	return $post ? $post['status'] : false;
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

/**
 * The permalink of a fixture post, as the site would compose it while the post
 * is still there — which is the whole reason the handler runs before the row is
 * deleted rather than after.
 */
function get_permalink( $post ) {
	$post_id = ( $post instanceof WP_Post ) ? $post->ID : $post;

	$post = njr_test_post( $post_id );
	if ( ! $post ) return false;

	return $post['permalink'] ?? "https://site.test/$post_id/";
}

function get_post( $post_id ) {
	$post = njr_test_post( $post_id );

	return $post ? new WP_Post( $post_id, $post['type'], $post['status'] ) : null;
}

function wp_get_upload_dir() {
	return [ 'baseurl' => 'https://site.test/wp-content/uploads' ];
}

function wp_make_link_relative( $url ) {
	return (string) preg_replace( '|https?://[^/]+(/.*)|i', '$1', $url );
}

class WP_Post {
	public $ID;
	public $post_type;
	public $post_status;

	public function __construct( $id, $post_type, $post_status ) {
		$this->ID          = $id;
		$this->post_type   = $post_type;
		$this->post_status = $post_status;
	}
}

class WP_Error {}

/**
 * A configured site, and nothing else a report asks of the settings.
 */
class NextJsRevalidate_Test_Settings {
	public function is_configured() { return true; }
}

/**
 * The composition root, which is how `Base::__get()` reaches the pending
 * changes — the real ones, read back after each delete.
 */
class NextJsRevalidate {

	/** @var NextJsRevalidate_Test_Settings */
	public $settings;

	/** @var NextJsRevalidate\PendingChanges */
	public $pendingChanges;

	/** @var NextJsRevalidate|null */
	private static $instance = null;

	public static function init() {
		if ( is_null( self::$instance ) ) {
			self::$instance                 = new self();
			self::$instance->settings       = new NextJsRevalidate_Test_Settings();
			self::$instance->pendingChanges = new NextJsRevalidate\PendingChanges();
		}

		return self::$instance;
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
require_once __DIR__ . '/../include/Change.php';
require_once __DIR__ . '/../include/PendingChanges.php';
require_once __DIR__ . '/../include/Revalidate.php';

use NextJsRevalidate\Change;

// The fixture site
// ====

$GLOBALS['njr_test_posts'] = [
	// A viewable type, one post per status.
	1  => [ 'type' => 'post', 'status' => 'publish' ],
	2  => [ 'type' => 'post', 'status' => 'private' ],
	3  => [ 'type' => 'post', 'status' => 'draft' ],
	4  => [ 'type' => 'post', 'status' => 'trash', 'permalink' => 'https://site.test/a-post__trashed/' ],

	// A type the front-end holds no page for.
	10 => [ 'type' => 'acf-field-group', 'status' => 'publish' ],

	// Deleting a post deletes its revisions, each of which reaches the same
	// hook, and trimming to the revision limit deletes one on its own.
	20 => [ 'type' => 'revision', 'status' => 'inherit', 'revision_of' => 1 ],
	21 => [ 'type' => 'revision', 'status' => 'inherit', 'revision_of' => 1, 'autosave' => true ],

	// An attachment is a file rather than a page, and so is a post whose
	// permalink points into the uploads directory.
	30 => [ 'type' => 'attachment', 'status' => 'publish' ],
	31 => [ 'type' => 'post', 'status' => 'publish', 'permalink' => 'https://site.test/wp-content/uploads/2026/09/a-file.pdf' ],
];

// The expectations
// ====

$revalidate = new NextJsRevalidate\Revalidate();

$failures = 0;

/**
 * Delete one post, in a request of its own, and assert what it reported.
 *
 * @param string  $description What the expectation says.
 * @param array[] $expected    The changes then pending.
 * @param int     $post_id     The post to delete.
 *
 * @return void
 */
function njr_test_delete( $description, array $expected, $post_id ) {
	global $revalidate, $failures;

	$pending_changes = NextJsRevalidate::init()->pendingChanges;

	$pending = new ReflectionProperty( $pending_changes, 'pending' );
	$pending->setAccessible( true );
	$pending->setValue( $pending_changes, [] );

	$revalidate->on_post_delete( $post_id );

	$reported = $pending_changes->pending();

	if ( $reported === $expected ) {
		printf( "ok   — %s\n", $description );
		return;
	}

	$failures++;
	printf( "FAIL — %s (expected %s, got %s)\n", $description, json_encode( $expected ), json_encode( $reported ) );
}

// The gap #77 names: a published post deleted outright, with no trash step.
// The page is gone, so the change has a before and no after.
njr_test_delete( 'deleting a published post reports its URI before, and no after', [ Change::post( 1, 'post', '/1/', null ) ], 1 );
njr_test_delete( 'deleting a private post reports its URI before, and no after', [ Change::post( 2, 'post', '/2/', null ) ], 2 );

// A post already in the trash was reported gone when it was trashed, and its
// permalink by then names a path the front-end never held.
njr_test_delete( 'deleting a trashed post reports nothing', [], 4 );
njr_test_delete( 'deleting a draft reports nothing', [], 3 );

// The type axis gates a delete as it gates a save — ADR 0005.
njr_test_delete( 'deleting a post of a type that is not viewable reports nothing', [], 10 );

// A revision has no page of its own, and its post is deleted in its own right.
njr_test_delete( 'deleting a revision reports nothing', [], 20 );
njr_test_delete( 'deleting an autosave reports nothing', [], 21 );

// Neither is a file a page the front-end could rebuild.
njr_test_delete( 'deleting an attachment reports nothing', [], 30 );
njr_test_delete( 'deleting a post whose permalink is an uploaded file reports nothing', [], 31 );

njr_test_delete( 'deleting a post that does not exist reports nothing', [], 999 );

// The site has the last word.
// ====

add_filter( 'nextjs_revalidate_should_revalidate_post', function( $should, $post_id ) {
	if ( 10 === $post_id ) return true;  // a headless site admits its own types
	if ( 1 === $post_id )  return false; // and may decline any post
	return $should;
}, 10, 2 );

njr_test_delete( 'the filter admits the delete of a post of a non viewable type', [ Change::post( 10, 'acf-field-group', '/10/', null ) ], 10 );
njr_test_delete( 'the filter declines the delete of a published post', [], 1 );

// It admits a candidate, and a trashed post is on the front-end on neither
// side: a change with both sides null is never produced.
add_filter( 'nextjs_revalidate_should_revalidate_post', '__return_true' );
njr_test_delete( 'the filter admitting a trashed post still reports nothing', [], 4 );

remove_all_filters( 'nextjs_revalidate_should_revalidate_post' );

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
