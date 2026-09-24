<?php
/**
 * Plugin Name:       Next.js revalidate
 * Plugin URI:        https://github.com/superhuit-agency/nextjs-revalidate.git
 * Description:       Next.js plugin allows you to purge & re-build the cached pages from the WordPress admin area. It also automatically purges & re-builds when a page/post/... is save or updated.
 * Author:            superhuit
 * Author URI:        https://www.superhuit.ch
 * Version:           1.7.0
 * license:           GPLv3
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Requires PHP:      7.4
 * Text Domain:       nextjs-revalidate
 * Requires at least: 5.6
 * Tested up to:      7.1
 *
 * @package NextJsRevalidate
 * @category Core
 * @author Superhuit, Kuuak
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
use NextJsRevalidate\Change;
use NextJsRevalidate\FailureWindow;
use NextJsRevalidate\FseSnapshot;
use NextJsRevalidate\I18n;
use NextJsRevalidate\Integrations\Redirection;
use NextJsRevalidate\PendingChanges;
use NextJsRevalidate\Probe;
use NextJsRevalidate\RevalidateAll;
use NextJsRevalidate\Revalidate;
use NextJsRevalidate\Settings;
use NextJsRevalidate\Cron\ScheduledPurges;
use NextJsRevalidate\Interfaces\Hookable;
use NextJsRevalidate\RestApi;
use NextJsRevalidate\RevalidateQueue;

// Exit if accessed directly.
defined( 'ABSPATH' ) or die( 'Cheatin&#8217; uh?' );

define( 'NJR_PATH', __DIR__ );
define( 'NJR_URI', plugin_dir_url(__FILE__) );

// The plugin version has exactly one source of truth: the header above.
// Reading it back keeps the constant from drifting when a release bumps the
// header — as it had, sitting at 1.6.0 while the plugin shipped 1.6.9.
// `get_file_data()` rather than `get_plugin_data()`: the latter lives in an
// admin-only include, and this constant is read on front-end requests too.
$njr_plugin_header = get_file_data( __FILE__, [ 'Version' => 'Version' ] );
// The fallback is the last release to ship without the migration ledger: a
// header this cannot read would otherwise leave the constant empty, and an
// empty version compares as older than every migration threshold — re-running
// every migration body on every request, which is the bug this replaced.
define( 'NJR_VERSION', $njr_plugin_header['Version'] ?: '1.6.9' );
unset( $njr_plugin_header );

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

/**
 * Every object the composition root constructs is private, and `__get()` below
 * hands any of them back to a reader outside this class — which is how
 * `Abstracts\Base` reaches the six it shares, how `Assets` and `Logger` reach
 * the one each of them needs, and how the two API functions at the foot of this
 * file reach theirs.
 *
 * Declared here so static analysis can see that surface: without these, every
 * one of those reads is an access to a private property, which is most of what
 * `phpstan-baseline.neon` used to carry. Read, never written — the composition
 * root is the only thing that assigns them.
 *
 * @property-read Assets          $assets
 * @property-read Revalidate      $revalidate
 * @property-read Probe           $probe
 * @property-read Settings        $settings
 * @property-read FailureWindow   $failureWindow
 * @property-read PendingChanges  $pendingChanges
 * @property-read ScheduledPurges $cronScheduledPurges
 * @property-read RevalidateAll   $revalidateAll
 * @property-read FseSnapshot     $fseSnapshot
 * @property-read RevalidateQueue $queue
 * @property-read RestApi         $restApi
 * @property-read Redirection     $redirection
 */
class NextJsRevalidate {

	private Assets $assets;
	private Revalidate $revalidate;
	private Probe $probe;
	private Settings $settings;
	private FailureWindow $failureWindow;
	private PendingChanges $pendingChanges;
	private ScheduledPurges $cronScheduledPurges;
	private RevalidateAll $revalidateAll;
	private FseSnapshot $fseSnapshot;
	private RevalidateQueue $queue;
	private RestApi $restApi;
	private Redirection $redirection;
	private static NextJsRevalidate $instance;

	/**
	 * Every object this root has constructed, in construction order.
	 *
	 * @var Hookable[]
	 */
	private array $hookables = [];

