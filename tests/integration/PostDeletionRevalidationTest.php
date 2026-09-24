<?php
/**
 * A post permanently deleted is reported gone — issue #77.
 *
 * The seam is the pending changes: each test arranges a post, lets go of
 * whatever saving that post into place reported, calls `wp_delete_post()` with
 * force, and asserts which changes are then pending — a deleted post's is its
 * URI before, and no `after`.
 * Nothing here asserts that a hook was added or which one — moving the handler
 * from `before_delete_post` to anything else that can still read the post's
 * URI should break none of it.
 *
 * Only this suite can see it: the URI of a real post, composed from a row
 * that exists at the moment the delete starts and not a moment later, is the
 * whole of what was missing — as is which deletes `wp_delete_post()` routes to
 * the trash instead. The handler's own decisions are pinned by
 * `tests/post-delete-revalidation-test.php`, and the gate they ask by
 * `tests/revalidatable-post-test.php`; neither needs WordPress.
 *
 * What the trash does on its way in is `PostChangeTest`'s. These tests let go
 * of the pending changes between arranging and deleting, and read only the
 * delete's own answer.
 *
 * @package NextJsRevalidate
 */

namespace NextJsRevalidate\Tests;

use NextJsRevalidate\Change;

class PostDeletionRevalidationTest extends PendingChangesTestCase {

	/**
	 * The post types this test registered, to be unregistered after it.
	 *
	 * @var string[]
	 */
	private $registered = [];

	public function set_up() {
		parent::set_up();

		// A permalink is composed from the permastruct when there is one, and
		// from a query var when there is not. Which one this site has is the
		// test's decision rather than whatever the environment was left holding.
		$this->set_permalink_structure( '/%postname%/' );

		$this->configure_site();
	}

	public function tear_down() {
		foreach ( $this->registered as $post_type ) unregister_post_type( $post_type );
		$this->registered = [];

		parent::tear_down();
	}

	// The gap #77 names
	// ====

	/**
	 * Publish, then delete outright with no trash step: the front-end holds a
	 * page for a post that no longer exists, and nothing else is ever going to
	 * tell it otherwise. This is what used to report nothing at all.
	 */
	public function test_deleting_a_published_post_is_reported_gone() {
		$post_id = $this->published_post();
		$path    = $this->path_of( get_permalink( $post_id ) );

		$this->reset_pending_changes();

		wp_delete_post( $post_id, true );

		$this->assertPendingChanges( [ Change::post( $post_id, 'post', $path, null ) ] );
	}

	/**
	 * A private post has a page the front-end could hold, so deleting it is the
	 * same event. The status axis admits private, which core's own
	 * `is_post_status_viewable()` does not.
	 */
	public function test_deleting_a_private_post_is_reported_gone() {
		$post_id = $this->published_post( [ 'post_status' => 'private' ] );
		$path    = $this->path_of( get_permalink( $post_id ) );

		$this->reset_pending_changes();

		wp_delete_post( $post_id, true );

		$this->assertPendingChanges( [ Change::post( $post_id, 'post', $path, null ) ] );
	}

	/**
	 * `wp_delete_post()` only redirects to the trash for `post` and `page`, so a
	 * custom post type is deleted outright by everything that reaches it —
	 * `wp post delete` on the command line most of all. The everyday case for a
	 * headless site, and the one the issue was found through.
	 */
	public function test_deleting_a_published_post_of_a_custom_type_is_reported_gone() {
		$post_type = $this->viewable_post_type( 'njr_deletable' );

		$post_id = $this->published_post( [ 'post_type' => $post_type ] );
		$path    = $this->path_of( get_permalink( $post_id ) );

		$this->reset_pending_changes();

		wp_delete_post( $post_id, true );

		$this->assertPendingChanges( [ Change::post( $post_id, $post_type, $path, null ) ] );
	}

	/**
	 * A published post is deleted with its revisions, each of which reaches the
	 * same hook. What comes out is the post's own revalidation and nothing else.
	 */
	public function test_deleting_a_post_with_revisions_is_reported_gone_once() {
		$post_id = $this->published_post();
		$path    = $this->path_of( get_permalink( $post_id ) );

		$this->revise( $post_id );

		$this->reset_pending_changes();

		wp_delete_post( $post_id, true );

		$this->assertPendingChanges( [ Change::post( $post_id, 'post', $path, null ) ] );
	}

	/**
	 * And a revision deleted on its own — which is what trimming to the revision
	 * limit does — is not its post leaving the front-end. The page is still
	 * there, unchanged, and asking for it to be rebuilt would be work nothing
	 * asked for.
	 */
	public function test_deleting_a_revision_reports_nothing() {
		$post_id = $this->published_post();

		$revisions = $this->revise( $post_id );
		$this->assertNotEmpty( $revisions, 'The fixture post has no revision to delete.' );

		$this->reset_pending_changes();

		wp_delete_post_revision( reset( $revisions )->ID );

		$this->assertNoPendingChanges();
	}

	// What the delete deliberately does not report
	// ====

	/**
	 * A post already in the trash was revalidated when it was trashed, and the
	 * front-end has had no reason to cache it since — so emptying the trash,
	 * whether by hand or through the `wp_scheduled_delete` sweep that is how
	 * most posts actually leave a site, reports nothing.
	 *
	 * Its permalink by then carries the `__trashed` suffix and names a path the
	 * front-end never held, which is the second reason not to send it.
	 */
	public function test_deleting_a_trashed_post_reports_nothing() {
		$post_id = $this->published_post();

		wp_trash_post( $post_id );

		$this->reset_pending_changes();

		wp_delete_post( $post_id, true );

		$this->assertNoPendingChanges();
	}

