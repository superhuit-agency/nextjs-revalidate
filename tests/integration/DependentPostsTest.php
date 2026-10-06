<?php
/**
 * What a save reports for the posts it moves without saving them — the
 * **dependent posts** of NextJsRevalidate\Revalidate.
 *
 * A child page's permalink is its parent's plus its own slug, so renaming or
 * moving the parent moves every descendant, and none of them is saved. Each
 * one whose URI moved is reported as a `post` change of its own, with the URI
 * it had before the save and the one it has after (#180). What only this suite
 * can see is the order WordPress does it in: the parent's row is written, and
 * its cache cleared, before any hook that follows the write — so a descendant's
 * `before` has to be read from `pre_post_update`, while the parent still has
 * its old slug.
 *
 * Also here, because it is the same question asked from the other side: a
 * post whose permalink a theme builds from something else, reported through
 * the `nextjs_revalidate_dependent_posts` filter or `nextjs_revalidate_post()`.
 *
 * @package NextJsRevalidate
 */

namespace NextJsRevalidate\Tests;

use NextJsRevalidate\Change;

class DependentPostsTest extends PendingChangesTestCase {

	public function set_up() {
		parent::set_up();

		$this->set_permalink_structure( '/%postname%/' );

		$this->configure_site();
	}

	public function tear_down() {
		remove_all_filters( 'nextjs_revalidate_dependent_posts' );
		remove_all_filters( 'nextjs_revalidate_change' );
		remove_all_filters( 'post_link' );

		parent::tear_down();
	}

	// Descendants
	// ====

	public function test_renaming_a_parent_page_reports_every_descendant_at_both_uris() {
		$parent     = $this->page( 'parent' );
		$child      = $this->page( 'child', $parent );
		$grandchild = $this->page( 'grandchild', $child );

		$this->reset_pending_changes();

		wp_update_post( [ 'ID' => $parent, 'post_name' => 'renamed' ] );

		$this->assertPendingChanges( [
			Change::post( $parent, 'page', '/parent/', '/renamed/' ),
			Change::post( $child, 'page', '/parent/child/', '/renamed/child/' ),
			Change::post( $grandchild, 'page', '/parent/child/grandchild/', '/renamed/child/grandchild/' ),
		] );
	}

	public function test_moving_a_page_under_another_parent_reports_its_children() {
		$old_parent = $this->page( 'old-parent' );
		$new_parent = $this->page( 'new-parent' );
		$moved      = $this->page( 'moved', $old_parent );
		$child      = $this->page( 'child', $moved );

		$this->reset_pending_changes();

		wp_update_post( [ 'ID' => $moved, 'post_parent' => $new_parent ] );

		$this->assertPendingChanges( [
			Change::post( $moved, 'page', '/old-parent/moved/', '/new-parent/moved/' ),
			Change::post( $child, 'page', '/old-parent/moved/child/', '/new-parent/moved/child/' ),
		] );
	}

	/**
	 * An edit moves nothing, and the descendants are not reported: most saves
	 * of a parent page are edits, and each would otherwise rebuild the tree.
	 */
	public function test_editing_a_parent_page_without_moving_it_reports_only_the_parent() {
		$parent = $this->page( 'parent' );
		$this->page( 'child', $parent );

		$this->reset_pending_changes();

		wp_update_post( [ 'ID' => $parent, 'post_content' => 'Edited.' ] );

		$this->assertPendingChanges( [ Change::post( $parent, 'page', '/parent/', '/parent/' ) ] );
	}

	/**
	 * A draft child has no page on either side, so it has nothing to report.
	 */
	public function test_a_descendant_that_is_not_on_the_front_end_is_not_reported() {
		$parent = $this->page( 'parent' );
		$this->page( 'draft-child', $parent, 'draft' );

		$this->reset_pending_changes();

		wp_update_post( [ 'ID' => $parent, 'post_name' => 'renamed' ] );

		$this->assertPendingChanges( [ Change::post( $parent, 'page', '/parent/', '/renamed/' ) ] );
	}

	/**
	 * A draft parent has no page of its own, but its published children do,
	 * and their URIs carry its slug all the same.
	 */
	public function test_renaming_a_draft_parent_reports_its_published_children() {
		$parent = $this->page( 'parent', 0, 'draft' );
		$child  = $this->page( 'child', $parent );

		$before = $this->uri_of( $child );
		$this->reset_pending_changes();

		wp_update_post( [ 'ID' => $parent, 'post_name' => 'renamed' ] );

		$after = $this->uri_of( $child );
		$this->assertNotSame( $before, $after, 'The fixture child kept its URI.' );

		$this->assertPendingChanges( [ Change::post( $child, 'page', $before, $after ) ] );
	}

