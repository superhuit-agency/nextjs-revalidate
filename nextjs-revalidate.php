<?php
/**
 * Plugin Name:       Next.js revalidate
 * Plugin URI:        https://github.com/com:superhuit-agency/nextjs-revalidate.git
 * Description:       Next.js plugin allows you to purge & re-build the cached pages from the WordPress admin area. It also automatically purges & re-builds when a page/post/... is save or updated.
 * Author:            superhuit
 * Author URI:        https://www.superhuit.ch
 * Version:           1.2.0
 * license:           GPLv3
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Requires PHP:      7.4
 * Text Domain:       nextjs-revalidate
 * Requires at least: 5.0.0
 * Tested up to:      6.2
 *
 * @package NextJsRevalidate
 * @category Core
 * @author Superhuit, Kuuak
 * @version 1.2.0
 */
/*
Next.js revalidate is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
any later version.

Next.js revalidate is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with Next.js revalidate. If not, see {URI to Plugin License}.
*/

use NextJsRevalidate\Assets;
use NextJsRevalidate\I18n;
use NextJsRevalidate\PurgeAll;
use NextJsRevalidate\Revalidate;
use NextJsRevalidate\Settings;
use NextJsRevalidate\Cron\ScheduledPurges;

// Exit if accessed directly.
defined( 'ABSPATH' ) or die( 'Cheatin&#8217; uh?' );

define( 'NJR_PATH', __DIR__ );
define( 'NJR_URI', plugin_dir_url(__FILE__) );

// Load dependencies
// ====
if ( ! file_exists(__DIR__ . '/vendor/autoload.php') ) {
	add_action( 'admin_notices', function() {
		?>
		<div class="notice notice-warning">
			<p><?php _e( 'Please install composer dependencies for Next.js Revalidate to work', 'nextjs-revalidate' ); ?></p>
		</div>
		<?php
	} );
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

class NextJsRevalidate {

	private Assets $assets;
	private Revalidate $revalidate;
	private Settings $settings;
	private ScheduledPurges $cronScheduledPurges;
	private PurgeAll $purgeAll;

	private static NextJsRevalidate $instance;

	public static function init(): NextJsRevalidate {

		if (!isset(self::$instance)) {
			self::$instance = new static();
	}

	return self::$instance;
	}

	protected function __construct() {

		new I18n();

		$this->assets              = new Assets();
		$this->settings            = new Settings();
		$this->revalidate          = new Revalidate();
		$this->cronScheduledPurges = new ScheduledPurges();
		$this->purgeAll            = new PurgeAll();

		register_activation_hook( __FILE__, [$this, 'activate'] );
		register_deactivation_hook( __FILE__, [$this, 'deactivate'] );
		register_uninstall_hook(__FILE__, [__CLASS__, 'uninstall']);
	}

	function __get($name) {
		return $this->{$name};
	}

	/**
	 * Execute anything necessary on plugin activation
	 */
	function activate() {
		$this->cronScheduledPurges->schedule_cron();
		$this->settings->define_settings();
	}

	/**
	 * Execute anything necessary on plugin deactivation
	 */
	function deactivate() {
		ScheduledPurges::unschedule_cron();
	}

	/**
	 * Execute anything necessary on plugin uninstall (deletion)
	 */
	public static function uninstall() {
		Settings::delete_settings();
	}

}

NextJsRevalidate::init();

/**
 * API functions
 */

/**
 * Purge an URL from Next.js cache
 * Triggers a revalidation of the given URL
 *
 * @param  string $url The URL to purge
 * @return bool        Whether the purge was successful
 */
function nextjs_revalidate_purge_url( $url ) {
	$njr = NextJsRevalidate::init();
	return $njr->revalidate->purge( $url );
}

/**
 * Schedule an URL purge from Next.js cache
 * Triggers a revalidation of the given URL at the given date time
 *
 * @param  String $datetime The date time when to purge
 * @param  String $url      The URL to purge
 * @return Bool             Whether the schedule is registered
 */
function nextjs_revalidate_schedule_purge_url( $datetime, $url ) {
	$njr = NextJsRevalidate::init();
	return $njr->cronScheduledPurges->schedule_purge( $datetime, $url );
}
