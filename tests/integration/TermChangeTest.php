<?php
/**
 * What creating, editing and deleting a term reports — NextJsRevalidate\Terms
 * (#55).
 *
 * A term's own lifecycle is a `term` change: its slug and archive URI before
 * and after, `null` on the side where it is not there. Its **dependent terms**
 * — the descendants whose archive URI an edit or a delete moved — are each a
 * change of their own, and a delete that moved posts into the taxonomy's
 * default term reports that term once. A term's change never reports the
 * posts in it. See `docs/adr/0040-a-term-reports-itself-and-a-post-the-terms-it-is-in.md`.
 *
 * What only this suite can see is the order WordPress does it in:
 * `wp_update_term()` writes both of the term's rows before `edit_term`, and
 * `wp_delete_term()` moves the children up a level before `delete_term`.
 *
 * @package NextJsRevalidate
 */

namespace NextJsRevalidate\Tests;

use NextJsRevalidate\Change;

class TermChangeTest extends PendingChangesTestCase {

	/**
	 * The taxonomies this test registered, to be unregistered after it.
	 *
	 * @var string[]
	 */
	private $registered = [];

	public function set_up() {
		parent::set_up();

		$this->set_permalink_structure( '/%postname%/' );

		// A taxonomy adds its archive's permastruct when it is registered, and
		// only on a site with pretty permalinks — which this one had not yet.
		create_initial_taxonomies();

		$this->configure_site();
	}

	public function tear_down() {
		foreach ( $this->registered as $taxonomy ) unregister_taxonomy( $taxonomy );
		$this->registered = [];

		remove_all_filters( 'nextjs_revalidate_should_revalidate_taxonomy' );

		parent::tear_down();
	}

	// Create, edit
	// ====

	public function test_creating_a_category_reports_no_before() {
		$video = $this->category( 'Video' );

		$this->assertPendingChanges( [
			Change::term( $video, 'category', null, Change::term_side( 'video', '/category/video/' ) ),
		] );
	}

	/**
	 * The name is what the front-end shows, so the change is sent with equal
	 * sides rather than not at all.
	 */
	public function test_renaming_a_category_label_reports_equal_sides() {
		$video = $this->category( 'Video' );
		$this->reset_pending_changes();

		wp_update_term( $video, 'category', [ 'name' => 'Videos' ] );

		$side = Change::term_side( 'video', '/category/video/' );
		$this->assertPendingChanges( [ Change::term( $video, 'category', $side, $side ) ] );
	}

	public function test_changing_a_category_slug_reports_both_slugs_and_uris() {
		$video = $this->category( 'Video' );
		$this->reset_pending_changes();

		wp_update_term( $video, 'category', [ 'slug' => 'videos' ] );

		$this->assertPendingChanges( [
			Change::term(
				$video,
				'category',
				Change::term_side( 'video', '/category/video/' ),
				Change::term_side( 'videos', '/category/videos/' )
			),
		] );
	}

	// Dependent terms
	// ====

	public function test_changing_a_parent_slug_reports_every_descendant_at_both_uris() {
		$parent     = $this->category( 'Media' );
		$child      = $this->category( 'Video', $parent );
		$grandchild = $this->category( 'Short', $child );
		$this->reset_pending_changes();

		wp_update_term( $parent, 'category', [ 'slug' => 'press' ] );

		$this->assertPendingChanges( [
			Change::term( $parent, 'category', Change::term_side( 'media', '/category/media/' ), Change::term_side( 'press', '/category/press/' ) ),
			Change::term( $child, 'category', Change::term_side( 'video', '/category/media/video/' ), Change::term_side( 'video', '/category/press/video/' ) ),
			Change::term( $grandchild, 'category', Change::term_side( 'short', '/category/media/video/short/' ), Change::term_side( 'short', '/category/press/video/short/' ) ),
		] );
	}

	/**
	 * An edit that moves nothing does not walk the tree.
	 */
	public function test_a_label_only_edit_of_a_parent_reports_only_the_parent() {
		$parent = $this->category( 'Media' );
		$this->category( 'Video', $parent );
		$this->reset_pending_changes();

		wp_update_term( $parent, 'category', [ 'name' => 'Press', 'description' => 'Everything printed.' ] );

		$side = Change::term_side( 'media', '/category/media/' );
		$this->assertPendingChanges( [ Change::term( $parent, 'category', $side, $side ) ] );
	}

	public function test_moving_a_category_under_another_parent_reports_it_and_its_descendants() {
		$old_parent = $this->category( 'Media' );
		$new_parent = $this->category( 'Press' );
		$moved      = $this->category( 'Video', $old_parent );
		$child      = $this->category( 'Short', $moved );
		$this->reset_pending_changes();

		wp_update_term( $moved, 'category', [ 'parent' => $new_parent ] );

		$this->assertPendingChanges( [
			Change::term( $moved, 'category', Change::term_side( 'video', '/category/media/video/' ), Change::term_side( 'video', '/category/press/video/' ) ),
			Change::term( $child, 'category', Change::term_side( 'short', '/category/media/video/short/' ), Change::term_side( 'short', '/category/press/video/short/' ) ),
		] );
	}

	// Delete
	// ====

