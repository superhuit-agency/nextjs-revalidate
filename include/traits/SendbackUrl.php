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
			$post_type = get_post_type($_GET['post']);
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
				'nextjs-revalidate-purged',
				'nextjs-revalidate-bulk-purged',
				'nextjs-revalidate-type',
				'nextjs-revalidate-revalidate-all',
				'nextjs-revalidate-queue-resetted',
			],
			$sendback
		);

		return $sendback;
	}
}
