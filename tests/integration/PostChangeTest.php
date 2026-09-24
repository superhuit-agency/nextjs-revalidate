<?php
/**
 * What saving a post reports, through WordPress's own save functions.
 *
 * A save reports a `post` change: the post as the front-end saw it before the
 * save and as it sees it after, `null` on a side where it had or has no page
 * (ADR 0033). The handler's decisions are pinned without WordPress by
 * `tests/post-save-change-test.php` and `tests/leaving-the-front-end-test.php`;
 * what only this suite can see is the order WordPress fires things in. A save
 * of a post that keeps revisions saves one *in the middle of* the post's own
 * save, and the revision's change would stand first in the pending changes if
 * it were reported — making the post as it now is the `before` of a publish or
 * a slug change. Every update here goes through `wp_update_post()`, revisions
 * and all, so that is asserted rather than assumed.
 *
 * @package NextJsRevalidate
 */

namespace NextJsRevalidate\Tests;

use NextJsRevalidate\Change;

class PostChangeTest extends PendingChangesTestCase {

	public function set_up() {
		parent::set_up();

		// A permalink is composed from the permastruct when there is one, and
		// from a query var when there is not. Which one this site has is the
		// test's decision rather than whatever the environment was left holding.
		$this->set_permalink_structure( '/%postname%/' );

		$this->configure_site();
	}

	/**
	 * A configured site publishes a post, and the post is reported with no
	 * page before and its own after.
	 */
	public function test_publishing_a_post_on_a_configured_site_reports_it() {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Straight out' ] );

		$this->assertPendingChanges( [ Change::post( $post_id, 'post', null, $this->uri_of( $post_id ) ) ] );
	}

	/**
	 * A draft published: no `before`, whatever the revision saved on the way
	 * knows about the post.
	 */
	public function test_publishing_a_draft_reports_no_before() {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft', 'post_title' => 'Not yet' ] );

		$this->reset_pending_changes();

		wp_update_post( [ 'ID' => $post_id, 'post_status' => 'publish', 'post_content' => 'Now.' ] );

		$this->assertPendingChanges( [ Change::post( $post_id, 'post', null, $this->uri_of( $post_id ) ) ] );
	}

	/**
	 * An edit: the page is where it was. One change, however many revisions
	 * the save wrote.
	 */
	public function test_editing_a_published_post_reports_two_equal_sides() {
		$post_id = $this->published_post();
		$uri     = $this->uri_of( $post_id );

		$this->reset_pending_changes();

		wp_update_post( [ 'ID' => $post_id, 'post_content' => 'A second draft.' ] );

		$this->assertPendingChanges( [ Change::post( $post_id, 'post', $uri, $uri ) ] );
	}

	/**
	 * A slug change: the old URI is the `before`, which is what reaches the
	 * page v1 left cached.
	 */
	public function test_a_slug_change_reports_both_uris() {
		$post_id = $this->published_post();
		$before  = $this->uri_of( $post_id );

		$this->reset_pending_changes();

		wp_update_post( [ 'ID' => $post_id, 'post_name' => 'a-new-name', 'post_content' => 'Renamed.' ] );

		$after = $this->uri_of( $post_id );
		$this->assertNotSame( $before, $after, 'The fixture post kept its URI.' );

		$this->assertPendingChanges( [ Change::post( $post_id, 'post', $before, $after ) ] );
	}

	/**
	 * Trashing a post: the page it had, and none — never the `__trashed` shape
	 * the post's name takes on the way in.
	 */
	public function test_trashing_a_published_post_reports_no_after() {
		$post_id = $this->published_post();
		$uri     = $this->uri_of( $post_id );

		$this->reset_pending_changes();

		wp_trash_post( $post_id );

		$this->assertPendingChanges( [ Change::post( $post_id, 'post', $uri, null ) ] );
	}

	/**
	 * Unpublishing to a draft leaves the front-end just as trashing does.
	 */
	public function test_unpublishing_a_post_reports_no_after() {
		$post_id = $this->published_post();
		$uri     = $this->uri_of( $post_id );

		$this->reset_pending_changes();

		wp_update_post( [ 'ID' => $post_id, 'post_status' => 'draft' ] );

		$this->assertPendingChanges( [ Change::post( $post_id, 'post', $uri, null ) ] );
	}

	/**
	 * Private is on the front-end too, so publish → private is not a leaving.
	 */
	public function test_making_a_post_private_reports_two_sides() {
		$post_id = $this->published_post();
		$uri     = $this->uri_of( $post_id );

		$this->reset_pending_changes();

		wp_update_post( [ 'ID' => $post_id, 'post_status' => 'private' ] );

		$this->assertPendingChanges( [ Change::post( $post_id, 'post', $uri, $this->uri_of( $post_id ) ) ] );
	}

	/**
	 * Three saves in one request are one change: the first `before`, the last
	 * `after`.
	 */
	public function test_saving_a_post_three_times_reports_one_change() {
		$post_id = $this->published_post();
		$before  = $this->uri_of( $post_id );

		$this->reset_pending_changes();

		wp_update_post( [ 'ID' => $post_id, 'post_name' => 'second-name', 'post_content' => 'Two.' ] );
		wp_update_post( [ 'ID' => $post_id, 'post_name' => 'third-name', 'post_content' => 'Three.' ] );
		wp_update_post( [ 'ID' => $post_id, 'post_name' => 'fourth-name', 'post_content' => 'Four.' ] );

		$this->assertPendingChanges( [ Change::post( $post_id, 'post', $before, $this->uri_of( $post_id ) ) ] );
	}

	/**
	 * A revision saved on its own stands for its post, as the post is.
	 */
	public function test_a_revision_saved_on_its_own_reports_its_post() {
		$post_id = $this->published_post();
		$uri     = $this->uri_of( $post_id );

		// A revision is only written when something differs from the last one.
		global $wpdb;
		$wpdb->update( $wpdb->posts, [ 'post_content' => 'Changed behind WordPress\'s back.' ], [ 'ID' => $post_id ] );
		clean_post_cache( $post_id );

		$this->reset_pending_changes();

		$revision_id = wp_save_post_revision( $post_id );
		$this->assertNotEmpty( $revision_id, 'No revision was written.' );

		$this->assertPendingChanges( [ Change::post( $post_id, 'post', $uri, $uri ) ] );
	}

	/**
	 * A draft saved as a draft was never a candidate.
	 */
	public function test_saving_a_draft_reports_nothing() {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );

		wp_update_post( [ 'ID' => $post_id, 'post_content' => 'Still a draft.' ] );

		$this->assertNoPendingChanges();
	}

	// Fixtures
	// ====

	/**
	 * A published post of the fixture site.
	 *
	 * @return int The post id.
	 */
	private function published_post() {
		return self::factory()->post->create( [
			'post_status'  => 'publish',
			'post_title'   => 'A post that changes',
			'post_content' => 'A first draft.',
		] );
	}

	/**
	 * The URI the post has now, as a change names it.
	 *
	 * @param int $post_id
	 * @return string
	 */
	private function uri_of( $post_id ) {
		return $this->path_of( get_permalink( $post_id ) );
	}
}
