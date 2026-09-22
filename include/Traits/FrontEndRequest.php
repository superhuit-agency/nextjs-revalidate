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
 *
 * This is also the one place in the plugin where a string of *arbitrary origin*
 * meets a URL holding the secret, so it is where the secret is redacted out of
 * one again — see `docs/adr/0020-the-transport-redacts-the-secret.md`, and
 * `redact_secret()` below.
 *
 * @property \NextJsRevalidate\Settings $settings
 */
trait FrontEndRequest {

	/**
	 * What a redacted secret is replaced with.
	 *
	 * A property rather than a constant: constants in traits are PHP 8.2, and
	 * this plugin's floor is 7.4 (ADR-0016).
	 *
	 * @var string
	 */
	protected static $redaction = '***';

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
	 *                       The two of those carrying a message this plugin did
	 *                       not write are redacted first.
	 */
	protected function send_front_end_request( $url, $timeout ) {

		// Read inside the try, and initialised before it, because the redaction
		// below runs in the catch block: reading a setting goes through
		// `get_option()` and therefore through whatever filters a site has on
		// it, and a throw from *there* would leave by the one door this method
		// promises is shut. An unread secret redacts by shape alone, which is
		// the half that matters for a URL.
		$secret = '';

		try {
			$secret = (string) $this->settings->secret;

			$response = wp_remote_get(
				$url,
				[ 'timeout' => $timeout ]
			);

			// The request never got an answer — DNS, TLS, a timeout. What the
			// transport has to say about it is the diagnostic, so it is carried
			// over rather than thrown away — redacted, because none of it was
			// written here.
			if ( is_wp_error($response) ) return new WP_Error( 'unreachable', self::redact_secret( $response->get_error_message(), $secret ) );

			$status = intval( wp_remote_retrieve_response_code( $response ) );

			if ( 200 === $status ) return true;

			// An answer with no status line at all is not an HTTP outcome to
			// report back, and `http_0` would name nothing an operator can act on.
			//
			// Deliberately not redacted, here or below: both messages are
			// `__()` literals this plugin wrote, so a redaction could only
			// mangle a string already known to be safe.
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
			return new WP_Error( 'exception', self::redact_secret( $th->getMessage(), $secret ) );
		}
	}

	/**
	 * One message of arbitrary origin, with the secret taken out of it.
	 *
	 * Applied to every message this plugin did not write itself, because what
	 * is on the other end of one is a transport or a `pre_http_request` filter
	 * rather than anything in this repository — and a message quoting the
	 * request URL back would put the secret into an admin notice, a REST
	 * response and a log file in `wp-content/uploads` that most hosts serve
	 * directly over HTTP.
	 *
	 * Two passes, because neither one covers the other:
	 *
	 * **By shape.** Any `secret=` query arg is blanked, with the configured
	 * value never consulted. That is the case the issue is actually about — a
	 * request URL echoed into a transport message — and it holds even when the
	 * secret cannot be read, or has been changed since the request was sent.
	 * The match is deliberately loose at the front: an arg named `api_secret`
	 * is blanked too, which over-reaches in the only direction that is safe.
	 *
	 * **By value.** The configured secret is then replaced wherever else it
	 * appears, because a message naming it without a URL around it is not
	 * reachable by looking for query args.
	 *
	 * There is deliberately **no minimum-length guard**. A secret's only
	 * validation in this plugin is that it is non-empty, so a one-character
	 * secret is a legal configuration — and a length guard would mean the site
	 * where this hole is worst is the one that silently opts out of the fix.
	 * Blind replacement against a short or common secret will mangle unrelated
	 * text (`in 1.2s` becoming `in ***.2s`), and that is the cheaper failure:
	 * a garbled diagnostic fails safe, a length guard fails open.
	 *
	 * @param string $message The message as it came back.
	 * @param string $secret  The configured secret, or '' when it could not be read.
	 *
	 * @return string The message, safe to write anywhere this plugin writes.
	 */
	protected static function redact_secret( $message, $secret ) {

		$message = (string) $message;

		// preg_replace() answers null only on a failure of the pattern itself,
		// which is a constant here; keeping the message unredacted would be the
		// wrong way to be wrong, so an unusable answer costs the whole message.
		$blanked = preg_replace( '/secret=[^&\s]*/i', 'secret=' . self::$redaction, $message );

		if ( ! is_string( $blanked ) ) return self::$redaction;

		$secret = (string) $secret;

		// str_replace() with an empty needle is a no-op rather than an error,
		// but saying so out loud is cheaper than making the next reader check.
		if ( '' === $secret ) return $blanked;

		return str_replace( $secret, self::$redaction, $blanked );
	}
}
