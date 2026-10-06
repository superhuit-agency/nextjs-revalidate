<?php
/**
 * Log rotation — Logger::log() moving the log into its one archive once it
 * reaches the size limit (ADR 0041).
 *
 * Pinned here, without WordPress:
 *
 *  - **A line written to a log at or past the limit moves the log into the
 *    archive first**, byte for byte, and starts a new log with that line.
 *  - **There is only ever one archive**, beside the log in the guarded
 *    directory under the site's suffix, and a second rotation replaces it.
 *  - **The limit is `nextjs_revalidate_log_max_size`**: 5 MB by default, `0`
 *    or less for never, an integer written as a string of digits for that
 *    integer, and anything else for the default.
 *  - **Nothing rotates with logging off**, and the legacy log is migrated
 *    before anything rotates.
 *  - **Writers crossing the limit together lose no line** and leave one log
 *    and one archive. Real processes, started together, each writing through
 *    the real logger — this script re-runs itself as each of them.
 *
 * The same filter through real WordPress, and the log viewer reading into the
 * archive, are `tests/integration/LogRotationTest.php`.
 *
 * Run with `npm run test:php`, or `php tests/log-rotation-test.php`.
 */

if ( 'cli' !== PHP_SAPI ) die( 'This file must be run from the command line.' );

/**
 * Stubs
 * =====
 */

$GLOBALS['njr_test_uploads_dir'] = '';
$GLOBALS['njr_test_debug']       = [ 'enable-logs' => 'on' ];
$GLOBALS['njr_test_options']     = [];

/** What `nextjs_revalidate_log_max_size` is filtered to; the sentinel leaves it unhooked. */
$GLOBALS['njr_test_max_size'] = 'unhooked';

function wp_upload_dir() {
	return [ 'basedir' => $GLOBALS['njr_test_uploads_dir'] ];
}

function trailingslashit( $string ) {
	return rtrim( $string, '/\\' ) . '/';
}

function wp_mkdir_p( $target ) {
	return is_dir( $target ) || @mkdir( $target, 0777, true ) || is_dir( $target );
}

function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
	return substr( str_repeat( bin2hex( random_bytes( 16 ) ), 2 ), 0, $length );
}

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['njr_test_options'] ) ? $GLOBALS['njr_test_options'][ $name ] : $default;
}

function add_option( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
	if ( array_key_exists( $name, $GLOBALS['njr_test_options'] ) ) return false;
	$GLOBALS['njr_test_options'][ $name ] = $value;
	return true;
}

function apply_filters( $name, $value, ...$args ) {
	if ( 'nextjs_revalidate_log_max_size' !== $name || 'unhooked' === $GLOBALS['njr_test_max_size'] ) return $value;
	return $GLOBALS['njr_test_max_size'];
}

class NextJsRevalidate_Test_Settings {
	public function __get( $name ) {
		return 'debug' === $name ? $GLOBALS['njr_test_debug'] : null;
	}
}

class NextJsRevalidate {
	public $settings;

	private static $instance;

	public static function init() {
		if ( ! isset( self::$instance ) ) self::$instance = new static();
		return self::$instance;
	}

	private function __construct() {
		$this->settings = new NextJsRevalidate_Test_Settings();
	}
}

require_once __DIR__ . '/../include/Logger.php';

use NextJsRevalidate\Logger;

/**
 * A writer
 * ========
 *
 * `--writer <uploads> <suffix> <limit> <start> <id> <count>`: one of the
 * concurrent processes below. It waits for the shared start time, so that every
 * writer reaches the full log together, then writes its lines.
 */

if ( isset( $argv[1] ) && '--writer' === $argv[1] ) {
	list( , , $uploads, $suffix, $limit, $start, $id, $count ) = $argv;

	$GLOBALS['njr_test_uploads_dir']                          = $uploads;
	$GLOBALS['njr_test_options'][ Logger::SUFFIX_OPTION_NAME ] = $suffix;
	$GLOBALS['njr_test_max_size']                             = (int) $limit;

	while ( microtime( true ) < (float) $start ) usleep( 200 );

	for ( $n = 1; $n <= (int) $count; $n++ ) Logger::log( "writer $id line $n", 'writer.php' );

	exit( 0 );
}

/**
 * Test harness
 * ============
 */

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

/**
 * A fresh site, logging on, holding a log of exactly `$contents` — or none —
 * and with `$max_size` as the filter's answer.
 */
function njr_test_site( $contents = null, $max_size = 'unhooked' ) {
	$dir = sys_get_temp_dir() . '/njr-log-rotation-test-' . uniqid();
	mkdir( $dir );

	$GLOBALS['njr_test_uploads_dir'] = $dir;
	$GLOBALS['njr_test_debug']       = [ 'enable-logs' => 'on' ];
	$GLOBALS['njr_test_options']     = [];
	$GLOBALS['njr_test_max_size']    = $max_size;

	if ( null !== $contents ) {
		mkdir( Logger::directory() );
		file_put_contents( Logger::path(), $contents );
	}

	return $dir;
}

