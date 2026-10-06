<?php
/**
 * Log rotation through real WordPress — Logger::log() and the
 * `nextjs_revalidate_log_max_size` filter (ADR 0041).
 *
 * What only this suite can see: the filter answered by a real `add_filter()`
 * callback, the logs setting read from a real option, and the archive written
 * into the real uploads directory. Concurrent writers, and the cases with no
 * WordPress in them, are `tests/log-rotation-test.php`; the log viewer reading
 * into the archive is `LogViewerTest`.
 *
 * @package NextJsRevalidate
 */

namespace NextJsRevalidate\Tests;

use NextJsRevalidate\Logger;
use NextJsRevalidate\LogViewer;
use NextJsRevalidate\Settings;

class LogRotationTest extends \WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		LogViewerTest::remove_log_directory();
		update_option( Settings::SETTINGS_DEBUG, [ 'enable-logs' => 'on' ] );
	}

	public function tear_down() {
		remove_all_filters( Logger::MAX_SIZE_FILTER );
		LogViewerTest::remove_log_directory();

		parent::tear_down();
	}

	public function test_a_line_written_at_the_limit_moves_the_log_into_the_archive() {
		$this->limit( 1024 );
		$old = $this->fill_log( 1024 );

		Logger::log( 'the first line of the new log', 'test.php' );

		$this->assertSame( $old, file_get_contents( Logger::archive_path() ) );
		$this->assertStringContainsString( 'the first line of the new log', (string) file_get_contents( Logger::path() ) );
		$this->assertSame( 1, substr_count( (string) file_get_contents( Logger::path() ), "\n" ), 'The new log holds more than the line that started it.' );
	}

	public function test_a_second_rotation_replaces_the_archive() {
		$this->limit( 1024 );
		$this->fill_log( 1024 );
		Logger::log( 'first rotation', 'test.php' );
		$second = $this->fill_log( 2048 );

		Logger::log( 'second rotation', 'test.php' );

		$this->assertSame( $second, file_get_contents( Logger::archive_path() ) );
		$this->assertSame(
			[ '.htaccess', 'index.php', basename( Logger::archive_path() ), basename( Logger::path() ) ],
			$this->files(),
			'There is not exactly one log and one archive beside the guards.'
		);
	}

	public function test_the_archive_is_in_the_guarded_directory_under_the_suffix() {
		$this->limit( 1024 );
		$this->fill_log( 1024 );
		Logger::log( 'rotate', 'test.php' );

		$this->assertSame( Logger::directory(), dirname( Logger::archive_path() ) );
		$this->assertStringContainsString( get_option( Logger::SUFFIX_OPTION_NAME ), basename( Logger::archive_path() ) );
		$this->assertStringEqualsFile( Logger::directory() . '/.htaccess', Logger::GUARDS['.htaccess'] );
	}

	/**
	 * @dataProvider filtered_limits
	 */
	public function test_the_filter_sets_the_limit( $filtered, $size, $rotates ) {
		$this->limit( $filtered );
		$this->fill_log( $size );

		Logger::log( 'a line', 'test.php' );

		$this->assertSame( $rotates, file_exists( Logger::archive_path() ) );
	}

	public function filtered_limits() {
		return [
			'unhooked, under 5 MB'     => [ null, Logger::DEFAULT_MAX_SIZE - 1, false ],
			'unhooked, at 5 MB'        => [ null, Logger::DEFAULT_MAX_SIZE, true ],
			'1 KB, at it'              => [ 1024, 1024, true ],
			'0 never rotates'          => [ 0, Logger::DEFAULT_MAX_SIZE + 1, false ],
			'-1 never rotates'         => [ -1, Logger::DEFAULT_MAX_SIZE + 1, false ],
			"'10MB' is the default"    => [ '10MB', Logger::DEFAULT_MAX_SIZE, true ],
			"'1024' is the default"    => [ '1024', 2048, false ],
			'an array is the default'  => [ [ 1024 ], 2048, false ],
			'false is the default'     => [ false, 2048, false ],
		];
	}

	public function test_a_filter_returning_null_falls_back_to_the_default() {
		add_filter( Logger::MAX_SIZE_FILTER, '__return_null' );

		$this->assertSame( Logger::DEFAULT_MAX_SIZE, Logger::max_size() );
	}

	public function test_nothing_rotates_with_logging_off() {
		$this->limit( 1024 );
		$old = $this->fill_log( 4096 );
		update_option( Settings::SETTINGS_DEBUG, [] );

		Logger::log( 'never written', 'test.php' );

		$this->assertFileDoesNotExist( Logger::archive_path() );
		$this->assertSame( $old, file_get_contents( Logger::path() ) );
	}

	public function test_viewing_a_log_past_the_limit_never_rotates_it() {
		$this->limit( 1024 );
		$old = $this->fill_log( 4096 );

		LogViewer::body();

		$this->assertFileDoesNotExist( Logger::archive_path() );
		$this->assertSame( $old, file_get_contents( Logger::path() ) );
	}

	// Helpers
	// ====

	/**
	 * Answer the size filter with `$value`, or leave it unhooked for null.
	 */
	private function limit( $value ) {
		if ( null === $value ) return;
		add_filter( Logger::MAX_SIZE_FILTER, function () use ( $value ) { return $value; } );
	}

	/**
	 * Make the log exactly `$size` bytes of whole lines, the guards in place,
	 * and answer what it holds.
	 */
	private function fill_log( $size ) {
		wp_mkdir_p( Logger::directory() );
		foreach ( Logger::GUARDS as $name => $contents ) file_put_contents( Logger::directory() . '/' . $name, $contents );

		$line     = str_pad( 'filler', 63, '.' ) . "\n";
		$contents = str_repeat( $line, intdiv( $size, 64 ) );
		$contents = str_repeat( '.', $size - strlen( $contents ) ) . $contents;

		file_put_contents( Logger::path(), $contents );

		return $contents;
	}

	private function files() {
		$files = array_values( array_diff( scandir( Logger::directory() ), [ '.', '..' ] ) );
		sort( $files );
		return $files;
	}
}
