<?php

namespace NextJsRevalidate\Traits;

use WP_Error;

/**
 * One request to the front-end, and one vocabulary for how it turned out.
 *
 * Every request this plugin makes goes to the same app, over the same
 * transport, carrying the same secret: the v2 `POST` of a site's **pending
 * changes**, with the secret in an `Authorization` header — whether they are
 * delivered when a request ends or as a probe. What must not differ between
 * those callers is the answer: `unreachable` and `http_401` send an operator to
 * completely different places, and a second caller that collapsed them into a
 * bare false would be a second thing to learn.
 *
 * So the naming of the outcome lives here, once, and the callers own only what
 * they send and how long they are willing to wait for it. v1's `GET`, naming
 * one path with the secret in a query arg, went with the revalidation queue
 * that sent it (ADR 0034).
 * See `docs/adr/0004-at-most-once-revalidation.md` for why the outcome is the
 * only trace a delivery which did not succeed ever leaves, and
 * `docs/adr/0034-changes-are-delivered-when-the-request-ends.md` for the `POST`.
 *
 * This is also the one place in the plugin where a string of *arbitrary origin*
 * meets a request holding the secret, so it is where the secret is redacted out
 * of one again — see `docs/adr/0023-the-transport-redacts-the-secret.md`, and
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
	 * Post a JSON body to the front-end, and name what came back.
	 *
	 * `Authorization: Bearer <secret>` rather than a query arg: it is the
	 * header most logging and tracing tools already redact, and it keeps the
	 * secret out of every access log the URL would have landed in (ADR 0034).
	 *
	 * Any 2xx is a success, because a `POST` may answer 202 or 204 as readily as
	 * 200. Redirects are not followed: a front-end answering 301 has not taken
	 * the changes, and following it would turn the `POST` into a `GET` of some
	 * other page, whose 200 would then be recorded as a success.
	 *
	 * Nothing is thrown out of here. The pending changes are delivered from
	 * `shutdown`, or in the middle of a save when a request reaches the cap, and neither is a place a throw belongs.
	 *
	 * The one place a `WP_Error` for a request is minted, so every message one
	 * carries passes through `redact_secret()`.
	 *
	 * @param string $url     The endpoint URL. It carries no secret.
	 * @param array  $body    What to send, encoded as JSON.
	 * @param int    $timeout Seconds to wait for an answer.
	 *
	 * @return true|WP_Error True when the front-end answered any 2xx. Otherwise
	 *                       a WP_Error whose code names the outcome:
	 *                       `unreachable` when the front-end was not reached,
	 *                       `no_response` when it answered without a status,
	 *                       `http_{status}` when it answered with one outside
	 *                       2xx, and `exception` when the attempt threw. The two
	 *                       of those carrying a message this plugin did not
	 *                       write are redacted first.
	 */
	protected function send_front_end_changes( $url, array $body, $timeout ) {

		// Read inside the try, and initialised before it, because the redaction
		// below runs in the catch block: reading a setting goes through
		// `get_option()` and therefore through whatever filters a site has on
		// it, and a throw from *there* would leave by the one door this method
		// promises is shut. An unread secret redacts by shape alone, which is
		// the half that matters for a URL.
		$secret = '';

		try {
			$secret = (string) $this->settings->secret;

			$json = wp_json_encode( $body );

			// A change holding a string that is not UTF-8 cannot be encoded,
			// and an empty body would reach the front-end as a request it can
			// only reject. Nothing was sent, so this is no HTTP outcome.
			if ( ! is_string( $json ) ) throw new \RuntimeException( 'The changes could not be encoded as JSON.' );

			$response = wp_remote_post(
				$url,
				[
					'timeout'     => $timeout,
					'redirection' => 0,
					'headers'     => [
						'Authorization' => 'Bearer ' . $secret,
						'Content-Type'  => 'application/json',
					],
					'body'        => $json,
				]
			);

			// The request never got an answer — DNS, TLS, a timeout. What the
			// transport has to say about it is the diagnostic, so it is carried
			// over rather than thrown away — redacted, because none of it was
			// written here.
			if ( is_wp_error($response) ) return new WP_Error( 'unreachable', self::redact_secret( $response->get_error_message(), $secret ) );

			$status = intval( wp_remote_retrieve_response_code( $response ) );

			if ( $status >= 200 && $status < 300 ) return true;

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
	 * request back would put the secret into an admin notice, a REST response
	 * and a log file in `wp-content/uploads` that most hosts serve directly
	 * over HTTP.
	 *
	 * Two passes, because neither one covers the other:
	 *
	 * **By shape.** Any `secret=` query arg is blanked, with the configured
	 * value never consulted. That is the case the issue is actually about — a
	 * request URL echoed into a transport message — and it holds even when the
	 * secret cannot be read, or has been changed since the request was sent.
	 * The match is deliberately loose at the front: an arg named `api_secret`
	 * is blanked too, which over-reaches in the only direction that is safe.
	 * The `POST` carries no `secret=` arg, so in a message quoting it this pass
	 * finds nothing; it stayed for v1's `GET`, and costs nothing now that is
	 * gone (ADR 0023, amended).
	 *
	 * **By value.** The configured secret is then replaced wherever else it
	 * appears, because a message naming it without a URL around it is not
	 * reachable by looking for query args — a transport quoting the
	 * request's `Authorization` header back is exactly that message. In all
	 * the spellings it can reach a message in, not only the configured one:
	 * the secret arrives at a URL through `add_query_arg()`, which
	 * `urlencode()`s it, so a secret holding a space or a `/` is never quoted
	 * back the way it was typed.
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

		// Every spelling the configured value can appear in. `add_query_arg()`
		// urlencodes what it puts in a URL, so the encoded form is the one a
		// message quoting a request actually holds; the by-shape pass covers
		// that only while it is still sitting in a `secret=` arg, and a
		// transport is free to quote a bare URL-encoded fragment instead.
		// rawurlencode() differs only on the space — `%20` against `+` — and
		// which one comes back is the transport's choice, not ours.
		$needles = array_unique( [ $secret, urlencode( $secret ), rawurlencode( $secret ) ] );

		// Longest first: str_replace() walks the needles in order and rescans
		// what earlier ones left behind, so a shorter spelling that is part of
		// a longer one would otherwise strand the remainder in the message.
		usort(
			$needles,
			function ( $a, $b ) { return strlen( $b ) - strlen( $a ); }
		);

		return str_replace( $needles, self::$redaction, $blanked );
	}
}
