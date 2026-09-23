<?php
/**
 * Which saves revalidate a post that has left the front-end, and at which path
 * — `Revalidate::on_post_save()`.
 *
 * The bug this guards (#78): the leaving branch tested an allowlist of two
 * destinations, `draft` and `trash`. An editor taking a published post to
 * Pending Review, or re-scheduling it to a future date, left the front-end just
 * as thoroughly, and nothing was enqueued — the front-end kept serving the
 * removed page indefinitely. Custom statuses from an editorial workflow plugin
 * were the same hole, permanently.
 *
 * The question is now asked against the status axis instead: the save moved the
 * post from a status the axis admits to one it does not. So what is under test
 * here is as much the *shape* as the two statuses that prompted it — a status
 * nothing in this file names has to behave like `pending` does.
 *
 * The predicate itself — whether a post is a **revalidatable post** at all — is
 * pinned in `revalidatable-post-test.php`. What this one adds is the half only
 * the save handler decides: whether anything reaches the queue, and *which*
 * permalink does, since a post that has left has two and only the one it held
 * before the save is the page the front-end cached.
 *
 * Reachable by stubbing a handful of WordPress functions, so it is a standalone
 * script rather than a PHPUnit test — see `docs/adr/0008-two-testing-idioms.md`.
 * That the queue row reaches the front-end is the integration suite's business.
 *
 * Run with `npm run test:php`, or `php tests/leaving-the-front-end-test.php`.
 */

if ( 'cli' !== PHP_SAPI ) die( 'This file must be run from the command line.' );

// The plugin files bail when this is not defined.
define( 'ABSPATH', __DIR__ . '/' );

/**
 * The permalink the post had while it was on the front-end, i.e. the page the
 * front-end is still holding once the post has left it.
 */
const NJR_TEST_PERMALINK_BEFORE = 'https://example.test/runbook-post/';

/**
 * What `get_permalink()` answers for the post as it stands *after* the save.
 *
 * Stands in for the unpublished shape — `/?p=42` — which is not the path the
 * front-end cached and never the one that should be enqueued for a post that
 * has just left.
 */
const NJR_TEST_PERMALINK_AFTER = 'https://example.test/?p=42';

// WordPress stubs
// ====

function add_action( $name, $callback, $priority = 10, $accepted_args = 1 ) {}
function add_filter( $name, $callback, $priority = 10, $accepted_args = 1 ) {}
function remove_action( $name, $callback, $priority = 10 ) {}
function __( $text, $domain = null ) { return $text; }
function _x( $text, $context, $domain = null ) { return $text; }

/**
 * Only the filter the predicate applies is answered; every other one
 * hands its value straight back, as WordPress does with nothing hooked.
 */
function apply_filters( $hook, $value, ...$args ) {
	if ( 'nextjs_revalidate_purge_should_revalidate_post_on_save' === $hook
		&& null !== $GLOBALS['njr_test_filter'] ) {
		return $GLOBALS['njr_test_filter'];
	}

	return $value;
}

function wp_is_post_autosave( $post_id ) { return false; }
function wp_is_post_revision( $post_id ) { return false; }

function get_post_type( $post_id ) { return $GLOBALS['njr_test_post_type']; }
function is_post_type_viewable( $post_type ) { return $GLOBALS['njr_test_type_viewable']; }
function get_post_status( $post_id ) { return $GLOBALS['njr_test_post_status']; }

/**
 * The post as it is *now* has the after permalink; a `WP_Post` handed in is the
 * post as it was before the save, and carries the permalink it had then.
 */
function get_permalink( $post ) {
	return $post instanceof WP_Post ? NJR_TEST_PERMALINK_BEFORE : NJR_TEST_PERMALINK_AFTER;
}

function wp_get_upload_dir() {
	return [ 'baseurl' => 'https://example.test/wp-content/uploads' ];
}

function wp_make_link_relative( $url ) {
	return (string) preg_replace( '|^(https?:)?//[^/]+(/?.*)|i', '$2', (string) $url );
}

class WP_Post {
	public $ID;
	public $post_status;

	public function __construct( $id, $post_status ) {
		$this->ID          = $id;
		$this->post_status = $post_status;
	}
}

class NextJsRevalidate_Test_Queue {
	/** @var string[] */
	public $items = [];

	public function add_item( $permalink, $priority = 10 ) {
		$this->items[] = $permalink;
		return true;
	}
}

class NextJsRevalidate {
	public $queue;

	private static $instance;

	public static function init() {
		if ( ! isset( self::$instance ) ) self::$instance = new static();
		return self::$instance;
	}