	public static function init(): NextJsRevalidate {

		if (!isset(self::$instance)) {
			self::$instance = new static();
	}

	return self::$instance;
	}

	/**
	 * The composition root: which of this plugin's objects exist, in what
	 * order, and when they register their hooks.
	 *
	 * Constructing a Hookable touches no global state, so the two are separate
	 * acts here: everything is built first, then every one of them is asked to
	 * register, in construction order. That order is load-bearing — WordPress
	 * runs same-hook, same-priority callbacks in registration order, and nine
	 * of this plugin's callbacks sit on `admin_init` at priority 10.
	 *
	 * See `docs/adr/0003-explicit-hook-registration.md`.
	 */
	protected function __construct() {

		$this->hookable( new I18n() );

		$this->assets              = $this->hookable( new Assets() );
		$this->settings            = $this->hookable( new Settings() );
		$this->failureWindow       = $this->hookable( new FailureWindow() );
		$this->pendingChanges      = $this->hookable( new PendingChanges() );
		$this->revalidate          = $this->hookable( new Revalidate() );
		$this->probe               = $this->hookable( new Probe() );
		$this->cronScheduledPurges = $this->hookable( new ScheduledPurges() );
		$this->revalidateAll       = $this->hookable( new RevalidateAll() );
		$this->fseSnapshot         = $this->hookable( new FseSnapshot() );
		$this->queue               = $this->hookable( new RevalidateQueue() );
		$this->restApi             = $this->hookable( new RestApi() );

		foreach ( $this->hookables as $hookable ) $hookable->register_hooks();

		// An integration registers its hooks explicitly, and only once it can
		// see whether the plugin it integrates with is there — constructing it
		// touches nothing. See docs/adr/0003-explicit-hook-registration.md.
		$this->redirection         = new Redirection();
		$this->redirection->register_hooks();

		register_activation_hook( __FILE__, [$this, 'activate'] );
		register_deactivation_hook( __FILE__, [$this, 'deactivate'] );
		register_uninstall_hook(__FILE__, [__CLASS__, 'uninstall']);

		// Sites created after the plugin was activated are set up as they are created.
		add_action( 'wp_initialize_site', [$this, 'setup_new_site'], 100 );
	}

	/**
	 * Enrol a freshly constructed object into the registration above, and hand
	 * it back so that constructing and enrolling stay a single expression.
	 *
	 * A class that is constructed and never registered is a silent failure —
	 * for `Revalidate` it would be eight lost hooks and content that stops
	 * revalidating on save, with nothing anywhere saying so. Returning the
	 * object is what makes that unlikely rather than impossible: enrolling
	 * costs nothing over not enrolling, because the assignment reads the same
	 * either way. Nothing stops a property above being assigned directly, so
	 * what catches a class that skipped this is `tests/HookRegistrationTest.php`,
	 * which asserts the whole registration sequence the root produces.
	 *
	 * @template T of Hookable
	 * @param T $hookable The object to register hooks for, once everything is built.
	 * @return T The same object.
	 */
	private function hookable( Hookable $hookable ): Hookable {
		$this->hookables[] = $hookable;

		return $hookable;
	}

	function __get($name) {
		return $this->{$name};
	}

