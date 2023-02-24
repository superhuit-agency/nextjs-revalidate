<?php

namespace NextJsRevalidate;

use WP_Post;

// Exit if accessed directly.
defined( 'ABSPATH' ) or die( 'Cheatin&#8217; uh?' );

class Revalidate {

	/**
	 * Constructor.
	 */
	function __construct() {
		add_action( 'wp_after_insert_post', [$this, 'on_post_save'], 99 );

		add_filter( 'page_row_actions', [$this, 'add_revalidate_row_action'], 20, 2 );
		add_filter( 'post_row_actions', [$this, 'add_revalidate_row_action'], 20, 2 );

		add_action( 'admin_init', [$this, 'maybe_revalidate_action'] );
		add_action( 'admin_notices', [$this, 'purged_notice'] );
	}

	function on_post_save( $post_id ) {
		// Bail for post type not viewable, nor autosave or revision, as in some cases it saves a draft!
		if ( !is_post_publicly_viewable($post_id) || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id) ) return;

		// Bail early if current request is for saving the metaboxes. (To not duplicate the purge query)
		if ( isset($_REQUEST['meta-box-loader']) ) return;

		// Ensure we do not fire this action twice. Safekeeping
		remove_action( 'wp_after_insert_post', [$this, 'on_post_save'], 99 );

		$this->purge( get_permalink( $post_id ) );
	}

	function purge( $permalink ) {
		global $NEXTJS_REVALIDATE;

		$url = $NEXTJS_REVALIDATE->settings->url;
		if ( empty($url) ) return false;

		$secret = $NEXTJS_REVALIDATE->settings->secret;
		if ( empty($secret) ) return false;

		$revalidate_uri = add_query_arg(
			[
				'path'   => wp_make_link_relative( $permalink ),
				'secret' => $secret
			],
			$url
		);

		$response = wp_remote_get( $revalidate_uri, [ 'timeout' => 60 ] );

		return ( is_wp_error($response)
			? error_log($response->get_error_message())
			: ($response['response']['code'] === 200)
		);
	}

	function add_revalidate_row_action( $actions, $post ) {

		if ( $post instanceof WP_Post || is_array( $actions ) ) {

			$actions['revalidate'] = sprintf(
				'<a href="%s" aria-label="%s">%s</a>',
				wp_nonce_url(
					add_query_arg(
						[
							'action'    => 'nextjs-revalidate-purge',
							'post'      => $post->ID,
						]
					),
					"nextjs-revalidate-purge_{$post->ID}"
				),
				esc_attr( sprintf( __('Purge cache of post “%s”', 'nextjs-revalidate'), get_the_title($post)) ),
				__('Purge cache', 'nextjs-revalidate'),
			);

		}


		return $actions;
	}

	function maybe_revalidate_action() {
		if ( ! (isset( $_GET['action'] ) && $_GET['action'] === 'nextjs-revalidate-purge' && isset($_GET['post']))  ) return;

		check_admin_referer( "nextjs-revalidate-purge_{$_GET['post']}" );

		$success = intval($this->purge( get_permalink( $_GET['post'] ) ) );

		$sendback  = wp_get_referer();
		if ( ! $sendback ) {
			$sendback = admin_url( 'edit.php' );
			$post_type = get_post_type($_GET['post']);
			if ( ! empty( $post_type ) ) {
				$sendback = add_query_arg( 'post_type', $post_type, $sendback );
			}
		}
		else {
			$sendback = remove_query_arg( [ 'trashed', 'untrashed', 'deleted', 'ids' ], $sendback );
		}

		wp_safe_redirect(
			add_query_arg( [ 'nextjs-revalidate-purged' => ($success ? $_GET['post'] : 0) ], $sendback )
		);
		exit;
	}


	function purged_notice() {
		if ( ! isset( $_GET['nextjs-revalidate-purged'] ) ) return;

		$success = boolval($_GET['nextjs-revalidate-purged']);
		printf(
			'<div class="notice notice-%s"><p>%s</p></div>',
			$success ? 'success' : 'error',
			($success
				? sprintf( __( '“%s” cache has been correctly purged.', 'nextjs-revalidate' ), get_the_title($_GET['nextjs-revalidate-purged']) )
				: __( 'The cache could not be purged correctly. Please try again or contact an administrator.', 'nextjs-revalidate' )
			)
		);
	}
}