	/**
	 * A post type that is not hierarchical has no descendants, whatever its
	 * `post_parent` column holds.
	 */
	public function test_renaming_a_post_reports_no_other_post() {
		$post  = self::factory()->post->create( [ 'post_status' => 'publish', 'post_name' => 'a-post' ] );
		$other = self::factory()->post->create( [ 'post_status' => 'publish', 'post_name' => 'other', 'post_parent' => $post ] );

		$this->reset_pending_changes();

		wp_update_post( [ 'ID' => $post, 'post_name' => 'renamed' ] );

		$this->assertPendingChanges( [ Change::post( $post, 'post', '/a-post/', '/renamed/', $this->uncategorized(), $this->uncategorized() ) ] );
		$this->assertNotContains( $other, array_column( $this->pending_changes()->pending(), 'id' ) );
	}

	/**
	 * A descendant saved in the same request is one change: the URI it had
	 * before the first save, and the one it has after the last.
	 */
	public function test_a_descendant_moved_and_saved_in_one_request_is_one_change() {
		$parent = $this->page( 'parent' );
		$child  = $this->page( 'child', $parent );

		$this->reset_pending_changes();

		wp_update_post( [ 'ID' => $parent, 'post_name' => 'renamed' ] );
		wp_update_post( [ 'ID' => $child, 'post_name' => 'child-renamed' ] );

		$this->assertPendingChanges( [
			Change::post( $parent, 'page', '/parent/', '/renamed/' ),
			Change::post( $child, 'page', '/parent/child/', '/renamed/child-renamed/' ),
		] );
	}

	/**
	 * A descendant a plugin saves from its parent's `save_post` is saved
	 * after the parent's row is written, and its own save would read it at
	 * its new URI. It is reported from the URI it had before the parent's.
	 */
	public function test_a_descendant_saved_during_its_parents_save_keeps_the_uri_it_had() {
		$parent = $this->page( 'about' );
		$child  = $this->page( 'team', $parent );

		$this->reset_pending_changes();

		$save_child = function ( $post_id ) use ( $parent, $child, &$save_child ) {
			if ( $parent !== $post_id ) return;

			remove_action( 'save_post', $save_child );
			wp_update_post( [ 'ID' => $child, 'post_content' => 'Edited.' ] );
		};
		add_action( 'save_post', $save_child );

		wp_update_post( [ 'ID' => $parent, 'post_name' => 'company' ] );

		$this->assertPendingChanges( [
			Change::post( $child, 'page', '/about/team/', '/company/team/' ),
			Change::post( $parent, 'page', '/about/', '/company/' ),
		] );
	}

	// Trash and delete
	// ====

	/**
	 * A permanent delete is no save: core reattaches the deleted page's
	 * children to its own parent with a direct write, and no save hook sees
	 * them. Each descendant is reported from where it was to where that left
	 * it, after the deleted page's own change (#144).
	 */
	public function test_deleting_a_parent_page_reports_every_descendant_where_it_was_reattached() {
		$parent     = $this->page( 'parent' );
		$child      = $this->page( 'child', $parent );
		$grandchild = $this->page( 'grandchild', $child );

		$this->reset_pending_changes();

		wp_delete_post( $parent, true );

		$this->assertPendingChanges( [
			Change::post( $parent, 'page', '/parent/', null ),
			Change::post( $child, 'page', '/parent/child/', '/child/' ),
			Change::post( $grandchild, 'page', '/parent/child/grandchild/', '/child/grandchild/' ),
		] );
	}

	/**
	 * A page already in the trash was reported gone when it was trashed, and
	 * its delete reports nothing of its own — but its published descendants
	 * still carried its `__trashed` slug, and lose it now.
	 */
	public function test_deleting_a_trashed_parent_page_reports_its_descendants_alone() {
		$parent     = $this->page( 'parent' );
		$child      = $this->page( 'child', $parent );
		$grandchild = $this->page( 'grandchild', $child );

		wp_trash_post( $parent );

		$this->reset_pending_changes();

		wp_delete_post( $parent, true );

		$this->assertPendingChanges( [
			Change::post( $child, 'page', '/parent__trashed/child/', '/child/' ),
			Change::post( $grandchild, 'page', '/parent__trashed/child/grandchild/', '/child/grandchild/' ),
		] );
	}

