<?php
/**
 * What a site setting reports, through WordPress's own option functions —
 * NextJsRevalidate\SiteSettings.
 *
 * A site setting added, updated to a different value, or deleted reports one
 * `settings` change, however many of them one request saves (ADR 0037). What
 * only this suite can see is which of WordPress's option hooks fire, and when:
 * an update to the value an option already holds fires none of them.
 *
 * @package NextJsRevalidate
 */

namespace NextJsRevalidate\Tests;

use NextJsRevalidate\Change;
use NextJsRevalidate\Settings;

class SiteSettingsChangeTest extends PendingChangesTestCase {

	public function set_up() {
		parent::set_up();

		$this->configure_site();
		$this->reset_pending_changes();
	}

	public function tear_down() {
		remove_all_filters( 'nextjs_revalidate_site_setting_options' );
		remove_all_filters( 'nextjs_revalidate_change' );
		$this->reset_log();

		parent::tear_down();
	}

	// Updated
	// ====

	public function test_updating_the_site_title_reports_a_settings_change() {
		update_option( 'blogname', 'A new title' );

		$this->assertPendingChanges( [ Change::settings() ] );
	}

	public function test_updating_two_site_settings_in_one_request_is_one_change() {
		update_option( 'blogname', 'A new title' );
		update_option( 'blogdescription', 'A new tagline' );

		$this->assertPendingChanges( [ Change::settings() ] );
	}

	/**
	 * @dataProvider default_site_settings
	 */
	public function test_every_default_site_setting_reports_a_change( $option, $value ) {
		$this->assertNotEquals( $value, get_option( $option ), "The fixture already holds $value for $option." );

		// `WPLANG` only takes a language that is installed, and the test site
		// has none but the default.
		$installed = function ( $languages ) {
			return array_merge( $languages, [ 'fr_FR' ] );
		};
		add_filter( 'get_available_languages', $installed );

		update_option( $option, $value );

		remove_filter( 'get_available_languages', $installed );

		$this->assertPendingChanges( [ Change::settings() ], "$option did not report a change." );
	}

	/**
	 * Values `sanitize_option()` keeps as they are: one it rejects is replaced
	 * with what the option already holds, and that is no change at all.
	 */
	public function default_site_settings() {
		return [
			'blogname'        => [ 'blogname', 'A new title' ],
			'blogdescription' => [ 'blogdescription', 'A new tagline' ],
			'date_format'     => [ 'date_format', 'd.m.Y' ],
			'time_format'     => [ 'time_format', 'H:i' ],
			'timezone_string' => [ 'timezone_string', 'Europe/Zurich' ],
			'gmt_offset'      => [ 'gmt_offset', 2 ],
			'home'            => [ 'home', 'http://example.org/elsewhere' ],
			'site_icon'       => [ 'site_icon', 42 ],
			'site_logo'       => [ 'site_logo', 42 ],
			'WPLANG'          => [ 'WPLANG', 'fr_FR' ],
		];
	}

	/**
	 * A classic theme's logo is its `custom_logo` mod, set from the
	 * Customizer. The mod is not a site setting — it lives in the theme's mods
	 * option, beside everything else the theme stores — but core copies it
	 * into `site_logo` as it is set, and that is.
	 */
	public function test_setting_the_theme_s_custom_logo_reports_a_change() {
		set_theme_mod( 'custom_logo', 42 );

		$this->assertSame( 42, (int) get_option( 'site_logo' ), 'Core did not copy the mod into site_logo.' );
		$this->assertPendingChanges( [ Change::settings() ] );
	}

	/**
	 * WordPress fires no update hook for an identical value.
	 */
	public function test_updating_a_site_setting_to_the_value_it_holds_reports_nothing() {
		update_option( 'blogname', 'The same title' );
		$this->reset_pending_changes();

		update_option( 'blogname', 'The same title' );

		$this->assertNoPendingChanges();
	}

	/**
	 * @dataProvider options_that_are_not_site_settings
	 */
	public function test_an_option_that_is_not_a_site_setting_reports_nothing( $option, $value ) {
		update_option( $option, $value );

		$this->assertNoPendingChanges();
	}

	public function options_that_are_not_site_settings() {
		return [
			'admin_email'         => [ 'admin_email', 'someone-else@example.test' ],
			'permalink_structure' => [ 'permalink_structure', '/%year%/%postname%/' ],
			'posts_per_page'      => [ 'posts_per_page', 3 ],
		];
	}

	// Added and deleted
	// ====

