<?php

namespace NextJsRevalidate;

use Exception;
use NextJsRevalidate\Abstracts\Base;
use NextJsRevalidate\Traits\SendbackUrl;
use WP_Post;

// Exit if accessed directly.
defined( 'ABSPATH' ) or die( 'Cheatin&#8217; uh?' );

class Revalidate extends Base {
	use SendbackUrl;

	/**
	 * Constructor.
	 */
	function __construct() {
		add_action( 'wp_after_insert_post', [$this, 'on_post_save'], 99, 4 );

		add_filter( 'page_row_actions', [$this, 'add_revalidate_row_action'], 20, 2 );
		add_filter( 'post_row_actions', [$this, 'add_revalidate_row_action'], 20, 2 );
		add_action( 'admin_init', [$this, 'revalidate_row_action'] );

		add_action( 'admin_init', [$this, 'register_bulk_actions'] );

		add_action( 'admin_notices', [$this, 'purged_notice'] );
	}

	/**
	 * Determine if the given post should be revalidated.
	 *
	 * Note: the post could be not publicly viewable, but we may possibly
	 *       revalidate it anyway if it was previously publicly viewable
	 *       e.g. a post was published, but then switched back to
	 *            draft or pending.
	 *       e.g. If a post is private or password protected,
	 *            we should revalidate it.
	 */
	function should_revalidate( $post_id ) {

		$should_revalidate_post = true;

		$isPubliclyViewable = is_post_publicly_viewable($post_id);
		$isAutosave = wp_is_post_autosave($post_id) !== false;
		$status = get_post_status( $post_id );

		try {
			if ( ! $isPubliclyViewable && !in_array($status, ['publish', 'private']) ) {
				// if the post is not publicly viewable, and it's an autosave, we should not revalidate it.
				if ( $isAutosave ) throw new Exception("not viewvable", 1);

				$parent_post_id = wp_is_post_revision($post_id);

				if ( false !== $parent_post_id ) {
					$parent_status = get_post_status( $parent_post_id );

					// Bypass if Parent is published, private or password protected, we should revalidate it
					if ( !in_array($parent_status, ['publish', 'private']) ) {

						throw new Exception("not viewvable", 1);

						// TODO: This code below ti to allow to revalidate previously published post
						//       But it's not working as expected, because when retrieving the permalink
						//       of a draft/pending post the permalink is not the correct one
						// $parent_post = get_post($parent_post_id);
						// // Check if the parent has a valid gmt date, if not it means it never has been published
						// if ( '0000-00-00 00:00:00' === $parent_post->post_date_gmt ) {
						// 	throw new Exception("not viewvable", 1);
						// }
					}
				}
				else {
					throw new Exception("not viewvable", 1);
				}
			}
		} catch (\Throwable $th) {
			$should_revalidate_post = false;
		}

		/**
		 * Filters whether to revalidate the given post on save.
		 *
		 * @param bool $should_revalidate_post Whether to revalidate the post on save.
		 * @param int  $post_id                The post ID.
		 */
		return apply_filters( 'nextjs_revalidate_purge_should_revalidate_post_on_save', $should_revalidate_post, $post_id );
	}

	function on_post_save( $post_id, $post, $update, $post_before ) {
		$should_revalidate_post = $this->should_revalidate( $post_id );

		// If the post was previously published, and is now draft, we should revalidate it
		if (isset($post_before) && $post_before->post_status === 'publish' && $post->post_status === 'draft') {
			$should_revalidate_post = true;
		}

		// Bail for not viewable post
		if ( !$should_revalidate_post ) return;

		// Bail early if current request is for saving the metaboxes. (To not duplicate the purge query)
		if ( isset($_REQUEST['meta-box-loader']) ) return;

		// Ensure we do not fire this action twice. Safekeeping
		remove_action( 'wp_after_insert_post', [$this, 'on_post_save'], 99 );

		$this->queue->add_item(
			$this->get_post_permalink( $post_id, false )
		);
	}

	function purge( $permalink ) {

		if ( !$this->settings->is_configured() ) return false;

		try {
			$response = wp_remote_get(
				$this->build_revalidate_uri( $permalink ),
				[ 'timeout' => 60 ]
			);

			if ( is_wp_error($response) ) throw new \Exception("Unable to revalidate $permalink", 1);
			return $response['response']['code'] === 200;
		} catch (\Throwable $th) {
			return false;
		}
	}

	function build_revalidate_uri( $permalink ) {
		return add_query_arg(
			[
				'path'   => wp_make_link_relative( $permalink ),
				'secret' => $this->settings->secret
			],
			$this->settings->url
		);
	}

