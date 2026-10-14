<?php
/**
 * What a routing setting reports, through WordPress's own option functions —
 * NextJsRevalidate\RoutingSettings.
 *
 * A routing setting added, updated to a different value, or deleted reports one
 * revalidate all of the whole site, however many of them one request saves
 * (ADR 0044). What only this suite can see is which of WordPress's option hooks
 * fire, and when: an update to the value an option already holds fires none of
 * them.
 *
 * @package NextJsRevalidate
 */

namespace NextJsRevalidate\Tests;

use NextJsRevalidate\Change;

class RoutingSettingsChangeTest extends PendingChangesTestCase {

	public function set_up() {
		parent::set_up();

		$this->configure_site();
		$this->reset_pending_changes();
	}

	public function tear_down() {
		remove_all_filters( 'nextjs_revalidate_change' );
		$this->reset_log();

		parent::tear_down();
	}

	/**
	 * Each routing setting WordPress owns, with a value it holds on the test
	 * site and a different one `sanitize_option()` keeps as it is.
	 */
	public function routing_settings() {
		return [
			'permalink_structure' => [ 'permalink_structure', '/%postname%/', '/%year%/%postname%/' ],
			'category_base'       => [ 'category_base', 'topics', 'subjects' ],
			'tag_base'            => [ 'tag_base', 'keywords', 'labels' ],
			'show_on_front'       => [ 'show_on_front', 'posts', 'page' ],
			'page_on_front'       => [ 'page_on_front', 12, 42 ],
			'page_for_posts'      => [ 'page_for_posts', 12, 42 ],
			'posts_per_page'      => [ 'posts_per_page', 10, 3 ],
		];
	}

	// Updated
	// ====

	/**
	 * @dataProvider routing_settings
	 */
	public function test_updating_a_routing_setting_reports_a_revalidate_all( $option, $before, $after ) {
		update_option( $option, $before );
		$this->reset_pending_changes();

		update_option( $option, $after );

		$this->assertPendingChanges( [ Change::all() ], "$option did not report a revalidate all." );
	}

	/**
	 * WordPress fires no update hook for an identical value.
	 *
	 * @dataProvider routing_settings
	 */
	public function test_updating_a_routing_setting_to_the_value_it_holds_reports_nothing( $option, $before ) {
		update_option( $option, $before );
		$this->reset_pending_changes();

		update_option( $option, $before );

		$this->assertNoPendingChanges( "$option reported a change for the value it already held." );
	}

	/**
	 * The Reading screen saves four of them in one request.
	 */
	public function test_saving_the_reading_screen_is_one_revalidate_all() {
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', 42 );
		update_option( 'page_for_posts', 43 );
		update_option( 'posts_per_page', 3 );

		$this->assertPendingChanges( [ Change::all() ] );
	}

	/**
	 * A routing setting and a site setting saved together are two different
	 * facts: neither is folded into the other.
	 */
	public function test_a_routing_setting_and_a_site_setting_report_one_change_each() {
		update_option( 'blogname', 'A new title' );
		update_option( 'posts_per_page', 3 );

		$this->assertPendingChanges( [ Change::settings(), Change::all() ] );
	}

	/**
	 * Through WordPress's own permalink API, as the Permalinks screen saves it.
	 */
	public function test_saving_the_permalinks_screen_is_one_revalidate_all() {
		global $wp_rewrite;

		$wp_rewrite->set_permalink_structure( '/%year%/%monthnum%/%postname%/' );
		$wp_rewrite->set_category_base( 'topics' );
		$wp_rewrite->set_tag_base( 'keywords' );

		$this->assertPendingChanges( [ Change::all() ] );
	}

	// Added and deleted
	// ====

	/**
	 * @dataProvider routing_settings
	 */
	public function test_adding_a_routing_setting_reports_a_revalidate_all( $option, $before ) {
		delete_option( $option );
		$this->reset_pending_changes();

		add_option( $option, $before );

		$this->assertPendingChanges( [ Change::all() ], "Adding $option did not report a revalidate all." );
	}

	/**
	 * @dataProvider routing_settings
	 */
	public function test_deleting_a_routing_setting_reports_a_revalidate_all( $option, $before ) {
		update_option( $option, $before );
		$this->reset_pending_changes();

		delete_option( $option );

		$this->assertPendingChanges( [ Change::all() ], "Deleting $option did not report a revalidate all." );
	}

	// What is not a routing setting
	// ====

	/**
	 * A rewrite flush, from whatever cause, is not a routing setting: plugins
	 * flush on activation and on registering a post type.
	 */
	public function test_flushing_the_rewrite_rules_reports_nothing() {
		flush_rewrite_rules();
		delete_option( 'rewrite_rules' );

		$this->assertNoPendingChanges();
	}

	public function test_an_option_that_is_not_a_routing_setting_reports_nothing() {
		update_option( 'admin_email', 'someone-else@example.test' );

		$this->assertNoPendingChanges();
	}

	// Through the pending changes
	// ====

	public function test_the_change_filter_can_drop_the_revalidate_all() {
		add_filter( 'nextjs_revalidate_change', function ( $change ) {
			return Change::ALL === $change['subject'] ? false : $change;
		} );

		update_option( 'posts_per_page', 3 );

		$this->assertNoPendingChanges();
	}

	public function test_an_unconfigured_site_refuses_the_revalidate_all() {
		$this->unconfigure_site();
		$this->enable_logs();

		update_option( 'posts_per_page', 3 );

		$this->assertNoPendingChanges();
		$this->assertStringContainsString( 'all change — site not configured', $this->log() );
	}
}
