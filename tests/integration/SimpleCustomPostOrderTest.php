<?php
/**
 * What a Simple Custom Post Order drag and drop reports —
 * NextJsRevalidate\Integrations\SimpleCustomPostOrder.
 *
 * Simple Custom Post Order writes the order of the posts it sorts with
 * `$wpdb->update()`, and saves nothing, so no save hook reports it (#180). Each
 * post whose order the sort changed is reported where it is, as a `post`
 * change with two equal sides: its URI did not move, but the listings of its
 * type are in another order. Driven through the plugin's own AJAX handlers.
 *
 * @package NextJsRevalidate
 */

namespace NextJsRevalidate\Tests;

use NextJsRevalidate\Change;

class SimpleCustomPostOrderTest extends ReorderedPostsTestCase {

	public function set_up() {
		parent::set_up();

		if ( ! defined( 'SCPORDER_VERSION' ) ) $this->markTestSkipped( 'Simple Custom Post Order is not installed.' );

		// The post types it sorts are the site's choice, and it sorts none
		// until one is made.
		update_option( 'scporder_options', [ 'objects' => [ 'post' ], 'tags' => [] ] );

		$this->reset_log();
	}

	public function tear_down() {
		$this->reset_log();

		parent::tear_down();
	}

	/**
	 * The list screen's drag and drop: the posts on the screen, in their new
	 * order, dealt the order values they already held.
	 */
	public function test_reordering_the_list_reports_the_posts_whose_order_changed() {
		$first  = $this->published( 'post', 'first', 0, 1 );
		$second = $this->published( 'post', 'second', 0, 2 );
		$third  = $this->published( 'post', 'third', 0, 3 );

		$this->reset_pending_changes();

		$this->ajax( 'update-menu-order', [
			'nonce' => wp_create_nonce( 'scporder_nonce_action' ),
			'order' => $this->serialized( [ $first, $third, $second ] ),
		] );

		$this->assertSame( 3, get_post( $second )->menu_order, 'Simple Custom Post Order did not reorder the posts.' );

		$this->assertEqualSets(
			[
				Change::post( $second, 'post', '/second/', '/second/', $this->uncategorized(), $this->uncategorized() ),
				Change::post( $third, 'post', '/third/', '/third/', $this->uncategorized(), $this->uncategorized() ),
			],
			$this->pending_changes()->pending()
		);
	}

	/**
	 * The order column's "move to position": one post placed, and its type
	 * renumbered around it. The post placed is reported; the listings of its
	 * type are what the others moved in.
	 */
	public function test_moving_a_post_to_a_position_reports_it() {
		$first  = $this->published( 'post', 'first', 0, 1 );
		$second = $this->published( 'post', 'second', 0, 2 );

		$this->reset_pending_changes();

		$this->ajax( 'scpo_set_position', [
			'nonce'    => wp_create_nonce( 'scporder_nonce_action' ),
			'id'       => $second,
			'position' => 1,
		] );

		$this->assertSame( 1, get_post( $second )->menu_order, 'Simple Custom Post Order did not move the post.' );

		$this->assertPendingChanges( [ Change::post( $second, 'post', '/second/', '/second/', $this->uncategorized(), $this->uncategorized() ) ] );
	}

	/**
	 * The list is read as the plugin reads it, by hand: a key whose `[]` is
	 * percent-encoded is the same key, and its posts are reported, not lost
	 * to a sanitisation that strips encoded octets (#186).
	 */
	public function test_a_list_whose_brackets_are_encoded_reports_its_posts() {
		$first  = $this->published( 'post', 'first', 0, 1 );
		$second = $this->published( 'post', 'second', 0, 2 );

		$this->reset_pending_changes();

		$this->ajax( 'update-menu-order', [
			'nonce' => wp_create_nonce( 'scporder_nonce_action' ),
			'order' => "post%5B%5D=$second&post%5B%5D=$first",
		] );

		$this->assertSame( 1, get_post( $second )->menu_order, 'Simple Custom Post Order did not reorder the posts.' );

		$this->assertEqualSets(
			[
				Change::post( $first, 'post', '/first/', '/first/', $this->uncategorized(), $this->uncategorized() ),
				Change::post( $second, 'post', '/second/', '/second/', $this->uncategorized(), $this->uncategorized() ),
			],
			$this->pending_changes()->pending()
		);
	}

