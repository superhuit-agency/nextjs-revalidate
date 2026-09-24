<?php
/**
 * What a manual revalidation of one post reports — the row action, the bulk
 * action and the admin bar entry of `Revalidate`.
 *
 * An operator asking for a post to be revalidated has not changed it, so the
 * change reports the post as it stands on both sides: two equal URIs, the
 * current one. All three entries go through one path and ask the same gate a
 * save asks, so what is revalidatable cannot differ between them.
 *
 * And `nextjs_revalidate_purge_action_permalink`, which rewrote or declined the
 * permalink these three enqueued in v1, is retired (ADR 0035): a post change is
 * keyed by its ID and has no permalink left to rewrite. A callback still on it
 * is never called, and `_deprecated_hook()` names `nextjs_revalidate_change` as
 * the replacement.
 *
 * The changes are read back from the real `PendingChanges`. The two entries
 * that end in a redirect `exit` after it, so the redirect stub throws instead,
 * and carries the sendback URL out.
 *
 * Reachable by stubbing a handful of WordPress functions, so it is a standalone
 * script rather than a PHPUnit test — see `docs/adr/0008-two-testing-idioms.md`.
 *
 * Run with `npm run test:php`, or `php tests/post-manual-action-test.php`.
 */

if ( 'cli' !== PHP_SAPI ) die( 'This file must be run from the command line.' );

// The plugin files bail when this is not defined.
define( 'ABSPATH', __DIR__ . '/' );

/**
 * The fixture site: post ID => WP_Post.
 * @var array<int, WP_Post>
 */
$GLOBALS['njr_test_posts'] = [];

/**
 * Registered filter callbacks. filter name => callable[]
 * @var array
 */
$GLOBALS['njr_test_filters'] = [];

/**
 * Every `_deprecated_hook()` call, as its arguments.
 * @var array[]
 */
$GLOBALS['njr_test_deprecated'] = [];

// WordPress stubs
// ====

function add_action( $name, $callback, $priority = 10, $accepted_args = 1 ) {}
function __( $text, $domain = null ) { return $text; }
function get_current_blog_id() { return 1; }
function __return_false() { return false; }
function check_admin_referer( $action ) { return 1; }
function current_user_can( $capability, ...$args ) { return true; }
function wp_get_referer() { return 'https://example.test/wp-admin/edit.php'; }
function remove_query_arg( $keys, $url ) { return $url; }

function add_query_arg( ...$args ) {
	// Both of core's shapes: an array and a URL, or a key, a value and a URL.
	[ $query, $url ] = is_array( $args[0] ) ? [ $args[0], $args[1] ] : [ [ $args[0] => $args[1] ], $args[2] ];

	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $query );
}

function get_edit_post_link( $post_id, $context = 'display' ) {
	return "https://example.test/wp-admin/post.php?post=$post_id&action=edit";
}

/**
 * `wp_safe_redirect()` is followed by `exit`, so it throws instead: the test
 * catches where the operator would have been sent.
 */
class NextJsRevalidate_Test_Redirect extends Exception {}

function wp_safe_redirect( $location ) {
	throw new NextJsRevalidate_Test_Redirect( $location );
}

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

function _deprecated_hook( $hook, $version, $replacement = '', $message = '' ) {
	$GLOBALS['njr_test_deprecated'][] = [ $hook, $version, $replacement ];
}

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

function wp_is_post_revision( $post_id ) { return false; }
function wp_is_post_autosave( $post_id ) { return false; }

