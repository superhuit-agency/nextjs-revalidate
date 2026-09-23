<?php
/**
 * Where the log lives — Logger::path(), and the migration that moves an
 * existing log there, Logger::migrate_legacy_log().
 *
 * The log holds every permalink revalidated and every outcome, and it used to
 * sit at the same path on every install, unguarded. ADR-0024 moves it into a
 * directory this plugin owns, guarded by an `.htaccess` and an `index.php`, under
 * a filename carrying a suffix unique to the site. Both mechanisms are pinned
 * here, because each covers what the other cannot: the guards are ignored by
 * nginx, and the suffix is not an actual denial anywhere.
 *
 * Runs on plain PHP — no WordPress, no composer autoload, no test framework
 * (ADR-0008). The WordPress functions and the plugin singleton the logger
 * reaches for are stubbed below, over an in-memory option store and a real
 * temporary uploads directory. Nothing here claims to cover nginx: that is a
 * known gap in the evidence, not a case this file forgot.
 *
 *     npm run test:php
 *     php tests/LogLocationTest.php
 */

if ( 'cli' !== PHP_SAPI ) die( 'This file must be run from the command line.' );

/**
 * Stubs
 * =====
 */

$GLOBALS['njr_test_uploads_dir'] = '';
$GLOBALS['njr_test_debug']       = false;
$GLOBALS['njr_test_options']     = [];
$GLOBALS['njr_test_salt']        = 'first-salt';

function wp_upload_dir() {
	return [ 'basedir' => $GLOBALS['njr_test_uploads_dir'] ];
}

function trailingslashit( $string ) {
	return rtrim( $string, '/\\' ) . '/';
}

function wp_mkdir_p( $target ) {
	return is_dir( $target ) || mkdir( $target, 0777, true );
}

function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
	$chars    = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
	$password = '';
	for ( $i = 0; $i < $length; $i++ ) $password .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
	return $password;
}

// Stubbed so a regression to a derived suffix has something to derive from,
// and shows up as a filename that moves when the salts do.
function wp_salt( $scheme = 'auth' ) {
	return $GLOBALS['njr_test_salt'];
}

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['njr_test_options'] ) ? $GLOBALS['njr_test_options'][ $name ] : $default;
}

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['njr_test_options'][ $name ] = $value;
	return true;
}

function add_option( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
	if ( array_key_exists( $name, $GLOBALS['njr_test_options'] ) ) return false;
	return update_option( $name, $value );
}

function delete_option( $name ) {
	if ( ! array_key_exists( $name, $GLOBALS['njr_test_options'] ) ) return false;
	unset( $GLOBALS['njr_test_options'][ $name ] );
	return true;
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

// A warning the code under test silenced with `@` is not one: PHP still calls
// the handler for it, with those bits masked out of error_reporting().
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
 * A fresh site: an empty uploads directory, no option rows, and the given
 * `debug` setting value. Returns the uploads directory.
 *
 * @param mixed $debug Value a read of the debug setting yields.
 * @return string
 */
function njr_test_site( $debug = [ 'enable-logs' => 'on' ] ) {
	$dir = sys_get_temp_dir() . '/njr-log-location-test-' . uniqid();
	mkdir( $dir );

	$GLOBALS['njr_test_uploads_dir'] = $dir;
	$GLOBALS['njr_test_debug']       = $debug;
	$GLOBALS['njr_test_options']     = [];
	$GLOBALS['njr_test_salt']        = 'first-salt';

	return $dir;
}

/** Every file and directory beneath $dir, relative to it and sorted. */
function njr_test_tree( $dir ) {
	$entries  = [];
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);
	foreach ( $iterator as $entry ) $entries[] = substr( $entry->getPathname(), strlen( $dir ) + 1 );
	sort( $entries );
	return $entries;
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

// With logging on, the line lands in the plugin's own directory, not beside
// the site's media.
$uploads = njr_test_site();
Logger::log( 'hello', 'file.php' );
$path = Logger::path();
njr_test_assert( 0 === strpos( $path, $uploads . '/' . Logger::DIRECTORY_NAME . '/' ), 'the log path is inside the plugin-owned directory beneath uploads' );
njr_test_assert( is_file( $path ) && false !== strpos( (string) file_get_contents( $path ), 'hello' ), 'a written line lands at that path' );
njr_test_assert( ! file_exists( $uploads . '/' . Logger::LEGACY_FILENAME ), 'nothing is written at the legacy path' );

// The guards.
$directory = $uploads . '/' . Logger::DIRECTORY_NAME;
$htaccess  = $directory . '/.htaccess';
njr_test_assert( is_file( $htaccess ), 'the directory holds an .htaccess' );
njr_test_assert(
	false !== strpos( (string) @file_get_contents( $htaccess ), 'Require all denied' )
		&& false !== strpos( (string) @file_get_contents( $htaccess ), 'Deny from all' ),
	'the .htaccess denies all access, on Apache 2.4 and 2.2 alike'
);
njr_test_assert( is_file( $directory . '/index.php' ), 'the directory holds an index.php' );
njr_test_assert( '' === trim( (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $directory . '/index.php' ) ) ), 'the index.php produces nothing' );