	/**
	 * Core writes the `__trashed` suffix before `pre_post_update`, so the save
	 * sees no slug change, and a URI read there already carries the suffix.
	 * The descendants are read when the trash starts.
	 */
	public function test_trashing_a_parent_page_reports_every_descendant_under_its_trashed_slug() {
		$parent     = $this->page( 'parent' );
		$child      = $this->page( 'child', $parent );
		$grandchild = $this->page( 'grandchild', $child );

		$this->reset_pending_changes();

		wp_trash_post( $parent );

		$this->assertPendingChanges( [
			Change::post( $parent, 'page', '/parent/', null ),
			Change::post( $child, 'page', '/parent/child/', '/parent__trashed/child/' ),
			Change::post( $grandchild, 'page', '/parent/child/grandchild/', '/parent__trashed/child/grandchild/' ),
		] );
	}

	/**
	 * Restoring goes through a save that gives the page its slug back, which
	 * the save path already sees. The page itself comes back a draft, with no
	 * page of its own.
	 */
	public function test_restoring_a_parent_page_reports_its_descendants_back_where_they_were() {
		$parent     = $this->page( 'parent' );
		$child      = $this->page( 'child', $parent );
		$grandchild = $this->page( 'grandchild', $child );

		wp_trash_post( $parent );

		$this->reset_pending_changes();

		wp_untrash_post( $parent );

		$this->assertPendingChanges( [
			Change::post( $child, 'page', '/parent__trashed/child/', '/parent/child/' ),
			Change::post( $grandchild, 'page', '/parent__trashed/child/grandchild/', '/parent/child/grandchild/' ),
		] );
	}

	/**
	 * A post type that is not hierarchical has no descendants, on its way to
	 * the trash or out of the site, whatever its `post_parent` column holds.
	 */
	public function test_trashing_a_post_reports_no_other_post() {
		$post  = self::factory()->post->create( [ 'post_status' => 'publish', 'post_name' => 'a-post' ] );
		$other = self::factory()->post->create( [ 'post_status' => 'publish', 'post_name' => 'other', 'post_parent' => $post ] );

		$started = $this->ids_the_filter_starts_with( $post );

		$this->reset_pending_changes();

		wp_trash_post( $post );

		$this->assertPendingChanges( [ Change::post( $post, 'post', '/a-post/', null, $this->uncategorized(), $this->uncategorized() ) ] );
		$this->assertNotContains( $other, array_column( $this->pending_changes()->pending(), 'id' ) );

		// A post's permalink is not built from its parent's, so `$other` stays
		// put either way: what pins the walk is what the filter is handed.
		$this->assertSame( [ [], [] ], $started->ids, 'The trash walked the tree of a type that has none.' );
	}

	public function test_deleting_a_post_reports_no_other_post() {
		$post  = self::factory()->post->create( [ 'post_status' => 'publish', 'post_name' => 'a-post' ] );
		$other = self::factory()->post->create( [ 'post_status' => 'publish', 'post_name' => 'other', 'post_parent' => $post ] );

		$started = $this->ids_the_filter_starts_with( $post );

		$this->reset_pending_changes();

		wp_delete_post( $post, true );

		$this->assertPendingChanges( [ Change::post( $post, 'post', '/a-post/', null, $this->uncategorized(), $this->uncategorized() ) ] );
		$this->assertNotContains( $other, array_column( $this->pending_changes()->pending(), 'id' ) );

		// Core reattaches no child of a type that is not hierarchical, so
		// `$other` stays put either way: what pins the walk is what the filter
		// is handed.
		$this->assertSame( [ [] ], $started->ids, 'The delete walked the tree of a type that has none.' );
	}

	/**
	 * A trash is a save too, and the save asks the filter again once the
	 * suffix is written. A post both name is reported once, from where it
	 * stood before the trash — not from the `__trashed` URI the save reads.
	 */
	public function test_a_descendant_the_save_also_names_is_reported_once_from_where_it_was() {
		$parent = $this->page( 'parent' );
		$child  = $this->page( 'child', $parent );

		add_filter( 'nextjs_revalidate_dependent_posts', function ( $post_ids, $post_id ) use ( $parent, $child ) {
			if ( $parent === $post_id ) $post_ids[] = $child;
			return $post_ids;
		}, 10, 2 );

		$reported = $this->count_reports_of( $child );

		$this->reset_pending_changes();

		wp_trash_post( $parent );

		$this->assertPendingChanges( [
			Change::post( $parent, 'page', '/parent/', null ),
			Change::post( $child, 'page', '/parent/child/', '/parent__trashed/child/' ),
		] );
		$this->assertSame( 1, $reported->count, 'The child was reported more than once.' );
	}

