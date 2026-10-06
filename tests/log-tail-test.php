<?php
/**
 * The end of the log file — LogTail::read(), which the log viewer shows.
 *
 * Three claims, each of them a way the viewer could lie or cost too much:
 *
 *  - **The last lines, in file order**, however the chunks the file is read in
 *    happen to cut them.
 *  - **The count and the size are the file's**, so the status line above the
 *    lines says how much of the log they are.
 *  - **The file is never loaded whole.** Its last lines are read from its end,
 *    and the count holds one chunk at a time, so a big log costs the viewer no
 *    more memory than a small one.
 *
 * Plain PHP — no WordPress, no autoloader, no framework — so a standalone
 * script (ADR 0008). Escaping, the status line and who may ask are the
 * integration suite's: `tests/integration/LogViewerTest.php`.
 *
 * Run with `npm run test:php`, or `php tests/log-tail-test.php`.
 */

if ( 'cli' !== PHP_SAPI ) die( 'This file must be run from the command line.' );

// The plugin files bail when this is not defined.
define( 'ABSPATH', __DIR__ . '/' );

require_once __DIR__ . '/../include/LogTail.php';

use NextJsRevalidate\LogTail;

$failures = 0;

set_error_handler( function( $errno, $errstr, $errfile, $errline ) {
	global $failures;

	if ( ! ( error_reporting() & $errno ) ) return true;

	$failures++;
	printf( "FAIL PHP error: %s in %s on line %d\n", $errstr, $errfile, $errline );

	return true;
} );

function njr_test_assert( $condition, $message ) {
	global $failures;

	if ( $condition ) {
		printf( "ok   %s\n", $message );
		return;
	}

	$failures++;
	printf( "FAIL %s\n", $message );
}

$directory = sys_get_temp_dir() . '/njr-log-tail-test-' . uniqid();
mkdir( $directory );

/**
 * A file holding exactly `$contents`, and its path.
 */
function njr_test_file( $contents ) {
	global $directory;

	$file = $directory . '/' . uniqid() . '.log';
	file_put_contents( $file, $contents );

	return $file;
}

/**
 * `$count` numbered lines, each `$width` bytes before its newline.
 */
function njr_test_lines( $count, $width = 40 ) {
	$lines = [];
	for ( $i = 1; $i <= $count; $i++ ) $lines[] = str_pad( "line $i ", $width, '.' );
	return $lines;
}

// The file is never loaded whole
// ====

// 40 MB of log, read for its last 200 lines and its count. Whatever the read
// holds at once, it is a small fraction of that. First, before anything else
// here has raised the peak this measures from.
$file   = $directory . '/big.log';
$handle = fopen( $file, 'wb' );
$block  = implode( "\n", njr_test_lines( 10000, 99 ) ) . "\n";
for ( $i = 0; $i < 40; $i++ ) fwrite( $handle, $block );
fclose( $handle );
unset( $block );

$before = memory_get_usage();
memory_reset_peak_usage_if_available();
$baseline = memory_get_peak_usage();
$tail     = LogTail::read( $file, 200 );
$growth   = memory_get_peak_usage() - max( $before, $baseline );

njr_test_assert( 400000 === $tail['total'] && 200 === count( $tail['lines'] ), 'a 40 MB log reads as its last 200 lines and its count' );
njr_test_assert( $growth < 4 * 1048576, sprintf( 'and reading it holds a few MB at most, not the file (%.1f MB)', $growth / 1048576 ) );

/**
 * `memory_reset_peak_usage()` is PHP 8.2; below it, the peak is measured from
 * wherever this script had already pushed it, which only makes the bound
 * looser — this script holds nothing near 40 MB before the read.
 */
function memory_reset_peak_usage_if_available() {
	if ( function_exists( 'memory_reset_peak_usage' ) ) memory_reset_peak_usage();
}

// Nothing to read
// ====

njr_test_assert( null === LogTail::read( $directory . '/missing.log', 200 ), 'a file that is not there reads as null' );
njr_test_assert( ! file_exists( $directory . '/missing.log' ), 'and reading it does not create it' );

