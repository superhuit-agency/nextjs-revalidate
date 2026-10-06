<?php

namespace NextJsRevalidate\Integrations;

use NextJsRevalidate\Abstracts\Base;
use NextJsRevalidate\Interfaces\Hookable;
use NextJsRevalidate\Traits\WhenPluginsLoaded;

// Exit if accessed directly.
defined( 'ABSPATH' ) or die( 'Cheatin&#8217; uh?' );

/**
 * The Nested Pages integration: a page dragged to another place in the tree is
 * reported.
 *
 * Nested Pages writes a sorted page's order and parent with `$wpdb` and saves
 * nothing, so no save hook sees it. A reorder leaves the listings of the type
 * in the old order, and a move under another parent moves the page's URI — and
 * its children's — with no change carrying the new one. Its optional "update
 * post hook" setting does not help: that `wp_update_post()` runs *before* the
 * SQL, so what it reports is the page where it was.
 *
 * So the pages in the sorted list are read before Nested Pages handles the
 * request, and once it has written each level of the tree, every page of that
 * level whose parent, order or URI moved is reported as a `post` change, from
 * the URI it had to the one it has. A child is in the list its parent is
 * dragged with, so a moved subtree is reported whole.
 *
 * Supported, never required: when Nested Pages is not there, this registers no
 * hooks at all.
 *
 * @property-read \NextJsRevalidate\Revalidate $revalidate The post changes,
 *                reached through `Base`.
 */
class NestedPages extends Base implements Hookable {
	use WhenPluginsLoaded;


	/**
	 * The AJAX action Nested Pages sorts its list with.
	 */
	const SORT_ACTION = 'wp_ajax_npsort';

	/**
	 * The nonce action a sort is sent with, in its `nonce` field — the one
	 * Nested Pages checks before it handles it.
	 */
	const NONCE_ACTION = 'nestedpages-nonce';

	/**
	 * Register the integration's hooks, once every plugin has declared itself
	 * — the same deferral, and for the same reason, as `Redirection`'s.
	 *
	 * @return void
	 */
	public function register_hooks(): void {

		$this->when_plugins_loaded( [$this, 'register_nested_pages_hooks'] );
	}

	/**
	 * Listen to Nested Pages' sorting, if Nested Pages is what this site runs.
	 *
	 * @return void
	 */
	public function register_nested_pages_hooks() {

		if ( ! defined( 'NESTEDPAGES_DIR' ) ) return;

		// Ahead of Nested Pages' own handler, on the same action, which writes
		// the tree before anything else hears of it.
		add_action( self::SORT_ACTION, [$this, 'before_sort'], 1 );

		add_action( 'nestedpages_posts_order_updated', [$this, 'on_posts_order_updated'] );
	}

	/**
	 * A sort is about to be handled: read where every page in its list stands.
	 *
	 * Read only when the request carries the nonce Nested Pages is about to
	 * check, so that a sort it refuses — one anyone logged in can send, naming
	 * as many pages as they like — has none of them looked up. Checked without
	 * dying, so that Nested Pages still answers it its own way. Nothing is
	 * reported unless it goes on to write the tree. No capability is checked:
	 * Nested Pages checks none when it sorts, and one guessed here could refuse
	 * a sort it then writes, which would leave that sort reported wrong.
	 *
	 * @return void
	 */
	public function before_sort() {
		$nonce = isset( $_POST['nonce'] ) && is_string( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) return;

		$list = isset( $_POST['list'] ) ? wp_unslash( $_POST['list'] ) : [];

		$this->revalidate->remember_positions( self::post_ids( $list, true ) );
	}

	/**
	 * One level of the tree was written — its pages, after their children.
	 *
	 * Reported here rather than from Nested Pages' per-page action, which it
	 * fires before it clears that page's cache: a URI read there would be the
	 * one the page had. By the end of a level, every page of it has been
	 * written and cleared, and so has every page above it.
	 *
	 * @param mixed $posts The level, as Nested Pages sorted it.
	 * @return void
	 */
	public function on_posts_order_updated( $posts = [] ) {
		$this->revalidate->report_repositioned( self::post_ids( $posts, false ) );
	}

	/**
	 * The page IDs a sorted list names: each item's `id`, and its children's
	 * when asked to.
	 *
	 * @param mixed $list        The list, as Nested Pages' script posts it.
	 * @param bool  $descendants Whether to read the children too.
	 * @return int[]
	 */
	private static function post_ids( $list, $descendants ) {

		if ( ! is_array( $list ) ) return [];

		$post_ids = [];
		foreach ( $list as $item ) {
			if ( ! is_array( $item ) ) continue;

			if ( isset( $item['id'] ) && is_scalar( $item['id'] ) && (int) $item['id'] > 0 ) $post_ids[] = (int) $item['id'];

			if ( $descendants && isset( $item['children'] ) ) {
				$post_ids = array_merge( $post_ids, self::post_ids( $item['children'], true ) );
			}
		}

		return $post_ids;
	}
}
