<?php

namespace NextJsRevalidate\Integrations;

use NextJsRevalidate\Abstracts\Base;
use NextJsRevalidate\Interfaces\Hookable;
use NextJsRevalidate\Traits\WhenPluginsLoaded;

// Exit if accessed directly.
defined( 'ABSPATH' ) or die( 'Cheatin&#8217; uh?' );

/**
 * The Simple Custom Post Order integration: a post dragged to another place in
 * its list is reported.
 *
 * Simple Custom Post Order writes the posts' order with `$wpdb->update()` and
 * saves nothing, so no save hook sees it, and the listings of the type stay in
 * the old order. It moves no URI — it never touches a parent — so a reordered
 * post is reported where it is, as a `post` change with two equal sides, which
 * is what tells the front-end the listings of its type changed.
 *
 * Its action, `scp_update_menu_order`, says a reorder happened and not which
 * posts, so the posts are read from the request before the plugin handles it —
 * the list screen's drag and drop sends the posts on the screen, the order
 * column's "move to position" the one post it places — and each whose order
 * the reorder changed is reported once the action has fired. The posts "move
 * to position" renumbers around the one it places are not: the listings they
 * are in are the placed post's type's, which its change covers.
 *
 * Supported, never required: when Simple Custom Post Order is not there, this
 * registers no hooks at all.
 *
 * @property-read \NextJsRevalidate\Revalidate $revalidate The post changes,
 *                reached through `Base`.
 */
class SimpleCustomPostOrder extends Base implements Hookable {
	use WhenPluginsLoaded;


	/**
	 * The posts this request's reorder is about, read from the request.
	 *
	 * @var int[]
	 */
	private array $post_ids = [];

	/**
	 * Register the integration's hooks, once every plugin has declared itself
	 * — the same deferral, and for the same reason, as `Redirection`'s.
	 *
	 * @return void
	 */
	public function register_hooks(): void {

		$this->when_plugins_loaded( [$this, 'register_scpo_hooks'] );
	}

	/**
	 * Listen to Simple Custom Post Order's reorders, if it is what this site
	 * runs.
	 *
	 * @return void
	 */
	public function register_scpo_hooks() {

		if ( ! defined( 'SCPORDER_VERSION' ) ) return;

		// Ahead of the plugin's own handlers, on the same actions, which write
		// the order before anything else hears of it.
		add_action( 'wp_ajax_update-menu-order', [$this, 'before_list_reorder'], 1 );
		add_action( 'wp_ajax_scpo_set_position', [$this, 'before_set_position'], 1 );

		add_action( 'scp_update_menu_order', [$this, 'on_menu_order_updated'] );
	}

	/**
	 * The list screen's drag and drop is about to be handled: read the order
	 * of the posts it sends.
	 *
	 * Read the way the plugin reads it — the body's `order` is a serialised
	 * form, `post[]=12&post[]=7`. Only read: whether the request is allowed is
	 * the plugin's question, and nothing is reported unless it goes on to
	 * write the order.
	 *
	 * @return void
	 */
	public function before_list_reorder() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read only; the plugin checks the nonce before it writes.
		$order = isset( $_POST['order'] ) && is_string( $_POST['order'] ) ? sanitize_text_field( wp_unslash( $_POST['order'] ) ) : '';

		$data = [];
		parse_str( $order, $data );

		$post_ids = [];
		foreach ( $data as $values ) {
			if ( ! is_array( $values ) ) continue;

			foreach ( $values as $post_id ) {
				if ( is_scalar( $post_id ) && (int) $post_id > 0 ) $post_ids[] = (int) $post_id;
			}
		}

		$this->remember( $post_ids );
	}

	/**
	 * The order column's "move to position" is about to be handled: read the
	 * order of the post it places.
	 *
	 * @return void
	 */
	public function before_set_position() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read only; the plugin checks the nonce before it writes.
		$post_id = isset( $_POST['id'] ) && is_scalar( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		if ( $post_id > 0 ) $this->remember( [ $post_id ] );
	}

	/**
	 * The plugin wrote the order: report each post whose order changed.
	 *
	 * @return void
	 */
	public function on_menu_order_updated() {
		$post_ids       = $this->post_ids;
		$this->post_ids = [];

		$this->revalidate->report_repositioned( $post_ids );
	}

	/**
	 * @param int[] $post_ids
	 * @return void
	 */
	private function remember( array $post_ids ) {
		$this->post_ids = array_values( array_unique( array_merge( $this->post_ids, $post_ids ) ) );

		$this->revalidate->remember_positions( $post_ids );
	}
}