	public function test_a_refused_request_reports_nothing() {
		$first  = $this->published( 'post', 'first', 0, 1 );
		$second = $this->published( 'post', 'second', 0, 2 );

		$this->reset_pending_changes();

		// A subscriber cannot reorder, and the handler answers before it
		// writes anything.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$this->ajax( 'update-menu-order', [
			'nonce' => wp_create_nonce( 'scporder_nonce_action' ),
			'order' => $this->serialized( [ $second, $first ] ),
		] );

		$this->assertNoPendingChanges();
	}

	/**
	 * The posts a request names are looked up before the plugin handles it —
	 * which is what the requests below must not reach.
	 */
	public function test_a_request_with_the_plugins_nonce_has_its_posts_looked_up() {
		$first  = $this->published( 'post', 'first', 0, 1 );
		$second = $this->published( 'post', 'second', 0, 2 );

		$this->stop_before_the_plugin( 'update-menu-order' );

		$built = $this->permalinks_built( function () use ( $first, $second ) {
			$this->ajax( 'update-menu-order', [
				'nonce' => wp_create_nonce( 'scporder_nonce_action' ),
				'order' => $this->serialized( [ $second, $first ] ),
			] );
		} );

		$this->assertSame( 2, get_post( $second )->menu_order, 'The plugin handled the request.' );
		$this->assertSame( 2, $built, 'Each post the request names was not looked up once.' );
	}

	/**
	 * A request without the plugin's nonce is one the plugin refuses, and one
	 * anyone logged in can send, naming as many posts as they like. None is
	 * looked up, nothing is reported, and the plugin still answers it its own
	 * way: nothing here dies or answers in its place (#187).
	 *
	 * @dataProvider requests_without_the_nonce
	 *
	 * @param string      $action The AJAX action, without its prefix.
	 * @param string|null $nonce  The nonce sent, or null for none.
	 */
	public function test_a_request_without_the_plugins_nonce_looks_up_no_post_and_reports_nothing( $action, $nonce ) {
		$first  = $this->published( 'post', 'first', 0, 1 );
		$second = $this->published( 'post', 'second', 0, 2 );

		$this->reset_pending_changes();

		$body = 'update-menu-order' === $action
			? [ 'order' => $this->serialized( [ $second, $first ] ) ]
			: [ 'id' => $second, 'position' => 1 ];
		if ( null !== $nonce ) $body['nonce'] = $nonce;

		$went_on = 0;
		add_action( "wp_ajax_$action", function () use ( &$went_on ) {
			$went_on++;
		}, 2 );

		$answer = null;
		$built  = $this->permalinks_built( function () use ( $action, $body, &$answer ) {
			$answer = $this->ajax( $action, $body );
		} );

		$this->assertSame( 0, $built, 'A post was looked up.' );
		$this->assertNoPendingChanges();

		$this->assertSame( 1, $went_on, 'The request did not go on to the plugin.' );
		$this->assertSame( [ -1, 403 ], $this->died_with, 'The plugin did not refuse the request with its own nonce check.' );
		$this->assertSame( '', $answer );
		$this->assertSame( 2, get_post( $second )->menu_order );
	}

	public function requests_without_the_nonce() {
		return [
			'drag and drop, no nonce'          => [ 'update-menu-order', null ],
			'drag and drop, a forged nonce'    => [ 'update-menu-order', 'forged1234' ],
			'move to position, no nonce'       => [ 'scpo_set_position', null ],
			'move to position, a forged nonce' => [ 'scpo_set_position', 'forged1234' ],
		];
	}

	/**
	 * The plugin's action says a reorder happened, not which posts. Should
	 * its request no longer be read the way it is sent, the posts are not
	 * known: each type it orders is reported whole, and the log says why.
	 */
	public function test_a_reorder_whose_request_was_not_read_reports_every_ordered_type_whole() {
		$this->enable_logs();
		$this->published( 'post', 'first', 0, 1 );

		$this->reset_pending_changes();

		// A body sent under a name this integration does not read — which
		// today's plugin refuses, and a future one might write from.
		$this->ajax( 'update-menu-order', [
			'nonce'    => wp_create_nonce( 'scporder_nonce_action' ),
			'order_v2' => 'post[]=1',
		] );
		do_action( 'scp_update_menu_order' );

		$this->assertSame( [ [ 'all', 'post' ] ], $this->subjects_and_types() );
		$this->assertStringContainsString( 'Simple Custom Post Order reordered posts', $this->log() );
	}

	/**
	 * A type the plugin starts ordering has every listing in another order,
	 * and so has one it stops ordering — its "reset order" among them.
	 */
	public function test_a_type_added_to_or_taken_out_of_the_ordered_ones_is_reported_whole() {
		$this->reset_pending_changes();

		update_option( 'scporder_options', [ 'objects' => [ 'page' ], 'tags' => [] ] );

		$this->assertEqualSets( [ [ 'all', 'post' ], [ 'all', 'page' ] ], $this->subjects_and_types() );
	}

	public function test_saving_the_settings_with_the_same_types_reports_nothing() {
		$this->reset_pending_changes();

		update_option( 'scporder_options', [ 'objects' => [ 'post' ], 'tags' => [ 'category' ] ] );

		$this->assertNoPendingChanges();
	}

	// Fixtures
	// ====

	/**
	 * The list, as jQuery UI's `sortable( 'serialize' )` sends it from the
	 * list screen.
	 *
	 * @param int[] $post_ids In their new order.
	 * @return string
	 */
	private function serialized( array $post_ids ) {
		return implode( '&', array_map( function ( $post_id ) {
			return "post[]=$post_id";
		}, $post_ids ) );
	}

	/**
	 * Each pending change's subject and type.
	 *
	 * @return array[]
	 */
	private function subjects_and_types() {
		return array_map( function ( $change ) {
			return [ $change['subject'], $change['type'] ?? null ];
		}, $this->pending_changes()->pending() );
	}
}
