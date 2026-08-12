<?php

namespace NextJsRevalidate;

use NextJsRevalidate\Abstracts\Base;
use NextJsRevalidate\Traits\AdminBarMenu;
use NextJsRevalidate\Traits\SendbackUrl;
use WP_Admin_Bar;

class RevalidateAll extends Base {
	use AdminBarMenu;
	use SendbackUrl;

	public function __construct() {
		add_action( 'admin_bar_menu', [$this, 'admin_top_bar_menu'], 100 );
		add_action( 'admin_notices', [$this, 'revalidated_notice'] );

		add_action( 'admin_init', [$this, 'revalidate_all_pages_action'] );

		add_action( 'wp_update_nav_menu', [$this, 'revalidate_all_after_menu_update'] );
	}

	/**
	 * Admin
	 * Display the Purge all pages/posts/... dropdown in Admin top bar
	 */
	function admin_top_bar_menu( WP_Admin_Bar $admin_bar ) {

		// An unconfigured site refuses every revalidation, so do not offer the
		// menu at all — the same way the row action and the bulk action are
		// not offered. The unconfigured notice says why.
		if ( !$this->settings->is_configured() ) return;

		$revalidate_all_opts = $this->settings->allow_revalidate_all;

		if ( empty($revalidate_all_opts) ) return;

		$this->add_admin_bar_menu( $admin_bar );

		foreach ($revalidate_all_opts as $post_type => $allow) {
			if ( $allow !== 'on' ) continue;

			if ( $post_type === 'all') {
				$name = _x('All', 'Admin top bar menu', 'nextjs-revalidate' );
			}
			else {
				$post_type_object = get_post_type_object( $post_type );
				// Do not continue if post_type_object is null. I can happen if the post type is not publicly_queryable
				if (!$post_type_object) continue;
				$name = $post_type_object->labels->name;
			}

			$admin_bar->add_node( [
				'id'     => "nextjs-revalidate-all-$post_type",
				'parent' => 'nextjs-revalidate',
				'title'  => $name,
				'href'   => esc_url(
					wp_nonce_url(
						add_query_arg(
							[
								'action'                 => 'nextjs-revalidate-revalidate-all',
								'nextjs-revalidate-type' => $post_type
							]
						),
						'nextjs-revalidate-revalidate-all'
					)
				),
				'meta'   => [
					'title' => _x( 'Purging all cache may take some time according to the number of pages to purge.', 'Admin top bar menu', 'nextjs-revalidate' ),
				]
			] );
		}
	}

	/**
	 * Display a success admin notice when all page revalidate has been triggered
	 */
	function revalidated_notice() {
		if ( isset($_GET['nextjs-revalidate-revalidate-all-refused']) ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Revalidate all: nothing was queued, this site is not configured.', 'nextjs-revalidate' )
			);
			return;
		}

		if ( !isset($_GET['nextjs-revalidate-revalidate-all']) ) return;

		printf(
			'<div class="notice notice-success"><p>%s</p></div>',
			sprintf(
				__( 'Purge all: %d pages added to purge. Please wait until all pages are purged.', 'nextjs-revalidate' ),
				$_GET['nextjs-revalidate-revalidate-all']
			)
		);
	}

	/**
	 * Revalidate all action
	 */
	function revalidate_all_pages_action() {
		if ( ! (isset( $_GET['action'] ) && $_GET['action'] === 'nextjs-revalidate-revalidate-all')  ) return;

		check_admin_referer( 'nextjs-revalidate-revalidate-all' );

		$nb_added = $this->revalidate_all( $_GET['nextjs-revalidate-type'] );
		$sendback = add_query_arg(
			( false === $nb_added
				? [ 'nextjs-revalidate-revalidate-all-refused' => 1 ]
				: [ 'nextjs-revalidate-revalidate-all' => $nb_added ]
			),
			$this->get_sendback_url()
		);

		wp_safe_redirect( $sendback );
		exit;
	}

	/**
	 * Revalidate all content after a menu update
	 *
	 * @param int $menu_id
	 * @return void
	 */
	function revalidate_all_after_menu_update( $menu_id ) {
		$revalidate_on_save = $this->settings->revalidate_on_menu_save;

		if (isset($revalidate_on_save['all']) && $revalidate_on_save['all'] === 'on') {
			$this->revalidate_all();
		}
		else {
			foreach ($revalidate_on_save as $post_type => $enabled) {
				if ( $enabled !== 'on' ) continue;
				$this->revalidate_all($post_type);
			}
		}
	}

	/**
	 * Retrive all post type content nodes to revalidate, saves them in option
	 * and schedule the revalidate all cron to run.
	 *
	 * @param string $type Optional. The type of post type to revalidate. Default. 'all'.
	 * @return int The number of nodes added to revalidate
	 */
	function revalidate_all( $type = 'all' ) {
		if ( !$this->settings->is_configured() ) {
			Logger::log(
				sprintf( '⛔ Refused revalidate all (%s) — site not configured (missing: %s)', $type, implode(', ', $this->settings->missing_settings()) ),
				__FILE__,
				Logger::ERROR
			);
			return false;
		}

		$count = 0;
		if ( $type === 'all' ) {
			// retrieve all public post types except attachments
			$post_types = array_filter(get_post_types([ 'public' => true ]), function($pt) { return $pt !== 'attachment'; });
		}
		else {
			$post_types = [ $type ];
		}

		foreach ($post_types as $post_type) {
			$posts = get_posts([
				'post_type'      => $post_type,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'post_status'    => ['publish', 'private'],
			]);

			foreach ($posts as $post_id) {
				$permalink = $this->revalidate->get_post_permalink( $post_id );

				// Skip a post holding no front-end page to rebuild,
				// rather than queueing an empty permalink
				if ( empty($permalink) ) continue;

				$this->queue->add_item( $permalink );
				$count++;
			}
		}

		// retrieve all public taxonomies
		$args = [
			'public' => true,
		];
		if ( $type !== 'all' ) $args['object_type'] = [ $type ];
		$taxonomies = get_taxonomies($args);
		foreach ($taxonomies as $taxonomy) {
			$terms = get_terms([
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'ids',
			]);

			foreach ($terms as $term_id) {
				$this->queue->add_item( get_term_link( $term_id ) );
				$count++;
			}
		}

		return $count;
	}
}