/** The files in the log's directory, by name, sorted. */
function njr_test_files() {
	$entries = array_values( array_diff( (array) scandir( Logger::directory() ), [ '.', '..' ] ) );
	sort( $entries );
	return $entries;
}

/** The names the directory should hold: guards, log, and the archive if there is one. */
function njr_test_expected_files( $with_archive ) {
	$names = array_merge( array_keys( Logger::GUARDS ), [ basename( Logger::path() ) ] );
	if ( $with_archive ) $names[] = basename( Logger::archive_path() );
	sort( $names );
	return $names;
}

function njr_test_rmdir( $dir ) {
	if ( ! is_dir( $dir ) ) return;
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $entry ) $entry->isDir() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
	rmdir( $dir );
}

/**
 * Cases
 * =====
 */

// The archive's name.
njr_test_site();
$GLOBALS['njr_test_options'][ Logger::SUFFIX_OPTION_NAME ] = 'TheSuffix';
njr_test_assert( Logger::directory() . '/nextjs-revalidate-TheSuffix.1.log' === Logger::archive_path(), 'the archive is named after the log, with the same suffix, in the same directory' );
njr_test_rmdir( $GLOBALS['njr_test_uploads_dir'] );

// Under the limit: nothing moves.
$uploads = njr_test_site( str_repeat( 'x', 99 ), 100 );
Logger::log( 'under', 'file.php' );
njr_test_assert( ! file_exists( Logger::archive_path() ), 'a log under the limit is not rotated' );
njr_test_assert( 0 === strpos( (string) file_get_contents( Logger::path() ), str_repeat( 'x', 99 ) ), 'and the line is appended to it' );
njr_test_rmdir( $uploads );

// At the limit: the log is moved, whole, and the line starts a new one.
$old     = str_repeat( "an old line\n", 50 );
$uploads = njr_test_site( $old, strlen( $old ) );
Logger::log( 'the first line of the new log', 'file.php' );
njr_test_assert( $old === @file_get_contents( Logger::archive_path() ), 'a log at the limit is moved into the archive, byte for byte' );
$new = (string) file_get_contents( Logger::path() );
njr_test_assert( 1 === substr_count( $new, "\n" ) && false !== strpos( $new, 'the first line of the new log' ), 'and the line starts a new log on its own' );
njr_test_assert( njr_test_expected_files( true ) === njr_test_files(), 'the archive sits beside the log and the guards, and nothing else is created' );
njr_test_rmdir( $uploads );

// Past the limit: the same.
$uploads = njr_test_site( str_repeat( 'y', 500 ), 100 );
Logger::log( 'past', 'file.php' );
njr_test_assert( str_repeat( 'y', 500 ) === @file_get_contents( Logger::archive_path() ), 'a log past the limit is rotated too' );
njr_test_rmdir( $uploads );

// A second rotation replaces the archive: there is only ever one.
$uploads = njr_test_site( str_repeat( 'a', 100 ), 100 );
Logger::log( 'first rotation', 'file.php' );
file_put_contents( Logger::path(), str_repeat( 'b', 100 ) );
Logger::log( 'second rotation', 'file.php' );
njr_test_assert( str_repeat( 'b', 100 ) === @file_get_contents( Logger::archive_path() ), 'a second rotation replaces the archive with the log it moved' );
njr_test_assert( njr_test_expected_files( true ) === njr_test_files(), 'and leaves exactly one archive' );
njr_test_rmdir( $uploads );

// The filter's values.
$cases = [
	'the default, 5 MB, just under'   => [ 'unhooked', Logger::DEFAULT_MAX_SIZE - 1, false ],
	'the default, 5 MB, at it'        => [ 'unhooked', Logger::DEFAULT_MAX_SIZE, true ],
	'a filtered size'                 => [ 1024, 1024, true ],
	'0, never'                        => [ 0, Logger::DEFAULT_MAX_SIZE + 1, false ],
	'a negative size, never'          => [ -1, Logger::DEFAULT_MAX_SIZE + 1, false ],
	"'10MB', the default"             => [ '10MB', Logger::DEFAULT_MAX_SIZE, true ],
	"'1024' as a string, 1 KB"        => [ '1024', 2048, true ],
	"'-1' as a string, never"         => [ '-1', Logger::DEFAULT_MAX_SIZE + 1, false ],
	"'1024 KB', the default"          => [ '1024 KB', 2048, false ],
	"'1024' and a newline, the default" => [ "1024\n", 2048, false ],
	"'1e3', the default"              => [ '1e3', 2048, false ],
	'null, the default'               => [ null, Logger::DEFAULT_MAX_SIZE, true ],
	'an array, the default'           => [ [ 1024 ], 2048, false ],
	'a float, the default'            => [ 1024.0, 2048, false ],
	'true, the default'               => [ true, 2048, false ],
];
foreach ( $cases as $label => list( $filtered, $size, $rotates ) ) {
	$uploads = njr_test_site( str_repeat( 'z', $size ), $filtered );
	Logger::log( 'a line', 'file.php' );
	njr_test_assert( $rotates === file_exists( Logger::archive_path() ), sprintf( 'filtered to %s, a %d-byte log is %s', $label, $size, $rotates ? 'rotated' : 'not rotated' ) );
	njr_test_rmdir( $uploads );
}
njr_test_assert( 5242880 === Logger::DEFAULT_MAX_SIZE, 'the default is 5 MB' );

