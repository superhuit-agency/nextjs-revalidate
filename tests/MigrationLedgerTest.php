<?php
/**
 * Migration ledger — Settings::migrate_db().
 *
 * The plugin has no test framework and no WordPress to boot, so this is a
 * standalone script (ADR-0008): it stubs the option functions the migrations
 * touch with an in-memory store, drives a set of fixture sites through
 * `migrate_db()`, and exits non-zero on the first failing expectation.
 *
 * What it is here to pin is that each migration body runs *at most once* per
 * site. The scheme this replaced re-ran the oldest body on every admin request
 * and never ran the newest one at all, which stayed invisible only because both
 * bodies happen to be idempotent. A migration that is not — the settings split
 * of #29 is the next one — depends on the gate, not on the accident.
 *
 * Run with `npm run test:php`, or `php tests/MigrationLedgerTest.php`.
 */

// The plugin files bail when this is not defined.
define( 'ABSPATH', __DIR__ . '/' );

/**
 * The version of the plugin code these fixtures are running.
 *
 * Fixed here rather than read from the plugin header, so the expectations below
 * do not move with the next release. That the constant is really derived from
 * the header is its own expectation, at the end of this file.
 */
define( 'NJR_VERSION', '1.7.0' );

/**
 * The fixture site's option rows. option name => stored value.
 *
 * A name absent from this array is a site holding no row for that option, which
 * is not the same as a site holding an empty one — the distinction the backfill
 * turns on.
 *
 * @var array
 */
$GLOBALS['njr_test_options'] = [];

/**
 * Every write the fixture site received, in order, so an expectation can assert
 * that a migration touched nothing at all.
 *
 * @var array
 */
$GLOBALS['njr_test_writes'] = [];

// WordPress stubs
// ====

function add_action( $name, $callback, $priority = 10, $accepted_args = 1 ) {}

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['njr_test_options'] )
		? $GLOBALS['njr_test_options'][ $name ]
		: $default;
}

function update_option( $name, $value ) {
	$GLOBALS['njr_test_writes'][]          = "update:$name";
	$GLOBALS['njr_test_options'][ $name ] = $value;
	return true;
}

function add_option( $name, $value = '' ) {
	if ( array_key_exists( $name, $GLOBALS['njr_test_options'] ) ) return false;
	return update_option( $name, $value );
}