// The regression that would stop a site serving its own media.
njr_test_assert( ! file_exists( $uploads . '/.htaccess' ), 'no .htaccess is written into uploads itself' );
njr_test_assert( ! file_exists( $uploads . '/index.php' ), 'no index.php is written into uploads itself' );
njr_test_assert( [ Logger::DIRECTORY_NAME ] === array_values( array_diff( scandir( $uploads ), [ '.', '..' ] ) ), 'uploads gains the one directory and nothing else' );

// The suffix.
$name = basename( $path );
njr_test_assert( Logger::LEGACY_FILENAME !== $name, 'the log is not named the same on every install' );
$suffix = get_option( Logger::SUFFIX_OPTION_NAME );
njr_test_assert( is_string( $suffix ) && strlen( $suffix ) >= 12 && false !== strpos( $name, $suffix ), 'the filename carries the stored per-site suffix' );
njr_test_assert( 1 === preg_match( '/^[A-Za-z0-9]+$/', (string) $suffix ), 'the suffix is safe in a filename and a URL' );

Logger::log( 'again', 'file.php' );
Logger::log( 'and again', 'file.php' );
njr_test_assert( $suffix === get_option( Logger::SUFFIX_OPTION_NAME ) && $path === Logger::path(), 'repeated logging does not rotate the suffix' );
njr_test_assert( 3 === count( array_filter( explode( "\n", (string) file_get_contents( $path ) ) ) ), 'every line lands in the one file' );

$GLOBALS['njr_test_salt'] = 'rotated-after-a-compromise';
njr_test_assert( $path === Logger::path(), 'a site whose salts change keeps the same log filename' );

// Two sites, two names.
$GLOBALS['njr_test_options'][ Logger::SUFFIX_OPTION_NAME ] = 'SiteOneSuffix';
$one = basename( Logger::path() );
$GLOBALS['njr_test_options'][ Logger::SUFFIX_OPTION_NAME ] = 'SiteTwoSuffix';
$two = basename( Logger::path() );
njr_test_assert( $one !== $two, 'two sites with different stored suffixes resolve to different filenames' );
njr_test_rmdir( $uploads );

// A directory removed by hand is repaired by the next line, which is kept.
$uploads = njr_test_site();
Logger::log( 'before', 'file.php' );
njr_test_rmdir( $uploads . '/' . Logger::DIRECTORY_NAME );
Logger::log( 'after the directory was removed', 'file.php' );
$expected = [ Logger::DIRECTORY_NAME, Logger::DIRECTORY_NAME . '/.htaccess', Logger::DIRECTORY_NAME . '/index.php', Logger::DIRECTORY_NAME . '/' . basename( Logger::path() ) ];
sort( $expected );
njr_test_assert(
	$expected === njr_test_tree( $uploads ),
	'a line written after the directory was removed recreates it and both guards'
);
njr_test_assert( false !== strpos( (string) file_get_contents( Logger::path() ), 'after the directory was removed' ), 'that line is not lost' );
njr_test_rmdir( $uploads );

