<?php
/**
 * What a block menu reports, through WordPress's own save, trash and delete
 * functions — NextJsRevalidate\BlockMenus.
 *
 * A block menu is a `wp_navigation` post. Saved, trashed, restored or deleted,
 * it reports one `menu` change with its post ID and no locations (ADR 0033, as
 * amended when the front-end dropped its timer). What only this suite can see
 * is which of WordPress's hooks each of those fires, and how often: a trash and
 * a restore go through `wp_insert_post()`, a permanent delete does not, and an
 * update saves a revision on its way. Each is asserted to deliver one change.
 *
 * Alongside it, what must not move: the post gate still declines every other
 * post type that is not viewable (ADR 0005), and a classic menu still reports
 * its term ID and its locations.
 *
 * @package NextJsRevalidate
 */

namespace NextJsRevalidate\Tests;

use NextJsRevalidate\Change;
use WP_REST_Request;

class BlockMenuChangeTest extends PendingChangesTestCase {

	public function set_up() {
		parent::set_up();

		$this->configure_site();
	}

	public function tear_down() {
		unregister_post_type( 'njr_internal' );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	// Saved
	// ====

	public function test_publishing_a_block_menu_reports_it() {
		$menu_id = $this->block_menu();

		$this->assertPendingChanges( [ Change::menu( $menu_id, [] ) ] );
	}

	/**
	 * An update saves a revision on its way, and the revision is not a second
	 * change.
	 */
	public function test_updating_a_block_menu_reports_it_once() {
		$menu_id = $this->block_menu();

		$this->reset_pending_changes();

		wp_update_post( [ 'ID' => $menu_id, 'post_content' => '<!-- wp:navigation-link {"label":"About","url":"/about/"} /-->' ] );

		$this->assertPendingChanges( [ Change::menu( $menu_id, [] ) ] );
	}

	/**
	 * The Site Editor saves a block menu over REST.
	 */
	public function test_updating_a_block_menu_over_rest_reports_it_once() {
		$menu_id = $this->block_menu();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->reset_pending_changes();

		$request = new WP_REST_Request( 'POST', "/wp/v2/navigation/$menu_id" );
		$request->set_body_params( [ 'content' => '<!-- wp:navigation-link {"label":"Contact","url":"/contact/"} /-->' ] );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status(), 'The REST update was not accepted.' );
		$this->assertPendingChanges( [ Change::menu( $menu_id, [] ) ] );
	}

	/**
	 * However many times one request touches a block menu, it is one change.
	 */
	public function test_a_block_menu_touched_three_times_is_one_change() {
		$menu_id = $this->block_menu();

		wp_update_post( [ 'ID' => $menu_id, 'post_content' => '<!-- wp:navigation-link {"label":"One","url":"/one/"} /-->' ] );
		wp_update_post( [ 'ID' => $menu_id, 'post_content' => '<!-- wp:navigation-link {"label":"Two","url":"/two/"} /-->' ] );

		$this->assertPendingChanges( [ Change::menu( $menu_id, [] ) ] );
	}

	// Trashed, restored and deleted
	// ====

	public function test_trashing_a_block_menu_reports_it_once() {
		$menu_id = $this->block_menu();

		$this->reset_pending_changes();

		$this->assertNotEmpty( wp_trash_post( $menu_id ), 'The block menu was not trashed.' );

		$this->assertPendingChanges( [ Change::menu( $menu_id, [] ) ] );
	}

	public function test_restoring_a_block_menu_reports_it_once() {
		$menu_id = $this->block_menu();
		wp_trash_post( $menu_id );

		$this->reset_pending_changes();

		$this->assertNotEmpty( wp_untrash_post( $menu_id ), 'The block menu was not restored.' );

		$this->assertPendingChanges( [ Change::menu( $menu_id, [] ) ] );
	}

	public function test_permanently_deleting_a_trashed_block_menu_reports_it_once() {
		$menu_id = $this->block_menu();
		wp_trash_post( $menu_id );

		$this->reset_pending_changes();

		$this->assertNotEmpty( wp_delete_post( $menu_id, true ), 'The block menu was not deleted.' );

		$this->assertPendingChanges( [ Change::menu( $menu_id, [] ) ] );
	}

	/**
	 * Deleted without going through the trash, as `wp post delete --force`
	 * does: no save hook fires at all.
	 */
	public function test_permanently_deleting_a_published_block_menu_reports_it_once() {
		$menu_id = $this->block_menu();

		$this->reset_pending_changes();

		$this->assertNotEmpty( wp_delete_post( $menu_id, true ), 'The block menu was not deleted.' );

		$this->assertPendingChanges( [ Change::menu( $menu_id, [] ) ] );
	}

	// What the front-end never renders
	// ====