	private function __construct() {
		$this->queue = new NextJsRevalidate_Test_Queue();
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

use NextJsRevalidate\Revalidate;

// The harness
// ====

$failures = 0;

function njr_test_assert( $condition, $description ) {
	global $failures;

	if ( $condition ) {
		printf( "ok   — %s\n", $description );
		return;
	}

	$failures++;
	printf( "FAIL — %s\n", $description );
}

/**
 * What one save enqueues.
 *
 * @param string|null $status_before The status the post held before the save,
 *                                   or null for a save with no previous post —
 *                                   the shape a first insert has.
 * @param string      $status_after  The status it holds after it.
 * @param array       $options       `type_viewable` — whether the post's type is
 *                                   viewable, default true. `filter` — what the
 *                                   site's filter answers, default null for a
 *                                   site with nothing hooked.
 *
 * @return string[] The permalinks the save added to the queue, in order.
 */
function njr_test_save( $status_before, $status_after, array $options = [] ) {
	$GLOBALS['njr_test_post_status']   = $status_after;
	$GLOBALS['njr_test_post_type']     = 'post';
	$GLOBALS['njr_test_type_viewable'] = array_key_exists( 'type_viewable', $options ) ? $options['type_viewable'] : true;
	$GLOBALS['njr_test_filter']        = array_key_exists( 'filter', $options ) ? $options['filter'] : null;

	$queue = NextJsRevalidate::init()->queue;
	$queue->items = [];

	$post_before = ( null === $status_before ? null : new WP_Post( 42, $status_before ) );

	$revalidate = new Revalidate();
	$revalidate->on_post_save( 42, new WP_Post( 42, $status_after ), true, $post_before );

	return $queue->items;
}

/**
 * Every status a save can take a published post to that the status axis does
 * not admit — including one no version of this plugin has ever named, standing
 * for the custom statuses an editorial workflow plugin registers.
 */
const NJR_TEST_LEFT_THE_FRONT_END = ['draft', 'pending', 'future', 'trash', 'njr_awaiting_legal'];

// The cases
// ====

// The bug itself. `draft` and `trash` were the only two that worked; the rest
// are what #78 reported, and the last of them is the one an allowlist
// could never have covered.
foreach ( NJR_TEST_LEFT_THE_FRONT_END as $status ) {
	$queued = njr_test_save( 'publish', $status );

	njr_test_assert(
		[ NJR_TEST_PERMALINK_BEFORE ] === $queued,
		"publish → $status enqueues the permalink the post had before the save"
	);
}

// Private counts as being on the front-end, so leaving it is a leaving too.
njr_test_assert(
	[ NJR_TEST_PERMALINK_BEFORE ] === njr_test_save( 'private', 'trash' ),
	'private → trash enqueues the permalink the post had before the save'
);

// The status axis admits private, so this is not a leaving at all — the post is
// revalidated as the private post it now is, at its own permalink.
njr_test_assert(
	[ NJR_TEST_PERMALINK_AFTER ] === njr_test_save( 'publish', 'private' ),
	'publish → private still enqueues, at its own permalink rather than the one before the save'
);

njr_test_assert(
	[ NJR_TEST_PERMALINK_AFTER ] === njr_test_save( 'publish', 'publish' ),
	'an ordinary update of a published post enqueues its own permalink'
);

// A post that was never on the front-end has nothing there to take down.
foreach ( ['draft', 'pending', 'future', 'njr_awaiting_legal'] as $status ) {
	njr_test_assert(
		[] === njr_test_save( $status, $status ),
		"saving a post that stays $status enqueues nothing"
	);
}

njr_test_assert(
	[] === njr_test_save( null, 'draft' ),
	'a save with no previous post enqueues nothing for an unpublished status'
);

// The type axis gates the carve-out — ADR 0005. A type the front-end holds no
// page for is never revalidated, however the post left the front-end.
foreach ( NJR_TEST_LEFT_THE_FRONT_END as $status ) {
	njr_test_assert(
		[] === njr_test_save( 'publish', $status, [ 'type_viewable' => false ] ),
		"publish → $status enqueues nothing for a post of a non-viewable type"
	);
}

// The site has the last word, on a leaving as on any other save.
foreach ( NJR_TEST_LEFT_THE_FRONT_END as $status ) {
	njr_test_assert(
		[] === njr_test_save( 'publish', $status, [ 'filter' => false ] ),
		"publish → $status is declined when the site's filter answers false"
	);
}

njr_test_assert(
	[ NJR_TEST_PERMALINK_BEFORE ] === njr_test_save( 'publish', 'draft', [ 'type_viewable' => false, 'filter' => true ] ),
	'the filter can admit a post the type axis declined, and its leaving still uses the permalink before the save'
);

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
