<?php
/**
 * Which saves report a post that has left the front-end, and at which URI —
 * `Revalidate::on_post_save()`.
 *
 * The bug this guards (#78): the leaving branch tested an allowlist of two
 * destinations, `draft` and `trash`. An editor taking a published post to
 * Pending Review, or re-scheduling it to a future date, left the front-end just
 * as thoroughly, and nothing was reported — the front-end kept serving the
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
 * the save handler decides: whether a change is reported, and what its sides
 * say. A post that has left has two URIs, and only the one it held before the
 * save is the page the front-end cached: that is the change's `before`, and its
 * `after` is `null` (ADR 0033).
 *
 * Reachable by stubbing a handful of WordPress functions, so it is a standalone
 * script rather than a PHPUnit test — see `docs/adr/0008-two-testing-idioms.md`.
 * The changes are read back from the real `PendingChanges`; that they reach the
 * front-end is `pending-changes-test.php`'s business.
 *
 * Run with `npm run test:php`, or `php tests/leaving-the-front-end-test.php`.
 */

if ( 'cli' !== PHP_SAPI ) die( 'This file must be run from the command line.' );

// The plugin files bail when this is not defined.
define( 'ABSPATH', __DIR__ . '/' );

/**
 * The URI the post has while it is on the front-end — and so, once it has left,
 * the page the front-end is still holding.
 */
const NJR_TEST_URI_BEFORE = '/runbook-post/';

/**
 * What `get_permalink()` answers for the post as it stands *after* the save.
 *
 * Stands in for the unpublished shape — `/?p=42` — which is not the path the
 * front-end cached and never one a change should name.
 */
const NJR_TEST_PERMALINK_AFTER = 'https://example.test/?p=42';

// WordPress stubs
// ====

function add_action( $name, $callback, $priority = 10, $accepted_args = 1 ) {}
function add_filter( $name, $callback, $priority = 10, $accepted_args = 1 ) {}
function __( $text, $domain = null ) { return $text; }
function _x( $text, $context, $domain = null ) { return $text; }
function get_current_blog_id() { return 1; }

/**
 * Only the filter the predicate applies is answered; every other one
 * hands its value straight back, as WordPress does with nothing hooked.
 */
function apply_filters( $hook, $value, ...$args ) {
	if ( 'nextjs_revalidate_should_revalidate_post' === $hook
		&& null !== $GLOBALS['njr_test_filter'] ) {
		return $GLOBALS['njr_test_filter'];
	}

	return $value;
}

function apply_filters_deprecated( $hook, $args, $version, $replacement = '', $message = '' ) {
	return $args[0];
}

function wp_is_post_autosave( $post_id ) { return false; }
function wp_is_post_revision( $post_id ) { return false; }

function get_post_type( $post_id ) { return $GLOBALS['njr_test_post_type']; }
function is_post_type_viewable( $post_type ) { return $GLOBALS['njr_test_type_viewable']; }
function get_post_status( $post_id ) { return $GLOBALS['njr_test_post_status']; }

function get_post( $post_id ) {
	return new WP_Post( $post_id, $GLOBALS['njr_test_post_status'] );
}

/**
 * The permalink WordPress composes for a post: its slug while the post is on the
 * front-end, the query shape while it is not. Handed the post as it was before
 * the save, it answers for that post as it was.
 */
function get_permalink( $post ) {
	$status = ( $post instanceof WP_Post ) ? $post->post_status : get_post_status( $post );

	return in_array( $status, [ 'publish', 'private' ], true )
		? 'https://example.test' . NJR_TEST_URI_BEFORE
		: NJR_TEST_PERMALINK_AFTER;
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
	public $post_type = 'post';

	public function __construct( $id, $post_status ) {
		$this->ID          = $id;
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
 * What one save reports, in a request of its own.
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
 * @return array[] The changes the save left pending, in order.
 */
function njr_test_save( $status_before, $status_after, array $options = [] ) {
	$GLOBALS['njr_test_post_status']   = $status_after;
	$GLOBALS['njr_test_post_type']     = 'post';
	$GLOBALS['njr_test_type_viewable'] = array_key_exists( 'type_viewable', $options ) ? $options['type_viewable'] : true;
	$GLOBALS['njr_test_filter']        = array_key_exists( 'filter', $options ) ? $options['filter'] : null;

	$pending_changes = NextJsRevalidate::init()->pendingChanges;

	$pending = new ReflectionProperty( $pending_changes, 'pending' );
	$pending->setAccessible( true );
	$pending->setValue( $pending_changes, [] );

	$post_before = ( null === $status_before ? null : new WP_Post( 42, $status_before ) );

	$revalidate = new Revalidate();
	$revalidate->on_post_save( 42, new WP_Post( 42, $status_after ), true, $post_before );

	return $pending_changes->pending();
}

/**
 * The change of a post that left the front-end: the page it had, and none.
 */
function njr_test_left() {
	return [ Change::post( 42, 'post', NJR_TEST_URI_BEFORE, null ) ];
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
	njr_test_assert(
		njr_test_left() === njr_test_save( 'publish', $status ),
		"publish → $status reports the URI the post had before the save, and no after"
	);
}

// Private counts as being on the front-end, so leaving it is a leaving too.
njr_test_assert(
	njr_test_left() === njr_test_save( 'private', 'trash' ),
	'private → trash reports the URI the post had before the save, and no after'
);

// The status axis admits private, so this is not a leaving at all — the post
// is on the front-end on both sides.
njr_test_assert(
	[ Change::post( 42, 'post', NJR_TEST_URI_BEFORE, NJR_TEST_URI_BEFORE ) ] === njr_test_save( 'publish', 'private' ),
	'publish → private is not a leaving: the post is on the front-end on both sides'
);

njr_test_assert(
	[ Change::post( 42, 'post', NJR_TEST_URI_BEFORE, NJR_TEST_URI_BEFORE ) ] === njr_test_save( 'publish', 'publish' ),
	'an ordinary update of a published post reports two equal sides'
);

// A post that was never on the front-end has nothing there to take down.
foreach ( ['draft', 'pending', 'future', 'njr_awaiting_legal'] as $status ) {
	njr_test_assert(
		[] === njr_test_save( $status, $status ),
		"saving a post that stays $status reports nothing"
	);
}

njr_test_assert(
	[] === njr_test_save( null, 'draft' ),
	'a save with no previous post reports nothing for an unpublished status'
);

// The type axis gates the carve-out — ADR 0005. A type the front-end holds no
// page for is never revalidated, however the post left the front-end.
foreach ( NJR_TEST_LEFT_THE_FRONT_END as $status ) {
	njr_test_assert(
		[] === njr_test_save( 'publish', $status, [ 'type_viewable' => false ] ),
		"publish → $status reports nothing for a post of a non-viewable type"
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
	njr_test_left() === njr_test_save( 'publish', 'draft', [ 'type_viewable' => false, 'filter' => true ] ),
	'the filter can admit a post the type axis declined, and its leaving still reports the URI before the save'
);

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