	function add_revalidate_row_action( $actions, $post ) {
		if ( $post instanceof WP_Post || is_array( $actions ) ) {
			if ( $this->settings->is_configured() ) {

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
		}


		return $actions;
	}

	function revalidate_row_action() {
		if ( ! (isset( $_GET['action'] ) && $_GET['action'] === 'nextjs-revalidate-purge' && isset($_GET['post']))  ) return;

		check_admin_referer( "nextjs-revalidate-purge_{$_GET['post']}" );

		$permalink = $this->get_post_permalink( $_GET['post'] );

		/**
		 * Filters the permalink to be added to the purge queue.
		 * Return false to prevent the permalink to be added to the purge queue.
		 *
		 * @param string|false $permalink The post permalink. False if the post is not public.
		 * @param int          $post_id   The post ID.
		 */
		$permalink = apply_filters( 'nextjs_revalidate_purge_action_permalink', $permalink, $_GET['post'] );

		if ( false !== $permalink ) $is_added = $this->queue->add_item( $permalink );

		$sendback  = $this->get_sendback_url();

		wp_safe_redirect(
			add_query_arg( [ 'nextjs-revalidate-purged' => $_GET['post'] ], $sendback )
		);
		exit;
	}

	/**
	 * Register "Purge caches" bulk action.
	 * All public post types, except "attachment" one
	 */
	function register_bulk_actions() {
		if ( !$this->settings->is_configured() ) return false;

		$post_types = get_post_types([ 'public' => true ]);

		unset( $post_types['attachment'] );

		foreach ($post_types as $post_type) {
			add_filter( "bulk_actions-edit-$post_type", [$this, 'add_revalidate_bulk_action'], 99 );
			add_filter( "handle_bulk_actions-edit-$post_type",  [$this, 'revalidate_bulk_action'], 10, 3 );
		}
	}

	function add_revalidate_bulk_action( $bulk_actions ) {
		$bulk_actions['nextjs_revalidate-bulk_purge'] = __( 'Purge caches', 'nextjs-revalidate' );
		return $bulk_actions;
	}

	function revalidate_bulk_action( $redirect_url, $action, $post_ids ) {
		if ($action === 'nextjs_revalidate-bulk_purge') {

			$purged = 0;
			foreach ($post_ids as $post_id) {
				$permalink = $this->get_post_permalink( $post_id );

				/**
				 * Filters the permalink to be added to the purge queue.
				 * Return false to prevent the permalink to be added to the purge queue.
				 *
				 * @param string|false $permalink The post permalink. False if the post is not public.
				 * @param int          $post_id   The post ID.
				 */
				$permalink = apply_filters( 'nextjs_revalidate_purge_action_permalink', $permalink, $_GET['post'] );

				if ( false !== $permalink ) {
					$this->queue->add_item( $permalink );
					$purged++;
				}
			}

			$redirect_url = add_query_arg('nextjs-revalidate-bulk-purged', $purged, $this->get_sendback_url($redirect_url));
		}

		return $redirect_url;
	}

	function purged_notice() {
		if ( isset( $_GET['nextjs-revalidate-purged'] ) ) {

			$success = boolval($_GET['nextjs-revalidate-purged']);
			printf(
				'<div class="notice notice-%s"><p>%s</p></div>',
				$success ? 'success' : 'error',
				($success
					? sprintf( __( '“%s” cache will be purged shortly.', 'nextjs-revalidate' ), get_the_title($_GET['nextjs-revalidate-purged']) )
					: __( 'Unable to purge cache. Please try again or contact an administrator.', 'nextjs-revalidate' )
				)
			);
		}

		if ( isset($_GET['nextjs-revalidate-bulk-purged']) ) {

			$nb_purged = intval($_GET['nextjs-revalidate-bulk-purged']);
			$success = $nb_purged > 0;

			printf(
				'<div class="notice notice-%s"><p>%s</p></div>',
				$success ? 'success' : 'error',
				($success
					? sprintf( _n( '%d cache will be purged shortly.', '%d caches will be purged shortly.', $nb_purged, 'nextjs-revalidate' ), $nb_purged )
					: __( 'Unable to purge cache. Please try again or contact an administrator.', 'nextjs-revalidate' )
				)
			);
		}
	}

	/**
	 * Get the post permalink.
	 *
	 * If the post_id is a revision, we should get the permalink from the parent post_id
	 * for instance when saving, the post_id is the revision id, but we want to purge the parent post permalink
	 *
	 * @param int  $post_id         The post ID.
	 * @param bool $check_if_public Optional. Whether to check if the post is public. Default true.
	 *
	 * @return string|false The post permalink. False if the post is not public.
	 */
	public function get_post_permalink( $post_id, $check_if_public = true ) {

		if ( $check_if_public ) {
			$is_public = $this->should_revalidate( $post_id );
			if ( !$is_public ) return false;
		}

		// If post_id is a revision, we should get the permalink from the parent post_id
		$parent_post_id = wp_is_post_revision($post_id);
		$post_id_for_permalink = ( false !== $parent_post_id
			? $parent_post_id
			: $post_id
		);

		return get_permalink( $post_id_for_permalink );
	}
}
