<?php

namespace NextJsRevalidate\Traits;

use WP_Error;

/**
 * One request to the front-end, and one vocabulary for how it turned out.
 *
 * Every request this plugin makes goes to the same app, over the same
 * transport, with the same secret in a query arg — a revalidation of a single
 * path and an FSE snapshot invalidation differ only in the URL they compose.
 * What must not differ is the answer: `unreachable` and `http_401` send an
 * operator to completely different places, and a second caller that collapsed
 * them into a bare false would be a second thing to learn.
 *
 * So the naming of the outcome lives here, once, and the callers own only the
 * URL and how long they are willing to wait for it.
 * See `docs/adr/0004-at-most-once-revalidation.md` for why the outcome is the
 * only trace a delivery which did not succeed ever leaves.
 */
trait FrontEndRequest {

	/**
	 * Ask the front-end for a URL, and name what came back.
	 *
	 * Nothing is thrown out of here. The queue drain runs this in a loop while
	 * holding a running-cron count, and the FSE snapshot calls it from
	 * `shutdown` — in both places a throw would cost far more than the one
	 * request that produced it.
	 *
	 * @param string $url     The fully composed URL, secret included.
	 * @param int    $timeout Seconds to wait for an answer.
	 *
	 * @return true|WP_Error True when the front-end answered 200. Otherwise a
	 *                       WP_Error whose code names the outcome:
	 *                       `unreachable` when the front-end was not reached,
	 *                       `no_response` when it answered without a status,
	 *                       `http_{status}` when it answered with one other
	 *                       than 200, and `exception` when the attempt threw.
	 */
	protected function send_front_end_request( $url, $timeout ) {

		try {
			$response = wp_remote_get(
				$url,
				[ 'timeout' => $timeout ]
			);

			// The request never got an answer — DNS, TLS, a timeout. What the
			// transport has to say about it is the diagnostic, so it is carried
			// over rather than thrown away.
			if ( is_wp_error($response) ) return new WP_Error( 'unreachable', $response->get_error_message() );

			$status = intval( wp_remote_retrieve_response_code( $response ) );

			if ( 200 === $status ) return true;

			// An answer with no status line at all is not an HTTP outcome to
			// report back, and `http_0` would name nothing an operator can act on.
			if ( 0 === $status ) return new WP_Error( 'no_response', __( 'The front-end answered without a status code.', 'nextjs-revalidate' ) );

			return new WP_Error(
				"http_$status",
				sprintf(
					/* translators: %d: the HTTP status code the front-end answered with. */
					__( 'The front-end answered %d.', 'nextjs-revalidate' ),
					$status
				)
			);
		} catch (\Throwable $th) {
			return new WP_Error( 'exception', $th->getMessage() );
		}
	}
}
