<?php
/**
 * Tests for NextJsRevalidate\Logger.
 *
 * Runs on plain PHP — no WordPress, no composer autoload, no test framework.
 * The WordPress functions and the plugin singleton the logger reaches for are
 * stubbed below, so the file can be run directly:
 *
 *     npm run test:php
 *     php tests/LoggerTest.php
 */

if ( 'cli' !== PHP_SAPI ) die( 'This file must be run from the command line.' );

/**
 * Stubs
 * =====
 */

$GLOBALS['njr_test_uploads_dir'] = '';
$GLOBALS['njr_test_debug']       = false;

function wp_upload_dir() {
	return [ 'basedir' => $GLOBALS['njr_test_uploads_dir'] ];
}

function trailingslashit( $string ) {
	return rtrim( $string, '/\\' ) . '/';
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
 * Test harness
 * ============
 */

$failures = 0;

// A warning or notice raised while logging is a failure: on PHP 8 the same
// condition is an error, and the plugin declares support for both.
set_error_handler( function( $errno, $errstr, $errfile, $errline ) {
	global $failures;

	$failures++;
	printf( "FAIL PHP error: %s in %s on line %d\n", $errstr, $errfile, $errline );

	return true;
} );

/**
 * Run one case with a fresh uploads dir and the given `debug` setting value,
 * and return the contents of the log file, or null when none was written.
 *
 * @param mixed    $debug    Value a read of the debug setting yields.
 * @param callable $callback Receives nothing; calls Logger::log().
 * @return string|null
 */
function njr_test_run( $debug, $callback ) {
	$dir = sys_get_temp_dir() . '/njr-logger-test-' . uniqid();
	mkdir( $dir );

	$GLOBALS['njr_test_uploads_dir'] = $dir;
	$GLOBALS['njr_test_debug']       = $debug;

	$callback();

	$logFile  = $dir . '/' . Logger::FILENAME;
	$contents = file_exists( $logFile ) ? file_get_contents( $logFile ) : null;

	if ( file_exists( $logFile ) ) unlink( $logFile );
	rmdir( $dir );

	return $contents;
}

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
 * Cases
 * =====
 */

// A site that never stored the setting logs nothing.
$contents = njr_test_run( false, function() {
	Logger::log( 'never written', __FILE__ );
} );
njr_test_assert( null === $contents, 'no log file when the debug setting was never stored' );

// An unchecked switch submits nothing at all, so the key is simply absent.
$contents = njr_test_run( [], function() {
	Logger::log( 'never written', __FILE__ );
} );
njr_test_assert( null === $contents, 'no log file when logs are not enabled' );

// A checked switch submits `on`: this is the case #19 reported as broken.
$contents = njr_test_run( [ 'enable-logs' => 'on' ], function() {
	Logger::log( 'hello', 'RevalidateQueue.php' );
} );
njr_test_assert( null !== $contents, 'log file written when logs are enabled' );
njr_test_assert(
	false !== strpos( (string) $contents, 'hello' ),
	'log file contains the logged text'
);
njr_test_assert(
	false !== strpos( (string) $contents, '[RevalidateQueue.php]' ),
	'log line names the file that logged, even when longer than the alignment column'
);
njr_test_assert(
	false !== strpos( (string) $contents, '[INFO]' ),
	'log line carries the default level'
);

// Levels are named in the log line.
$contents = njr_test_run( [ 'enable-logs' => 'on' ], function() {
	Logger::log( 'oops', 'file.php', Logger::ERROR );
	Logger::log( 'psst', 'file.php', Logger::DEBUG );
} );
njr_test_assert(
	false !== strpos( (string) $contents, '[ERROR]' ) && false !== strpos( (string) $contents, '[DEBUG]' ),
	'log lines carry the level they were logged at'
);

// Consecutive calls append rather than overwrite.
$contents = njr_test_run( [ 'enable-logs' => 'on' ], function() {
	Logger::log( 'first', 'file.php' );
	Logger::log( 'second', 'file.php' );
} );
njr_test_assert(
	2 === count( array_filter( explode( "\n", (string) $contents ) ) ),
	'each call appends a line'
);

printf( "\n%s\n", 0 === $failures ? 'All tests passed.' : sprintf( '%d test(s) failed.', $failures ) );
exit( 0 === $failures ? 0 : 1 );