	public function test_adding_a_site_setting_reports_a_change() {
		delete_option( 'site_icon' );
		$this->reset_pending_changes();

		add_option( 'site_icon', 42 );

		$this->assertPendingChanges( [ Change::settings() ] );
	}

	public function test_deleting_a_site_setting_reports_a_change() {
		update_option( 'site_icon', 42 );
		$this->reset_pending_changes();

		delete_option( 'site_icon' );

		$this->assertPendingChanges( [ Change::settings() ] );
	}

	// The list, filtered
	// ====

	public function test_a_site_can_add_its_own_option_to_the_list() {
		add_filter( 'nextjs_revalidate_site_setting_options', function ( $options ) {
			$options[] = 'njr_footer_text';
			return $options;
		} );

		update_option( 'njr_footer_text', '© Somebody' );

		$this->assertPendingChanges( [ Change::settings() ] );
	}

	/**
	 * An option stored once per language — `landbot_config_url_fr`,
	 * `landbot_config_url_de` — is named by the prefix they share, because the
	 * languages cannot be listed ahead of time.
	 */
	public function test_a_site_can_add_every_option_sharing_a_prefix() {
		add_filter( 'nextjs_revalidate_site_setting_options', function ( $options ) {
			$options[] = 'landbot_config_url_*';
			return $options;
		} );

		update_option( 'landbot_config_url_fr', 'https://landbot.test/fr' );

		$this->assertPendingChanges( [ Change::settings() ] );
	}

	/**
	 * @dataProvider options_a_prefix_does_not_name
	 */
	public function test_a_prefix_names_only_the_options_that_start_with_it( $option ) {
		add_filter( 'nextjs_revalidate_site_setting_options', function ( $options ) {
			$options[] = 'landbot_config_url_*';
			return $options;
		} );

		update_option( $option, 'https://landbot.test/fr' );

		$this->assertNoPendingChanges();
	}

	public function options_a_prefix_does_not_name() {
		return [
			'the prefix without its separator' => [ 'landbot_config_url' ],
			'the prefix, not at the start'     => [ 'old_landbot_config_url_fr' ],
		];
	}

	/**
	 * A bare `*` would make every option on the site a site setting — the
	 * cron array and every transient among them — and report a settings
	 * change from nearly every request. It names no option instead.
	 */
	public function test_a_bare_asterisk_names_no_option() {
		add_filter( 'nextjs_revalidate_site_setting_options', function ( $options ) {
			return [ '*' ];
		} );

		update_option( 'njr_anything', 'at all' );

		$this->assertNoPendingChanges();
	}

	public function test_a_site_can_remove_a_default_from_the_list() {
		add_filter( 'nextjs_revalidate_site_setting_options', function ( $options ) {
			return array_diff( $options, [ 'blogdescription' ] );
		} );

		update_option( 'blogdescription', 'Not rendered anywhere' );

		$this->assertNoPendingChanges();
	}

	public function test_a_filter_that_returns_no_list_names_no_site_setting() {
		add_filter( 'nextjs_revalidate_site_setting_options', '__return_null' );

		update_option( 'blogname', 'A filter returned null' );

		$this->assertNoPendingChanges();
	}

	// Through the pending changes
	// ====

	public function test_the_change_filter_can_drop_a_settings_change() {
		add_filter( 'nextjs_revalidate_change', function ( $change ) {
			return Change::SETTINGS === $change['subject'] ? false : $change;
		} );

		update_option( 'blogname', 'A new title' );

		$this->assertNoPendingChanges();
	}

	public function test_the_change_filter_can_alter_a_settings_change() {
		add_filter( 'nextjs_revalidate_change', function ( $change ) {
			if ( Change::SETTINGS === $change['subject'] ) $change['site'] = 'main';
			return $change;
		} );

		update_option( 'blogname', 'A new title' );
		update_option( 'blogdescription', 'A new tagline' );

		$this->assertPendingChanges( [ [ 'subject' => Change::SETTINGS, 'site' => 'main' ] ] );
	}

	public function test_an_unconfigured_site_refuses_a_settings_change() {
		$this->unconfigure_site();
		$this->enable_logs();

		update_option( 'blogname', 'A new title' );

		$this->assertNoPendingChanges();
		$this->assertStringContainsString( '⛔ Refused a settings change — site not configured', $this->log() );
	}

	/**
	 * The plugin's own settings are options too, and none of them is a site
	 * setting.
	 */
	public function test_saving_the_plugins_own_settings_reports_nothing() {
		update_option( Settings::SETTINGS_DOMAIN_NAME, 'https://elsewhere.test' );

		$this->assertNoPendingChanges();
	}
}
