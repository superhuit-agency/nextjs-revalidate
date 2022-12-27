<?php
/**
 * Plugin Name:       Next.js revalidate
 * Plugin URI:        https://github.com/com:superhuit-agency/nextjs-revalidate.git
 * Description:       Next.js plugin allows you to purge & re-build the cached pages from the WordPress admin area. It also automatically purges & re-builds when a page/post/... is save or updated.
 * Author:            superhuit
 * Author URI:        https://www.superhuit.ch
 * Version:           1.0.1
 * license:           GPLv3
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Requires PHP:      7.4
 * Text Domain:       nextjs-revalidate
 * Requires at least: 5.0.0
 * Tested up to:      6.1
 *
 * @package NextJsRevalidate
 * @category Core
 * @author Superhuit, Kuuak
 * @version 1.0.1
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

use NextJsRevalidate\I18n;
use NextJsRevalidate\Revalidate;
use NextJsRevalidate\Settings;

// Exit if accessed directly.
defined( 'ABSPATH' ) or die( 'Cheatin&#8217; uh?' );

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

	private Revalidate $revalidate;
	private Settings $settings;

	function __construct() {

		new I18n();

		$this->revalidate = new Revalidate();
		$this->settings   = new Settings();

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
	function activate() {}

	/**
	 * Execute anything necessary on plugin deactivation
	 */
	function deactivate() {}

	/**
	 * Execute anything necessary on plugin uninstall (deletion)
	 */
	public static function uninstall() {
		Settings::delete_settings();
	}

}

$NEXTJS_REVALIDATE = new NextJsRevalidate();

/**
 * API functions
 */

/**
 * Purge an URL from Next.js cache
 * Triggers a revalidation of the given URL
 *
 * @param  string $url The URL to purge
 * @return bool        Wether the purge was successful
 */
function nextjs_revalidate_purge_url( $url ) {
	global $NEXTJS_REVALIDATE;
	return $NEXTJS_REVALIDATE->revalidate->purge( $url );
}
