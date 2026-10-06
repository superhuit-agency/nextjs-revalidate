<?php

namespace NextJsRevalidate\Traits;

trait SendbackUrl {

	/**
	 * Clean the sendback url from unnecessary query args
	 *
	 * @param string|null $sendback
	 * @return string
	 */
	protected function get_sendback_url( $sendback = null ) {
		if ( empty($sendback) ) $sendback  = wp_get_referer();

		if ( ! $sendback ) {
			$sendback = admin_url( 'edit.php' );

			// The post the request names, when it names one: revalidate all
			// names none, and a bulk action names a list.
			$post_id   = ( isset( $_GET['post'] ) && is_scalar( $_GET['post'] ) ) ? absint( $_GET['post'] ) : 0;
			$post_type = $post_id ? get_post_type( $post_id ) : false;
			if ( ! empty( $post_type ) ) {
				$sendback = add_query_arg( 'post_type', $post_type, $sendback );
			}
		}

		$sendback = remove_query_arg(
			[ 'action',
				'trashed',
				'untrashed',
				'deleted',
				'ids',
				'nextjs-revalidate-revalidated',
				'nextjs-revalidate-bulk-revalidated',
				'nextjs-revalidate-type',
				'nextjs-revalidate-revalidate-all',
				'nextjs-revalidate-revalidate-all-refused',
				'nextjs-revalidate-revalidate-all-dropped',
			],
			$sendback
		);

		return $sendback;
	}
}