// A guard emptied by hand is restored by the next line, not taken on trust
// because a file of that name exists.
$uploads = njr_test_site();
Logger::log( 'before', 'file.php' );
file_put_contents( $uploads . '/' . Logger::DIRECTORY_NAME . '/.htaccess', '' );
Logger::log( 'after the guard was emptied', 'file.php' );
njr_test_assert( Logger::GUARDS['.htaccess'] === file_get_contents( $uploads . '/' . Logger::DIRECTORY_NAME . '/.htaccess' ), 'an emptied .htaccess is rewritten' );
njr_test_rmdir( $uploads );

// A directory the guards cannot be written into gets no log either: a line
// lost is better than a log served.
$uploads = njr_test_site();
mkdir( $uploads . '/' . Logger::DIRECTORY_NAME, 0555 );
Logger::log( 'unguarded', 'file.php' );
njr_test_assert( ! file_exists( Logger::path() ), 'no line is written into a directory that could not be guarded' );
chmod( $uploads . '/' . Logger::DIRECTORY_NAME, 0755 );
njr_test_rmdir( $uploads );

// Logging off leaves no trace at all: not a directory, not a guard, not an option.
foreach ( [ 'never stored' => false, 'switched off' => [] ] as $label => $debug ) {
	$uploads = njr_test_site( $debug );
	Logger::log( 'never written', 'file.php' );
	njr_test_assert( [] === njr_test_tree( $uploads ), "with logging $label, nothing is created in uploads" );
	njr_test_assert( [] === $GLOBALS['njr_test_options'], "with logging $label, no suffix is stored" );
	njr_test_rmdir( $uploads );
}

// The migration: an upgrading site's log is moved, byte-for-byte.
$legacy_contents = "[2026-01-01 00:00:00]\t[INFO]\t[file.php]         an old line\n\x00\xff binary-safe";
$uploads         = njr_test_site();
file_put_contents( $uploads . '/' . Logger::LEGACY_FILENAME, $legacy_contents );
Logger::migrate_legacy_log();
njr_test_assert( ! file_exists( $uploads . '/' . Logger::LEGACY_FILENAME ), 'the migration moves the legacy log away from the legacy path' );
njr_test_assert( is_file( Logger::path() ) && $legacy_contents === file_get_contents( Logger::path() ), 'the migration renames it to the new path, byte-for-byte' );
njr_test_assert( is_file( $uploads . '/' . Logger::DIRECTORY_NAME . '/.htaccess' ) && is_file( $uploads . '/' . Logger::DIRECTORY_NAME . '/index.php' ), 'the migration writes the guards' );

Logger::migrate_legacy_log();
njr_test_assert( 4 === count( njr_test_tree( $uploads ) ) && $legacy_contents === file_get_contents( Logger::path() ), 'a second migration leaves exactly one log, neither truncated nor duplicated' );
njr_test_rmdir( $uploads );

// Both present: the log at the new path is never overwritten, and the old one
// is never deleted.
$uploads = njr_test_site();
Logger::log( 'written since the upgrade', 'file.php' );
$current = (string) file_get_contents( Logger::path() );
file_put_contents( $uploads . '/' . Logger::LEGACY_FILENAME, 'restored from a backup' );
Logger::migrate_legacy_log();
njr_test_assert( $current === file_get_contents( Logger::path() ), 'the migration does not overwrite a log already at the new path' );
njr_test_assert( 'restored from a backup' === @file_get_contents( $uploads . '/' . Logger::LEGACY_FILENAME ), 'nor does it delete the legacy log it did not move' );
njr_test_rmdir( $uploads );

// An upgraded site whose first line after the upgrade comes from cron or the
// front-end, before anybody opens the admin: that line must not create the new
// file first and so strand the old log at the guessable path for good.
$uploads = njr_test_site();
file_put_contents( $uploads . '/' . Logger::LEGACY_FILENAME, "an old line\n" );
Logger::log( 'the first line since the upgrade', 'file.php' );
njr_test_assert( ! file_exists( $uploads . '/' . Logger::LEGACY_FILENAME ), 'a line logged before any admin request moves the legacy log first' );
njr_test_assert( 0 === strpos( (string) file_get_contents( Logger::path() ), "an old line\n" ) && false !== strpos( (string) file_get_contents( Logger::path() ), 'the first line since the upgrade' ), 'and appends to it rather than starting a new file' );
njr_test_rmdir( $uploads );

