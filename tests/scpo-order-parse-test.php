<?php
/**
 * How Simple Custom Post Order's drag and drop list is read —
 * Integrations\SimpleCustomPostOrder::order_ids().
 *
 * The list screen sends its posts as one serialised form, `post[]=12&post[]=7…`,
 * in the body's `order`. It is read by hand, the way the plugin's own private
 * `parse_order_ids()` reads it, and not with `parse_str()`: that stops at
 * `max_input_vars` with a warning, which broke the drag and drop's JSON answer
 * and left the posts past the limit unreported (#186). Reachable without
 * WordPress, so it is asserted here; what a reorder reports is asserted in
 * `tests/integration/SimpleCustomPostOrderTest.php`. See
 * `docs/adr/0008-two-testing-idioms.md` for the split.
 *
 * Run with `npm run test:php`, or `php tests/scpo-order-parse-test.php`.
 */

// The plugin files bail when this is not defined.
define( 'ABSPATH', __DIR__ . '/' );

// Any warning or notice the read raises fails the test, instead of being
// printed and passed over.
set_error_handler( function ( $severity, $message, $file, $line ) {
	throw new ErrorException( $message, 0, $severity, $file, $line );
} );

// The subject
// ====

require_once __DIR__ . '/../include/Interfaces/Hookable.php';
require_once __DIR__ . '/../include/Abstracts/Base.php';
require_once __DIR__ . '/../include/Traits/WhenPluginsLoaded.php';
require_once __DIR__ . '/../include/Integrations/SimpleCustomPostOrder.php';

use NextJsRevalidate\Integrations\SimpleCustomPostOrder;

// The expectations
// ====

$failures = 0;

/**
 * @param string $description What is being asserted.
 * @param string $order       The body's `order`, as it reaches PHP.
 * @param int[]  $expected    The post IDs read, in order.
 * @return void
 */
function njr_test_reads( $description, $order, array $expected ) {
	global $failures;

	try {
		$actual = SimpleCustomPostOrder::order_ids( $order );
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
	$shown = function ( $ids ) {
		return count( $ids ) > 12 ? sprintf( '%d IDs, from %s to %s', count( $ids ), json_encode( reset( $ids ) ), json_encode( end( $ids ) ) ) : json_encode( $ids );
	};
	printf( "FAIL — %s (expected %s, got %s)\n", $description, $shown( $expected ), $shown( $actual ) );
}

/**
 * The list as jQuery UI's `sortable( 'serialize' )` sends it.
 *
 * @param int[] $post_ids
 * @return string
 */
function njr_test_serialized( array $post_ids ) {
	return implode( '&', array_map( function ( $post_id ) {
		return "post[]=$post_id";
	}, $post_ids ) );
}

njr_test_reads( 'a list is read in its order', 'post[]=12&post[]=7&post[]=30', [ 12, 7, 30 ] );

// The limit in force, whatever it is: no ini change needed, and none possible
// — `max_input_vars` cannot be set at runtime.
$limit    = (int) ini_get( 'max_input_vars' );
$post_ids = range( 1, $limit + 1 );
njr_test_reads( "a list one longer than max_input_vars ($limit) is read whole, and raises no warning", njr_test_serialized( $post_ids ), $post_ids );

njr_test_reads( 'a repeated post keeps its first position', 'post[]=12&post[]=7&post[]=12', [ 12, 7 ] );

njr_test_reads(
	'a value that is not a positive whole number, an empty value, a pair without `=` and a key not ending in `[]` are skipped, the rest read',
	'post[]=12&bulk[]=edit&post[]=0&post[]=&post[]&post=9&post[]=-3&post[]=4.5&post[]=7',
	[ 12, 7 ]
);

njr_test_reads( 'a key with an encoded `[]` is read', 'post%5B%5D=12&post%5b%5d=7', [ 12, 7 ] );

njr_test_reads( 'an encoded value is read once decoded', 'post[]=%31%32', [ 12 ] );

njr_test_reads( 'a value is split from its key at the first `=` only', 'post[]=12=7&post[]=7', [ 7 ] );

njr_test_reads( 'an empty list reads no post', '', [] );

njr_test_reads( 'a list with nothing readable in it reads no post', 'bulk[]=edit&order=12&&=', [] );

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
