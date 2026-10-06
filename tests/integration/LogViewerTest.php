<?php
/**
 * The log viewer on the Debug tab, and the refresh that reads it again —
 * NextJsRevalidate\LogViewer.
 *
 * What only this suite can see: which settings fields the Debug tab registers
 * for each state of the logs setting, what the viewer prints of a real log
 * written by the real logger, and how `admin-ajax.php` answers a refresh for
 * each kind of caller. How the end of a file is read is pinned without
 * WordPress, by `tests/log-tail-test.php`; the scrolling and the live refresh
 * happen in a browser, and are the runbook's.
 *
 * @package NextJsRevalidate
 */

namespace NextJsRevalidate\Tests;

use NextJsRevalidate;
use NextJsRevalidate\Logger;
use NextJsRevalidate\LogTail;
use NextJsRevalidate\LogViewer;
use NextJsRevalidate\Settings;
use WPAjaxDieContinueException;
use WPAjaxDieStopException;

class LogViewerTest extends \WP_Ajax_UnitTestCase {

	public function set_up() {
		parent::set_up();

		// Whatever an earlier test logged is on disk, where no rollback
		// reaches, and under a suffix that rollback has since forgotten.
		self::remove_log_directory();
	}

	public function tear_down() {
		self::remove_log_directory();
		unset( $_POST['nonce'] );

		parent::tear_down();
	}

	// The Debug tab
	// ====

	public function test_the_debug_tab_has_no_viewer_while_logging_is_off() {
		$fields = $this->debug_fields();

		$this->assertArrayHasKey( 'enable-logs', $fields );
		$this->assertArrayNotHasKey( LogViewer::FIELD_ID, $fields );
	}

	public function test_a_log_left_from_when_logging_was_on_is_not_shown_once_it_is_off() {
		$this->enable_logs();
		Logger::log( 'written while on', 'test.php' );
		$this->disable_logs();

		$this->assertArrayNotHasKey( LogViewer::FIELD_ID, $this->debug_fields() );
	}

	public function test_the_debug_tab_shows_the_viewer_while_logging_is_on() {
		$this->enable_logs();

		$fields = $this->debug_fields();

		$this->assertArrayHasKey( 'enable-logs', $fields );
		$this->assertArrayHasKey( LogViewer::FIELD_ID, $fields );
	}

