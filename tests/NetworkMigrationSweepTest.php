<?php
/**
 * The network migration sweep — Settings::sweep_migrations().
 *
 * `migrate_db()` is hooked on `admin_init`, which fires per site, so on a
 * network a site migrated only when a human opened *that site's* admin. A
 * traffic-only subsite therefore ran new code over unmigrated data for as long
 * as nobody logged in — and its cron, which front-end traffic triggers, drained
 * its queue against whatever those options happened to hold.
 *
 * What this file pins is *when every site gets asked*: once per network per
 * release, triggered by a version comparison rather than by an update event,
 * stamped only after the sweep has been through every site, and declined rather
 * than truncated on a large network. Which migrations then run on a given site
 * is the per-site ledger's answer and belongs to `MigrationLedgerTest.php`.
 *
 * The plugin has no test framework and no WordPress to boot, so this is a
 * standalone script (ADR-0008): it stubs the option functions with one store per
 * fixture site, stands a double in for the composition root's sweep helper, and
 * exits non-zero on the first failing expectation. The double is what lets a
 * large network and an interrupted sweep be reached at all; the helper itself is
 * #24's and is covered by the network stack of the extended pass.
 *
 * Run with `npm run test:php`, or `php tests/NetworkMigrationSweepTest.php`.
 */

if ( 'cli' !== PHP_SAPI ) die( 'This file must be run from the command line.' );

// The plugin files bail when this is not defined.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );

/**
 * The version of the plugin code these fixtures are running.
 *
 * Fixed here rather than read from the header, so the expectations below do not
 * move with the next release.
 */
define( 'NJR_VERSION', '1.7.0' );

/** One option store per fixture site, keyed by site id. @var array */
$GLOBALS['njr_sites'] = [];

/** The site the stubs read and write, as `switch_to_blog()` would decide. @var int */
$GLOBALS['njr_current'] = 1;

/** The network's own options — what `get_site_option()` answers from. @var array */
$GLOBALS['njr_network'] = [];

/** Whether the fixture install is a network at all. @var bool */
$GLOBALS['njr_is_multisite'] = true;

/** Whether the fixture network is over core's large-network threshold. @var bool */
$GLOBALS['njr_large_network'] = false;

/** Whether the current user is a super admin. @var bool */
$GLOBALS['njr_is_super_admin'] = true;

/** How many times the sweep helper has been asked to sweep. @var int */
$GLOBALS['njr_sweeps'] = 0;

/** The sites the sweep reached, in order, since the last reset. @var array */
$GLOBALS['njr_visited'] = [];

/** The site id the sweep should die on, to model a sweep cut short. @var int|null */
$GLOBALS['njr_interrupt_at'] = null;

/** Whether a function that only exists on multisite has been called. @var bool */
$GLOBALS['njr_ms_only_called'] = false;

// WordPress stubs
// ====

function add_action( $name, $callback, $priority = 10, $accepted_args = 1 ) {}

function &njr_site_options() {
	return $GLOBALS['njr_sites'][ $GLOBALS['njr_current'] ];
}

function get_option( $name, $default = false ) {
	$options = &njr_site_options();
	return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
}

function update_option( $name, $value ) {
	$options = &njr_site_options();
	$options[ $name ] = $value;
	return true;
}

function add_option( $name, $value = '' ) {
	$options = &njr_site_options();
	if ( array_key_exists( $name, $options ) ) return false;
	return update_option( $name, $value );
}

function delete_option( $name ) {
	$options = &njr_site_options();
	if ( ! array_key_exists( $name, $options ) ) return false;
	unset( $options[ $name ] );
	return true;
}

function get_site_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['njr_network'] ) ? $GLOBALS['njr_network'][ $name ] : $default;
}

function update_site_option( $name, $value ) {
	$GLOBALS['njr_network'][ $name ] = $value;
	return true;
}

function is_multisite() { return $GLOBALS['njr_is_multisite']; }

