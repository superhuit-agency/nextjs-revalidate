<?php
/**
 * A post's **term membership** on both sides of its change, and a post's
 * terms written with no save — NextJsRevalidate\Revalidate (#188).
 *
 * Each side of a `post` change that exists lists the post's terms in
 * revalidatable taxonomies, so the front-end reaches the archives a post
 * joined and the ones it left. What only this suite can see is the order
 * WordPress does it in: a save writes the post's row, then its terms, and only
 * then reaches the hook that reports it — so its `before` terms are read on
 * `pre_post_update` — and a direct `wp_remove_object_terms()` fires no
 * `set_object_terms` at all. See
 * `docs/adr/0040-a-term-reports-itself-and-a-post-the-terms-it-is-in.md`.
 *
 * @package NextJsRevalidate
 */

namespace NextJsRevalidate\Tests;

use NextJsRevalidate\Change;

class PostTermsTest extends PendingChangesTestCase {

	/**
	 * The taxonomies this test registered, to be unregistered after it.
	 *
	 * @var string[]
	 */
	private $registered = [];

	/** @var int */
	private $video;

	/** @var int */
	private $podcast;

	public function set_up() {
		parent::set_up();

		$this->set_permalink_structure( '/%postname%/' );

		$this->configure_site();

		$this->video   = (int) wp_insert_term( 'Video', 'category' )['term_id'];
		$this->podcast = (int) wp_insert_term( 'Podcast', 'category' )['term_id'];

		$this->reset_pending_changes();
	}

	public function tear_down() {
		foreach ( $this->registered as $taxonomy ) unregister_taxonomy( $taxonomy );
		$this->registered = [];

		parent::tear_down();
	}

	// Saves
	// ====

	public function test_publishing_a_post_reports_its_terms_after() {
		$post = self::factory()->post->create( [ 'post_status' => 'publish', 'post_name' => 'hello', 'post_category' => [ $this->video ] ] );

		$this->assertPendingChanges( [
			Change::post( $post, 'post', null, '/hello/', [], [ $this->video() ] ),
		] );
	}

	/**
	 * Through the REST API, which writes a new post's terms after
	 * `wp_insert_post()` returns and before the hook that reports it.
	 */
	public function test_publishing_a_post_over_rest_reports_no_before() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/posts' );
		$request->set_body_params( [ 'title' => 'Hello', 'slug' => 'hello', 'status' => 'publish', 'categories' => [ $this->video ] ] );
		$response = rest_do_request( $request );

		$this->assertSame( 201, $response->get_status(), 'The fixture post was not created.' );

