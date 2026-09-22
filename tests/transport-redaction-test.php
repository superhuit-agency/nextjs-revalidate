<?php
/**
 * The secret, kept out of every message the transport hands back —
 * `Traits\FrontEndRequest`.
 *
 * Every request this plugin makes carries the secret as a query arg, and two of
 * the outcomes the trait answers carry a string of *arbitrary origin* back with
 * them: `unreachable` carries whatever the HTTP transport said, and `exception`
 * carries whatever anything in the request path threw. Those strings reach an
 * admin notice, a REST response and a log file in `wp-content/uploads` that
 * most hosts serve directly over HTTP — so a transport that quoted the request
 * URL back would publish the one value this plugin exists to hold.
 * See `docs/adr/0023-the-transport-redacts-the-secret.md`.
 *
 * Two halves, because the decision has two halves:
 *
 *  - **The behaviour** — what a message looks like once it has been through the
 *    seam, including the cases the redaction is deliberately allowed to be
 *    clumsy about.
 *  - **The seam** — that no `WP_Error` anywhere in `include/` carries a
 *    transport or exception message without going through the redaction. This
 *    is the half that survives the next person, on the model of
 *    `tests/psr4-autoload-test.php`: a second transport added beside this one
 *    fails the gate rather than shipping.
 *
 * Reachable by stubbing a handful of WordPress functions, so it is a standalone
 * script rather than a PHPUnit test — see `docs/adr/0008-two-testing-idioms.md`.
 *
 * Run with `npm run test:php`, or `php tests/transport-redaction-test.php`.
 */

if ( 'cli' !== PHP_SAPI ) die( 'This file must be run from the command line.' );

// The plugin files bail when this is not defined.
define( 'ABSPATH', __DIR__ . '/' );

/**
 * What the next `wp_remote_get()` answers: an array, a WP_Error, or a callable
 * to run in its place.
 * @var mixed
 */
$GLOBALS['njr_test_response'] = null;

/**
 * The secret the fixture site holds.
 * @var string
 */
$GLOBALS['njr_test_secret'] = '';

// WordPress stubs
// ====

function __( $text, $domain = null ) { return $text; }

function wp_remote_get( $url, $args = [] ) {
	$response = $GLOBALS['njr_test_response'];

	return is_callable( $response ) ? $response() : $response;
}