	public function test_a_descendant_is_reported_once_for_a_delete() {
		$parent = $this->page( 'parent' );
		$child  = $this->page( 'child', $parent );

		$reported = $this->count_reports_of( $child );

		$this->reset_pending_changes();

		wp_delete_post( $parent, true );

		$this->assertSame( 1, $reported->count, 'The child was not reported exactly once.' );
	}

	/**
	 * The filter is asked on a delete too, with nothing about to be written:
	 * a post whose permalink a theme builds from the deleted one moves with it.
	 */
	public function test_the_filter_adds_a_post_whose_permalink_is_built_from_a_deleted_one() {
		$page    = $this->page( 'features' );
		$feature = self::factory()->post->create( [ 'post_status' => 'publish', 'post_name' => 'a-feature' ] );
		update_post_meta( $feature, 'linked_page', $page );

		$this->link_posts_under_their_page();

		$asked = [];
		add_filter( 'nextjs_revalidate_dependent_posts', function ( $post_ids, $post_id, $post_before, $data ) use ( $page, $feature, &$asked ) {
			if ( $page !== $post_id ) return $post_ids;

			$asked[]    = [ $post_before->ID, $data ];
			$post_ids[] = $feature;
			return $post_ids;
		}, 10, 4 );

		$this->reset_pending_changes();

		wp_delete_post( $page, true );

		$this->assertSame( [ [ $page, [] ] ], $asked );
		$this->assertPendingChanges( [
			Change::post( $page, 'page', '/features/', null ),
			Change::post( $feature, 'post', '/features/a-feature/', '/a-feature/', $this->uncategorized(), $this->uncategorized() ),
		] );
	}

	// The filter
	// ====

	/**
	 * A post whose permalink a theme builds from another post's — here, a
	 * post under its linked page — is that page's to name.
	 */
	/**
	 * An update that fails once its dependent posts are read never reaches
	 * the end of its save. What it read is not the `before` of the next one:
	 * the child moved in between.
	 */
	public function test_a_failed_update_leaves_nothing_behind_for_the_next_one() {
		$parent = $this->page( 'parent' );
		$child  = $this->page( 'child', $parent );

		// As a save that fails once core has asked `pre_post_update` does.
		do_action( 'pre_post_update', $parent, [ 'post_name' => 'never' ] );

		wp_update_post( [ 'ID' => $child, 'post_name' => 'kid' ] );

		$this->reset_pending_changes();

		wp_update_post( [ 'ID' => $parent, 'post_name' => 'renamed' ] );

		$this->assertPendingChanges( [
			Change::post( $parent, 'page', '/parent/', '/renamed/' ),
			Change::post( $child, 'page', '/parent/kid/', '/renamed/kid/' ),
		] );
	}

	public function test_the_filter_adds_a_post_whose_permalink_is_built_from_the_saved_one() {
		$page    = $this->page( 'features' );
		$feature = self::factory()->post->create( [ 'post_status' => 'publish', 'post_name' => 'a-feature' ] );
		update_post_meta( $feature, 'linked_page', $page );

		$this->link_posts_under_their_page();

		add_filter( 'nextjs_revalidate_dependent_posts', function ( $post_ids, $post_id ) use ( $page, $feature ) {
			if ( $page === $post_id ) $post_ids[] = $feature;
			return $post_ids;
		}, 10, 2 );

		$this->reset_pending_changes();

		wp_update_post( [ 'ID' => $page, 'post_name' => 'all-features' ] );

		$this->assertPendingChanges( [
			Change::post( $page, 'page', '/features/', '/all-features/' ),
			Change::post( $feature, 'post', '/features/a-feature/', '/all-features/a-feature/', $this->uncategorized(), $this->uncategorized() ),
		] );
	}