	/**
	 * A draft has no page on the front-end to make go away.
	 */
	public function test_deleting_a_draft_reports_nothing() {
		$post_id = $this->published_post( [ 'post_status' => 'draft' ] );

		$this->reset_pending_changes();

		wp_delete_post( $post_id, true );

		$this->assertNoPendingChanges();
	}

	/**
	 * The type axis gates a delete as it gates a save: a type WordPress will
	 * route no query for is not one the front-end holds a page for, whatever the
	 * status of its posts. See ADR 0005.
	 */
	public function test_deleting_a_post_of_a_type_that_is_not_viewable_reports_nothing() {
		$post_type = $this->registered_post_type( 'njr_not_viewable', [ 'public' => false, 'publicly_queryable' => false ] );

		$post_id = $this->published_post( [ 'post_type' => $post_type ] );

		$this->reset_pending_changes();

		wp_delete_post( $post_id, true );

		$this->assertNoPendingChanges();
	}

	// The site has the last word
	// ====

	/**
	 * The delete asks the same gate every other entry point asks, so the filter
	 * a headless site keeps its non-queryable types revalidating with is
	 * consulted here too — the direction ADR 0005 says the hatch exists for.
	 */
	public function test_the_filter_admits_a_deleted_post_of_a_type_that_is_not_viewable() {
		$post_type = $this->registered_post_type( 'njr_filtered_in', [ 'public' => false, 'publicly_queryable' => false ] );

		$post_id = $this->published_post( [ 'post_type' => $post_type ] );
		$path    = $this->path_of( get_permalink( $post_id ) );

		$this->reset_pending_changes();

		$this->with_verdict( $post_id, true, function() use ( $post_id ) {
			wp_delete_post( $post_id, true );
		});

		$this->assertPendingChanges( [ Change::post( $post_id, $post_type, $path, null ) ] );
	}

	/**
	 * And declines a post it would otherwise have taken.
	 */
	public function test_the_filter_declines_a_deleted_post() {
		$post_id = $this->published_post();

		$this->reset_pending_changes();

		$this->with_verdict( $post_id, false, function() use ( $post_id ) {
			wp_delete_post( $post_id, true );
		});

		$this->assertNoPendingChanges();
	}

	/**
	 * The filter's v1 name is still applied, deprecated since 2.0.0 — a site
	 * hooking it keeps its verdicts, and WordPress says the name is going.
	 */
	public function test_the_filter_under_its_v1_name_still_declines_a_deleted_post() {
		$this->setExpectedDeprecated( 'nextjs_revalidate_purge_should_revalidate_post_on_save' );

		$post_id = $this->published_post();

		$this->reset_pending_changes();

		$declined = function( $should_revalidate, $filtered_post_id ) use ( $post_id ) {
			return ( intval( $filtered_post_id ) === $post_id ? false : $should_revalidate );
		};

		add_filter( 'nextjs_revalidate_purge_should_revalidate_post_on_save', $declined, 10, 2 );
		wp_delete_post( $post_id, true );
		remove_filter( 'nextjs_revalidate_purge_should_revalidate_post_on_save', $declined, 10 );

		$this->assertNoPendingChanges();
	}

	// Fixtures
	// ====

	/**
	 * A post of the fixture site, published unless told otherwise.
	 *
	 * @param array $args Optional. The post arguments to override.
	 * @return int The post id.
	 */
	private function published_post( array $args = [] ) {
		return $this->factory()->post->create( array_merge( [
			'post_status' => 'publish',
			'post_title'  => 'A post that leaves',
		], $args ) );
	}

	/**
	 * Give the post a revision, and answer the revisions it then has.
	 *
	 * @param int $post_id The post to revise.
	 * @return \WP_Post[] The post's revisions, newest first.
	 */
	private function revise( $post_id ) {
		wp_update_post( [ 'ID' => $post_id, 'post_content' => 'A second draft.' ] );
		wp_save_post_revision( $post_id );

		return wp_get_post_revisions( $post_id );
	}

	/**
	 * Register a post type, to be unregistered on teardown.
	 *
	 * @param string $post_type The post type name.
	 * @param array  $args      The registration arguments.
	 *
	 * @return string The post type name.
	 */
	private function registered_post_type( $post_type, array $args ) {
		register_post_type( $post_type, $args );
		$this->registered[] = $post_type;

		return $post_type;
	}

	/**
	 * Register a post type the front-end could hold pages for, with a
	 * permastruct of its own so its posts have a path rather than a query.
	 *
	 * @param string $post_type The post type name.
	 * @return string The post type name.
	 */
	private function viewable_post_type( $post_type ) {
		return $this->registered_post_type( $post_type, [
			'public'             => true,
			'publicly_queryable' => true,
			'rewrite'            => [ 'slug' => $post_type ],
		] );
	}

	/**
	 * Run $during with the gate forced to $verdict for one post, and the filter
	 * removed again afterwards.
	 *
	 * @param int      $post_id The post to force a verdict for.
	 * @param bool     $verdict The verdict to force.
	 * @param callable $during  What to run while it is forced.
	 *
	 * @return void
	 */
	private function with_verdict( $post_id, $verdict, callable $during ) {
		$forced = function( $should_revalidate, $filtered_post_id ) use ( $post_id, $verdict ) {
			return ( intval( $filtered_post_id ) === $post_id ? $verdict : $should_revalidate );
		};

		add_filter( 'nextjs_revalidate_should_revalidate_post', $forced, 10, 2 );
		$during();
		remove_filter( 'nextjs_revalidate_should_revalidate_post', $forced, 10 );
	}
}
