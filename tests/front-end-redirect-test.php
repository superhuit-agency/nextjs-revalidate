<?php
/**
 * Which redirects a delivery follows — `Traits\FrontEndRequest`.
 *
 * A Next.js app with `trailingSlash: true` answers `POST /api/revalidate` with
 * a 308 to `/api/revalidate/`, and a delivery that stopped there never reached
 * one. So a 307 or a 308 to the origin the request was sent to is followed, with
 * the same method, headers and body; nothing else is. Each claim below is a way
 * following could go wrong:
 *
 *  - **The secret stays on the configured origin.** A redirect to another
 *    scheme, host or port is the front-end naming a URL the operator never
 *    typed, and the request carries the secret in a header.
 *  - **A 301 or a 302 is still a failure.** Following one would turn the `POST`
 *    into a `GET` of some other page, and record its 200 as a delivery.
 *  - **A front-end redirecting to itself cannot hold the request.** The hops
 *    are capped, and the last redirect is the outcome.
 *  - **Basic-auth credentials go with the request.** A staging front-end would
 *    answer the next hop 401 without them, whether the `Location` is a path or
 *    an absolute URL on the same origin. They stay on that origin, as the
 *    secret does.
 *
 * See `docs/adr/0039-a-delivery-follows-a-redirect-that-keeps-the-request.md`.
 *
 * Reachable by stubbing a handful of WordPress functions, so it is a standalone
 * script rather than a PHPUnit test — see `docs/adr/0008-two-testing-idioms.md`.
 *
 * Run with `npm run test:php`, or `php tests/front-end-redirect-test.php`.
 */

if ( 'cli' !== PHP_SAPI ) die( 'This file must be run from the command line.' );

// The plugin files bail when this is not defined.
define( 'ABSPATH', __DIR__ . '/' );

/**
 * What each `wp_remote_post()` answers, in turn, keyed by the URL it was sent
 * to. A URL answered more than once repeats its last answer.
 * @var array<string, array>
 */
$GLOBALS['njr_test_answers'] = [];

/**
 * Every `wp_remote_post()` since the last reset, as `[ url, args ]`.
 * @var array
 */
$GLOBALS['njr_test_posts'] = [];

// WordPress stubs
// ====

function __( $text, $domain = null ) { return $text; }

function wp_json_encode( $data ) { return json_encode( $data ); }

function wp_parse_url( $url, $component = -1 ) {
	return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
}

function wp_remote_post( $url, $args = [] ) {
	$GLOBALS['njr_test_posts'][] = [ $url, $args ];

	return $GLOBALS['njr_test_answers'][ $url ] ?? [ 'response' => [ 'code' => 404 ] ];
}

function wp_remote_retrieve_response_code( $response ) {
	return $response['response']['code'] ?? '';
}

