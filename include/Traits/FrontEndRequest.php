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
	 * How many redirects one delivery follows before the last one is its
	 * outcome.
	 *
	 * A trailing-slash redirect is one hop, and nothing a front-end has a reason
	 * to answer takes more than two; the cap is what keeps a front-end
	 * redirecting to itself from holding the request until its timeout.
	 *
	 * @var int
	 */
	protected static $max_redirects = 2;

	/**
	 * Post a JSON body to the front-end, and name what came back.
	 *
	 * `Authorization: Bearer <secret>` rather than a query arg: it is the
	 * header most logging and tracing tools already redact, and it keeps the
	 * secret out of every access log the URL would have landed in (ADR 0034).
	 *
	 * Any 2xx is a success, because a `POST` may answer 202 or 204 as readily as
	 * 200. A 307 or a 308 to the same origin is followed — it is how a Next.js
	 * app with `trailingSlash: true` answers `/api/revalidate` — and no other
	 * redirect is (ADR 0039). A 301 or a 302 has not taken the changes, and
	 * following it would turn the `POST` into a `GET` of some other page, whose
	 * 200 would then be recorded as a success. A redirect to another origin
	 * would hand the secret to whoever it names. Either one, like a redirect
	 * past `$max_redirects`, is its own outcome: `http_{status}`.
	 *
	 * The timeout is the caller's, for the whole delivery: a redirect is
	 * followed only with what is left of it.
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
	 * @return true|WP_Error True when the front-end answered any 2xx, after any
	 *                       redirect it was right to follow. Otherwise
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

			// `'redirection' => 0` because the transport's own following knows no
			// origin and turns a 301 into a `GET`; the redirects this method
			// does follow, it follows itself, below.
			$args = [
				'timeout'     => $timeout,
				'redirection' => 0,
				'headers'     => [
					'Authorization' => 'Bearer ' . $secret,
					'Content-Type'  => 'application/json',
				],
				'body'        => $json,
			];

			$deadline = microtime( true ) + $timeout;
			$hops     = 0;

			while ( true ) {
				$response = wp_remote_post( $url, $args );

				// The request never got an answer — DNS, TLS, a timeout. What the
				// transport has to say about it is the diagnostic, so it is carried
				// over rather than thrown away — redacted, because none of it was
				// written here.
				if ( is_wp_error($response) ) return new WP_Error( 'unreachable', self::redact_secret( $response->get_error_message(), $secret ) );

				$status = intval( wp_remote_retrieve_response_code( $response ) );

				if ( $status >= 200 && $status < 300 ) return true;

				$next = self::redirect_to_follow( $url, $status, $response );
				$left = $deadline - microtime( true );

				if ( '' === $next || $hops >= self::$max_redirects || $left <= 0 ) break;

				// The same method, headers and body, to where the front-end said
				// they belong — which is what a 307 and a 308 ask for.
				$url             = $next;
				$args['timeout'] = $left;
				$hops++;
			}

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
	 * Where a redirect the front-end answered should be followed to, or '' when
	 * it should not be.
	 *
	 * Only a 307 or a 308, because those are the two that promise the method
	 * and the body are to be sent again unchanged. Only to the origin the
	 * request was sent to — scheme, host and port — because the request carries
	 * the secret, and a redirect is the front-end naming a URL the operator
	 * never typed.
	 *
	 * The credentials of the URL the request went to carry over, whichever shape
	 * the `Location` takes, because a staging front-end behind basic auth would
	 * answer the next hop 401 without them. A `Location` that is a path — the
	 * shape Next.js answers a trailing-slash redirect with — is joined to that
	 * URL, credentials and all. One that is an absolute URL on the same origin —
	 * the shape a proxy rewriting `Location` answers with — is given its
	 * credentials, and is otherwise followed exactly as spelt; one naming
	 * credentials of its own keeps them, because the front-end named them. The
	 * origin leaves the credentials out, so neither shape is refused for them.
	 *
	 * A relative path, or a `Location` given more than once, is not followed —
	 * neither is anything a front-end has a reason to send, and guessing at one
	 * is worse than reporting it.
	 *
	 * @param string $from     The URL the request was sent to.
	 * @param int    $status   The status it was answered with.
	 * @param array  $response What the transport answered.
	 *
	 * @return string The URL to send the request to next, or ''.
	 */
	protected static function redirect_to_follow( $from, $status, $response ) {

		if ( 307 !== $status && 308 !== $status ) return '';

		$location = wp_remote_retrieve_header( $response, 'location' );
		if ( ! is_string( $location ) ) return '';

		$location = trim( $location );
		if ( '' === $location ) return '';

		// A path on the same origin: everything in `$from` before its own path,
		// then the path. A `//` opens a URL of another host, not a path.
		if ( '/' === $location[0] && '//' !== substr( $location, 0, 2 ) ) {
			if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://[^/?\#]+#i', $from, $prefix ) ) return '';

			return $prefix[0] . $location;
		}

		$origin = self::origin( $from );
		if ( '' === $origin || self::origin( $location ) !== $origin ) return '';

		// An absolute URL on the same origin: as spelt, with the credentials of
		// `$from` after its scheme unless it names credentials of its own.
		// `origin()` has already proved both are `scheme://host` URLs.
		$credentials = self::credentials( $from );
		if ( '' === $credentials || '' !== self::credentials( $location ) ) return $location;

		if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $location, $scheme ) ) return '';

		return $scheme[0] . $credentials . substr( $location, strlen( $scheme[0] ) );
	}

	/**
	 * A URL's credentials as spelt in it, `@` included — `user:pass@` — or ''
	 * for a URL that names none.
	 *
	 * Read off the authority rather than out of `wp_parse_url()`'s `user` and
	 * `pass`, so they carry over exactly as typed, percent-encoding and all.
	 * Everything up to the last `@` before the path is the credentials: a host
	 * cannot hold one.
	 *
	 * @param string $url
	 *
	 * @return string
	 */
	protected static function credentials( $url ) {

		return preg_match( '#^[a-z][a-z0-9+.-]*://([^/?\#]*@)#i', $url, $match ) ? $match[1] : '';
	}

	/**
	 * A URL's scheme, host and port, spelled one way, or '' for a URL that is
	 * not an `http` or `https` one with a host.
	 *
	 * The port is spelled out when the URL leaves it to its scheme, so
	 * `https://example.com` and `https://example.com:443` are the one origin
	 * they are.
	 *
	 * @param string $url
	 *
	 * @return string
	 */
	protected static function origin( $url ) {

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) return '';

		$scheme = strtolower( $parts['scheme'] );
		if ( 'http' !== $scheme && 'https' !== $scheme ) return '';

		$port = $parts['port'] ?? ( 'https' === $scheme ? 443 : 80 );

		return $scheme . '://' . strtolower( $parts['host'] ) . ':' . $port;
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
