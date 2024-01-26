<?php

namespace NextJsRevalidate;

use NextJsRevalidate;
use WP_Admin_Bar;

class RevalidateAll {

	public function __construct() {
		add_action( 'admin_bar_menu', [$this, 'admin_top_bar_menu'], 100 );
		add_action( 'admin_notices', [$this, 'revalidated_notice'] );

		add_action( 'admin_init', [$this, 'revalidate_all_pages_action'] );

		add_action( 'wp_update_nav_menu', [$this, 'revalidate_all_after_menu_update'] );
	}

	function __get( $name ) {
		$njr = NextJsRevalidate::init();

		if      ( $name === 'queue' )      return $njr->queue;
		else if ( $name === 'settings' )   return $njr->settings;
		else if ( $name === 'revalidate' ) return $njr->revalidate;

		return null;
	}

	/**
	 * Admin
	 * Display the Purge all pages/posts/... dropdown in Admin top bar
	 */
	function admin_top_bar_menu( WP_Admin_Bar $admin_bar ) {

		$revalidate_all_opts = $this->settings->allow_revalidate_all;

		if ( empty($revalidate_all_opts) ) return;

		$admin_bar->add_menu( [
			'id'     => 'nextjs-revalidate',
			'title'  => _x( 'Purge caches', 'Admin top bar menu', 'nextjs-revalidate'),
			'meta'   => [
				'class' => "nextjs-revalidate",
			]
		] );

		foreach ($revalidate_all_opts as $post_type => $allow) {
			if ( $allow !== 'on' ) continue;

			if ( $post_type === 'all') {
				$name = _x('All', 'Admin top bar menu', 'nextjs-revalidate' );
			}
			else {
				$post_type_object = get_post_type_object( $post_type );
				$name = $post_type_object->labels->name;
			}

			$admin_bar->add_node( [
				'id'     => "nextjs-revalidate-all-$post_type",
				'parent' => 'nextjs-revalidate',
				'title'  => $name,
				'href'   => esc_url(
					wp_nonce_url(
						add_query_arg( ['action' => 'nextjs-revalidate-revalidate-all', 'nextjs-revalidate-type' => $post_type ] ),
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
			[ 'nextjs-revalidate-revalidate-all' => $nb_added ],
			$this->revalidate->get_sendback_url()
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
		if ( !$this->settings->is_configured() ) return false;

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
				'post_status'    => 'publish',
			]);

			foreach ($posts as $post_id) {
				$this->queue->add_item( get_permalink( $post_id ) );
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