		$this->assertPendingChanges( [
			Change::post( $response->get_data()['id'], 'post', null, '/hello/', [], [ $this->video() ] ),
		] );
	}

	public function test_trashing_a_post_reports_its_terms_before() {
		$post = $this->published( [ $this->video ] );

		wp_trash_post( $post );

		$this->assertPendingChanges( [
			Change::post( $post, 'post', '/hello/', null, [ $this->video() ] ),
		] );
	}

	public function test_moving_a_post_to_another_category_reports_both() {
		$post = $this->published( [ $this->video ] );

		wp_update_post( [ 'ID' => $post, 'post_category' => [ $this->podcast ] ] );

		$this->assertPendingChanges( [
			Change::post( $post, 'post', '/hello/', '/hello/', [ $this->video() ], [ $this->podcast() ] ),
		] );
	}

	public function test_a_title_edit_reports_equal_terms() {
		$post = $this->published( [ $this->video ] );

		wp_update_post( [ 'ID' => $post, 'post_title' => 'Hello again' ] );

		$this->assertPendingChanges( [
			Change::post( $post, 'post', '/hello/', '/hello/', [ $this->video() ], [ $this->video() ] ),
		] );
	}

	public function test_a_post_saved_twice_keeps_the_first_terms_and_the_last() {
		$post = $this->published( [ $this->video ] );

		wp_update_post( [ 'ID' => $post, 'post_category' => [ $this->podcast ] ] );
		wp_update_post( [ 'ID' => $post, 'post_title' => 'Hello again' ] );

		$this->assertPendingChanges( [
			Change::post( $post, 'post', '/hello/', '/hello/', [ $this->video() ], [ $this->podcast() ] ),
		] );
	}

	// Direct writes
	// ====

	public function test_setting_a_published_posts_terms_with_no_save_reports_it() {
		$post = $this->published( [ $this->video ] );

		wp_set_object_terms( $post, [ $this->podcast ], 'category' );

		$this->assertPendingChanges( [
			Change::post( $post, 'post', '/hello/', '/hello/', [ $this->video() ], [ $this->podcast() ] ),
		] );
	}

	public function test_adding_a_term_with_no_save_reports_it() {
		$post = $this->published( [ $this->video ] );

		wp_add_object_terms( $post, [ $this->podcast ], 'category' );

		$this->assertPendingChanges( [
			Change::post( $post, 'post', '/hello/', '/hello/', [ $this->video() ], [ $this->video(), $this->podcast() ] ),
		] );
	}

	/**
	 * `wp_remove_object_terms()` fires no `set_object_terms`.
	 */
	public function test_removing_a_term_with_no_save_reports_it() {
		$post = $this->published( [ $this->video, $this->podcast ] );

		wp_remove_object_terms( $post, [ $this->video ], 'category' );

		$this->assertPendingChanges( [
			Change::post( $post, 'post', '/hello/', '/hello/', [ $this->video(), $this->podcast() ], [ $this->podcast() ] ),
		] );
	}

	public function test_a_direct_write_then_a_save_is_one_change() {
		$post = $this->published( [ $this->video ] );

		wp_set_object_terms( $post, [ $this->podcast ], 'category' );
		wp_update_post( [ 'ID' => $post, 'post_name' => 'renamed' ] );

		$this->assertPendingChanges( [
			Change::post( $post, 'post', '/hello/', '/renamed/', [ $this->video() ], [ $this->podcast() ] ),
		] );
	}

	/**
	 * An attachment's insert ends on `add_attachment`, without the
	 * `wp_insert_post` action a post's insert ends with: an importer uploads
	 * the media, then inserts the posts and sets their terms.
	 */
	public function test_a_direct_write_after_an_upload_in_the_same_request_is_reported() {
		self::factory()->attachment->create_object( [ 'file' => 'image.jpg', 'post_mime_type' => 'image/jpeg' ] );
		$post = $this->published( [ $this->video ] );

		wp_set_object_terms( $post, [ $this->podcast ], 'category' );

		$this->assertPendingChanges( [
			Change::post( $post, 'post', '/hello/', '/hello/', [ $this->video() ], [ $this->podcast() ] ),
		] );
	}

	/**
	 * A plugin inserting a post from another post's save, then setting its
	 * terms: the inner insert has ended, though the outer one has not.
	 */
	public function test_a_direct_write_on_a_post_inserted_from_another_posts_save_is_reported() {
		$inner    = 0;
		$callback = function () use ( &$inner, &$callback ) {
			remove_action( 'save_post', $callback );

			$inner = self::factory()->post->create( [ 'post_status' => 'publish', 'post_name' => 'inner', 'post_category' => [ $this->video ] ] );
			wp_set_object_terms( $inner, [ $this->podcast ], 'category' );
		};
		add_action( 'save_post', $callback );

		$outer = self::factory()->post->create( [ 'post_status' => 'publish', 'post_name' => 'outer', 'post_category' => [ $this->video ] ] );

		$this->assertPendingChanges( [
			Change::post( $inner, 'post', null, '/inner/', [], [ $this->podcast() ] ),
			Change::post( $outer, 'post', null, '/outer/', [], [ $this->video() ] ),
		] );
	}

	public function test_a_write_that_changes_no_term_reports_nothing() {
		$post = $this->published( [ $this->video ] );

		wp_set_object_terms( $post, [ $this->video ], 'category' );
		wp_add_object_terms( $post, [ $this->video ], 'category' );

		$this->assertNoPendingChanges();
	}

	public function test_a_drafts_terms_written_with_no_save_report_nothing() {
		$draft = self::factory()->post->create( [ 'post_status' => 'draft', 'post_category' => [ $this->video ] ] );
		$this->reset_pending_changes();

		wp_set_object_terms( $draft, [ $this->podcast ], 'category' );
		wp_remove_object_terms( $draft, [ $this->podcast ], 'category' );

		$this->assertNoPendingChanges();
	}

	/**
	 * Its terms are let go on the way, and the delete is the change.
	 */
	public function test_permanently_deleting_a_post_reports_only_the_delete() {
		$post = $this->published( [ $this->video ] );

		wp_delete_post( $post, true );

		$this->assertPendingChanges( [
			Change::post( $post, 'post', '/hello/', null, [ $this->video() ] ),
		] );
	}

	// Term deletes
	// ====

	/**
	 * The deleted term's own change covers its members (#55).
	 */
	public function test_deleting_a_category_with_posts_reports_no_post_change() {
		$this->published( [ $this->video ] );
		$this->published( [ $this->video, $this->podcast ], 'other' );

		wp_delete_term( $this->video, 'category' );

		$this->assertSame( [ 'term', 'term' ], array_column( $this->pending_changes()->pending(), 'subject' ) );
	}

	public function test_deleting_a_tag_with_posts_reports_no_post_change() {
		$tag = (int) wp_insert_term( 'Live', 'post_tag' )['term_id'];
		$post = $this->published( [ $this->video ] );
		wp_set_object_terms( $post, [ $tag ], 'post_tag' );
		$this->reset_pending_changes();

		wp_delete_term( $tag, 'post_tag' );

		$this->assertSame( [ 'term' ], array_column( $this->pending_changes()->pending(), 'subject' ) );
	}

	// Taxonomies
	// ====

	public function test_terms_of_a_taxonomy_that_is_not_revalidatable_never_appear() {
		register_taxonomy( 'njr_internal', 'post', [ 'public' => true, 'publicly_queryable' => false ] );
		$this->registered[] = 'njr_internal';
		$internal = (int) wp_insert_term( 'Hidden', 'njr_internal' )['term_id'];

		$post = $this->published( [ $this->video ] );

		wp_set_object_terms( $post, [ $internal ], 'njr_internal' );
		$this->assertNoPendingChanges( 'A write in a taxonomy that is not revalidatable was reported.' );

		wp_update_post( [ 'ID' => $post, 'post_title' => 'Hello again' ] );

		$this->assertPendingChanges( [
			Change::post( $post, 'post', '/hello/', '/hello/', [ $this->video() ], [ $this->video() ] ),
		] );
	}

	// Helpers
	// ====

	/**
	 * A published post in the given categories, and nothing pending.
	 *
	 * @param int[]  $categories
	 * @param string $slug
	 * @return int
	 */
	private function published( array $categories, $slug = 'hello' ) {
		$post = self::factory()->post->create( [ 'post_status' => 'publish', 'post_name' => $slug, 'post_category' => $categories ] );
		$this->reset_pending_changes();

		return $post;
	}

	private function video() {
		return Change::post_term( $this->video, 'category', 'video' );
	}

	private function podcast() {
		return Change::post_term( $this->podcast, 'category', 'podcast' );
	}
}