	/**
	 * WordPress moves a deleted term's children up a level, which moves their
	 * archives, and their children's.
	 */
	public function test_deleting_a_category_with_children_reports_each_child_at_its_new_uri() {
		$parent     = $this->category( 'Media' );
		$deleted    = $this->category( 'Video', $parent );
		$child      = $this->category( 'Short', $deleted );
		$grandchild = $this->category( 'Clip', $child );
		$this->reset_pending_changes();

		wp_delete_term( $deleted, 'category' );

		$this->assertPendingChanges( [
			Change::term( $deleted, 'category', Change::term_side( 'video', '/category/media/video/' ), null ),
			Change::term( $child, 'category', Change::term_side( 'short', '/category/media/video/short/' ), Change::term_side( 'short', '/category/media/short/' ) ),
			Change::term( $grandchild, 'category', Change::term_side( 'clip', '/category/media/video/short/clip/' ), Change::term_side( 'clip', '/category/media/short/clip/' ) ),
		] );
	}

	/**
	 * The posts the category held alone move into *Uncategorized*, whose
	 * archive gains them: reported once, however many moved. The posts
	 * themselves are not reported.
	 */
	public function test_deleting_a_category_with_posts_reports_it_and_the_default_category_once() {
		$video = $this->category( 'Video' );
		$other = $this->category( 'Podcast' );
		self::factory()->post->create( [ 'post_status' => 'publish', 'post_category' => [ $video ] ] );
		self::factory()->post->create( [ 'post_status' => 'publish', 'post_category' => [ $video ] ] );
		self::factory()->post->create( [ 'post_status' => 'publish', 'post_category' => [ $video, $other ] ] );
		$this->reset_pending_changes();

		wp_delete_term( $video, 'category' );

		$default = (int) get_option( 'default_category' );
		$side    = Change::term_side( get_term( $default )->slug, Change::uri_of( get_term_link( $default, 'category' ) ) );

		$this->assertPendingChanges( [
			Change::term( $video, 'category', Change::term_side( 'video', '/category/video/' ), null ),
			Change::term( $default, 'category', $side, $side ),
		] );
	}

	/**
	 * A tag has no default term: its posts just lose it.
	 */
	public function test_deleting_a_tag_with_posts_reports_only_the_tag() {
		$tag = (int) wp_insert_term( 'Live', 'post_tag' )['term_id'];
		self::factory()->post->create( [ 'post_status' => 'publish', 'tags_input' => [ 'live' ] ] );
		$this->reset_pending_changes();

		wp_delete_term( $tag, 'post_tag' );

		$this->assertPendingChanges( [
			Change::term( $tag, 'post_tag', Change::term_side( 'live', '/tag/live/' ), null ),
		] );
	}

	// Candidates
	// ====

	public function test_a_term_of_a_taxonomy_that_is_not_revalidatable_reports_nothing() {
		$this->register( 'njr_internal', [ 'public' => true, 'publicly_queryable' => false, 'hierarchical' => true ] );

		$term = (int) wp_insert_term( 'Hidden', 'njr_internal' )['term_id'];
		wp_update_term( $term, 'njr_internal', [ 'slug' => 'still-hidden' ] );
		wp_delete_term( $term, 'njr_internal' );

		$this->assertNoPendingChanges();
	}

	public function test_a_term_the_filter_declines_reports_nothing() {
		add_filter( 'nextjs_revalidate_should_revalidate_taxonomy', function ( $should, $taxonomy ) {
			return 'category' === $taxonomy ? false : $should;
		}, 10, 2 );

		$video = $this->category( 'Video' );
		wp_update_term( $video, 'category', [ 'slug' => 'videos' ] );
		wp_delete_term( $video, 'category' );

		$this->assertNoPendingChanges();
	}

	// Pending changes
	// ====

	public function test_a_term_created_and_deleted_in_one_request_reports_nothing() {
		$brief = $this->category( 'Brief' );
		wp_delete_term( $brief, 'category' );

		$this->assertNoPendingChanges();
	}

	public function test_a_term_edited_twice_in_one_request_is_one_change() {
		$video = $this->category( 'Video' );
		$this->reset_pending_changes();

		wp_update_term( $video, 'category', [ 'slug' => 'videos' ] );
		wp_update_term( $video, 'category', [ 'slug' => 'films' ] );

		$this->assertPendingChanges( [
			Change::term(
				$video,
				'category',
				Change::term_side( 'video', '/category/video/' ),
				Change::term_side( 'films', '/category/films/' )
			),
		] );
	}

	// Helpers
	// ====

	/**
	 * Create a category, as the admin's form does.
	 *
	 * @param string $name
	 * @param int    $parent
	 * @return int The term ID.
	 */
	private function category( $name, $parent = 0 ) {
		$term = wp_insert_term( $name, 'category', [ 'parent' => $parent ] );
		$this->assertIsArray( $term, 'The fixture category was not created.' );

		return (int) $term['term_id'];
	}

	/**
	 * Register a taxonomy over posts for this test only.
	 *
	 * @param string $taxonomy
	 * @param array  $args
	 * @return void
	 */
	private function register( $taxonomy, array $args ) {
		register_taxonomy( $taxonomy, 'post', $args );
		$this->registered[] = $taxonomy;
	}
}
