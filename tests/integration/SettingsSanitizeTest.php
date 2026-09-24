<?php
/**
 * A setting is sanitised through its registered callback by core's own option
 * writes — issue #99.
 *
 * The callbacks themselves are pinned by `tests/settings-sanitize-test.php`,
 * which needs no WordPress. What only this suite can see is the part that
 * script has to imitate: that `register_setting()` really attaches them to every
 * `update_option()` and `add_option()`, that a missing row takes core's second
 * pass through the callback (#21989) without a second settings error, and that
 * answering with the domain held before really does leave the row alone.
 *
 * @package NextJsRevalidate
 */

namespace NextJsRevalidate\Tests;

use NextJsRevalidate\Settings;

class SettingsSanitizeTest extends \WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		( new Settings() )->register_settings();
		$GLOBALS['wp_settings_errors'] = [];
	}

	public function tear_down() {

		// All six, as `set_up()` registered them: the rollback restores the
		// options table, not `$wp_registered_settings`, and a callback left
		// behind would sanitise the next test's writes.
		foreach ( [
			Settings::SETTINGS_DOMAIN_NAME,
			Settings::SETTINGS_ENDPOINT_PATH_NAME,
			Settings::SETTINGS_SECRET_NAME,
			Settings::SETTINGS_ALLOW_REVALIDATE_ALL_NAME,
			Settings::SETTINGS_REVALIDATE_ON_MENU_SAVE,
			Settings::SETTINGS_DEBUG,
		] as $name ) {
			unregister_setting( Settings::SETTINGS_GROUP, $name );
		}
		$GLOBALS['wp_settings_errors'] = [];

		parent::tear_down();
	}

	public function test_a_refused_first_domain_stores_nothing_and_says_so_once() {
		delete_option( Settings::SETTINGS_DOMAIN_NAME );

		update_option( Settings::SETTINGS_DOMAIN_NAME, 'ftp://front-end.test' );

		$this->assertSame( '', get_option( Settings::SETTINGS_DOMAIN_NAME ) );
		$this->assertCount( 1, get_settings_errors( Settings::SETTINGS_DOMAIN_NAME ) );
	}

	public function test_a_refused_domain_keeps_the_one_held_before() {
		update_option( Settings::SETTINGS_DOMAIN_NAME, ' https://front-end.test/sub?x=1#y ' );
		$this->assertSame( 'https://front-end.test/sub', get_option( Settings::SETTINGS_DOMAIN_NAME ) );

		$this->assertFalse( update_option( Settings::SETTINGS_DOMAIN_NAME, 'front-end.test' ), 'nothing is written' );

		$this->assertSame( 'https://front-end.test/sub', get_option( Settings::SETTINGS_DOMAIN_NAME ) );
		$this->assertCount( 1, get_settings_errors( Settings::SETTINGS_DOMAIN_NAME ) );
	}

	public function test_a_valid_first_domain_survives_the_second_pass() {
		delete_option( Settings::SETTINGS_DOMAIN_NAME );

		update_option( Settings::SETTINGS_DOMAIN_NAME, " https://front-end.test\n" );

		$this->assertSame( 'https://front-end.test', get_option( Settings::SETTINGS_DOMAIN_NAME ) );
		$this->assertSame( [], get_settings_errors( Settings::SETTINGS_DOMAIN_NAME ) );
	}

	public function test_the_secret_is_stored_trimmed() {
		update_option( Settings::SETTINGS_SECRET_NAME, "  my secret/+%\n" );

		$this->assertSame( 'my secret/+%', get_option( Settings::SETTINGS_SECRET_NAME ) );
	}
}
