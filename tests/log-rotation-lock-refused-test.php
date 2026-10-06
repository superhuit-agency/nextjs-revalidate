<?php
/**
 * Log rotation where the lock is refused — Logger::log() on a filesystem whose
 * `flock()` fails, as an NFS client's does for an exclusive lock on a file not
 * open for writing (ADR 0041).
 *
 * `rotate()` takes an exclusive lock before its last check and the rename, so
 * that two writers crossing the limit together rotate once. Where that lock
 * cannot be had, the log must still rotate: the alternative is a log that grows
 * without bound, which is the failure rotation exists to prevent. Unlocked, the
 * check is the same, so the log is moved whole and the line starts a new one.
 *
 * `flock()` is replaced in the plugin's namespace, which PHP resolves before the
 * global function, so the real logger runs with a lock that is always refused.
 * Rotation with a lock that works, and writers racing for it, are
 * `tests/log-rotation-test.php`.
 *
 * Run with `npm run test:php`, or `php tests/log-rotation-lock-refused-test.php`.
 */

namespace NextJsRevalidate {

	/**
	 * A lock that is never granted, recording each operation asked for.
	 */
	function flock( $handle, $operation, &$would_block = null ) {
		$GLOBALS['njr_test_locks'][] = $operation;
		return false;
	}
}

namespace {

	if ( 'cli' !== PHP_SAPI ) die( 'This file must be run from the command line.' );

	/**
	 * Stubs
	 * =====
	 */

	$GLOBALS['njr_test_uploads_dir'] = sys_get_temp_dir() . '/njr-log-lock-refused-test-' . uniqid();
	$GLOBALS['njr_test_options']     = [ 'nextjs_revalidate-log_suffix' => 'TheSuffix' ];
	$GLOBALS['njr_test_locks']       = [];

	function wp_upload_dir() {
		return [ 'basedir' => $GLOBALS['njr_test_uploads_dir'] ];
	}

	function trailingslashit( $string ) {
		return rtrim( $string, '/\\' ) . '/';
	}

	function wp_mkdir_p( $target ) {
		return is_dir( $target ) || @mkdir( $target, 0777, true ) || is_dir( $target );
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
		return 'nextjs_revalidate_log_max_size' === $name ? 1024 : $value;
	}

	class NextJsRevalidate_Test_Settings {
		public function __get( $name ) {
			return 'debug' === $name ? [ 'enable-logs' => 'on' ] : null;
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
	 * Cases
	 * =====
	 */

	$old = str_repeat( "an old line\n", 100 );
	mkdir( \NextJsRevalidate\Logger::directory(), 0777, true );
	file_put_contents( \NextJsRevalidate\Logger::path(), $old );

	\NextJsRevalidate\Logger::log( 'the first line of the new log', 'file.php' );

	njr_test_assert( [ LOCK_EX ] === $GLOBALS['njr_test_locks'], 'the lock was asked for, refused, and so never released' );
	njr_test_assert( $old === @file_get_contents( \NextJsRevalidate\Logger::archive_path() ), 'with the lock refused, a log at the limit is still moved into the archive, byte for byte' );

	$new = (string) @file_get_contents( \NextJsRevalidate\Logger::path() );
	njr_test_assert( 1 === substr_count( $new, "\n" ) && false !== strpos( $new, 'the first line of the new log' ), 'and the line starts a new log on its own' );

	$GLOBALS['njr_test_locks'] = [];
	\NextJsRevalidate\Logger::log( 'a second line', 'file.php' );

	njr_test_assert( [] === $GLOBALS['njr_test_locks'] && $old === @file_get_contents( \NextJsRevalidate\Logger::archive_path() ), 'a log under the limit is neither locked nor rotated' );

	/**
	 * Cleanup
	 * =======
	 */

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $GLOBALS['njr_test_uploads_dir'], FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $entry ) $entry->isDir() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
	rmdir( $GLOBALS['njr_test_uploads_dir'] );

	printf( "\n%s\n", 0 === $failures ? 'All tests passed.' : sprintf( '%d test(s) failed.', $failures ) );
	exit( 0 === $failures ? 0 : 1 );
}