$tail = LogTail::read( njr_test_file( '' ), 200 );
njr_test_assert( [ 'lines' => [], 'total' => 0, 'size' => 0 ] === $tail, 'an empty file reads as no lines' );

// A short file
// ====

$lines = njr_test_lines( 5 );
$file  = njr_test_file( implode( "\n", $lines ) . "\n" );
$tail  = LogTail::read( $file, 200 );
njr_test_assert( $lines === $tail['lines'], 'a file shorter than the view is shown whole, in file order' );
njr_test_assert( 5 === $tail['total'], 'its count is its own number of lines' );
njr_test_assert( filesize( $file ) === $tail['size'], 'its size is its own size in bytes' );

$tail = LogTail::read( njr_test_file( implode( "\n", $lines ) ), 200 );
njr_test_assert( $lines === $tail['lines'] && 5 === $tail['total'], 'a last line without its newline is a line all the same' );

$tail = LogTail::read( njr_test_file( "only\n" ), 200 );
njr_test_assert( [ 'only' ] === $tail['lines'] && 1 === $tail['total'], 'a file of one line is that line' );

// A long file
// ====

$lines = njr_test_lines( 1000 );
$file  = njr_test_file( implode( "\n", $lines ) . "\n" );
$tail  = LogTail::read( $file, 200 );
njr_test_assert( array_slice( $lines, -200 ) === $tail['lines'], 'a longer file shows its last 200 lines, oldest first' );
njr_test_assert( 1000 === $tail['total'], 'and counts every line it holds' );

$tail = LogTail::read( njr_test_file( implode( "\n", $lines ) ), 200 );
njr_test_assert( array_slice( $lines, -200 ) === $tail['lines'] && 1000 === $tail['total'], 'the same without a final newline' );

// Lines that straddle the chunks the file is read in — and, at 256 lines of
// 4,096 bytes with their newlines, a file that is exactly sixteen chunks.
foreach ( [ 250 => 1, 251 => 7, 252 => 333, 253 => 4999, 256 => 4095 ] as $count => $width ) {
	$lines = njr_test_lines( $count, $width );
	$tail  = LogTail::read( njr_test_file( implode( "\n", $lines ) . "\n" ), 200 );
	njr_test_assert(
		array_slice( $lines, -200 ) === $tail['lines'] && $count === $tail['total'],
		"lines of $width bytes are cut nowhere but at their newlines"
	);
}

$lines = njr_test_lines( 200 );
$tail  = LogTail::read( njr_test_file( implode( "\n", $lines ) . "\n" ), 200 );
njr_test_assert( $lines === $tail['lines'] && 200 === $tail['total'], 'a file of exactly 200 lines is shown whole' );

$lines = njr_test_lines( 201 );
$tail  = LogTail::read( njr_test_file( implode( "\n", $lines ) . "\n" ), 200 );
njr_test_assert( array_slice( $lines, 1 ) === $tail['lines'] && 201 === $tail['total'], 'a file of 201 lines drops only its first' );

// Lines that weigh more together than the read holds: fewer of them, and
// never a part of one.
$lines = njr_test_lines( 10, 300000 );
$tail  = LogTail::read( njr_test_file( implode( "\n", $lines ) . "\n" ), 200 );
njr_test_assert(
	count( $tail['lines'] ) < 10 && array_slice( $lines, -count( $tail['lines'] ) ) === $tail['lines'],
	'lines too heavy to read together are shown whole, as many as fit'
);
njr_test_assert( 10 === $tail['total'], 'and the count is still the whole file\'s' );

// Bytes are bytes: nothing is decoded on the way.
$binary = "[INFO] caf\xc3\xa9\n[ERROR] \x00\xff broken\n";
$tail   = LogTail::read( njr_test_file( $binary ), 200 );
njr_test_assert( [ "[INFO] caf\xc3\xa9", "[ERROR] \x00\xff broken" ] === $tail['lines'], 'the lines are the file\'s bytes, unaltered' );

// Cleanup
// ====

foreach ( glob( $directory . '/*' ) as $entry ) unlink( $entry );
rmdir( $directory );

printf( "\n%s\n", 0 === $failures ? 'All tests passed.' : sprintf( '%d test(s) failed.', $failures ) );
exit( 0 === $failures ? 0 : 1 );