// `wp_is_large_network()` and `get_blog_count()` live in `ms-functions.php`,
// which a single install never loads — calling either there is a fatal. These
// two record that they were reached, so that a single-install expectation can
// assert they were not.
function wp_is_large_network( $using = 'sites', $network_id = null ) {
	$GLOBALS['njr_ms_only_called'] = true;
	return $GLOBALS['njr_large_network'];
}

function get_blog_count( $network_id = null ) {
	$GLOBALS['njr_ms_only_called'] = true;
	return count( $GLOBALS['njr_sites'] );
}

function current_user_can( $capability ) {
	return $capability === 'manage_network' ? $GLOBALS['njr_is_super_admin'] : true;
}

function number_format_i18n( $number, $decimals = 0 ) { return number_format( $number, $decimals ); }
function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES ); }
function __( $text, $domain = 'default' ) { return $text; }
function untrailingslashit( $string ) { return rtrim( $string, '/\\' ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }

/**
 * A double for the composition root, standing in for its sweep helper.
 *
 * It reproduces the helper's contract rather than its body: a single install
 * runs the callback once, a large network declines without touching a site, and
 * anything else visits every site in turn and answers true. What the double adds
 * is a way to count sweeps and to cut one short — neither of which the real
 * helper offers a seam for, and both of which this file is here to pin.
 */
class NextJsRevalidate {

	public static function for_each_site( callable $callback ) {

		$GLOBALS['njr_sweeps']++;

		if ( ! $GLOBALS['njr_is_multisite'] ) {
			call_user_func( $callback );
			return true;
		}

		if ( wp_is_large_network( 'sites' ) ) return false;

		foreach ( array_keys( $GLOBALS['njr_sites'] ) as $site_id ) {
			$GLOBALS['njr_current']  = $site_id;
			$GLOBALS['njr_visited'][] = $site_id;

			if ( $GLOBALS['njr_interrupt_at'] === $site_id ) {
				$GLOBALS['njr_current'] = 1;
				throw new RuntimeException( 'the request went away mid-sweep' );
			}

			call_user_func( $callback );
		}

		$GLOBALS['njr_current'] = 1;

		return true;
	}
}

// The subject
// ====

require_once __DIR__ . '/../include/Interfaces/Hookable.php';
require_once __DIR__ . '/../include/Abstracts/Base.php';
require_once __DIR__ . '/../include/Settings.php';

use NextJsRevalidate\Settings;

const LEDGER               = Settings::DB_VERSION_OPTION_NAME;
const SWEPT                = Settings::SWEPT_VERSION_OPTION_NAME;
const ALLOW_REVALIDATE_ALL = Settings::SETTINGS_ALLOW_REVALIDATE_ALL_NAME;

$failures = 0;

function check( $condition, $description ) {
	global $failures;

	if ( $condition ) {
		printf( "ok   — %s\n", $description );
		return;
	}

	$failures++;
	printf( "FAIL — %s\n", $description );
}

function check_same( $expected, $actual, $description ) {
	global $failures;

	if ( $expected === $actual ) {
		printf( "ok   — %s\n", $description );
		return;
	}

	$failures++;
	printf( "FAIL — %s (expected %s, got %s)\n", $description, json_encode( $expected ), json_encode( $actual ) );
}

/**
 * Put the fixture network in a known state.
 *
 * @param array $sites   Option rows per site, keyed by site id.
 * @param array $network The network's own option rows.
 * @return Settings The instance the expectations drive.
 */
function network( array $sites, array $network = [] ) {
	$GLOBALS['njr_sites']           = $sites;
	$GLOBALS['njr_network']         = $network;
	$GLOBALS['njr_current']         = 1;
	$GLOBALS['njr_is_multisite']    = true;
	$GLOBALS['njr_large_network']   = false;
	$GLOBALS['njr_is_super_admin']  = true;
	$GLOBALS['njr_sweeps']          = 0;
	$GLOBALS['njr_visited']         = [];
	$GLOBALS['njr_interrupt_at']    = null;
	$GLOBALS['njr_ms_only_called']  = false;

	return new Settings();
}

/** What `sweep_declined_notice()` renders, if anything. */
function notice( Settings $settings ) {
	ob_start();
	$settings->sweep_declined_notice();
	return ob_get_clean();
}

/** The ledger of every fixture site, keyed by site id. */
function ledgers() {
	$ledgers = [];
	foreach ( $GLOBALS['njr_sites'] as $site_id => $options ) {
		$ledgers[ $site_id ] = $options[ LEDGER ] ?? null;
	}
	return $ledgers;
}

// The expectations
// ====

// A single install is untouched: the per-site hook already reaches the only site
// there is, and there is no network to hold a record of anything.
$settings = network( [ 1 => [] ] );
$GLOBALS['njr_is_multisite'] = false;
$settings->sweep_migrations();
check_same( 0, $GLOBALS['njr_sweeps'], 'a single install does not sweep' );
check_same( [], $GLOBALS['njr_network'], 'a single install writes no network record' );
check_same( null, ledgers()[1], 'a single install is left to its own admin_init migration' );
check_same( '', notice( $settings ), 'a single install renders no notice' );
check(
	! $GLOBALS['njr_ms_only_called'],
	'a single install calls nothing out of ms-functions.php, which it never loads'
);

// The whole point: every site migrates, without anyone opening its admin. Site 2
// is a 1.4.x site and site 3 a 1.5.x one — neither has ever been visited.
$settings = network( [
	1 => [],
	2 => [ 'nextjs_revalidate-allow_purge_all' => [ 'post' => '1' ] ],
	3 => [ 'nextjs-revalidate-queue' => [ 'https://front-end.test/' ] ],
] );
$settings->sweep_migrations();
check_same( [ 1, 2, 3 ], $GLOBALS['njr_visited'], 'the sweep reaches every site of the network' );
check_same(
	[ 1 => '1.7.0', 2 => '1.7.0', 3 => '1.7.0' ],
	ledgers(),
	'every site is stamped, with nobody having visited its admin'
);
check_same(
	[ 'post' => '1' ],
	$GLOBALS['njr_sites'][2][ ALLOW_REVALIDATE_ALL ] ?? null,
	'an unvisited subsite runs the migration body it was owed'
);
check_same( '1.7.0', $GLOBALS['njr_network'][ SWEPT ] ?? null, 'the network is stamped at the running version' );

// Once per network per release — not once per site, and not once per request.
$settings->sweep_migrations();
$settings->sweep_migrations();
check_same( 1, $GLOBALS['njr_sweeps'], 'a network already swept for this release is not swept again' );
check_same( [ 1, 2, 3 ], $GLOBALS['njr_visited'], 'no site is visited a second time' );

// The trigger is the comparison, so a deploy that replaces the plugin's files —
// Composer, git, a zip dropped over the directory — sweeps on the next admin
// request without WordPress's updater having run.
$settings = network( [ 1 => [], 2 => [] ], [ SWEPT => '1.6.9' ] );
$settings->sweep_migrations();
check_same( 1, $GLOBALS['njr_sweeps'], 'a network one release behind is swept' );
check_same( '1.7.0', $GLOBALS['njr_network'][ SWEPT ], 'and stamped at the release it was swept for' );

// Versions are compared as versions. The scheme this plugin used before the
// ledger concatenated the digits — 1.7.0 as 170, 1.6.10 as 1610 — and so read
// the newer release as the older one, which would skip this sweep outright.
$settings = network( [ 1 => [], 2 => [] ], [ SWEPT => '1.6.10' ] );
$settings->sweep_migrations();
check_same( 1, $GLOBALS['njr_sweeps'], 'a network swept at 1.6.10 is swept again by 1.7.0' );

// Older code over newer data — a downgrade — must not make a sweep the network
// has already been through due again, for the reason the per-site ledger keeps
// its higher DB version.
$settings = network( [ 1 => [], 2 => [] ], [ SWEPT => '1.9.0' ] );
$settings->sweep_migrations();
check_same( 0, $GLOBALS['njr_sweeps'], 'a downgraded network is not swept' );
check_same( '1.9.0', $GLOBALS['njr_network'][ SWEPT ], 'and keeps its higher record' );

// A record left by some earlier release, or by a sweep that never finished, is
// as good as no record at all: anything that is not "already at or past this
// release" is due.
$settings = network( [ 1 => [], 2 => [] ], [ SWEPT => '' ] );
$settings->sweep_migrations();
check_same( 1, $GLOBALS['njr_sweeps'], 'an empty record is due' );

// Stamped only after the sweep has been through every site. A sweep cut short
// leaves the record behind the running version, so the next admin request
// retries the network rather than skipping one that was never finished.
$settings = network( [ 1 => [], 2 => [ 'nextjs_revalidate-allow_purge_all' => [ 'post' => '1' ] ] ] );
$GLOBALS['njr_interrupt_at'] = 2;
try { $settings->sweep_migrations(); } catch ( RuntimeException $e ) {}
check( ! array_key_exists( SWEPT, $GLOBALS['njr_network'] ), 'an interrupted sweep stamps nothing' );
check_same( '1.7.0', ledgers()[1], 'the sites it did reach are migrated' );
check_same( null, ledgers()[2], 'the site it did not reach is not' );

$GLOBALS['njr_interrupt_at'] = null;
$settings->sweep_migrations();
check_same( 2, $GLOBALS['njr_sweeps'], 'the next admin request retries the interrupted sweep' );
check_same( '1.7.0', ledgers()[2], 'and the site it had not reached migrates' );
check_same( '1.7.0', $GLOBALS['njr_network'][ SWEPT ], 'and only now is the network stamped' );

// A large network declines rather than truncating: a sweep reaches every site or
// it does not start. Nothing is stamped, so the network stays due.
$settings = network( [ 1 => [], 2 => [], 3 => [] ] );
$GLOBALS['njr_large_network'] = true;
$settings->sweep_migrations();
check_same( [], $GLOBALS['njr_visited'], 'a large network is not partly swept' );
check( ! array_key_exists( SWEPT, $GLOBALS['njr_network'] ), 'a declined sweep stamps nothing' );

// And it says so, rather than leaving a network running new code over old data
// with no sign of it anywhere.
$rendered = notice( $settings );
check( strpos( $rendered, 'cannot migrate the 3 sites' ) !== false, 'a declined sweep names the site count' );
check( strpos( $rendered, 'notice-warning' ) !== false, 'and renders as an admin notice' );

// Nobody but a super admin can act on it, or can even see the sites it is about.
$GLOBALS['njr_is_super_admin'] = false;
check_same( '', notice( $settings ), 'a declined sweep is not reported to a site administrator' );
$GLOBALS['njr_is_super_admin'] = true;

// The threshold is filterable, so a network that stops being large is swept on
// the next admin request — and stops being told about it.
$GLOBALS['njr_large_network'] = false;
$settings->sweep_migrations();
check_same( [ 1, 2, 3 ], $GLOBALS['njr_visited'], 'a network that stops being large is swept' );
check_same( '1.7.0', $GLOBALS['njr_network'][ SWEPT ], 'and stamped' );
check_same( '', notice( $settings ), 'a swept network is told nothing' );

// An ordinary network is never told anything either: `admin_init` has swept it
// by the time notices render, so the condition the notice recomputes is false.
$settings = network( [ 1 => [], 2 => [] ] );
$settings->sweep_migrations();
check_same( '', notice( $settings ), 'an ordinary network renders no notice' );

// One sweep helper serves setup, teardown and migration alike. A second
// blog-switching path here would be one more place for the large-network refusal
// and the restore to be got wrong, so there is not one.
$source = file_get_contents( __DIR__ . '/../include/Settings.php' );
check(
	strpos( $source, 'switch_to_blog' ) === false,
	'the sweep reuses for_each_site rather than switching sites itself'
);

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
