<?php
/**
 * What Yoast SEO's options report — NextJsRevalidate\Integrations\Yoast.
 *
 * Its titles and social defaults are site settings; `wpseo`, which Yoast writes
 * on its own, is not (ADR 0037). Runs with Yoast SEO loaded, which
 * `.wp-env.tests.json` installs; without it, the integration registers nothing,
 * which `tests/settings-integration-registration-test.php` asserts instead.
 *
 * @package NextJsRevalidate
 */

namespace NextJsRevalidate\Tests;

use NextJsRevalidate\Change;

class YoastSettingsTest extends PendingChangesTestCase {

	public function set_up() {
		parent::set_up();

		if ( ! defined( 'WPSEO_VERSION' ) ) $this->markTestSkipped( 'Yoast SEO is not installed.' );

		$this->configure_site();
		$this->reset_pending_changes();
	}

	/**
	 * @dataProvider yoast_site_settings
	 */
	public function test_a_yoast_site_setting_reports_a_change( $option ) {
		update_option( $option, [ 'njr-marker' => uniqid() ] );

		$this->assertPendingChanges( [ Change::settings() ] );
	}

	public function yoast_site_settings() {
		return [
			'wpseo_titles' => [ 'wpseo_titles' ],
			'wpseo_social' => [ 'wpseo_social' ],
		];
	}

	/**
	 * Yoast's own bookkeeping, which it rewrites in the background.
	 */
	public function test_yoasts_own_option_reports_nothing() {
		update_option( 'wpseo', [ 'njr-marker' => uniqid() ] );

		$this->assertNoPendingChanges();
	}
}