	public function test_an_auto_draft_block_menu_reports_nothing() {
		$menu_id = $this->block_menu( 'auto-draft' );

		$this->assertNoPendingChanges( 'Creating an auto-draft reported a change.' );

		wp_delete_post( $menu_id, true );

		$this->assertNoPendingChanges( 'Deleting an auto-draft reported a change.' );
	}

	public function test_a_revision_of_a_block_menu_reports_nothing() {
		$menu_id = $this->block_menu();

		// A revision is only written when something differs from the last one.
		global $wpdb;
		$wpdb->update( $wpdb->posts, [ 'post_content' => '<!-- wp:navigation-link {"label":"Behind","url":"/behind/"} /-->' ], [ 'ID' => $menu_id ] );
		clean_post_cache( $menu_id );

		$this->reset_pending_changes();

		$revision_id = wp_save_post_revision( $menu_id );
		$this->assertNotEmpty( $revision_id, 'No revision was written.' );

		$this->assertNoPendingChanges( 'Saving a revision reported a change.' );

		wp_delete_post_revision( $revision_id );

		$this->assertNoPendingChanges( 'Deleting a revision reported a change.' );
	}

	// What does not move
	// ====

	/**
	 * The block menu has a producer of its own; the post gate is not widened
	 * for it, and no other type that is not viewable gains a change.
	 */
	public function test_another_post_type_that_is_not_viewable_still_reports_nothing() {
		register_post_type( 'njr_internal', [ 'public' => false ] );

		$post_id = self::factory()->post->create( [ 'post_type' => 'njr_internal', 'post_status' => 'publish' ] );
		wp_update_post( [ 'ID' => $post_id, 'post_content' => 'Edited.' ] );
		wp_trash_post( $post_id );
		wp_delete_post( $post_id, true );

		$this->assertNoPendingChanges();
	}

	public function test_the_post_gate_still_declines_a_block_menu() {
		$menu_id = $this->block_menu();

		$this->assertFalse( \NextJsRevalidate::init()->revalidate->should_revalidate( $menu_id ) );
	}

	public function test_a_classic_menu_still_reports_its_term_id_and_locations() {
		$menu_id = wp_create_nav_menu( 'Primary navigation' );
		$this->assertIsInt( $menu_id, 'The classic menu was not created.' );

		set_theme_mod( 'nav_menu_locations', [ 'primary' => $menu_id, 'footer' => $menu_id ] );

		$this->reset_pending_changes();

		wp_update_nav_menu_object( $menu_id, [ 'menu-name' => 'Primary navigation' ] );

		$this->assertPendingChanges( [ Change::menu( $menu_id, [ 'primary', 'footer' ] ) ] );
	}

	// The pipeline every change goes through
	// ====

	public function test_the_filter_can_drop_a_block_menu_change() {
		add_filter(
			'nextjs_revalidate_change',
			function ( $change ) {
				return ( Change::MENU === $change['subject'] ) ? false : $change;
			}
		);

		$this->block_menu();

		$this->assertNoPendingChanges();
	}

	public function test_the_filter_can_alter_a_block_menu_change() {
		add_filter(
			'nextjs_revalidate_change',
			function ( $change ) {
				if ( Change::MENU === $change['subject'] ) $change['kind'] = 'block';
				return $change;
			}
		);

		$menu_id = $this->block_menu();

		$this->assertPendingChanges( [ Change::menu( $menu_id, [] ) + [ 'kind' => 'block' ] ] );
	}

	public function test_an_unconfigured_site_refuses_a_block_menu_change() {
		$this->unconfigure_site();

		$this->block_menu();

		$this->assertNoPendingChanges();
	}

	/**
	 * Held until the request ends, then delivered with an empty list of
	 * locations — a JSON array, not an object.
	 */
	public function test_a_block_menu_change_is_delivered_when_the_request_ends() {
		$menu_id = $this->block_menu();

		$bodies = [];
		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) use ( &$bodies ) {
				$bodies[] = (string) ( $args['body'] ?? '' );

				return [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 204, 'message' => 'No Content' ], 'cookies' => [] ];
			},
			10,
			2
		);

		$this->assertSame( [], $bodies, 'The change was sent before the request ended.' );

		$this->pending_changes()->deliver();

		$this->assertCount( 1, $bodies );
		$this->assertStringContainsString( sprintf( '{"subject":"menu","id":%d,"locations":[]}', $menu_id ), $bodies[0] );
	}

	// Fixtures
	// ====

	/**
	 * A block menu of the fixture site.
	 *
	 * @param string $status Optional. Default 'publish'.
	 * @return int The block menu's post ID.
	 */
	private function block_menu( $status = 'publish' ) {
		return self::factory()->post->create( [
			'post_type'    => 'wp_navigation',
			'post_status'  => $status,
			'post_title'   => 'Header navigation',
			'post_content' => '<!-- wp:navigation-link {"label":"Home","url":"/"} /-->',
		] );
	}
}
