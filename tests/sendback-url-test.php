<?php
/**
 * Where an admin action sends the operator back to — Traits\SendbackUrl.
 *
 * Revalidate all, the row action, the admin bar's "revalidate this page" and
 * the bulk action all answer with a redirect to the screen they came from: the
 * referer, cleaned of the query args that would repeat a notice. With no
 * referer — a link opened in a new tab with `noreferrer`, a browser or proxy
 * that strips it, a bookmarked action URL — they fall back to the posts list,
 * of the post's type when the request names a post.
 *
 * Revalidate all names no post, and reading `$_GET['post']` regardless raised
 * an "Undefined array key" warning. With warnings displayed, that output went
 * out before the redirect's headers, so the operator was left on a page of
 * warnings instead of their notice. Reachable by stubbing a handful of
 * WordPress functions, so it is a standalone script — ADR 0008's rule.
 *
 * Run with `npm run test:php`, or `php tests/sendback-url-test.php`.
 */

// The plugin files bail when this is not defined.
define( 'ABSPATH', __DIR__ . '/' );

// Any warning or notice the fallback raises fails the test, instead of being
// printed and passed over.
set_error_handler( function ( $severity, $message, $file, $line ) {
	throw new ErrorException( $message, 0, $severity, $file, $line );
} );

// WordPress, reduced to what the trait calls
// ====

/**
 * The referer the request carries, or false for none.
 * @var string|false
 */
$GLOBALS['njr_test_referer'] = false;

/**
 * The post type of each fixture post.
 * @var array<int, string>
 */
$GLOBALS['njr_test_post_types'] = [ 5 => 'page', 9 => 'post' ];

function wp_get_referer() { return $GLOBALS['njr_test_referer']; }

function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }

function absint( $maybeint ) { return abs( (int) $maybeint ); }

function get_post_type( $post = null ) {
	if ( null === $post ) throw new LogicException( 'get_post_type() was asked about the global post.' );
	return $GLOBALS['njr_test_post_types'][ (int) $post ] ?? false;
}

function add_query_arg( $key, $value, $url ) {
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $key . '=' . $value;
}

function remove_query_arg( array $keys, $url ) {
	$parts = explode( '?', $url, 2 );
	if ( ! isset( $parts[1] ) ) return $url;

	$kept = array_filter( explode( '&', $parts[1] ), function ( $pair ) use ( $keys ) {
		return ! in_array( explode( '=', $pair, 2 )[0], $keys, true );
	} );

	return $kept ? $parts[0] . '?' . implode( '&', $kept ) : $parts[0];
}

// The subject
// ====

require_once __DIR__ . '/../include/Traits/SendbackUrl.php';

class NJR_Test_Sendback {
	use NextJsRevalidate\Traits\SendbackUrl;

	public function url( $sendback = null ) {
		return $this->get_sendback_url( $sendback );
	}
}

// The expectations
// ====

$failures = 0;

/**
 * @param string      $description What is being asserted.
 * @param string|false $referer    The referer the request carries.
 * @param array       $get         The request's query args.
 * @param string|null $sendback    The sendback the caller passes, if any.
 * @param string      $expected    The URL the operator is sent back to.
 * @return void
 */
function njr_test_sends_back( $description, $referer, array $get, $sendback, $expected ) {
	global $failures;

	$GLOBALS['njr_test_referer'] = $referer;
	$_GET = $get;

	try {
		$actual = ( new NJR_Test_Sendback() )->url( $sendback );
	} catch ( Throwable $e ) {
		$failures++;
		printf( "FAIL — %s (raised %s: %s)\n", $description, get_class( $e ), $e->getMessage() );
		return;
	}

	if ( $actual === $expected ) {
		printf( "ok   — %s\n", $description );
		return;
	}

	$failures++;
	printf( "FAIL — %s (expected %s, got %s)\n", $description, $expected, $actual );
}

njr_test_sends_back(
	'revalidate all with no referer goes back to the posts list, and raises no warning',
	false,
	[ 'action' => 'nextjs-revalidate-revalidate-all', 'nextjs-revalidate-type' => 'post' ],
	null,
	'https://example.test/wp-admin/edit.php'
);

njr_test_sends_back(
	'a row action with no referer goes back to the list of its post\'s type',
	false,
	[ 'action' => 'nextjs-revalidate-revalidate-post', 'post' => '5' ],
	null,
	'https://example.test/wp-admin/edit.php?post_type=page'
);

njr_test_sends_back(
	'a `post` that names no post falls back to the posts list',
	false,
	[ 'post' => '404' ],
	null,
	'https://example.test/wp-admin/edit.php'
);

njr_test_sends_back(
	'a `post` that is a list, as a bulk action sends it, falls back to the posts list, and raises no warning',
	false,
	[ 'post' => [ '5', '9' ] ],
	null,
	'https://example.test/wp-admin/edit.php'
);

njr_test_sends_back(
	'the referer is where the operator goes back to, without the args that would repeat a notice',
	'https://example.test/wp-admin/edit.php?post_type=page&paged=2&nextjs-revalidate-revalidated=1&action=x',
	[ 'post' => '9' ],
	null,
	'https://example.test/wp-admin/edit.php?post_type=page&paged=2'
);

njr_test_sends_back(
	'a sendback the caller passes wins over the referer',
	'https://example.test/wp-admin/edit.php',
	[],
	'https://example.test/wp-admin/upload.php?mode=list&ids=4',
	'https://example.test/wp-admin/upload.php?mode=list'
);

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