	/**
	 * Named, but not moved: nothing to say about it.
	 */
	public function test_a_dependent_post_whose_uri_did_not_move_is_not_reported() {
		$page  = $this->page( 'a-page' );
		$other = self::factory()->post->create( [ 'post_status' => 'publish', 'post_name' => 'unrelated' ] );

		add_filter( 'nextjs_revalidate_dependent_posts', function ( $post_ids ) use ( $other ) {
			$post_ids[] = $other;
			return $post_ids;
		} );

		$this->reset_pending_changes();

		wp_update_post( [ 'ID' => $page, 'post_name' => 'renamed' ] );

		$this->assertPendingChanges( [ Change::post( $page, 'page', '/a-page/', '/renamed/' ) ] );
	}

	/**
	 * The filter is asked on every update, not only when a slug or a parent
	 * changes: what else a theme builds a permalink from is the theme's to
	 * know. It is handed the post before the save and the data it is saved
	 * with, to tell.
	 */
	public function test_the_filter_is_asked_with_the_post_and_the_data_it_is_saved_with() {
		$page    = $this->page( 'a-page' );
		$content = get_post( $page )->post_content;

		$asked = [];
		add_filter( 'nextjs_revalidate_dependent_posts', function ( $post_ids, $post_id, $post_before, $data ) use ( &$asked ) {
			$asked[] = [ $post_id, $post_before->post_content, $data['post_content'] ];
			return $post_ids;
		}, 10, 4 );

		wp_update_post( [ 'ID' => $page, 'post_content' => 'Edited.' ] );

		$this->assertSame( [ [ $page, $content, 'Edited.' ] ], $asked );
	}

	/**
	 * Whatever a callback returns, the save goes on: something that is not a
	 * list of IDs names no post.
	 */
	public function test_a_filter_that_returns_no_list_names_no_post() {
		$parent = $this->page( 'parent' );
		$this->page( 'child', $parent );

		add_filter( 'nextjs_revalidate_dependent_posts', '__return_null' );

		$this->reset_pending_changes();

		wp_update_post( [ 'ID' => $parent, 'post_name' => 'renamed' ] );

		$this->assertPendingChanges( [ Change::post( $parent, 'page', '/parent/', '/renamed/' ) ] );
	}

	// nextjs_revalidate_post()
	// ====

	/**
	 * A permalink built from a term moves when the term does, and no post is
	 * saved: the theme reports the URI the post had.
	 */
	public function test_the_api_reports_a_post_from_the_uri_it_had() {
		$post = self::factory()->post->create( [ 'post_status' => 'publish', 'post_name' => 'a-post' ] );

		$this->reset_pending_changes();

		$this->assertTrue( nextjs_revalidate_post( $post, home_url( '/old-category/a-post/' ) ) );

		$this->assertPendingChanges( [ Change::post( $post, 'post', '/old-category/a-post/', '/a-post/', $this->uncategorized(), $this->uncategorized() ) ] );
	}

	/**
	 * The README's recipe, as written: the permalinks read on `edit_terms`,
	 * before the term changes, and reported on `edited_term`, once core has
	 * cleared the term's cache — on `edited_terms` the term is still the old
	 * one, and the post's `after` would be its `before`.
	 */
	public function test_the_api_reports_a_post_a_term_rename_moved_following_the_readme() {
		$category = self::factory()->category->create( [ 'slug' => 'old-category' ] );
		$post     = self::factory()->post->create( [ 'post_status' => 'publish', 'post_name' => 'a-post', 'post_category' => [ $category ] ] );

		add_filter( 'post_link', function ( $permalink, $post ) {
			$terms = get_the_category( $post->ID );
			return empty( $terms ) ? $permalink : home_url( '/' . $terms[0]->slug . '/' . $post->post_name . '/' );
		}, 10, 2 );

		$before = [];
		add_action( 'edit_terms', function ( $term_id, $taxonomy ) use ( $category, $post, &$before ) {
			if ( 'category' === $taxonomy && $category === $term_id ) $before[ $post ] = get_permalink( $post );
		}, 10, 2 );
		add_action( 'edited_term', function ( $term_id, $tt_id, $taxonomy ) use ( &$before ) {
			if ( 'category' !== $taxonomy ) return;
			foreach ( $before as $post_id => $url ) nextjs_revalidate_post( $post_id, $url );
		}, 10, 3 );

		$term_before = Change::term_side( 'old-category', Change::uri_of( get_term_link( $category, 'category' ) ) );
		$this->reset_pending_changes();

		wp_update_term( $category, 'category', [ 'slug' => 'new-category' ] );

		// The term's own change comes first (#55): it is reported on
		// `edited_term` ahead of the theme's callback. The API has no record of
		// the post's terms before, so both sides list the ones it has (#188).
		$in_category = Change::post_term( $category, 'category', 'new-category' );
		$this->assertPendingChanges( [
			Change::term( $category, 'category', $term_before, Change::term_side( 'new-category', Change::uri_of( get_term_link( $category, 'category' ) ) ) ),
			Change::post( $post, 'post', '/old-category/a-post/', '/new-category/a-post/', [ $in_category ], [ $in_category ] ),
		] );
	}

