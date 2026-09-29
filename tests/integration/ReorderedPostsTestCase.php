<?php
/**
 * The base case for tests that drive a reordering plugin's AJAX handler.
 *
 * @package NextJsRevalidate
 */

namespace NextJsRevalidate\Tests;

use WPDieException;

/**
 * An administrator, pretty permalinks, and an AJAX handler that answers by
 * throwing rather than by ending the process.
 *
 * Nested Pages and Simple Custom Post Order both answer their AJAX requests
 * with `wp_send_json()`, which ends the request with `die` unless the request
 * is an AJAX one — and then ends it through `wp_die()`. This case makes every
 * request an AJAX one, and `wp_die()` throw, so a test can drive the handler
 * through `do_action()` exactly as `admin-ajax.php` does, and read the pending
 * changes after it has answered.
 */
abstract class ReorderedPostsTestCase extends PendingChangesTestCase {

	public function set_up() {
		parent::set_up();

		$this->set_permalink_structure( '/%postname%/' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', function () {
			return function ( $message ) {
				throw new WPDieException( is_string( $message ) ? $message : '' );
			};
		} );

		$this->configure_site();
	}

	public function tear_down() {
		$_POST    = [];
		$_REQUEST = [];

		parent::tear_down();
	}

	/**
	 * Run an AJAX action as `admin-ajax.php` would, with the given request
	 * body, until the handler answers.
	 *
	 * @param string $action The action, without its `wp_ajax_` prefix.
	 * @param array  $post   The request body.
	 * @return string What the handler answered.
	 */
	protected function ajax( $action, array $post ) {
		// `check_ajax_referer()` reads the nonce from `$_REQUEST`, which PHP
		// builds from the request body before WordPress runs.
		$_POST    = $post;
		$_REQUEST = $post;

		ob_start();
		try {
			do_action( "wp_ajax_$action" );
		} catch ( WPDieException $answered ) {
			// The handler answered, which is what ends it.
		}

		return (string) ob_get_clean();
	}

	/**
	 * A published post, of a type, under a parent or none.
	 *
	 * @param string $type
	 * @param string $slug
	 * @param int    $parent     Optional. Default 0.
	 * @param int    $menu_order Optional. Default 0.
	 * @return int
	 */
	protected function published( $type, $slug, $parent = 0, $menu_order = 0 ) {
		return self::factory()->post->create( [
			'post_type'   => $type,
			'post_status' => 'publish',
			'post_name'   => $slug,
			'post_title'  => $slug,
			'post_parent' => $parent,
			'menu_order'  => $menu_order,
		] );
	}
}
