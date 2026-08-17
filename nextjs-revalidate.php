<?php
/**
 * Plugin Name:       Next.js revalidate
 * Plugin URI:        https://github.com/superhuit-agency/nextjs-revalidate.git
 * Description:       Next.js plugin allows you to purge & re-build the cached pages from the WordPress admin area. It also automatically purges & re-builds when a page/post/... is save or updated.
 * Author:            superhuit
 * Author URI:        https://www.superhuit.ch
 * Version:           1.6.9
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
 * @version 1.3.0
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
use NextJsRevalidate\FailureWindow;
use NextJsRevalidate\I18n;
use NextJsRevalidate\RevalidateAll;
use NextJsRevalidate\Revalidate;
use NextJsRevalidate\Settings;
use NextJsRevalidate\Cron\ScheduledPurges;
use NextJsRevalidate\RestApi;
use NextJsRevalidate\RevalidateQueue;

// Exit if accessed directly.
defined( 'ABSPATH' ) or die( 'Cheatin&#8217; uh?' );

define( 'NJR_PATH', __DIR__ );
define( 'NJR_URI', plugin_dir_url(__FILE__) );
define( 'NJR_VERSION', '1.6.0' );

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
	private FailureWindow $failureWindow;
	private ScheduledPurges $cronScheduledPurges;
	private RevalidateAll $revalidateAll;
	private RevalidateQueue $queue;
	private RestApi $restApi;
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
		$this->failureWindow       = new FailureWindow();
		$this->revalidate          = new Revalidate();
		$this->cronScheduledPurges = new ScheduledPurges();
		$this->revalidateAll       = new RevalidateAll();
		$this->queue               = new RevalidateQueue();
		$this->restApi             = new RestApi();

		register_activation_hook( __FILE__, [$this, 'activate'] );
		register_deactivation_hook( __FILE__, [$this, 'deactivate'] );
		register_uninstall_hook(__FILE__, [__CLASS__, 'uninstall']);

		// Sites created after the plugin was activated are set up as they are created.
		add_action( 'wp_initialize_site', [$this, 'setup_new_site'], 100 );
	}

	function __get($name) {
		return $this->{$name};
	}

	/**
	 * Apply a per-site operation to every site of the network.
	 *
	 * Every piece of this plugin's state is per-site, so setup, teardown and
	 * migration all reach the sites they concern through this one sweep.
	 * A sweep reaches every site or it does not start: on a large network it
	 * declines, leaving the sites untouched, rather than covering as many as
	 * one request has time for. On a single install the only site is the one
	 * being served.
	 *
	 * @param callable $callback The per-site operation to apply.
	 * @return bool Whether the sweep ran.
	 */
	public static function for_each_site( callable $callback ) {

		if ( !is_multisite() ) {
			call_user_func( $callback );
			return true;
		}

		if ( wp_is_large_network( 'sites' ) ) return false;

		$site_ids = get_sites( [
			'fields'     => 'ids',
			'network_id' => get_current_network_id(),
			'number'     => 0,
		] );

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			call_user_func( $callback );
			restore_current_blog();
		}

		return true;
	}

	/**
	 * Prepare one site to revalidate: its queue table, its registered
	 * settings and its scheduled cron.
	 *
	 * Applied identically whether the site is the only one of a single
	 * install, an existing site reached by a sweep, or a site created later.
	 */
	public function setup_site() {
		$this->cronScheduledPurges->schedule_cron();
		$this->settings->define_settings();

		$this->queue->create_table();
	}

	/**
	 * Tear one site down as far as a deactivation goes: its crons stop, its
	 * failure window is forgotten, its queue table and its settings stay where
	 * they are.
	 *
	 * The failure window is the one exception to the two teardown depths, and
	 * the reason is semantic rather than tidiness: while deactivated, content
	 * changes and nothing is attempted, so on reactivation the front-end's
	 * health is not bad but *unknown*, and carrying the old warning across that
	 * gap asserts evidence the plugin no longer has. The exception covers state
	 * that is evidence about a *running* plugin and stops there — clearing
	 * settings or pending scheduled purges here would be destructive.
	 */
	public function teardown_site() {
		ScheduledPurges::unschedule_cron();
		$this->queue->unschedule_cron();

		FailureWindow::clear();
	}

	/**
	 * Tear one site down as far as an uninstall goes: its settings, its
	 * scheduled purges and its queue table are dropped.
	 */
	public function uninstall_site() {
		Settings::delete_settings();
		ScheduledPurges::delete_scheduled_purges();
		FailureWindow::clear();

		$this->queue->delete_table();
	}

	/**
	 * Execute anything necessary on plugin activation
	 *
	 * @param bool $network_wide Whether the plugin is being activated for
	 *                           every site of the network.
	 */
	function activate( $network_wide = false ) {

		if ( !$network_wide ) {
			$this->setup_site();
			return;
		}

		if ( !self::for_each_site( [$this, 'setup_site'] ) ) $this->refuse_network_activation();
	}

	/**
	 * Set up a site created after the plugin was activated.
	 *
	 * A newly created site starts unconfigured by design — it gets what site
	 * setup gives every site, never the revalidate url and secret of the site
	 * it was created from, which point at another front-end.
	 *
	 * @param WP_Site $new_site The site that has just been created.
	 */
	public function setup_new_site( $new_site ) {

		// A site the plugin is not active on is not this plugin's to set up.
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( !is_plugin_active_for_network( plugin_basename( __FILE__ ) ) ) return;

		switch_to_blog( (int) $new_site->blog_id );
		$this->setup_site();
		restore_current_blog();
	}

	/**
	 * Refuse a network activation whose sweep declined to start.
	 *
	 * Setup is eager and has no lazy fallback, so a network left activated
	 * with most of its sites unset up would revalidate nothing and say
	 * nothing. The activation is undone instead, which is also what lets the
	 * operator activate the plugin on each site individually: a network
	 * activated plugin cannot be activated per site.
	 *
	 * Dying is what undoes it — WordPress records the activation after the
	 * activation hook has run, so the record is never written. The
	 * deactivation is there for the paths that record it first, where it is
	 * the only thing that undoes anything.
	 */
	private function refuse_network_activation() {

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		deactivate_plugins( plugin_basename( __FILE__ ), true, true );

		wp_die(
			sprintf(
				/* translators: %s: number of sites on the network. */
				__( 'Next.js revalidate cannot set up the %s sites of this network in a single request, and it does not set up some of them and leave the rest without a queue table. Activate the plugin on each site individually instead.', 'nextjs-revalidate' ),
				number_format_i18n( get_blog_count() )
			),
			__( 'Plugin could not be activated', 'nextjs-revalidate' ),
			[ 'back_link' => true ]
		);
	}

	/**
	 * Execute anything necessary on plugin deactivation
	 *
	 * @param bool $network_deactivating Whether the plugin is being
	 *                                   deactivated for every site of the
	 *                                   network.
	 */
	function deactivate( $network_deactivating = false ) {

		if ( !$network_deactivating ) {
			$this->teardown_site();
			return;
		}

		self::for_each_site( [$this, 'teardown_site'] );
	}

	/**
	 * Execute anything necessary on plugin uninstall (deletion)
	 *
	 * A plugin is uninstalled for the whole network at once, so the teardown
	 * always sweeps.
	 */
	public static function uninstall() {
		self::for_each_site( [self::init(), 'uninstall_site'] );
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
 * @param  string $url       The URL to purge
 * @param  int    $priority  Optional. Used to specify the order in which the url are purged.
 *                           Lower numbers correspond with earlier purge,
 *                           and urls with the same priority are executed in the order in which they were added.
 *                           Default 10.
 *
 * @return bool        Whether the URL was queued. False when the site is
 *                     unconfigured and the revalidation is refused.
 */
function nextjs_revalidate_purge_url( $url, $priority = 10 ) {
	$njr = NextJsRevalidate::init();
	$added = $njr->queue->add_item( $url, $priority );
	return ( $added && !is_wp_error($added) );
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