function get_permalink( $post ) {
	if ( ! $post instanceof WP_Post ) $post = get_post( $post );
	if ( ! $post ) return false;

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

	public function __construct( $id, $post_type, $post_status, $post_name ) {
		$this->ID          = $id;
		$this->post_type   = $post_type;
		$this->post_status = $post_status;
		$this->post_name   = $post_name;
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

use NextJsRevalidate\Change;
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
 * A new request, on a site holding a published post, a published page, a
 * draft and an attachment, with nothing hooked.
 *
 * @return Revalidate
 */
function njr_test_request() {
	$GLOBALS['njr_test_posts'] = [
		42 => new WP_Post( 42, 'post', 'publish', 'hello' ),
		43 => new WP_Post( 43, 'page', 'private', 'about' ),
		44 => new WP_Post( 44, 'post', 'draft', 'not-yet' ),
		45 => new WP_Post( 45, 'attachment', 'inherit', 'a-file' ),
	];
	$GLOBALS['njr_test_filters']    = [];
	$GLOBALS['njr_test_deprecated'] = [];
	$_GET = [];

	$pending_changes = NextJsRevalidate::init()->pendingChanges;

	$pending = new ReflectionProperty( $pending_changes, 'pending' );
	$pending->setAccessible( true );
	$pending->setValue( $pending_changes, [] );

	return new Revalidate();
}

/**
 * Run an entry that ends in a redirect, and answer where it sent the operator.
 *
 * @param callable $entry
 * @return string|null
 */
function njr_test_redirected( callable $entry ) {
	try {
		$entry();
	}
	catch ( NextJsRevalidate_Test_Redirect $redirect ) {
		return $redirect->getMessage();
	}

	return null;
}

function njr_test_pending() {
	return NextJsRevalidate::init()->pendingChanges->pending();
}

function njr_test_row_action( Revalidate $revalidate, $post_id ) {
	$_GET = [ 'action' => 'nextjs-revalidate-purge', 'post' => (string) $post_id ];

	return njr_test_redirected( [ $revalidate, 'revalidate_row_action' ] );
}

function njr_test_admin_bar( Revalidate $revalidate, $post_id ) {
	$_GET = [ 'nextjs-revalidate-purge-post' => (string) $post_id ];

	return njr_test_redirected( [ $revalidate, 'revalidate_current_post_action' ] );
}

function njr_test_bulk_action( Revalidate $revalidate, array $post_ids ) {
	return $revalidate->revalidate_bulk_action( 'https://example.test/wp-admin/edit.php', 'nextjs_revalidate-bulk_purge', $post_ids );
}

// The row action
// ====

$revalidate = njr_test_request();
$sendback   = njr_test_row_action( $revalidate, 42 );
njr_test_assert(
	[ Change::post( 42, 'post', '/hello/', '/hello/' ) ] === njr_test_pending(),
	'the row action reports the post with both sides its current URI',
	njr_test_pending()
);
njr_test_assert(
	false !== strpos( (string) $sendback, 'nextjs-revalidate-purged=42' ),
	'the row action sends the operator back with the post it reported',
	$sendback
);

$revalidate = njr_test_request();
$sendback   = njr_test_row_action( $revalidate, 44 );
njr_test_assert( [] === njr_test_pending(), 'the row action on a draft reports nothing', njr_test_pending() );
njr_test_assert(
	false !== strpos( (string) $sendback, 'nextjs-revalidate-purged=0' ),
	'and sends the operator back saying so',
	$sendback
);

$revalidate = njr_test_request();
njr_test_row_action( $revalidate, 45 );
njr_test_assert( [] === njr_test_pending(), 'the row action on an attachment reports nothing', njr_test_pending() );

$revalidate = njr_test_request();
add_filter( 'nextjs_revalidate_should_revalidate_post', '__return_false' );
njr_test_row_action( $revalidate, 42 );
njr_test_assert( [] === njr_test_pending(), 'the row action asks the gate, and the site\'s filter can decline it', njr_test_pending() );

// The admin bar
// ====

$revalidate = njr_test_request();
$sendback   = njr_test_admin_bar( $revalidate, 43 );
njr_test_assert(
	[ Change::post( 43, 'page', '/about/', '/about/' ) ] === njr_test_pending(),
	'the admin bar entry reports the post with both sides its current URI',
	njr_test_pending()
);
njr_test_assert(
	0 === strpos( (string) $sendback, get_edit_post_link( 43 ) ) && false !== strpos( (string) $sendback, 'nextjs-revalidate-purged=43' ),
	'the admin bar entry sends the operator back to the edit screen',
	$sendback
);

// The bulk action
// ====

$revalidate = njr_test_request();
$sendback   = njr_test_bulk_action( $revalidate, [ 42, 43, 44, 45 ] );
njr_test_assert(
	[
		Change::post( 42, 'post', '/hello/', '/hello/' ),
		Change::post( 43, 'page', '/about/', '/about/' ),
	] === njr_test_pending(),
	'the bulk action reports each revalidatable post with both sides its current URI, and nothing for the rest',
	njr_test_pending()
);
njr_test_assert(
	false !== strpos( (string) $sendback, 'nextjs-revalidate-bulk-purged=2' ),
	'the bulk action counts the posts it reported',
	$sendback
);

// The retired permalink filter
// ====

$revalidate = njr_test_request();
njr_test_row_action( $revalidate, 42 );
njr_test_assert( [] === $GLOBALS['njr_test_deprecated'], 'a site not hooking the permalink filter hears nothing about it', $GLOBALS['njr_test_deprecated'] );

$called     = 0;
$revalidate = njr_test_request();
add_filter( 'nextjs_revalidate_purge_action_permalink', function( $permalink ) use ( &$called ) {
	$called++;
	return false;
}, 10, 2 );

njr_test_row_action( $revalidate, 42 );
njr_test_admin_bar( $revalidate, 43 );
njr_test_bulk_action( $revalidate, [ 42, 43 ] );

njr_test_assert( 0 === $called, 'a callback on the permalink filter is never called', $called );
njr_test_assert(
	[
		Change::post( 42, 'post', '/hello/', '/hello/' ),
		Change::post( 43, 'page', '/about/', '/about/' ),
	] === njr_test_pending(),
	'and cannot decline what the manual actions report',
	njr_test_pending()
);
njr_test_assert(
	[] !== $GLOBALS['njr_test_deprecated']
		&& [ [ 'nextjs_revalidate_purge_action_permalink', '2.0.0', 'nextjs_revalidate_change' ] ] === array_values( array_unique( $GLOBALS['njr_test_deprecated'], SORT_REGULAR ) ),
	'a site hooking the permalink filter is told it is deprecated since 2.0.0, and that nextjs_revalidate_change replaces it',
	$GLOBALS['njr_test_deprecated']
);

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
