<?php
/**
 * What a Nested Pages drag and drop reports —
 * NextJsRevalidate\Integrations\NestedPages.
 *
 * Nested Pages writes a sorted page's order and parent with `$wpdb`, and
 * saves nothing, so no save hook reports it (#180). Each page the sort moved —
 * whose parent, order or URI differs after it — is reported as a `post`
 * change, with the URI it had before the sort and the one it has after. Driven
 * through Nested Pages' own `npsort` AJAX handler.
 *
 * @package NextJsRevalidate
 */

namespace NextJsRevalidate\Tests;

use NextJsRevalidate\Change;

class NestedPagesOrderTest extends ReorderedPostsTestCase {

	public function set_up() {
		parent::set_up();

		if ( ! defined( 'NESTEDPAGES_DIR' ) ) $this->markTestSkipped( 'Nested Pages is not installed.' );

		// Nested Pages sets its AJAX listeners up only on an admin request, as
		// `admin-ajax.php` is and a test run is not.
		new \NestedPages\Form\Events();
	}

	public function tear_down() {
		remove_all_filters( 'nestedpages_use_update_post' );
		$this->reset_log();

		parent::tear_down();
	}

	public function test_moving_a_page_under_another_reports_it_and_its_children_at_both_uris() {
		$about = $this->published( 'page', 'about', 0, 0 );
		$team  = $this->published( 'page', 'team', 0, 1 );
		$alice = $this->published( 'page', 'alice', $team, 0 );

		$this->reset_pending_changes();

		// `team` dragged under `about`, with its child.
		$this->sort( [
			[ 'id' => $about, 'children' => [
				[ 'id' => $team, 'children' => [ [ 'id' => $alice ] ] ],
			] ],
		] );

		$this->assertSame( $about, get_post( $team )->post_parent, 'Nested Pages did not move the page.' );

		$pending = $this->pending_changes()->pending();
		$this->assertContains( Change::post( $team, 'page', '/team/', '/about/team/' ), $pending );
		$this->assertContains( Change::post( $alice, 'page', '/team/alice/', '/about/team/alice/' ), $pending );
	}

	/**
	 * Reordered, not moved: the listings its type shows are in another order,
	 * so the page is reported where it is.
	 */
	public function test_reordering_pages_reports_those_whose_order_changed() {
		$first  = $this->published( 'page', 'first', 0, 0 );
		$second = $this->published( 'page', 'second', 0, 1 );
		$third  = $this->published( 'page', 'third', 0, 2 );

		$this->reset_pending_changes();

		// `second` and `third` swap; `first` stays where it was.
		$this->sort( [ [ 'id' => $first ], [ 'id' => $third ], [ 'id' => $second ] ] );

		$this->assertEqualSets(
			[
				Change::post( $second, 'page', '/second/', '/second/' ),
				Change::post( $third, 'page', '/third/', '/third/' ),
			],
			$this->pending_changes()->pending()
		);
	}

	public function test_a_sort_that_changes_nothing_reports_nothing() {
		$first  = $this->published( 'page', 'first', 0, 0 );
		$second = $this->published( 'page', 'second', 0, 1 );

		$this->reset_pending_changes();

		$this->sort( [ [ 'id' => $first ], [ 'id' => $second ] ] );

		$this->assertNoPendingChanges();
	}

	/**
	 * Nested Pages' "update post hook" setting saves each page with
	 * `wp_update_post()` *before* it writes the new parent, so that save
	 * reports the page where it was. The sort's own change carries it to where
	 * it is: one change, the URI before the sort and the URI after.
	 */
	public function test_with_its_update_post_hook_a_moved_page_is_one_change_from_before_to_after() {
		add_filter( 'nestedpages_use_update_post', '__return_true' );

		$about = $this->published( 'page', 'about', 0, 0 );
		$team  = $this->published( 'page', 'team', 0, 1 );

		$this->reset_pending_changes();

		$this->sort( [ [ 'id' => $about, 'children' => [ [ 'id' => $team ] ] ] ] );

		$this->assertContains( Change::post( $team, 'page', '/team/', '/about/team/' ), $this->pending_changes()->pending() );
	}