	/**
	 * Whether this plugin is activated for the whole network.
	 *
	 * Asked here rather than wherever it is needed because only this file can
	 * ask it: `plugin_basename()` answers about the file it is called from, so
	 * a class under `include/` putting the same question would name itself
	 * instead of the plugin.
	 *
	 * False on a single install, where there is no network to be active for.
	 *
	 * @return bool
	 */
	public static function is_network_active() {

		if ( !is_multisite() ) return false;

		// An admin-only include. Loaded on demand rather than assumed: this is
		// read from `admin_init`, where it is already there, and from the site
		// creation hook, where it need not be.
		if ( !function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active_for_network( plugin_basename( __FILE__ ) );
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
		if ( !self::is_network_active() ) return;

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

		// The swept version is the network's own state rather than any site's,
		// so it is dropped here and not once per site. Left behind, a later
		// reinstall would read it, believe every site had already been asked to
		// migrate for this release, and sweep none of them — the network-scoped
		// twin of the stale ledger `uninstall_site()` deletes.
		if ( is_multisite() ) delete_site_option( Settings::SWEPT_VERSION_OPTION_NAME );
	}

}

NextJsRevalidate::init();

/**
 * API functions
 */

/**
 * Report a path as changed, so the front-end revalidates whatever it cached
 * from it.
 *
 * A **path** change — `{ "subject": "path", "uri": … }` — for a path this
 * plugin has no other way to know about: a page assembled from something that
 * is not a post, a listing of an external feed, anything site code knows went
 * stale. What the front-end expires for it is the front-end's decision.
 *
 * The change is *accepted*, not delivered: it joins this request's pending
 * changes, and they are sent to the front-end once the request has answered.
 * So the answer here can only ever be whether the change was taken on, never
 * whether the front-end took it — that happens after this call has returned,
 * and delivery is at most once, a failure being recorded in the log and
 * dropped (docs/adr/0010-the-public-api-reports-acceptance-not-delivery.md).
 *
 * @since 2.0.0
 *
 * @param string $url A URL, or a path. A URL is reduced to its path from the
 *                    domain root, dropping the query string and the fragment;
 *                    a path is taken as from the domain root already.
 *
 * @return bool Whether the change was accepted into the pending changes. False
 *              on a refusal — the site is unconfigured, and nothing it accepted
 *              could be delivered — false when the `nextjs_revalidate_change`
 *              filter dropped it, and false for a URL that names no path.
 */
function nextjs_revalidate_path( $url ) {
	$uri = Change::uri_of( $url );

	if ( null === $uri ) return false;

	// A refusal arrives as a WP_Error, which is truthy; it is a false here.
	// Callers needing the reason read it from `report()` directly, as the REST
	// routes do — this function's documented answer is a bool.
	return true === NextJsRevalidate::init()->pendingChanges->report( Change::path( $uri ) );
}

/**
 * Register a path to be reported as changed at a future date time — a
 * **scheduled purge**.
 *
 * Registering one is not reporting a change: the path is reported by the cron
 * request that finds it due, and is refused there like any other change if the
 * site is unconfigured by then. The entry is dropped either way.
 *
 * @since 2.0.0
 *
 * @param string $datetime The date time from which the path is due.
 * @param string $url      A URL, or a path. Registered as given, and reduced to
 *                         its path from the domain root when it comes due.
 *
 * @return bool Whether this call registered the scheduled purge. False when the
 *              URL is already registered for that date time — the schedule
 *              stands, this call added nothing to it — and false when the
 *              write failed.
 */
function nextjs_revalidate_schedule_path( $datetime, $url ) {
	return NextJsRevalidate::init()->cronScheduledPurges->schedule_purge( $datetime, $url );
}

/**
 * Purge an URL from Next.js cache.
 *
 * @deprecated 2.0.0 Use nextjs_revalidate_path(). Removed in 3.0.0 (ADR 0035).
 *
 * @param string $url      The URL, or path, to revalidate.
 * @param int    $priority Accepted and ignored: there is no queue left for it
 *                         to order.
 *
 * @return bool What nextjs_revalidate_path() answers.
 */
function nextjs_revalidate_purge_url( $url, $priority = 10 ) {
	_deprecated_function( __FUNCTION__, '2.0.0', 'nextjs_revalidate_path()' );

	return nextjs_revalidate_path( $url );
}

/**
 * Schedule an URL purge from Next.js cache.
 *
 * @deprecated 2.0.0 Use nextjs_revalidate_schedule_path(). Removed in 3.0.0 (ADR 0035).
 *
 * @param string $datetime The date time when to purge.
 * @param string $url      The URL, or path, to revalidate.
 *
 * @return bool What nextjs_revalidate_schedule_path() answers.
 */
function nextjs_revalidate_schedule_purge_url( $datetime, $url ) {
	_deprecated_function( __FUNCTION__, '2.0.0', 'nextjs_revalidate_schedule_path()' );

	return nextjs_revalidate_schedule_path( $datetime, $url );
}