// Logging off: no line, and no rotation, however big the log.
$uploads = njr_test_site( str_repeat( 'o', 200 ), 100 );
$GLOBALS['njr_test_debug'] = [];
Logger::log( 'never written', 'file.php' );
njr_test_assert( ! file_exists( Logger::archive_path() ) && str_repeat( 'o', 200 ) === file_get_contents( Logger::path() ), 'with logging off, a log past the limit is left as it is' );
njr_test_rmdir( $uploads );

// The legacy log is moved into place first, then rotated by the same line.
$uploads = njr_test_site( null, 100 );
file_put_contents( $uploads . '/' . Logger::LEGACY_FILENAME, str_repeat( 'l', 300 ) );
Logger::log( 'the first line since the upgrade', 'file.php' );
njr_test_assert( ! file_exists( $uploads . '/' . Logger::LEGACY_FILENAME ), 'a legacy log is migrated before anything rotates' );
njr_test_assert( str_repeat( 'l', 300 ) === @file_get_contents( Logger::archive_path() ), 'and, over the limit, becomes the archive' );
njr_test_assert( false !== strpos( (string) file_get_contents( Logger::path() ), 'the first line since the upgrade' ), 'while the line starts the new log' );
njr_test_rmdir( $uploads );

// Concurrent writers crossing the limit together. The log starts exactly at the
// limit; every writer finds it there, and only one of them may move it. A
// second rename would move the new log — holding the first writer's line —
// over the archive, losing the old log and that line both. Five rounds, to give
// a race the chance to show.
$all_pipes = [];
$writers = 8;
$lines   = 25;
for ( $round = 1; $round <= 5; $round++ ) {
	$old     = str_repeat( "an old line\n", 2000 );
	$uploads = njr_test_site( $old, strlen( $old ) );
	$suffix  = $GLOBALS['njr_test_options'][ Logger::SUFFIX_OPTION_NAME ];
	foreach ( Logger::GUARDS as $guard => $contents ) file_put_contents( Logger::directory() . '/' . $guard, $contents );

	$start     = microtime( true ) + 0.5;
	$processes = [];
	for ( $id = 1; $id <= $writers; $id++ ) {
		$command = implode( ' ', array_map( 'escapeshellarg', [ PHP_BINARY, __FILE__, '--writer', $uploads, $suffix, (string) strlen( $old ), sprintf( '%.6F', $start ), (string) $id, (string) $lines ] ) );
		$processes[] = proc_open( $command, [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
		$all_pipes[] = $pipes;
	}

	$exits = [];
	foreach ( $processes as $i => $process ) {
		$output  = stream_get_contents( $all_pipes[ $i ][1] ) . stream_get_contents( $all_pipes[ $i ][2] );
		$exits[] = proc_close( $process );
		if ( '' !== trim( $output ) ) printf( "     writer %d said: %s\n", $i + 1, trim( $output ) );
	}
	$all_pipes = [];

	$log      = (string) @file_get_contents( Logger::path() );
	$expected = [];
	for ( $id = 1; $id <= $writers; $id++ ) for ( $n = 1; $n <= $lines; $n++ ) $expected[] = "writer $id line $n";
	$missing  = array_filter( $expected, function ( $line ) use ( $log ) { return 1 !== substr_count( $log, $line . "\n" ); } );

	njr_test_assert( [ 0 ] === array_values( array_unique( $exits ) ), "round $round: every writer ran" );
	njr_test_assert( $old === @file_get_contents( Logger::archive_path() ), "round $round: the archive is the old log, whole — rotated once, never over again" );
	njr_test_assert( [] === $missing, sprintf( "round $round: every writer's every line is in the new log, once (%d missing)", count( $missing ) ) );
	njr_test_assert( njr_test_expected_files( true ) === njr_test_files(), "round $round: one log and one archive" );

	njr_test_rmdir( $uploads );
}

printf( "\n%s\n", 0 === $failures ? 'All tests passed.' : sprintf( '%d test(s) failed.', $failures ) );
exit( 0 === $failures ? 0 : 1 );