function wp_remote_retrieve_header( $response, $header ) {
	return $response['headers'][ $header ] ?? '';
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

class WP_Error {
	private $code;
	private $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}

// The subject
// ====

require_once __DIR__ . '/../include/Traits/FrontEndRequest.php';

/** The trait's using class, reduced to a secret and a way in. */
class NextJsRevalidate_Test_Transport {
	use NextJsRevalidate\Traits\FrontEndRequest;

	/** @var object */
	public $settings;

	public function __construct() {
		$this->settings = new class {
			public function __get( $name ) {
				return 'secret' === $name ? 'sup3r-s3cret' : null;
			}
		};
	}

	public function send( $url, $timeout = 5 ) {
		return $this->send_front_end_changes( $url, [ 'version' => 2, 'changes' => [ [ 'subject' => 'templates' ] ] ], $timeout );
	}
}

// The harness
// ====

$failures = 0;

function njr_test_assert( $condition, $description ) {
	global $failures;

	if ( $condition ) {
		printf( "ok   — %s\n", $description );
		return;
	}

	$failures++;
	printf( "FAIL — %s\n", $description );
}

/** A redirect, as the transport hands one back. */
function njr_redirect( $status, $location ) {
	return [ 'response' => [ 'code' => $status ], 'headers' => [ 'location' => $location ] ];
}

/**
 * Deliver to `$url` against a front-end answering `$answers`, and answer the
 * outcome's code — 'ok' for a delivery — and the URLs the request went to.
 *
 * @return array{string, string[]}
 */
function njr_deliver( $url, array $answers, $timeout = 5 ) {
	$GLOBALS['njr_test_answers'] = $answers;
	$GLOBALS['njr_test_posts']   = [];

	$outcome = ( new NextJsRevalidate_Test_Transport() )->send( $url, $timeout );

	return [
		is_wp_error( $outcome ) ? $outcome->get_error_code() : 'ok',
		array_column( $GLOBALS['njr_test_posts'], 0 ),
	];
}

$endpoint = 'https://front-end.test/api/revalidate';
$slashed  = 'https://front-end.test/api/revalidate/';
$ok       = [ 'response' => [ 'code' => 204 ] ];

// Followed
// ====

// The case the issue is about: Next.js answers a trailing-slash redirect with a
// path, not a URL.
foreach ( [ 307, 308 ] as $status ) {
	list( $code, $urls ) = njr_deliver( $endpoint, [ $endpoint => njr_redirect( $status, '/api/revalidate/' ), $slashed => $ok ] );

	njr_test_assert( 'ok' === $code, "a $status to a path on the same origin is followed to a delivery" );
	njr_test_assert( [ $endpoint, $slashed ] === $urls, "a $status is followed to the path it names, and nowhere else" );
}

// The same request, again: a 307 and a 308 promise the method and the body.
njr_deliver( $endpoint, [ $endpoint => njr_redirect( 308, '/api/revalidate/' ), $slashed => $ok ] );
list( list( , $first ), list( , $second ) ) = $GLOBALS['njr_test_posts'];
njr_test_assert( $first['body'] === $second['body'], 'the body is sent again unchanged' );
njr_test_assert( $first['headers'] === $second['headers'], 'the headers — the secret among them — are sent again unchanged' );
njr_test_assert( 0 === $second['redirection'], "the transport's own redirect following stays off on the next hop" );

// An absolute Location on the same origin, spelled however the front-end likes.
foreach (
	[
		'the same URL form'          => $slashed,
		'the default port spelt out' => 'https://front-end.test:443/api/revalidate/',
		'the host in capitals'       => 'https://FRONT-END.test/api/revalidate/',
	] as $what => $location
) {
	list( $code, $urls ) = njr_deliver( $endpoint, [ $endpoint => njr_redirect( 308, $location ), $location => $ok ] );

	njr_test_assert( 'ok' === $code && [ $endpoint, $location ] === $urls, "a 308 to an absolute URL on the same origin — $what — is followed" );
}

// A path keeps the credentials and the port of the URL it is joined to: a
// staging front-end behind basic auth would answer the next hop 401 without them.
$staging = 'http://user:pass@localhost:8083/revalidate';
list( $code, $urls ) = njr_deliver( $staging, [ $staging => njr_redirect( 308, '/revalidate/' ), 'http://user:pass@localhost:8083/revalidate/' => $ok ] );
njr_test_assert( 'ok' === $code && 'http://user:pass@localhost:8083/revalidate/' === ( $urls[1] ?? '' ), 'a path is joined to the credentials and port the request was sent with' );

// So does an absolute URL on the same origin, which is how a proxy rewriting
// `Location` answers the same redirect (#184). The credentials are not part of
// the origin, so without them the next hop is the one that answers 401.
list( $code, $urls ) = njr_deliver( $staging, [ $staging => njr_redirect( 308, 'http://localhost:8083/revalidate/' ), 'http://user:pass@localhost:8083/revalidate/' => $ok ] );
njr_test_assert( 'ok' === $code && [ $staging, 'http://user:pass@localhost:8083/revalidate/' ] === $urls, 'an absolute URL on the same origin is given the credentials the request was sent with' );

// Everything but the credentials is kept as the front-end spelt it.
$spelt = 'HTTP://LocalHost:8083/revalidate/?x=1';
list( $code, $urls ) = njr_deliver( $staging, [ $staging => njr_redirect( 307, $spelt ), 'HTTP://user:pass@LocalHost:8083/revalidate/?x=1' => $ok ] );
njr_test_assert( 'ok' === $code && 'HTTP://user:pass@LocalHost:8083/revalidate/?x=1' === ( $urls[1] ?? '' ), 'the credentials are added to the Location without respelling its scheme, host, port, path or query' );

// The default port, which the URL the request went to leaves to its scheme.
$defaulted = 'https://user:pass@front-end.test/api/revalidate';
list( $code, $urls ) = njr_deliver( $defaulted, [ $defaulted => njr_redirect( 308, 'https://front-end.test:443/api/revalidate/' ), 'https://user:pass@front-end.test:443/api/revalidate/' => $ok ] );
njr_test_assert( 'ok' === $code && 'https://user:pass@front-end.test:443/api/revalidate/' === ( $urls[1] ?? '' ), 'an absolute URL spelling out the default port is given the credentials too' );

// A Location naming credentials of its own keeps them: the front-end named
// them, and they are not overridden.
$own = 'http://other:word@localhost:8083/revalidate/';
list( $code, $urls ) = njr_deliver( $staging, [ $staging => njr_redirect( 308, $own ), $own => $ok ] );
njr_test_assert( 'ok' === $code && [ $staging, $own ] === $urls, 'an absolute URL with credentials of its own is followed exactly as given' );

// A request sent without credentials has none to carry over.
$bare = 'http://localhost:8083/revalidate';
list( $code, $urls ) = njr_deliver( $bare, [ $bare => njr_redirect( 308, 'http://localhost:8083/revalidate/' ), 'http://localhost:8083/revalidate/' => $ok ] );
njr_test_assert( 'ok' === $code && [ $bare, 'http://localhost:8083/revalidate/' ] === $urls, 'an absolute URL is followed exactly as given when the request carried no credentials' );

// Each redirect starts from the URL it was sent to, so the credentials a first
// hop carried over reach the third request too.
list( $code, $urls ) = njr_deliver(
	$staging,
	[
		$staging                                         => njr_redirect( 308, '/revalidate/' ),
		'http://user:pass@localhost:8083/revalidate/'    => njr_redirect( 307, 'http://localhost:8083/v2/revalidate/' ),
		'http://user:pass@localhost:8083/v2/revalidate/' => $ok,
	]
);
njr_test_assert( 'ok' === $code && [ $staging, 'http://user:pass@localhost:8083/revalidate/', 'http://user:pass@localhost:8083/v2/revalidate/' ] === $urls, 'a path then an absolute URL on the same origin both carry the credentials' );

// Only an `@` in the authority names credentials: one in the Location's path,
// query or fragment is the front-end's to spell, and the credentials still go
// in front of the host.
foreach (
	[
		'its path'     => [ 'http://localhost:8083/by/a@b/', 'http://user:pass@localhost:8083/by/a@b/' ],
		'its query'    => [ 'http://localhost:8083?by=a@b', 'http://user:pass@localhost:8083?by=a@b' ],
		'its fragment' => [ 'http://localhost:8083/revalidate/#a@b', 'http://user:pass@localhost:8083/revalidate/#a@b' ],
	] as $what => list( $location, $next )
) {
	list( $code, $urls ) = njr_deliver( $staging, [ $staging => njr_redirect( 308, $location ), $next => $ok ] );

	njr_test_assert( 'ok' === $code && [ $staging, $next ] === $urls, "an @ in $what is not taken for credentials of the Location's own" );
}

// The credentials carry over exactly as typed, percent-encoding and all.
$encoded = 'http://us%3Aer:p%40ss@localhost:8083/revalidate';
list( $code, $urls ) = njr_deliver( $encoded, [ $encoded => njr_redirect( 308, 'http://localhost:8083/revalidate/' ), 'http://us%3Aer:p%40ss@localhost:8083/revalidate/' => $ok ] );
njr_test_assert( 'ok' === $code && 'http://us%3Aer:p%40ss@localhost:8083/revalidate/' === ( $urls[1] ?? '' ), 'percent-encoded credentials carry over as typed' );

// An IPv6 host is bracketed, and the credentials go in front of the bracket.
$ipv6 = 'http://user:pass@[::1]:8083/revalidate';
list( $code, $urls ) = njr_deliver( $ipv6, [ $ipv6 => njr_redirect( 308, 'http://[::1]:8083/revalidate/' ), 'http://user:pass@[::1]:8083/revalidate/' => $ok ] );
njr_test_assert( 'ok' === $code && 'http://user:pass@[::1]:8083/revalidate/' === ( $urls[1] ?? '' ), 'an absolute URL on an IPv6 host is given the credentials too' );

// Not followed
// ====

// Another origin is someone the operator never named, and the request carries
// the secret.
foreach (
	[
		'another host'               => 'https://elsewhere.test/api/revalidate/',
		'a subdomain'                => 'https://www.front-end.test/api/revalidate/',
		'another port'               => 'https://front-end.test:8443/api/revalidate/',
		'another scheme'             => 'http://front-end.test/api/revalidate/',
		'a protocol-relative URL'    => '//elsewhere.test/api/revalidate/',
		'a scheme that is not http'  => 'ftp://front-end.test/api/revalidate/',
	] as $what => $location
) {
	list( $code, $urls ) = njr_deliver( $endpoint, [ $endpoint => njr_redirect( 308, $location ), $location => $ok ] );

	njr_test_assert( 'http_308' === $code && [ $endpoint ] === $urls, "a 308 to $what is not followed, and is the outcome" );
}

// Nor do the credentials leave the origin: carrying them over comes after the
// origin is checked, so a request that has some is refused the same redirects.
foreach (
	[
		'another host'                         => 'http://elsewhere.test/revalidate/',
		'another port'                         => 'http://localhost:8084/revalidate/',
		'another scheme'                       => 'https://localhost:8083/revalidate/',
		'another host, naming the credentials' => 'http://user:pass@elsewhere.test/revalidate/',
		'a protocol-relative URL'              => '//elsewhere.test/revalidate/',
	] as $what => $location
) {
	list( $code, $urls ) = njr_deliver( $staging, [ $staging => njr_redirect( 308, $location ), $location => $ok ] );

	njr_test_assert( 'http_308' === $code && [ $staging ] === $urls, "a 308 to $what is not followed with the credentials either" );
}

// A 301 or a 302 would turn the POST into a GET of some other page.
foreach ( [ 301, 302, 303 ] as $status ) {
	list( $code, $urls ) = njr_deliver( $endpoint, [ $endpoint => njr_redirect( $status, '/api/revalidate/' ), $slashed => $ok ] );

	njr_test_assert( "http_$status" === $code && [ $endpoint ] === $urls, "a $status is not followed, even to the same origin" );
}

// Nothing a front-end has a reason to send, so nothing to guess at.
foreach (
	[
		'no Location at all'   => '',
		'a relative path'      => 'revalidate/',
		'a Location sent twice' => [ '/api/revalidate/', '/api/revalidate/' ],
	] as $what => $location
) {
	list( $code, $urls ) = njr_deliver( $endpoint, [ $endpoint => njr_redirect( 308, $location ), $slashed => $ok ] );

	njr_test_assert( 'http_308' === $code && [ $endpoint ] === $urls, "a 308 with $what is not followed" );
}

// Bounded
// ====

// A front-end redirecting to itself: the hops are capped, and the last redirect
// is the outcome rather than a timeout.
list( $code, $urls ) = njr_deliver( $endpoint, [ $endpoint => njr_redirect( 308, '/api/revalidate' ) ] );
njr_test_assert( 'http_308' === $code, 'a redirect loop ends in the redirect, not in a delivery' );
njr_test_assert( 3 === count( $urls ), 'a redirect loop is followed twice and no further' );

// A chain within the cap is followed to its end.
$third = 'https://front-end.test/v2/revalidate/';
list( $code, $urls ) = njr_deliver( $endpoint, [ $endpoint => njr_redirect( 308, '/api/revalidate/' ), $slashed => njr_redirect( 307, '/v2/revalidate/' ), $third => $ok ] );
njr_test_assert( 'ok' === $code && [ $endpoint, $slashed, $third ] === $urls, 'two redirects are followed to a delivery' );

// The caller's timeout is for the whole delivery, so a hop is given what is
// left of it.
njr_deliver( $endpoint, [ $endpoint => njr_redirect( 308, '/api/revalidate/' ), $slashed => $ok ] );
list( list( , $first ), list( , $second ) ) = $GLOBALS['njr_test_posts'];
njr_test_assert( 5 === $first['timeout'], 'the first request is given the whole timeout' );
njr_test_assert( $second['timeout'] > 0 && $second['timeout'] <= 5, 'the next hop is given what is left of it' );

// A delivery that has spent its timeout does not follow another redirect.
list( $code, $urls ) = njr_deliver( $endpoint, [ $endpoint => njr_redirect( 308, '/api/revalidate/' ), $slashed => $ok ], 0 );
njr_test_assert( 'http_308' === $code && [ $endpoint ] === $urls, 'a spent timeout follows no redirect' );

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