	public function test_the_api_reports_a_post_as_it_stands_without_a_before() {
		$post = self::factory()->post->create( [ 'post_status' => 'publish', 'post_name' => 'a-post' ] );

		$this->reset_pending_changes();

		$this->assertTrue( nextjs_revalidate_post( $post ) );

		$this->assertPendingChanges( [ Change::post( $post, 'post', '/a-post/', '/a-post/', $this->uncategorized(), $this->uncategorized() ) ] );
	}

	public function test_the_api_declines_a_post_that_is_not_revalidatable() {
		$draft = self::factory()->post->create( [ 'post_status' => 'draft' ] );

		$this->reset_pending_changes();

		$this->assertFalse( nextjs_revalidate_post( $draft, '/somewhere/' ) );
		$this->assertFalse( nextjs_revalidate_post( 0 ) );

		$this->assertNoPendingChanges();
	}

	public function test_the_api_declines_a_before_that_names_no_path() {
		$post = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$this->reset_pending_changes();

		$this->assertFalse( nextjs_revalidate_post( $post, '' ) );

		$this->assertNoPendingChanges();
	}

	public function test_the_api_answers_false_on_an_unconfigured_site() {
		$post = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$this->unconfigure_site();

		$this->assertFalse( nextjs_revalidate_post( $post, '/before/' ) );
	}

	// Fixtures
	// ====

	/**
	 * A page, under a parent or none.
	 *
	 * @param string $slug
	 * @param int    $parent Optional. Default 0.
	 * @param string $status Optional. Default publish.
	 * @return int The page ID.
	 */
	private function page( $slug, $parent = 0, $status = 'publish' ) {
		return self::factory()->post->create( [
			'post_type'   => 'page',
			'post_status' => $status,
			'post_name'   => $slug,
			'post_title'  => $slug,
			'post_parent' => $parent,
		] );
	}

	/**
	 * Give a post with a `linked_page` the permalink of that page, followed by
	 * its own slug — a shape a theme gives a post type.
	 *
	 * @return void
	 */
	private function link_posts_under_their_page() {
		add_filter( 'post_link', function ( $permalink, $post ) {
			$page = (int) get_post_meta( $post->ID, 'linked_page', true );
			if ( ! $page ) return $permalink;

			return trailingslashit( get_permalink( $page ) ) . $post->post_name . '/';
		}, 10, 2 );
	}

	/**
	 * Record the IDs `nextjs_revalidate_dependent_posts` starts with each time
	 * it is asked about a post from here on: the descendants the plugin walked,
	 * before any callback adds to them.
	 *
	 * @param int $post_id
	 * @return \stdClass Its `ids`, one list per ask, kept up to date.
	 */
	private function ids_the_filter_starts_with( $post_id ) {
		$started = (object) [ 'ids' => [] ];

		add_filter( 'nextjs_revalidate_dependent_posts', function ( $post_ids, $asked_id ) use ( $post_id, $started ) {
			if ( $post_id === $asked_id ) $started->ids[] = $post_ids;
			return $post_ids;
		}, PHP_INT_MIN, 2 );

		return $started;
	}

	/**
	 * Count how many times a post's change is reported from here on, merged or
	 * not: the pending changes merge a post's changes into one, which would
	 * hide a second report.
	 *
	 * @param int $post_id
	 * @return \stdClass Its `count`, kept up to date.
	 */
	private function count_reports_of( $post_id ) {
		$reported = (object) [ 'count' => 0 ];

		add_filter( 'nextjs_revalidate_change', function ( $change ) use ( $post_id, $reported ) {
			if ( is_array( $change ) && 'post' === $change['subject'] && $post_id === $change['id'] ) $reported->count++;
			return $change;
		} );

		return $reported;
	}

	/**
	 * @param int $post_id
	 * @return string
	 */
	private function uri_of( $post_id ) {
		return $this->path_of( get_permalink( $post_id ) );
	}
}