function wp_remote_retrieve_response_code( $response ) {
	return $response['response']['code'] ?? '';
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

/**
 * The trait's using class, reduced to what the trait actually asks of one: a
 * `settings` holding a secret, and a way in from outside.
 *
 * Driven directly rather than through `Revalidate` or `FseSnapshot` because the
 * seam under test is the trait — the whole point of ADR 0020 is that a caller
 * cannot opt out of this, so a test that went through one caller would prove
 * the weaker thing. `purge-outcome-test.php` covers the trip through
 * `Revalidate::purge()`, settings and all.
 */
class NextJsRevalidate_Test_Transport {
	use NextJsRevalidate\Traits\FrontEndRequest;

	/** @var object */
	public $settings;

	public function __construct() {
		$this->settings = new class {
			public function __get( $name ) {
				return 'secret' === $name ? $GLOBALS['njr_test_secret'] : null;
			}
		};
	}

	public function send( $url ) {
		return $this->send_front_end_request( $url, 60 );
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

/**
 * Run one request against a fixture site, and answer the message it came back
 * with.
 *
 * @param string $secret   What the site holds.
 * @param mixed  $response What `wp_remote_get()` answers.
 * @return string
 */
function njr_test_message( $secret, $response ) {
	$GLOBALS['njr_test_secret']   = $secret;
	$GLOBALS['njr_test_response'] = $response;

	$outcome = ( new NextJsRevalidate_Test_Transport() )->send( 'https://front-end.test/api/revalidate?path=%2F&secret=' . rawurlencode( $secret ) );

	return is_wp_error( $outcome ) ? $outcome->get_error_message() : '';
}

/** A transport that answers with a WP_Error, as a failed request does. */
function njr_test_transport_error( $message ) {
	return new WP_Error( 'http_request_failed', $message );
}

/** Something in the request path throwing, as a filter can. */
function njr_test_throw( $message ) {
	return function() use ( $message ) { throw new \RuntimeException( $message ); };
}

// The behaviour
// ====

$secret = 'sup3r-s3cret-v4lue';
$url    = "https://front-end.test/api/revalidate?path=%2Fhello-world%2F&secret=$secret";

// The case the issue is about: a transport quoting the request URL back.
foreach (
	[
		'a transport error' => njr_test_transport_error( "Failed to open stream: $url" ),
		'a throw'           => njr_test_throw( "cannot connect to $url" ),
	] as $what => $response
) {
	$message = njr_test_message( $secret, $response );

	njr_test_assert( false === strpos( $message, $secret ), "$what quoting the request URL does not carry the secret out" );
	njr_test_assert( false !== strpos( $message, 'secret=***' ), "$what quoting the request URL leaves `secret=***` where the value was" );
	njr_test_assert( false !== strpos( $message, 'path=%2Fhello-world%2F' ), "$what keeps the rest of the URL, which is the diagnostic" );
}

// The redaction is by shape *and* by value, and neither pass covers the other.

// By shape: the arg is blanked without the configured secret being consulted at
// all — which is what holds when the secret has been changed since the request
// was sent, or could not be read.
$message = njr_test_message( 'something-else-entirely', njr_test_transport_error( 'stream timed out: https://front-end.test/api/revalidate?secret=the-old-one&path=%2F' ) );
njr_test_assert( false === strpos( $message, 'the-old-one' ), 'a `secret=` arg is blanked even when it is not the configured secret' );
njr_test_assert( false !== strpos( $message, 'path=%2F' ), 'blanking a `secret=` arg stops at the next query arg' );

// By value: a message naming the secret with no URL around it is not reachable
// by looking for query args.
$message = njr_test_message( 'sup3r-s3cret-v4lue', njr_test_transport_error( 'the header X-Njr-Secret: sup3r-s3cret-v4lue was rejected' ) );
njr_test_assert( false === strpos( $message, 'sup3r-s3cret-v4lue' ), 'the configured secret is replaced wherever else it appears' );
njr_test_assert( false !== strpos( $message, 'X-Njr-Secret: ***' ), 'replacing it by value leaves the rest of the message legible' );

// And by value in the spelling the secret actually travels in. `add_query_arg()`
// urlencodes what it puts in a URL, so a secret holding a space or a `/` is
// never quoted back the way it was typed — a by-value pass that only knew the
// configured spelling would walk straight past it. The by-shape pass covers
// these only while they sit in a `secret=` arg, so each one here is quoted
// somewhere else in the message.
foreach (
	[
		'a space, which add_query_arg() writes as `+`' => [ 'two words', 'two+words' ],
		'a space, which a transport may re-encode as `%20`' => [ 'two words', 'two%20words' ],
		'a slash, which is percent-encoded either way' => [ 'a/b', 'a%2Fb' ],
	] as $what => list( $configured, $encoded )
) {
	$message = njr_test_message( $configured, njr_test_transport_error( "rejected token $encoded at the edge" ) );

	njr_test_assert(
		false === strpos( $message, $encoded ),
		"the secret is redacted when it is quoted with $what"
	);
	njr_test_assert(
		false !== strpos( $message, 'rejected token *** at the edge' ),
		"redacting $what leaves the rest of the message legible"
	);
}

// The configured spelling still wins where both could match, which is what the
// longest-first ordering buys: a needle that is part of a longer one must not
// consume it and strand the remainder.
$message = njr_test_message( 'a b', njr_test_transport_error( 'saw a%20b here' ) );
njr_test_assert(
	'saw *** here' === $message,
	'a shorter spelling does not pre-empt a longer one and leave the tail behind'
);

// An arg named `api_secret` or `client_secret` is blanked too. The match
// over-reaches at the front on purpose: every direction it reaches in is one
// where blanking a value nobody needed is cheaper than printing one somebody
// did.
$message = njr_test_message( $secret, njr_test_transport_error( 'refused: https://front-end.test/api?api_secret=abc123' ) );
njr_test_assert( false === strpos( $message, 'abc123' ), 'an arg whose name merely ends in `secret` is blanked as well' );

// There is deliberately no minimum-length guard: a one-character secret is a
// legal configuration — `Settings::missing_settings()` only asks for non-empty —
// and a length guard would mean the site where this hole is worst is the one
// that silently opts out. The cost is a mangled diagnostic, and it is the
// cheaper failure: garbled text fails safe, a length guard fails open.
$message = njr_test_message( '1', njr_test_transport_error( 'cURL error 28: Operation timed out after 1200 ms' ) );
njr_test_assert( false === strpos( $message, '1200' ), 'a one-character secret is redacted rather than waved through' );
njr_test_assert( false !== strpos( $message, 'cURL error 28' ), 'and the rest of the message survives, garbled or not' );

// A site holding no secret has nothing to replace by value, and must not have
// every character of the message replaced instead.
$message = njr_test_message( '', njr_test_transport_error( 'cURL error 6: Could not resolve host: front-end.test' ) );
njr_test_assert( 'cURL error 6: Could not resolve host: front-end.test' === $message, 'an empty secret leaves the message exactly as it came' );

// What the transport says is still the diagnostic. ADR 0004 settled that this
// message is the only trace a failure leaves, so a redaction that swallowed it
// would gut the outcome rather than protect it.
$message = njr_test_message( $secret, njr_test_transport_error( 'cURL error 6: Could not resolve host: front-end.test' ) );
njr_test_assert( 'cURL error 6: Could not resolve host: front-end.test' === $message, 'a message holding no secret is passed through untouched' );

// The two outcomes this plugin writes itself are left alone: they are `__()`
// literals, so a redaction over them could only mangle a string already known
// to be safe.
njr_test_assert(
	'The front-end answered without a status code.' === njr_test_message( 'answered', [ 'body' => '' ] ),
	'`no_response` keeps the literal this plugin wrote, redaction and all'
);
njr_test_assert(
	'The front-end answered 500.' === njr_test_message( 'answered', [ 'response' => [ 'code' => 500 ] ] ),
	'`http_{status}` keeps the literal this plugin wrote, redaction and all'
);

// Nothing is thrown out of the trait, including out of the redaction: the queue
// drain runs this in a loop holding a running-cron count.
$thrown = false;
try {
	njr_test_message( $secret, njr_test_throw( 'a filter blew up' ) );
} catch ( \Throwable $th ) {
	$thrown = true;
}
njr_test_assert( ! $thrown, 'a throw inside the request is still caught rather than escaping' );

// The seam
// ====

/**
 * The top-level arguments of the `new WP_Error(...)` opening at `$i`, as the
 * source text of each.
 *
 * Text rather than a parse tree because the question is a textual one — does
 * this argument mention a message nobody here wrote, and does it mention the
 * redaction — and a tokeniser is what makes "top-level comma" mean the right
 * thing inside nested calls.
 *
 * @param array $tokens token_get_all() output
 * @param int   $i      index of the token opening the argument list
 * @return string[]
 */
function njr_call_arguments( array $tokens, int $i ): array {
	$arguments = [ '' ];
	$depth     = 0;

	for ( $j = $i; $j < count( $tokens ); $j++ ) {
		$text = is_array( $tokens[ $j ] ) ? $tokens[ $j ][1] : $tokens[ $j ];

		if ( '(' === $text || '[' === $text ) {
			$depth++;
			if ( 1 === $depth ) continue;
		}
		elseif ( ')' === $text || ']' === $text ) {
			$depth--;
			if ( 0 === $depth ) return $arguments;
		}
		elseif ( ',' === $text && 1 === $depth ) {
			$arguments[] = '';
			continue;
		}

		if ( $depth >= 1 ) $arguments[ count( $arguments ) - 1 ] .= $text;
	}

	return $arguments;
}

/**
 * Every `new WP_Error(...)` a file mints, as `[ line, arguments ]`.
 *
 * @param string $path
 * @return array<array{int, string[]}>
 */
function njr_wp_errors( string $path ): array {
	$tokens = token_get_all( (string) file_get_contents( $path ) );
	$found  = [];

	for ( $i = 0; $i < count( $tokens ); $i++ ) {
		if ( ! is_array( $tokens[ $i ] ) || T_NEW !== $tokens[ $i ][0] ) continue;

		// The name follows the keyword, leading `\` and all. It has to be
		// accumulated rather than read from one token: PHP 8 lumps a qualified
		// name into a single T_NAME_* token, where 7.4 emits a run of
		// T_NS_SEPARATOR and T_STRING — so on the 7.4 this repo lints with,
		// taking the first token alone sees `\` and misses every
		// `new \WP_Error(` in the tree. Same shape as `njr_read_name()` in
		// `tests/psr4-autoload-test.php`, and for the same reason.
		$name = '';
		$line = $tokens[ $i ][2];

		for ( $j = $i + 1; $j < count( $tokens ); $j++ ) {
			$token = $tokens[ $j ];

			if ( is_array( $token ) && T_WHITESPACE === $token[0] ) {
				if ( '' !== $name ) break;
				continue;
			}
			if ( ! is_array( $token ) ) break;

			$is_name = T_STRING === $token[0]
				|| T_NS_SEPARATOR === $token[0]
				|| ( defined( 'T_NAME_QUALIFIED' ) && T_NAME_QUALIFIED === $token[0] )
				|| ( defined( 'T_NAME_FULLY_QUALIFIED' ) && T_NAME_FULLY_QUALIFIED === $token[0] );

			if ( ! $is_name ) break;

			$name .= $token[1];
			$i     = $j;
		}

		if ( 'WP_Error' !== ltrim( $name, '\\' ) ) continue;

		$found[] = [ $line, njr_call_arguments( $tokens, $i + 1 ) ];
	}

	return $found;
}

/**
 * The local variables a file assigns a foreign message into, as names without
 * their `$`.
 *
 * Without this the scan is one `$message = $th->getMessage();` away from being
 * fooled, and that is not a devious rewrite — it is what anyone does the moment
 * the message needs touching before it is carried. Textual and single-line on
 * purpose: this is a tripwire rather than a taint analysis, and one that tried
 * to be exhaustive would mostly produce a false sense of one.
 *
 * @param string $source
 * @return string[]
 */
function njr_tainted_locals( string $source ): array {
	preg_match_all( '/\$(\w+)\s*=\s*[^;]*(?:get_error_message|getMessage)\s*\(/', $source, $matches );

	return array_values( array_unique( $matches[1] ) );
}

/**
 * Every .php file under a directory, relative to the repo root.
 *
 * @param string $root
 * @param string $relative
 * @return string[]
 */
function njr_php_files( string $root, string $relative ): array {
	$files = [];

	foreach ( scandir( "$root/$relative" ) ?: [] as $entry ) {
		if ( '.' === $entry || '..' === $entry ) continue;

		$path = "$relative/$entry";

		if ( is_dir( "$root/$path" ) ) $files = array_merge( $files, njr_php_files( $root, $path ) );
		elseif ( '.php' === substr( $entry, -4 ) ) $files[] = $path;
	}

	sort( $files );
	return $files;
}

$root = dirname( __DIR__ );

/** @var array<string, int> file => the WP_Errors it was seen to mint */
$minted = [];

foreach ( array_merge( njr_php_files( $root, 'include' ), [ 'nextjs-revalidate.php' ] ) as $file ) {
	$tainted = njr_tainted_locals( (string) file_get_contents( "$root/$file" ) );

	foreach ( njr_wp_errors( "$root/$file" ) as list( $line, $arguments ) ) {
		$minted[ $file ] = ( $minted[ $file ] ?? 0 ) + 1;

		// The message, which is where a string of foreign origin would land.
		$message = $arguments[1] ?? '';

		// `get_error_message()` is what a transport's WP_Error answers;
		// `getMessage()` is what a Throwable answers. Either one in a message
		// argument — directly, or through a local this file put one into —
		// means this WP_Error is carrying text nobody here wrote.
		$carries = false !== strpos( $message, 'get_error_message' )
			|| false !== strpos( $message, 'getMessage' );

		foreach ( $tainted as $name ) {
			if ( preg_match( '/\$' . preg_quote( $name, '/' ) . '\b/', $message ) ) $carries = true;
		}

		if ( ! $carries ) continue;

		if ( false !== strpos( $message, 'redact_secret' ) ) {
			printf( "ok   — %s:%d redacts the message it carries\n", $file, $line );
			continue;
		}

		$failures++;
		printf(
			"FAIL — %s:%d mints a WP_Error carrying a message of foreign origin without redact_secret(): %s\n"
				. "       Every request this plugin makes holds the secret in a query arg, and this message\n"
				. "       reaches the log file in wp-content/uploads. See docs/adr/0023-the-transport-redacts-the-secret.md.\n",
			$file,
			$line,
			$message
		);
	}
}

// A scan that matched nothing would pass silently forever, which is the one way
// a test like this fails without saying so. Both spellings are named, because
// the two are different tokens and a reader that handles only one goes blind to
// half the tree while still reporting `ok` for the other half.
njr_test_assert(
	( $minted['include/Traits/FrontEndRequest.php'] ?? 0 ) >= 2,
	'the scan sees `new WP_Error(` — the bare name'
);
njr_test_assert(
	( $minted['include/RestApi.php'] ?? 0 ) >= 1 && ( $minted['include/Settings.php'] ?? 0 ) >= 1,
	'the scan sees `new \\WP_Error(` — the same name written fully qualified'
);

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
