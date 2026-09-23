<?php
/**
 * A post permanently deleted revalidates its path — issue #77.
 *
 * The seam is the revalidation queue's contents: each test arranges a post,
 * empties the queue of whatever saving that post into place enqueued, calls
 * `wp_delete_post()` with force, and asserts which paths the queue then holds.
 * Nothing here asserts that a hook was added or which one — moving the handler
 * from `before_delete_post` to anything else that can still read the post's
 * permalink should break none of it.
 *
 * Only this suite can see it: the permalink of a real post, composed from a row
 * that exists at the moment the delete starts and not a moment later, is the
 * whole of what was missing — as is which deletes `wp_delete_post()` routes to
 * the trash instead. The handler's own decisions are pinned by
 * `tests/post-delete-revalidation-test.php`, and the gate they ask by
 * `tests/revalidatable-post-test.php`; neither needs WordPress.
 *
 * What the trash does on its way in is #68's, and is not asserted here: a save
 * time revalidation removes its own hook for the rest of the request, so a
 * fixture that enqueues would decide what a later one in the same process can.
 * These tests reset the queue between arranging and deleting for that reason,
 * and read only the delete's own answer.
 *
 * @package NextJsRevalidate
 */

namespace NextJsRevalidate\Tests;

class PostDeletionRevalidationTest extends QueueTestCase {

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
	 * tell it otherwise. This is what used to enqueue nothing at all.
	 */
	public function test_deleting_a_published_post_revalidates_its_path() {
		$post_id = $this->published_post();
		$path    = $this->path_of( get_permalink( $post_id ) );

		$this->reset_queue();

		wp_delete_post( $post_id, true );

		$this->assertQueueRevalidates( [ $path ] );
		$this->assertQueueRevalidatesAtPriorities(
			[ $path => 10 ],
			'A post leaving the site holds no special place in the queue.'
		);
	}

	/**
	 * A private post has a page the front-end could hold, so deleting it is the
	 * same event. The status axis admits private, which core's own
	 * `is_post_status_viewable()` does not.
	 */
	public function test_deleting_a_private_post_revalidates_its_path() {
		$post_id = $this->published_post( [ 'post_status' => 'private' ] );
		$path    = $this->path_of( get_permalink( $post_id ) );

		$this->reset_queue();

		wp_delete_post( $post_id, true );

		$this->assertQueueRevalidates( [ $path ] );
	}

	/**
	 * `wp_delete_post()` only redirects to the trash for `post` and `page`, so a
	 * custom post type is deleted outright by everything that reaches it —
	 * `wp post delete` on the command line most of all. The everyday case for a
	 * headless site, and the one the issue was found through.
	 */
	public function test_deleting_a_published_post_of_a_custom_type_revalidates_its_path() {
		$post_type = $this->viewable_post_type( 'njr_deletable' );

		$post_id = $this->published_post( [ 'post_type' => $post_type ] );
		$path    = $this->path_of( get_permalink( $post_id ) );

		$this->reset_queue();

		wp_delete_post( $post_id, true );

		$this->assertQueueRevalidates( [ $path ] );
	}

	/**
	 * A published post is deleted with its revisions, each of which reaches the
	 * same hook. What comes out is the post's own revalidation and nothing else.
	 */
	public function test_deleting_a_post_with_revisions_revalidates_its_path_once() {
		$post_id = $this->published_post();
		$path    = $this->path_of( get_permalink( $post_id ) );

		$this->revise( $post_id );

		$this->reset_queue();

		wp_delete_post( $post_id, true );

		$this->assertQueueRevalidates( [ $path ] );
	}

	/**
	 * And a revision deleted on its own — which is what trimming to the revision
	 * limit does — is not its post leaving the front-end. The page is still
	 * there, unchanged, and asking for it to be rebuilt would be work nothing
	 * asked for.
	 */
	public function test_deleting_a_revision_revalidates_nothing() {
		$post_id = $this->published_post();

		$revisions = $this->revise( $post_id );
		$this->assertNotEmpty( $revisions, 'The fixture post has no revision to delete.' );

		$this->reset_queue();

		wp_delete_post_revision( reset( $revisions )->ID );

		$this->assertQueueIsEmpty();
	}

	// What the delete deliberately does not enqueue
	// ====

	/**
	 * A post already in the trash was revalidated when it was trashed, and the
	 * front-end has had no reason to cache it since — so emptying the trash,
	 * whether by hand or through the `wp_scheduled_delete` sweep that is how
	 * most posts actually leave a site, enqueues nothing.
	 *
	 * Its permalink by then carries the `__trashed` suffix and names a path the
	 * front-end never held, which is the second reason not to send it.
	 */
	public function test_deleting_a_trashed_post_revalidates_nothing() {
		$post_id = $this->published_post();

		wp_trash_post( $post_id );

		$this->reset_queue();

		wp_delete_post( $post_id, true );

		$this->assertQueueIsEmpty();
	}

	/**
	 * A draft has no page on the front-end to make go away.
	 */
	public function test_deleting_a_draft_revalidates_nothing() {
		$post_id = $this->published_post( [ 'post_status' => 'draft' ] );

		$this->reset_queue();

		wp_delete_post( $post_id, true );

		$this->assertQueueIsEmpty();
	}

	/**
	 * The type axis gates a delete as it gates a save: a type WordPress will
	 * route no query for is not one the front-end holds a page for, whatever the
	 * status of its posts. See ADR 0005.
	 */
	public function test_deleting_a_post_of_a_type_that_is_not_viewable_revalidates_nothing() {
		$post_type = $this->registered_post_type( 'njr_not_viewable', [ 'public' => false, 'publicly_queryable' => false ] );

		$post_id = $this->published_post( [ 'post_type' => $post_type ] );

		$this->reset_queue();

		wp_delete_post( $post_id, true );

		$this->assertQueueIsEmpty();
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

		$this->reset_queue();

		$this->with_verdict( $post_id, true, function() use ( $post_id ) {
			wp_delete_post( $post_id, true );
		});

		$this->assertQueueRevalidates( [ $path ] );
	}

	/**
	 * And declines a post it would otherwise have taken.
	 */
	public function test_the_filter_declines_a_deleted_post() {
		$post_id = $this->published_post();

		$this->reset_queue();

		$this->with_verdict( $post_id, false, function() use ( $post_id ) {
			wp_delete_post( $post_id, true );
		});

		$this->assertQueueIsEmpty();
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

		add_filter( 'nextjs_revalidate_purge_should_revalidate_post_on_save', $forced, 10, 2 );
		$during();
		remove_filter( 'nextjs_revalidate_purge_should_revalidate_post_on_save', $forced, 10 );
	}
}