function untrailingslashit( $string ) {
	return rtrim( $string, '/\\' );
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function delete_option( $name ) {
	if ( ! array_key_exists( $name, $GLOBALS['njr_test_options'] ) ) return false;

	$GLOBALS['njr_test_writes'][] = "delete:$name";
	unset( $GLOBALS['njr_test_options'][ $name ] );
	return true;
}

// The subject
// ====

require_once __DIR__ . '/../include/Interfaces/Hookable.php';
require_once __DIR__ . '/../include/Abstracts/Base.php';
require_once __DIR__ . '/../include/Settings.php';

use NextJsRevalidate\Settings;

const LEDGER               = Settings::DB_VERSION_OPTION_NAME;
const ALLOW_REVALIDATE_ALL = Settings::SETTINGS_ALLOW_REVALIDATE_ALL_NAME;
const LEGACY_URL           = Settings::LEGACY_URL_OPTION_NAME;
const DOMAIN               = Settings::SETTINGS_DOMAIN_NAME;
const PATH_OPT             = Settings::SETTINGS_ENDPOINT_PATH_NAME;
const SECRET               = Settings::SETTINGS_SECRET_NAME;

$failures = 0;

/**
 * Put the fixture site in a known state and migrate it.
 *
 * @param array $options The option rows the site holds beforehand.
 * @return Settings The instance which migrated it, for a second run.
 */
function migrate( array $options ) {
	$GLOBALS['njr_test_options'] = $options;
	$GLOBALS['njr_test_writes']  = [];

	$settings = new Settings();
	$settings->migrate_db();

	return $settings;
}

/** The fixture site's option rows, key-ordered so a comparison ignores order. */
function options() {
	$options = $GLOBALS['njr_test_options'];
	ksort( $options );
	return $options;
}

function writes() {
	return $GLOBALS['njr_test_writes'];
}

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

// The expectations
// ====

// A fresh install holds no legacy option, so no migration body may touch it.
// Its data already has the running code's shape; it is only stamped.
migrate( [] );
check_same( [ LEDGER => NJR_VERSION ], options(), 'a fresh install is stamped, and nothing else' );
check_same( [ 'update:' . LEDGER ], writes(), 'a fresh install runs no migration body' );

// A 1.4.x site: both migrations run, in order, on the one request. The option
// the 1.5.0 body carries over is the one the 1.6.0 body then drops.
migrate( [
	'nextjs_revalidate-allow_purge_all' => [ 'post' => '1' ],
	'nextjs-revalidate-purge_all'       => [ 'post_type' => 'page' ],
	'nextjs-revalidate-queue'           => [ 'https://front-end.test/' ],
] );
check_same(
	[ ALLOW_REVALIDATE_ALL => [ 'post' => '1' ], LEDGER => NJR_VERSION ],
	options(),
	'1.4.x → 1.7.0 carries the renamed option over and drops the queue options'
);

// A 1.5.x site: the rename already happened, so only the 1.6.0 body runs. The
// giveaway would be an `allow_revalidate_all` appearing out of nowhere.
migrate( [
	'nextjs-revalidate-queue'          => [ 'https://front-end.test/' ],
	'nextjs-revalidate-revalidate_all' => [ 'post_type' => 'page' ],
] );
check_same( [ LEDGER => NJR_VERSION ], options(), '1.5.x → 1.7.0 drops the queue options only' );
check( ! array_key_exists( ALLOW_REVALIDATE_ALL, options() ), '1.5.x → 1.7.0 does not re-run the 1.5.0 rename' );

// A site whose settings already have the running code's shape predates the
// ledger too, but has been through every migration. Its settings are an
// operator's, and a migration must not read as licence to touch them.
migrate( [
	DOMAIN               => 'https://front-end.test',
	SECRET               => 's3cret',
	ALLOW_REVALIDATE_ALL => [ 'post' => '1' ],
] );
check_same(
	[
		ALLOW_REVALIDATE_ALL => [ 'post' => '1' ],
		LEDGER               => NJR_VERSION,
		DOMAIN               => 'https://front-end.test',
		SECRET               => 's3cret',
	],
	options(),
	'a site with the running shape is stamped, and its settings left alone'
);
check_same( [ 'update:' . LEDGER ], writes(), 'a site with the running shape runs no migration body' );

// 1.7.0 — the revalidate URL split into a domain and a set of paths.
// ====
//
// Guarded on the data rather than on the ledger, and the reason is structural:
// every site predating the ledger is backfilled to the release that introduces
// it, so a version gate on that same release would be read *after* the site had
// already been stamped past it, and would never fire for anybody.

// The standard install — the split is where the value is stored, not where a
// revalidation is sent, so the composition has to come back out byte-identical.
migrate( [ LEGACY_URL => 'https://front-end.test/api/revalidate', SECRET => 's3cret' ] );
check_same( 'https://front-end.test', options()[ DOMAIN ], 'the legacy URL yields its scheme and host as the domain' );
check_same( '/api/revalidate', options()[ PATH_OPT ], 'the legacy URL yields its path' );
check( ! array_key_exists( LEGACY_URL, options() ), 'the legacy option is deleted once it has been split' );

// The reason the split is a migration and not string surgery at read time: the
// path is whatever the operator's Next.js app routes, and nothing else knows it.
migrate( [ LEGACY_URL => 'https://front-end.test/fr/api/purge' ] );
check_same( 'https://front-end.test', options()[ DOMAIN ], 'a custom path does not disturb the domain' );
check_same( '/fr/api/purge', options()[ PATH_OPT ], 'a custom path is preserved verbatim' );

// A port and a subdirectory are the domain's, and `wp_parse_url()` is what
// tells them apart from the path — the development fixture is exactly this.
migrate( [ LEGACY_URL => 'http://host.docker.internal:8083/revalidate' ] );
check_same( 'http://host.docker.internal:8083', options()[ DOMAIN ], 'a port stays with the domain' );
check_same( '/revalidate', options()[ PATH_OPT ], 'the path of a URL with a port is split off cleanly' );

// An operator who pasted the whole request in, secret and all, gets the
// endpoint — not a domain carrying somebody's secret in a query arg.
migrate( [ LEGACY_URL => 'https://front-end.test/api/revalidate?path=/hello/&secret=s3cret' ] );
check_same( 'https://front-end.test', options()[ DOMAIN ], 'query args are stripped from the domain' );
check_same( '/api/revalidate', options()[ PATH_OPT ], 'query args are stripped from the path' );

// Basic-auth credentials belong to the domain. A protected staging front-end is
// exactly the kind of site that carries them, and dropping them silently turns a
// working install into one that 401s with nothing on screen to explain it.
migrate( [ LEGACY_URL => 'https://user:pass@front-end.test/api/revalidate' ] );
check_same( 'https://user:pass@front-end.test', options()[ DOMAIN ], 'credentials stay with the domain' );
check_same( '/api/revalidate', options()[ PATH_OPT ], 'credentials do not disturb the path' );

// A user with no password is still a user.
migrate( [ LEGACY_URL => 'https://user@front-end.test/api/revalidate' ] );
check_same( 'https://user@front-end.test', options()[ DOMAIN ], 'a user without a password stays with the domain' );

// A trailing slash belongs to neither half: the composition puts exactly one
// slash between them, so carrying a second would produce `//api/revalidate`.
migrate( [ LEGACY_URL => 'https://front-end.test/api/revalidate/' ] );
check_same( 'https://front-end.test', options()[ DOMAIN ], 'a trailing slash is not carried into the domain' );
check_same( '/api/revalidate', options()[ PATH_OPT ], 'a trailing slash is not carried into the path' );

// A bare domain carries no path to preserve, so the path field is left empty
// and the composition falls back to the default.
migrate( [ LEGACY_URL => 'https://front-end.test/' ] );
check_same( 'https://front-end.test', options()[ DOMAIN ], 'a bare legacy domain yields the domain' );
check( ! array_key_exists( PATH_OPT, options() ), 'a bare legacy domain writes no path' );

// Never configured: there is nothing to split, and a migration that writes an
// empty domain to such a site would be indistinguishable from one that ran.
migrate( [] );
check_same( [ 'update:' . LEDGER ], writes(), 'a site holding no legacy URL splits nothing' );

migrate( [ LEGACY_URL => '' ] );
check_same( [ 'update:' . LEDGER ], writes(), 'a site holding an empty legacy URL splits nothing' );
check( array_key_exists( LEGACY_URL, options() ), 'an empty legacy URL is not deleted either' );

// The guard is on the domain, so the split is over the moment one exists. This
// is the case the version gate could not express, and the one that matters:
// `migrate_db()` runs on every `admin_init`, so an unguarded re-split would
// overwrite an operator's edits on every page load.
$settings = migrate( [ LEGACY_URL => 'https://front-end.test/api/revalidate' ] );
update_option( DOMAIN, 'https://edited-by-hand.test' );
update_option( PATH_OPT, '/edited/by/hand' );
update_option( LEGACY_URL, 'https://front-end.test/api/revalidate' );
$settings->migrate_db();
$settings->migrate_db();
check_same( 'https://edited-by-hand.test', options()[ DOMAIN ], 'a re-run does not clobber an edited domain' );
check_same( '/edited/by/hand', options()[ PATH_OPT ], 'a re-run does not clobber an edited path' );

// A URL too broken to parse is left where it is. Silently discarding an
// operator's only record of their endpoint is the one outcome worse than an
// unconfigured site, which is what they get either way until they retype it.
migrate( [ LEGACY_URL => 'not a url' ] );
check_same( [ 'update:' . LEDGER ], writes(), 'an unparseable legacy URL is neither split nor deleted' );
check_same( 'not a url', options()[ LEGACY_URL ], 'an unparseable legacy URL is left for the operator to see' );

// A legacy option holding an empty value is still evidence of the release that
// wrote it, and `get_option()` cannot tell that row from an absent one — so the
// backfill must not ask by value.
migrate( [ 'nextjs-revalidate-queue' => '' ] );
check_same( [ LEDGER => NJR_VERSION ], options(), 'an empty legacy option still fingerprints the site' );

// A stamped site runs nothing, even holding an option a migration would
// otherwise claim: the ledger has the last word, not the data.
migrate( [
	LEDGER                              => NJR_VERSION,
	'nextjs_revalidate-allow_purge_all' => [ 'post' => '1' ],
	ALLOW_REVALIDATE_ALL                => [ 'page' => '1' ],
] );
check_same( [], writes(), 'a site stamped at the running version is not written to at all' );
check_same( [ 'page' => '1' ], options()[ ALLOW_REVALIDATE_ALL ], 'a stamped site keeps an operator edit' );

// The same, one release behind: the ledger moves forward, no body runs.
migrate( [ LEDGER => '1.6.9', ALLOW_REVALIDATE_ALL => [ 'page' => '1' ] ] );
check_same( [ 'update:' . LEDGER ], writes(), 'a site stamped 1.6.9 only re-stamps' );
check_same( NJR_VERSION, options()[ LEDGER ], 'the ledger moves to the running version' );

// Migrating twice is indistinguishable from migrating once, whatever the site
// does with its data in between. This is what the version comparison could not
// give, and what a migration that is not naturally idempotent will depend on.
$settings = migrate( [ 'nextjs_revalidate-allow_purge_all' => [ 'post' => '1' ] ] );
update_option( ALLOW_REVALIDATE_ALL, [ 'edited-by-hand' => '1' ] );
update_option( 'nextjs_revalidate-allow_purge_all', [ 'post' => '1' ] );
$settings->migrate_db();
check_same( [ 'edited-by-hand' => '1' ], options()[ ALLOW_REVALIDATE_ALL ], 'a second migration does not clobber an operator edit' );
check( array_key_exists( 'nextjs_revalidate-allow_purge_all', options() ), 'a second migration does not re-consume a legacy option' );

// Older code over newer data — a downgrade — must not walk the ledger back,
// which would make migrations the site has been through eligible again.
migrate( [ LEDGER => '9.9.9' ] );
check_same( [], writes(), 'a downgraded site keeps its higher DB version' );

// Versions are compared as versions. The scheme this replaced concatenated the
// digits, which made 1.7.0 (170) compare as *older* than 1.6.10 (1610) — one
// patch release away, the day it was written.
check( version_compare( '1.7.0', '1.6.10', '>' ), '1.7.0 is newer than 1.6.10' );

// The ledger describes this site's data, so it is torn down with it. Left
// behind, a later reinstall would read it and skip every migration.
$GLOBALS['njr_test_options'] = [ LEDGER => NJR_VERSION, DOMAIN => 'https://front-end.test', LEGACY_URL => 'https://front-end.test/api/revalidate' ];
Settings::delete_settings();
check_same( [], options(), 'uninstalling takes the ledger with the settings' );

// The plugin version has one source of truth: the header. A hardcoded
// `NJR_VERSION` is how it came to say 1.6.0 while the plugin shipped 1.6.9, and
// a ledger stamped from a stale constant re-runs migrations forever — so the
// drift has to be structurally impossible rather than merely policed.
$plugin_file = file_get_contents( __DIR__ . '/../nextjs-revalidate.php' );
check(
	preg_match( '/^\s*\*\s*Version:\s*\d+\.\d+\.\d+/m', $plugin_file ) === 1,
	'the plugin header declares a version'
);
check(
	preg_match( "/define\(\s*['\"]NJR_VERSION['\"]\s*,\s*['\"]/", $plugin_file ) === 0,
	'NJR_VERSION is derived from the header, not written out beside it'
);

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