	public function test_the_viewer_carries_what_a_refresh_needs() {
		$this->enable_logs();

		$html = $this->render();

		$this->assertStringContainsString( 'data-action="' . LogViewer::ACTION . '"', $html );
		$this->assertStringContainsString( 'data-nonce="' . wp_create_nonce( LogViewer::ACTION ) . '"', $html );
		$this->assertStringContainsString( 'data-url="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '"', $html );
	}

	public function test_the_live_refresh_toggle_is_never_submitted_or_restored() {
		$this->enable_logs();

		$this->assertMatchesRegularExpression(
			'/<input type="checkbox" class="njr-log-viewer__live" autocomplete="off" \/>/',
			$this->render(),
			'The toggle has a name, so the settings form would save it, or a browser may restore it.'
		);
	}

	// Nothing logged yet
	// ====

	public function test_a_site_that_never_logged_reads_as_nothing_logged_yet() {
		$this->enable_logs();

		$this->assertStringContainsString( 'Nothing has been logged yet', LogViewer::body() );
	}

	public function test_viewing_a_site_that_never_logged_creates_nothing() {
		$this->enable_logs();

		LogViewer::body();

		$this->assertFalse( get_option( Logger::SUFFIX_OPTION_NAME ), 'Viewing handed the site a suffix.' );
		$this->assertDirectoryDoesNotExist( Logger::directory(), 'Viewing created the log directory.' );
	}

	public function test_viewing_a_log_deleted_by_hand_does_not_recreate_it() {
		$this->enable_logs();
		Logger::log( 'soon deleted', 'test.php' );
		unlink( Logger::path() );

		$this->assertStringContainsString( 'Nothing has been logged yet', LogViewer::body() );
		$this->assertFileDoesNotExist( Logger::path() );
	}

	// The end of the log
	// ====

	public function test_the_viewer_shows_the_last_200_lines_in_file_order() {
		$this->enable_logs();
		for ( $i = 1; $i <= 250; $i++ ) Logger::log( "line number $i.", 'test.php' );

		$body = LogViewer::body();

		$this->assertStringNotContainsString( 'line number 50.', $body );
		$this->assertStringContainsString( 'line number 51.', $body );
		$this->assertStringContainsString( 'line number 250.', $body );
		$this->assertLessThan( strpos( $body, 'line number 250.' ), strpos( $body, 'line number 51.' ), 'The lines are not in file order.' );
		$this->assertSame( 200, substr_count( $body, 'line number ' ) );
	}

	public function test_the_status_line_counts_the_lines_and_sizes_the_file() {
		$this->enable_logs();
		for ( $i = 1; $i <= 250; $i++ ) Logger::log( "line number $i.", 'test.php' );

		$size = size_format( filesize( Logger::path() ), 1 );

		$this->assertMatchesRegularExpression(
			'/Showing the last 200 of 250 lines \(' . preg_quote( esc_html( $size ), '/' ) . '\) — refreshed \d{2}:\d{2}:\d{2}/',
			LogViewer::body()
		);
	}

	public function test_the_count_shrinks_with_a_short_log() {
		$this->enable_logs();
		Logger::log( 'one', 'test.php' );
		Logger::log( 'two', 'test.php' );
		Logger::log( 'three', 'test.php' );

		$this->assertStringContainsString( 'Showing the last 3 of 3 lines', LogViewer::body() );
	}

	public function test_a_log_whose_last_line_is_too_long_to_read_is_not_called_empty() {
		$this->enable_logs();
		Logger::log( 'a short line', 'test.php' );
		Logger::log( str_repeat( 'x', LogTail::MAX_BYTES + 1 ), 'test.php' );

		$body = LogViewer::body();

		$this->assertStringNotContainsString( 'Nothing has been logged yet', $body );
		$this->assertStringContainsString( 'Showing the last 0 of 2 lines', $body );
	}

	public function test_a_line_is_escaped_exactly_as_it_was_written() {
		$this->enable_logs();
		Logger::log( '<script>alert("x")</script> & &amp;', 'test.php' );

		$body = LogViewer::body();

		$this->assertStringNotContainsString( '<script>', $body );
		$this->assertStringContainsString( '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; &amp;amp;', $body );
	}

	public function test_a_line_that_is_not_utf8_is_shown_rather_than_emptied() {
		$this->enable_logs();
		Logger::log( "before \xff after", 'test.php' );

		$this->assertStringContainsString( "before \u{FFFD} after", LogViewer::body() );
	}

	public function test_only_error_lines_are_tinted() {
		$this->enable_logs();
		Logger::log( 'all is well', 'test.php' );
		Logger::log( 'something broke', 'test.php', Logger::ERROR );
		Logger::log( 'looking closer', 'test.php', Logger::DEBUG );

		$body = LogViewer::body();

		$this->assertSame( 1, substr_count( $body, 'njr-log-viewer__error' ) );
		$this->assertMatchesRegularExpression( '/<span class="njr-log-viewer__error">[^<]*\[ERROR\][^<]*something broke<\/span>/', $body );
	}

	// The refresh
	// ====

	public function test_a_refresh_answers_what_the_page_printed() {
		$this->enable_logs();
		Logger::log( 'a line', 'test.php' );
		$this->become( 'administrator' );

		$answer = $this->refresh( wp_create_nonce( LogViewer::ACTION ) );

		$this->assertTrue( $answer['success'] );
		$this->assertTrue( $answer['data']['enabled'] );
		$this->assertSame( self::without_time( LogViewer::body() ), self::without_time( $answer['data']['html'] ) );
		$this->assertStringContainsString( 'a line', $answer['data']['html'] );
	}

	public function test_a_refresh_is_refused_without_manage_options() {
		$this->enable_logs();
		Logger::log( 'not for editors', 'test.php' );
		$this->become( 'editor' );

		$answer = $this->refresh( wp_create_nonce( LogViewer::ACTION ) );

		$this->assertFalse( $answer['success'] );
		$this->assertSame( 'forbidden', $answer['data']['reason'] );
		$this->assertStringNotContainsString( 'not for editors', (string) $this->_last_response );
	}

	public function test_a_refresh_is_refused_with_a_bad_nonce() {
		$this->enable_logs();
		Logger::log( 'not without a nonce', 'test.php' );
		$this->become( 'administrator' );

		$answer = $this->refresh( 'not-a-nonce' );

		$this->assertFalse( $answer['success'] );
		$this->assertSame( 'nonce', $answer['data']['reason'] );
		$this->assertStringNotContainsString( 'not without a nonce', (string) $this->_last_response );
	}

	public function test_a_refresh_after_logging_was_switched_off_says_so() {
		$this->enable_logs();
		Logger::log( 'written while on', 'test.php' );
		$this->disable_logs();
		$this->become( 'administrator' );

		$answer = $this->refresh( wp_create_nonce( LogViewer::ACTION ) );

		$this->assertTrue( $answer['success'] );
		$this->assertFalse( $answer['data']['enabled'] );
		$this->assertStringContainsString( 'Logging has been switched off', $answer['data']['html'] );
		$this->assertStringNotContainsString( 'written while on', $answer['data']['html'] );
	}

	public function test_a_refresh_of_a_site_that_never_logged_creates_no_file() {
		$this->enable_logs();
		$this->become( 'administrator' );

		$answer = $this->refresh( wp_create_nonce( LogViewer::ACTION ) );

		$this->assertStringContainsString( 'Nothing has been logged yet', $answer['data']['html'] );
		// No suffix is asserted here, unlike for `body()` above: `admin-ajax.php`
		// fires `admin_init`, where the Enable logs help line asks
		// `Logger::reported_location()` for the full path, which hands a site
		// that is logging its suffix (ADR 0024). That is the settings screen's,
		// not the viewer's.
		$this->assertDirectoryDoesNotExist( Logger::directory() );
	}

	// Helpers
	// ====

	private function enable_logs() {
		update_option( Settings::SETTINGS_DEBUG, [ 'enable-logs' => 'on' ] );
	}

	private function disable_logs() {
		update_option( Settings::SETTINGS_DEBUG, [] );
	}

	private function become( $role ) {
		wp_set_current_user( self::factory()->user->create( [ 'role' => $role ] ) );
	}

	/**
	 * The fields the Debug tab registers, keyed by ID.
	 *
	 * @return array
	 */
	private function debug_fields() {
		global $wp_settings_fields;

		$wp_settings_fields = [];
		NextJsRevalidate::init()->settings->register_fields();

		return $wp_settings_fields[ Settings::PAGE_NAME ]['nextjs-revalidate-section-debug'] ?? [];
	}

	/**
	 * What the viewer prints with the page.
	 *
	 * @return string
	 */
	private function render() {
		ob_start();
		LogViewer::render();
		return (string) ob_get_clean();
	}

	/**
	 * Ask for a refresh through `admin-ajax.php`'s own route, and decode the
	 * answer.
	 *
	 * @param string $nonce
	 * @return array
	 */
	private function refresh( $nonce ) {
		$_POST['nonce'] = $nonce;

		try {
			$this->_handleAjax( LogViewer::ACTION );
		} catch ( WPAjaxDieContinueException $e ) {
			// `wp_send_json()` ends the request this way, having answered.
		} catch ( WPAjaxDieStopException $e ) {
			// And this way when it answered nothing, which the decode below fails.
		}

		$answer = json_decode( (string) $this->_last_response, true );
		$this->assertIsArray( $answer, 'The refresh did not answer JSON: ' . $this->_last_response );

		return $answer;
	}

	/**
	 * A body without the time it was read, which two reads a second apart
	 * would disagree on.
	 */
	private static function without_time( $html ) {
		return preg_replace( '/\d{2}:\d{2}:\d{2}/', 'HH:MM:SS', $html );
	}

	private static function remove_log_directory() {
		$directory = Logger::directory();
		if ( ! is_dir( $directory ) ) return;

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $entry ) $entry->isDir() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
		rmdir( $directory );
	}
}