	/**
	 * A request whose list cannot be read — Nested Pages changed what it
	 * sends — still reports each page the sort wrote, as it stands: a moved
	 * page loses its old URI, and its listings are still told. The log says
	 * so.
	 */
	public function test_without_a_list_to_read_each_sorted_page_is_reported_as_it_stands() {
		$this->enable_logs();
		$first  = $this->published( 'page', 'first', 0, 0 );
		$second = $this->published( 'page', 'second', 0, 1 );

		$this->reset_pending_changes();

		$repository = new \NestedPages\Entities\Post\PostUpdateRepository();
		$repository->updateOrder( [ [ 'id' => $second ], [ 'id' => $first ] ], 0 );

		$this->assertEqualSets(
			[
				Change::post( $first, 'page', '/first/', '/first/' ),
				Change::post( $second, 'page', '/second/', '/second/' ),
			],
			$this->pending_changes()->pending()
		);
		$this->assertStringContainsString( "Post #$first was reordered or reparented", $this->log() );
	}

	/**
	 * The pages a sort names are looked up before Nested Pages handles it —
	 * which is what the sorts below must not reach.
	 */
	public function test_a_sort_with_nested_pages_nonce_has_its_pages_looked_up() {
		$first  = $this->published( 'page', 'first', 0, 0 );
		$second = $this->published( 'page', 'second', 0, 1 );

		$built = $this->permalinks_built( function () use ( $first, $second ) {
			$this->sort( [ [ 'id' => $second ], [ 'id' => $first ] ] );
		} );

		$this->assertGreaterThan( 0, $built );
	}

	/**
	 * A sort without Nested Pages' nonce is one it refuses, and one anyone
	 * logged in can send, naming as many pages as they like. None is looked
	 * up, nothing is reported, and Nested Pages still answers it its own way:
	 * nothing here dies or answers in its place (#187).
	 *
	 * @dataProvider nonces_nested_pages_refuses
	 *
	 * @param string|null $nonce The nonce sent, or null for none.
	 */
	public function test_a_sort_without_nested_pages_nonce_looks_up_no_page_and_reports_nothing( $nonce ) {
		$first  = $this->published( 'page', 'first', 0, 0 );
		$second = $this->published( 'page', 'second', 0, 1 );

		$this->reset_pending_changes();

		$body = [
			'list'      => [ [ 'id' => $second ], [ 'id' => $first ] ],
			'post_type' => 'page',
			'syncmenu'  => 'nosync',
		];
		if ( null !== $nonce ) $body['nonce'] = $nonce;

		// Nested Pages reads its nonce without asking whether there is one.
		set_error_handler( function ( $errno, $errstr ) {
			return E_WARNING === $errno && false !== strpos( $errstr, 'nonce' );
		} );

		$answer = null;
		try {
			$built = $this->permalinks_built( function () use ( $body, &$answer ) {
				$answer = $this->ajax( 'npsort', $body );
			} );
		} finally {
			restore_error_handler();
		}

		$this->assertSame( 0, $built, 'A page was looked up.' );
		$this->assertNoPendingChanges();

		$this->assertSame( [ 'status' => 'error', 'message' => 'Invalid Nonce' ], json_decode( $answer, true ) );
		$this->assertSame( 1, get_post( $second )->menu_order );
	}

	public function nonces_nested_pages_refuses() {
		return [
			'no nonce'       => [ null ],
			'an empty nonce' => [ '' ],
			'a forged nonce' => [ 'forged1234' ],
		];
	}

	public function test_an_unconfigured_site_refuses_a_sort() {
		$first  = $this->published( 'page', 'first', 0, 0 );
		$second = $this->published( 'page', 'second', 0, 1 );

		$this->unconfigure_site();
		$this->reset_pending_changes();

		$this->sort( [ [ 'id' => $second ], [ 'id' => $first ] ] );

		$this->assertNoPendingChanges();
	}

	// Fixtures
	// ====

	/**
	 * Sort the pages as Nested Pages' list screen does.
	 *
	 * @param array $list The tree, as its script posts it.
	 * @return void
	 */
	private function sort( array $list ) {
		$this->ajax( 'npsort', [
			'nonce'     => wp_create_nonce( 'nestedpages-nonce' ),
			'list'      => $list,
			'post_type' => 'page',
			'syncmenu'  => 'nosync',
		] );
	}
}
