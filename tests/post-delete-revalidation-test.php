<?php
/**
 * A permanent delete enqueues one revalidation — Revalidate::on_post_delete().
 *
 * `wp_delete_post()` fires no save hook, so nothing used to be enqueued when a
 * post was deleted outright and the front-end went on serving its page
 * forever (#77). What this file pins is the handler's decisions: which posts
 * reaching `before_delete_post` produce a revalidation, and of which permalink.
 *
 * Reachable by stubbing a handful of WordPress functions, so it is a standalone
 * script rather than a PHPUnit test — see `docs/adr/0008-two-testing-idioms.md`.
 * What only the integration suite can see is a *real* permalink, composed from a
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
function remove_action( $name, $callback, $priority = 10 ) {}

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
function get_permalink( $post_id ) {
	$post = njr_test_post( $post_id );
	if ( ! $post ) return false;

	return $post['permalink'] ?? "https://site.test/$post_id/";
}

function wp_get_upload_dir() {
	return [ 'baseurl' => 'https://site.test/wp-content/uploads' ];
}

function wp_make_link_relative( $url ) {
	return (string) preg_replace( '|https?://[^/]+(/.*)|i', '$1', $url );
}

/**
 * The queue, recording what the handler enqueued instead of writing a table.
 */
class NextJsRevalidate_Test_Queue {

	/** @var string[] */
	public $added = [];

	public function add_item( $permalink, $priority = 10 ) {
		$this->added[] = $permalink;
		return true;
	}
}

/**
 * The composition root, which is how `Base::__get()` reaches the queue.
 */
class NextJsRevalidate {

	/** @var NextJsRevalidate_Test_Queue */
	public $queue;

	/** @var NextJsRevalidate|null */
	private static $instance = null;

	public static function init() {
		if ( is_null( self::$instance ) ) {
			self::$instance        = new self();
			self::$instance->queue = new NextJsRevalidate_Test_Queue();
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
require_once __DIR__ . '/../include/Revalidate.php';

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
$queue      = NextJsRevalidate::init()->queue;

$failures = 0;

/**
 * Delete one post and assert what it enqueued.
 *
 * @param string   $description What the expectation says.
 * @param string[] $expected    The permalinks the queue should then hold.
 * @param int      $post_id     The post to delete.
 *
 * @return void
 */
function njr_test_delete( $description, array $expected, $post_id ) {
	global $revalidate, $queue, $failures;

	$queue->added = [];

	$revalidate->on_post_delete( $post_id );

	if ( $queue->added === $expected ) {
		printf( "ok   — %s\n", $description );
		return;
	}

	$failures++;
	printf( "FAIL — %s (expected %s, got %s)\n", $description, json_encode( $expected ), json_encode( $queue->added ) );
}

// The gap #77 names: a published post deleted outright, with no trash step.
njr_test_delete( 'deleting a published post revalidates its permalink', [ 'https://site.test/1/' ], 1 );
njr_test_delete( 'deleting a private post revalidates its permalink', [ 'https://site.test/2/' ], 2 );

// A post already in the trash was revalidated when it was trashed, and its
// permalink by then names a path the front-end never held.
njr_test_delete( 'deleting a trashed post revalidates nothing', [], 4 );
njr_test_delete( 'deleting a draft revalidates nothing', [], 3 );

// The type axis gates a delete as it gates a save — ADR 0005.
njr_test_delete( 'deleting a post of a type that is not viewable revalidates nothing', [], 10 );

// A revision has no page of its own, and its post is deleted in its own right.
njr_test_delete( 'deleting a revision revalidates nothing', [], 20 );
njr_test_delete( 'deleting an autosave revalidates nothing', [], 21 );

// Neither is a file a page the front-end could rebuild.
njr_test_delete( 'deleting an attachment revalidates nothing', [], 30 );
njr_test_delete( 'deleting a post whose permalink is an uploaded file revalidates nothing', [], 31 );

njr_test_delete( 'deleting a post that does not exist revalidates nothing', [], 999 );

// The site has the last word.
// ====

add_filter( 'nextjs_revalidate_purge_should_revalidate_post_on_save', function( $should, $post_id ) {
	if ( 10 === $post_id ) return true;  // a headless site admits its own types
	if ( 1 === $post_id )  return false; // and may decline any post
	return $should;
}, 10, 2 );

njr_test_delete( 'the filter admits the delete of a post of a non viewable type', [ 'https://site.test/10/' ], 10 );
njr_test_delete( 'the filter declines the delete of a published post', [], 1 );

remove_all_filters( 'nextjs_revalidate_purge_should_revalidate_post_on_save' );

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
