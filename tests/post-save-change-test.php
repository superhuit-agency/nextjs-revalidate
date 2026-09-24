<?php
/**
 * What a save reports — `Revalidate::on_post_save()`, into the real
 * `PendingChanges`.
 *
 * A save reports a `post` change: the post as the front-end saw it before the
 * save and as it sees it after, `null` on a side where it had or has no page
 * (ADR 0033). The comparison is the front-end's to make, so what is pinned here
 * is only that each kind of save comes out as the two sides it should: an edit
 * as two equal ones, a slug change as two different URIs, a publish with no
 * `before`. Leaving the front-end, the other way a side goes `null`, has a file
 * of its own — `leaving-the-front-end-test.php`.
 *
 * The rest is what a request does with more than one save: a post saved three
 * times is one change, from the first `before` to the last `after`, and the
 * revision WordPress saves in the middle of its post's save must not take the
 * place of the `before` only the post's own save carries.
 *
 * The changes are read back from the real `PendingChanges`, so the merging they
 * go through is the one that ships. Its own rules are pinned in
 * `pending-changes-test.php`.
 *
 * Reachable by stubbing a handful of WordPress functions, so it is a standalone
 * script rather than a PHPUnit test — see `docs/adr/0008-two-testing-idioms.md`.
 *
 * Run with `npm run test:php`, or `php tests/post-save-change-test.php`.
 */

if ( 'cli' !== PHP_SAPI ) die( 'This file must be run from the command line.' );

// The plugin files bail when this is not defined.
define( 'ABSPATH', __DIR__ . '/' );

/**
 * The fixture site, as it stands right now: post ID => WP_Post.
 * @var array<int, WP_Post>
 */
$GLOBALS['njr_test_posts'] = [];

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
function __return_false() { return false; }

function add_filter( $name, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['njr_test_filters'][ $name ][] = $callback;
}