// A move that fails leaves the line unwritten rather than written first: the
// new file would then exist, and the old log would never be moved. Here the
// directory and its guards are in place and writable, but uploads itself is
// not, so the log cannot be taken out of it.
$uploads   = njr_test_site();
$directory = $uploads . '/' . Logger::DIRECTORY_NAME;
mkdir( $directory );
foreach ( Logger::GUARDS as $guard => $contents ) file_put_contents( $directory . '/' . $guard, $contents );
file_put_contents( $uploads . '/' . Logger::LEGACY_FILENAME, "an old line\n" );
chmod( $uploads, 0555 );
Logger::log( 'while the move fails', 'file.php' );
njr_test_assert( "an old line\n" === @file_get_contents( $uploads . '/' . Logger::LEGACY_FILENAME ), 'a legacy log that cannot be moved stays where it is' );
njr_test_assert( ! file_exists( Logger::path() ), 'and no line is written to the new path ahead of it' );
chmod( $uploads, 0755 );
Logger::log( 'once it can be moved', 'file.php' );
njr_test_assert( ! file_exists( $uploads . '/' . Logger::LEGACY_FILENAME ) && 0 === strpos( (string) @file_get_contents( Logger::path() ), "an old line\n" ), 'the next line moves it after all' );
njr_test_rmdir( $uploads );

// Logging off is no reason to leave an existing log at the guessable path: the
// migration moves it and guards it all the same.
$uploads = njr_test_site( [] );
file_put_contents( $uploads . '/' . Logger::LEGACY_FILENAME, "an old line\n" );
Logger::migrate_legacy_log();
njr_test_assert( ! file_exists( $uploads . '/' . Logger::LEGACY_FILENAME ) && "an old line\n" === @file_get_contents( Logger::path() ), 'with logging off, the migration still moves the legacy log' );
njr_test_assert( is_file( $uploads . '/' . Logger::DIRECTORY_NAME . '/.htaccess' ) && is_file( $uploads . '/' . Logger::DIRECTORY_NAME . '/index.php' ), 'and guards the directory it moved it into' );
njr_test_assert( Logger::path() === Logger::reported_location(), 'and the settings screen reports where it went' );
njr_test_rmdir( $uploads );

// Nothing to migrate: a site that never logged gains nothing, whatever its setting.
$uploads = njr_test_site( false );
Logger::migrate_legacy_log();
njr_test_assert( [] === njr_test_tree( $uploads ) && [] === $GLOBALS['njr_test_options'], 'with no legacy log the migration creates nothing' );
njr_test_rmdir( $uploads );

// The settings screen reports where the logger writes — composed by the
// logger, not rebuilt beside it.
$uploads = njr_test_site();
$shown   = Logger::reported_location();
Logger::log( 'where does this go?', 'file.php' );
njr_test_assert( $shown === Logger::path() && is_file( $shown ), 'with logging on, the settings screen reports the path the logger writes to' );
njr_test_rmdir( $uploads );

// With logging off, opening the settings screen must not hand the site a
// suffix, so it can report the directory only.
$uploads = njr_test_site( [] );
$shown   = Logger::reported_location();
njr_test_assert( $uploads . '/' . Logger::DIRECTORY_NAME . '/' === $shown, 'with logging off, the settings screen reports the directory' );
njr_test_assert( [] === $GLOBALS['njr_test_options'] && [] === njr_test_tree( $uploads ), 'and reporting it creates nothing' );
njr_test_rmdir( $uploads );

// A site that has a log already — it logged before, or its legacy log was
// migrated — keeps being told where it is after logging is switched off.
$uploads = njr_test_site();
Logger::log( 'written while on', 'file.php' );
$GLOBALS['njr_test_debug'] = [];
njr_test_assert( Logger::path() === Logger::reported_location(), 'with logging off on a site that has a suffix, the settings screen still reports the full path' );
njr_test_rmdir( $uploads );

printf( "\n%s\n", 0 === $failures ? 'All tests passed.' : sprintf( '%d test(s) failed.', $failures ) );
exit( 0 === $failures ? 0 : 1 );
