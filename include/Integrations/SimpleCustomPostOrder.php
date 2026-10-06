<?php

namespace NextJsRevalidate\Integrations;

use NextJsRevalidate\Abstracts\Base;
use NextJsRevalidate\Interfaces\Hookable;
use NextJsRevalidate\Logger;
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
 * are in are the placed post's type's, which its change covers. Nor is the
 * renumbering its `refresh()` does as the list screen renders: it closes the
 * gaps between the numbers and keeps every post where it was.
 *
 * Should the request stop reading the way it is read here, the action still
 * says a reorder happened: each type the plugin orders is reported whole, as
 * an `all` change, and the log says why.
 *
 * Which types it orders is its setting, `scporder_options`: a type added to it
 * or taken out of it — its "reset order" takes the types it resets out — has
 * every listing in another order, and is reported whole too.
 *
 * Supported, never required: when Simple Custom Post Order is not there, this
 * registers no hooks at all.
 *
 * @property-read \NextJsRevalidate\Revalidate    $revalidate    The post changes,
 *                reached through `Base`.
 * @property-read \NextJsRevalidate\RevalidateAll $revalidateAll The revalidate
 *                all changes, reached through `Base`.
 */
class SimpleCustomPostOrder extends Base implements Hookable {
	use WhenPluginsLoaded;


	/**
	 * The AJAX action the list screen's drag and drop is sent with.
	 */
	const LIST_ACTION = 'wp_ajax_update-menu-order';

	/**
	 * The AJAX action the order column's "move to position" is sent with.
	 */
	const POSITION_ACTION = 'wp_ajax_scpo_set_position';

	/**
	 * The nonce action both AJAX actions are sent with, in their `nonce`
	 * field — the one the plugin checks before it handles either.
	 */
	const NONCE_ACTION = 'scporder_nonce_action';

	/**
	 * The option the plugin's settings are stored in, the types it orders
	 * among them.
	 */
	const OPTION = 'scporder_options';

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

		$this->when_plugins_loaded( [$this, 'register_simple_custom_post_order_hooks'] );
	}

	/**
	 * Listen to Simple Custom Post Order's reorders, if it is what this site
	 * runs.
	 *
	 * @return void
	 */
	public function register_simple_custom_post_order_hooks() {

		if ( ! defined( 'SCPORDER_VERSION' ) ) return;

		// Ahead of the plugin's own handlers, on the same actions, which write
		// the order before anything else hears of it.
		add_action( self::LIST_ACTION,     [$this, 'before_list_reorder'], 1 );
		add_action( self::POSITION_ACTION, [$this, 'before_set_position'], 1 );

		add_action( 'scp_update_menu_order', [$this, 'on_menu_order_updated'] );

		add_action( 'add_option_' . self::OPTION,    [$this, 'on_option_add'], 10, 2 );
		add_action( 'update_option_' . self::OPTION, [$this, 'on_option_update'], 10, 2 );
	}

	/**
	 * The list screen's drag and drop is about to be handled: read the order
	 * of the posts it sends.
	 *
	 * Read the way the plugin reads it — the body's `order` is a serialised
	 * form, `post[]=12&post[]=7`. Read only when the request carries the
	 * nonce the plugin is about to check; whether it is allowed beyond that is
	 * the plugin's question, and nothing is reported unless it goes on to
	 * write the order.
	 *
	 * @return void
	 */
	public function before_list_reorder() {
		if ( ! self::has_valid_nonce() ) return;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked by has_valid_nonce(), above.
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

		$this->remember_posts_to_report( $post_ids );
	}

	/**
	 * The order column's "move to position" is about to be handled: read the
	 * order of the post it places — when the request carries the plugin's
	 * nonce.
	 *
	 * @return void
	 */
	public function before_set_position() {
		if ( ! self::has_valid_nonce() ) return;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked by has_valid_nonce(), above.
		$post_id = isset( $_POST['id'] ) && is_scalar( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		if ( $post_id > 0 ) $this->remember_posts_to_report( [ $post_id ] );
	}

	/**
	 * The plugin wrote the order: report each post whose order changed — or,
	 * when nothing was read from its request, each type it orders, whole.
	 *
	 * @return void
	 */
	public function on_menu_order_updated() {
		$post_ids       = $this->post_ids;
		$this->post_ids = [];

		if ( ! empty( $post_ids ) ) {
			$this->revalidate->report_repositioned( $post_ids );
			return;
		}

		Logger::log(
			'⚠️ Simple Custom Post Order reordered posts, but which was not read from its request — every type it orders is reported whole',
			__FILE__,
			Logger::ERROR
		);

		$this->report_types( self::ordered_types( get_option( self::OPTION ) ) );
	}

	/**
	 * The plugin's settings were saved for the first time: every type it now
	 * orders has its listings in another order.
	 *
	 * @param mixed $option The option's name.
	 * @param mixed $value  The option.
	 * @return void
	 */
	public function on_option_add( $option, $value ) {
		$this->report_types( self::ordered_types( $value ) );
	}

	/**
	 * The plugin's settings were saved: each type it orders now and did not,
	 * or did and does not, has its listings in another order.
	 *
	 * @param mixed $old_value The option before.
	 * @param mixed $value     The option after.
	 * @return void
	 */
	public function on_option_update( $old_value, $value ) {
		$before = self::ordered_types( $old_value );
		$after  = self::ordered_types( $value );

		$this->report_types( array_merge( array_diff( $before, $after ), array_diff( $after, $before ) ) );
	}

	/**
	 * Report each given type whole: its posts and its term archives.
	 *
	 * @param string[] $types
	 * @return void
	 */
	private function report_types( array $types ) {
		foreach ( array_unique( $types ) as $type ) $this->revalidateAll->revalidate_all( $type );
	}

	/**
	 * The post types a value of the plugin's option has it order.
	 *
	 * @param mixed $options
	 * @return string[]
	 */
	private static function ordered_types( $options ) {
		$objects = is_array( $options ) ? ( $options['objects'] ?? null ) : null;
		if ( ! is_array( $objects ) ) return [];

		return array_values( array_filter( $objects, function ( $type ) {
			return is_string( $type ) && '' !== $type;
		} ) );
	}

	/**
	 * Whether the request carries the nonce the plugin is about to check.
	 *
	 * Checked first, so that a request the plugin refuses — one anyone logged
	 * in can send, naming as many posts as they like — has none of them looked
	 * up. Checked without dying, so that the plugin still answers it its own
	 * way. The plugin's capability is not guessed at: it is the plugin's to
	 * filter and narrow, and a guess that refused a reorder the plugin went on
	 * to write would leave it reported wrong.
	 *
	 * @return bool
	 */
	private static function has_valid_nonce() {
		return false !== check_ajax_referer( self::NONCE_ACTION, 'nonce', false );
	}

	/**
	 * Remember the posts this request's reorder is about, and where each
	 * stands before the plugin writes it.
	 *
	 * In place of whatever an earlier read left: a request the plugin refused
	 * never reached its action, and the posts it named are not this one's.
	 *
	 * @param int[] $post_ids
	 * @return void
	 */
	private function remember_posts_to_report( array $post_ids ) {
		$this->post_ids = array_values( array_unique( $post_ids ) );

		$this->revalidate->remember_positions( $post_ids );
	}
}