function has_filter( $name ) {
	return ! empty( $GLOBALS['njr_test_filters'][ $name ] );
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

/**
 * A copy, as WordPress hands out: what a test holds on to as the post before a
 * save does not change when the fixture moves on.
 */
function get_post( $post_id ) {
	return isset( $GLOBALS['njr_test_posts'][ $post_id ] ) ? clone $GLOBALS['njr_test_posts'][ $post_id ] : null;
}

function get_post_type( $post_id ) {
	$post = get_post( $post_id );
	return $post ? $post->post_type : false;
}

function get_post_status( $post_id ) {
	$post = get_post( $post_id );
	return $post ? $post->post_status : false;
}

function is_post_type_viewable( $post_type ) {
	return in_array( $post_type, [ 'post', 'page', 'attachment' ], true );
}

function wp_is_post_revision( $post_id ) {
	$post = get_post( $post_id );
	return ( $post && 'revision' === $post->post_type ) ? $post->post_parent : false;
}

function wp_is_post_autosave( $post_id ) { return false; }

/**
 * The permalink WordPress would compose for a post: its slug while it is on the
 * front-end, the query shape while it is not. Handed a `WP_Post`, it answers
 * for that object as it is, which is what makes the post before a save compose
 * the permalink it had then.
 */
function get_permalink( $post ) {
	if ( ! $post instanceof WP_Post ) $post = get_post( $post );
	if ( ! $post ) return false;

	if ( 'attachment' === $post->post_type ) return "https://example.test/wp-content/uploads/{$post->post_name}.pdf";

	return in_array( $post->post_status, [ 'publish', 'private' ], true )
		? "https://example.test/{$post->post_name}/"
		: "https://example.test/?p={$post->ID}";
}

function wp_get_upload_dir() {
	return [ 'baseurl' => 'https://example.test/wp-content/uploads' ];
}

function wp_make_link_relative( $url ) {
	return (string) preg_replace( '|^(https?:)?//[^/]+(/?.*)|i', '$2', (string) $url );
}

class WP_Post {
	public $ID;
	public $post_type;
	public $post_status;
	public $post_name;
	public $post_parent;

	public function __construct( $id, $post_type, $post_status, $post_name, $post_parent = 0 ) {
		$this->ID          = $id;
		$this->post_type   = $post_type;
		$this->post_status = $post_status;
		$this->post_name   = $post_name;
		$this->post_parent = $post_parent;
	}
}

class WP_Error {}

/**
 * A configured site, and nothing else a report asks of the settings.
 */
class NextJsRevalidate_Test_Settings {
	public function is_configured() { return true; }
}

class NextJsRevalidate {
	public $settings;
	public $pendingChanges;

	private static $instance;

	public static function init() {
		if ( ! isset( self::$instance ) ) self::$instance = new static();
		return self::$instance;
	}

	private function __construct() {
		$this->settings       = new NextJsRevalidate_Test_Settings();
		$this->pendingChanges = new NextJsRevalidate\PendingChanges();
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

use NextJsRevalidate\Revalidate;

// The harness
// ====

$failures = 0;

function njr_test_assert( $condition, $description, $actual = null ) {
	global $failures;

	if ( $condition ) {
		printf( "ok   — %s\n", $description );
		return;
	}

	$failures++;
	printf( "FAIL — %s%s\n", $description, is_null( $actual ) ? '' : ' (got ' . json_encode( $actual ) . ')' );
}

/**
 * A new request: nothing pending, a handler that has seen no save, and a site
 * holding only the given posts.
 *
 * @param WP_Post[] $posts
 * @return Revalidate
 */
function njr_test_request( array $posts = [] ) {
	$GLOBALS['njr_test_posts']   = [];
	$GLOBALS['njr_test_filters'] = [];

	foreach ( $posts as $post ) $GLOBALS['njr_test_posts'][ $post->ID ] = $post;

	$pending = new ReflectionProperty( NextJsRevalidate\PendingChanges::class, 'pending' );
	$pending->setAccessible( true );
	$pending->setValue( NextJsRevalidate::init()->pendingChanges, [] );

	return new Revalidate();
}

/**
 * Save a post into the given state, the way `wp_insert_post()` would: an
 * update fires `post_updated` first, and `wp_after_insert_post` last, with the
 * post as it was before.
 *
 * @param Revalidate    $revalidate
 * @param WP_Post       $post       The post as the save leaves it.
 * @param callable|null $during     What else happens between the two hooks —
 *                                  where WordPress saves a revision.
 * @return void
 */
function njr_test_save( Revalidate $revalidate, WP_Post $post, ?callable $during = null ) {
	$before = get_post( $post->ID );

	if ( $before ) $revalidate->on_post_updated( $post->ID );

	$GLOBALS['njr_test_posts'][ $post->ID ] = $post;

	if ( $during ) $during();

	$revalidate->on_post_save( $post->ID, get_post( $post->ID ), ! is_null( $before ), $before );
}

/**
 * The changes the request holds.
 *
 * @return array[]
 */
function njr_test_pending() {
	return NextJsRevalidate::init()->pendingChanges->pending();
}

function njr_test_change( $id, $before, $after, $type = 'post' ) {
	return NextJsRevalidate\Change::post( $id, $type, $before, $after );
}

// The cases
// ====

// An edit: the page is where it was, and it changed.
$revalidate = njr_test_request( [ new WP_Post( 42, 'post', 'publish', 'hello' ) ] );
njr_test_save( $revalidate, new WP_Post( 42, 'post', 'publish', 'hello' ) );
njr_test_assert(
	[ njr_test_change( 42, '/hello/', '/hello/' ) ] === njr_test_pending(),
	'an edit of a published post reports two equal sides',
	njr_test_pending()
);

$revalidate = njr_test_request( [ new WP_Post( 43, 'page', 'private', 'secret' ) ] );
njr_test_save( $revalidate, new WP_Post( 43, 'page', 'private', 'secret' ) );
njr_test_assert(
	[ njr_test_change( 43, '/secret/', '/secret/', 'page' ) ] === njr_test_pending(),
	'an edit of a private page reports two equal sides, and the page type',
	njr_test_pending()
);

// A slug change: v1 revalidated only the new permalink, and the old path stayed
// cached. The `before` is what reaches it now.
$revalidate = njr_test_request( [ new WP_Post( 42, 'post', 'publish', 'hello' ) ] );
njr_test_save( $revalidate, new WP_Post( 42, 'post', 'publish', 'hello-world' ) );
njr_test_assert(
	[ njr_test_change( 42, '/hello/', '/hello-world/' ) ] === njr_test_pending(),
	'a slug change reports the URI before and the URI after',
	njr_test_pending()
);

// A publish: there was no page before.
$revalidate = njr_test_request( [ new WP_Post( 42, 'post', 'draft', 'hello' ) ] );
njr_test_save( $revalidate, new WP_Post( 42, 'post', 'publish', 'hello' ) );
njr_test_assert(
	[ njr_test_change( 42, null, '/hello/' ) ] === njr_test_pending(),
	'publishing a draft reports no before',
	njr_test_pending()
);

$revalidate = njr_test_request();
njr_test_save( $revalidate, new WP_Post( 44, 'post', 'publish', 'straight-out' ) );
njr_test_assert(
	[ njr_test_change( 44, null, '/straight-out/' ) ] === njr_test_pending(),
	'a post inserted published reports no before',
	njr_test_pending()
);

// A draft saved as a draft was never a candidate.
$revalidate = njr_test_request( [ new WP_Post( 42, 'post', 'draft', 'hello' ) ] );
njr_test_save( $revalidate, new WP_Post( 42, 'post', 'draft', 'hello' ) );
njr_test_assert( [] === njr_test_pending(), 'saving a draft as a draft reports nothing', njr_test_pending() );

// Three saves, one change: the first before, the last after.
$revalidate = njr_test_request( [ new WP_Post( 42, 'post', 'publish', 'first' ) ] );
njr_test_save( $revalidate, new WP_Post( 42, 'post', 'publish', 'second' ) );
njr_test_save( $revalidate, new WP_Post( 42, 'post', 'publish', 'third' ) );
njr_test_save( $revalidate, new WP_Post( 42, 'post', 'publish', 'fourth' ) );
njr_test_assert(
	[ njr_test_change( 42, '/first/', '/fourth/' ) ] === njr_test_pending(),
	'saving a post three times in one request reports one change, from the first before to the last after',
	njr_test_pending()
);

// And every save is heard: v1 removed its own hook after the first one, which a
// merge would turn into the wrong after.
$revalidate = njr_test_request( [ new WP_Post( 42, 'post', 'draft', 'hello' ) ] );
njr_test_save( $revalidate, new WP_Post( 42, 'post', 'publish', 'hello' ) );
njr_test_save( $revalidate, new WP_Post( 42, 'post', 'draft', 'hello' ) );
njr_test_assert(
	[] === njr_test_pending(),
	'a draft published and unpublished again in one request reports nothing',
	njr_test_pending()
);

// Revisions
// ====

// WordPress saves a revision in the middle of its post's save, before the
// post's own `wp_after_insert_post`. It must not stand first: it knows the post
// only as it now is.
$revalidate = njr_test_request( [ new WP_Post( 42, 'post', 'draft', 'hello' ) ] );
njr_test_save( $revalidate, new WP_Post( 42, 'post', 'publish', 'hello' ), function() use ( $revalidate ) {
	njr_test_save( $revalidate, new WP_Post( 90, 'revision', 'inherit', '42-revision-v1', 42 ) );
} );
njr_test_assert(
	[ njr_test_change( 42, null, '/hello/' ) ] === njr_test_pending(),
	'a revision saved during its post\'s publish leaves the publish its missing before',
	njr_test_pending()
);

$revalidate = njr_test_request( [ new WP_Post( 42, 'post', 'publish', 'hello' ) ] );
njr_test_save( $revalidate, new WP_Post( 42, 'post', 'publish', 'hello-world' ), function() use ( $revalidate ) {
	njr_test_save( $revalidate, new WP_Post( 90, 'revision', 'inherit', '42-revision-v1', 42 ) );
} );
njr_test_assert(
	[ njr_test_change( 42, '/hello/', '/hello-world/' ) ] === njr_test_pending(),
	'a revision saved during its post\'s slug change leaves the old URI as the before',
	njr_test_pending()
);

// A revision saved on its own stands for its post, as the post is.
$revalidate = njr_test_request( [ new WP_Post( 42, 'post', 'publish', 'hello' ) ] );
njr_test_save( $revalidate, new WP_Post( 91, 'revision', 'inherit', '42-revision-v2', 42 ) );
njr_test_assert(
	[ njr_test_change( 42, '/hello/', '/hello/' ) ] === njr_test_pending(),
	'a revision saved on its own reports its post, on both sides',
	njr_test_pending()
);

// Once its post's save is over, a revision is on its own again.
$revalidate = njr_test_request( [ new WP_Post( 42, 'post', 'publish', 'hello' ) ] );
njr_test_save( $revalidate, new WP_Post( 42, 'post', 'publish', 'hello' ) );
njr_test_save( $revalidate, new WP_Post( 91, 'revision', 'inherit', '42-revision-v2', 42 ) );
njr_test_assert(
	[ njr_test_change( 42, '/hello/', '/hello/' ) ] === njr_test_pending(),
	'a revision saved after its post\'s save merges into it',
	njr_test_pending()
);

$revalidate = njr_test_request( [ new WP_Post( 42, 'post', 'draft', 'hello' ) ] );
njr_test_save( $revalidate, new WP_Post( 91, 'revision', 'inherit', '42-revision-v2', 42 ) );
njr_test_assert( [] === njr_test_pending(), 'a revision of a draft reports nothing', njr_test_pending() );

// Attachments
// ====

$revalidate = njr_test_request( [ new WP_Post( 50, 'attachment', 'inherit', 'a-file' ) ] );
njr_test_save( $revalidate, new WP_Post( 50, 'attachment', 'inherit', 'a-file' ) );
njr_test_assert( [] === njr_test_pending(), 'saving an attachment reports nothing', njr_test_pending() );

$revalidate = njr_test_request();
add_filter( 'nextjs_revalidate_should_revalidate_post', '__return_true' );
njr_test_save( $revalidate, new WP_Post( 51, 'attachment', 'publish', 'another-file' ) );
njr_test_assert( [] === njr_test_pending(), 'an attachment the filter admits still reports nothing', njr_test_pending() );

// The site has the last word, but not over what the sides say
// ====

$revalidate = njr_test_request( [ new WP_Post( 42, 'post', 'publish', 'hello' ) ] );
add_filter( 'nextjs_revalidate_should_revalidate_post', '__return_false' );
njr_test_save( $revalidate, new WP_Post( 42, 'post', 'publish', 'hello' ) );
njr_test_assert( [] === njr_test_pending(), 'the filter declines an edit', njr_test_pending() );

// A candidate on neither side of the front-end has no change to report, and a
// change with both sides null is never produced.
$revalidate = njr_test_request( [ new WP_Post( 42, 'post', 'draft', 'hello' ) ] );
add_filter( 'nextjs_revalidate_should_revalidate_post', '__return_true' );
njr_test_save( $revalidate, new WP_Post( 42, 'post', 'draft', 'hello' ) );
njr_test_assert( [] === njr_test_pending(), 'a draft the filter admits reports nothing, having no page on either side', njr_test_pending() );

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
