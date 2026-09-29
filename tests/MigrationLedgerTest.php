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

/**
 * The sanitize callback `register_setting()` attached to each option.
 *
 * `Settings::register_fields()` runs before `migrate_db()` on `admin_init`, so
 * every write a migration makes to a setting goes through that setting's
 * callback (#99). The writes below apply them as core's do.
 *
 * @var callable[]
 */
$GLOBALS['njr_test_sanitizers'] = [];

/**
 * Every settings error a callback added, as [ setting, code ].
 * @var array[]
 */
$GLOBALS['njr_test_settings_errors'] = [];

function register_setting( $group, $name, $args = [] ) {
	if ( isset( $args['sanitize_callback'] ) ) $GLOBALS['njr_test_sanitizers'][ $name ] = $args['sanitize_callback'];
}

function sanitize_option( $name, $value ) {
	$callback = $GLOBALS['njr_test_sanitizers'][ $name ] ?? null;

	return $callback ? call_user_func( $callback, $value ) : $value;
}

function add_settings_error( $setting, $code, $message, $type = 'error' ) {
	$GLOBALS['njr_test_settings_errors'][] = [ $setting, $code ];
}

function get_settings_errors( $setting = '', $sanitize = false ) {
	return $GLOBALS['njr_test_settings_errors'];
}

function __( $text, $domain = null ) { return $text; }

function update_option( $name, $value ) {
	$value     = sanitize_option( $name, $value );
	$old_value = get_option( $name );

	// Core writes nothing for a value it already holds — which is how a
	// refused domain, answered with the one held before, is not stored.
	if ( $value === $old_value ) return false;

	// …and hands a missing row to `add_option()`, which sanitises again.
	if ( false === $old_value ) return add_option( $name, $value );

	$GLOBALS['njr_test_writes'][]          = "update:$name";
	$GLOBALS['njr_test_options'][ $name ] = $value;
	return true;
}

function add_option( $name, $value = '' ) {
	$value = sanitize_option( $name, $value );

	if ( array_key_exists( $name, $GLOBALS['njr_test_options'] ) ) return false;

	$GLOBALS['njr_test_writes'][]          = "update:$name";
	$GLOBALS['njr_test_options'][ $name ] = $value;
	return true;
}

function untrailingslashit( $string ) {
	return rtrim( $string, '/\\' );
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

/**
 * The fixture site's uploads directory: a real, empty temporary directory, so
 * the migration that moves the log has somewhere to look and finds nothing
 * unless a case puts a legacy log there.
 */
$GLOBALS['njr_test_uploads_dir'] = sys_get_temp_dir() . '/njr-migration-ledger-test-' . uniqid();
mkdir( $GLOBALS['njr_test_uploads_dir'] );

function wp_upload_dir() {
	return [ 'basedir' => $GLOBALS['njr_test_uploads_dir'] ];
}

function trailingslashit( $string ) {
	return rtrim( $string, '/\\' ) . '/';
}

function wp_mkdir_p( $target ) {
	return is_dir( $target ) || mkdir( $target, 0777, true );
}

function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
	return str_repeat( 'a', $length );
}

function delete_option( $name ) {
	if ( ! array_key_exists( $name, $GLOBALS['njr_test_options'] ) ) return false;

	$GLOBALS['njr_test_writes'][] = "delete:$name";
	unset( $GLOBALS['njr_test_options'][ $name ] );
	return true;
}

/**
 * The fixture site's scheduled cron events: hook name => next timestamp.
 *
 * @var array
 */
$GLOBALS['njr_test_cron'] = [];

function wp_next_scheduled( $hook, $args = [] ) {
	return $GLOBALS['njr_test_cron'][ $hook ] ?? false;
}

function wp_unschedule_hook( $hook ) {
	if ( ! isset( $GLOBALS['njr_test_cron'][ $hook ] ) ) return 0;

	$GLOBALS['njr_test_writes'][] = "unschedule:$hook";
	unset( $GLOBALS['njr_test_cron'][ $hook ] );
	return 1;
}

/** A transient is an option under core's own naming, which is all this store needs. */
function delete_transient( $name ) {
	return delete_option( "_transient_$name" );
}

/**
 * The fixture site's database, as much of it as a migration reaches: whether
 * the revalidation queue's table is there, and how many rows it holds.
 *
 * 2.0 drops that table (ADR 0034), and asks about it on every run, since the
 * upgrade is guarded on the table rather than on the ledger. Only the three
 * statements it issues are understood; anything else fails the file rather
 * than being answered with a guess.
 */
class NJR_Wpdb_Double {

	/** @var string */
	public $prefix = 'wp_';

	/** @var array table name => number of rows */
	public $tables = [];

	/** @var string[] Every statement issued since the last reset. */
	public $statements = [];

	public function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}

	public function prepare( $query, ...$args ) {
		return str_replace( '%s', "'" . $args[0] . "'", $query );
	}

	public function get_var( $query ) {
		$this->statements[] = $query;

		// The pattern arrives LIKE-escaped, as `esc_like()` above writes it.
		if ( preg_match( "/^SHOW TABLES LIKE '(.*)'$/", $query, $m ) ) {
			$table = stripslashes( $m[1] );
			return isset( $this->tables[ $table ] ) ? $table : null;
		}

		if ( preg_match( '/^SELECT COUNT\(\*\) FROM `(.+)`$/', $query, $m ) ) return (string) $this->tables[ $m[1] ];

		throw new LogicException( "The database double does not understand: $query" );
	}

	public function query( $query ) {
		$this->statements[] = $query;

		if ( preg_match( '/^DROP TABLE IF EXISTS `(.+)`$/', $query, $m ) ) {
			$GLOBALS['njr_test_writes'][] = "drop:{$m[1]}";
			unset( $this->tables[ $m[1] ] );
			return true;
		}

		throw new LogicException( "The database double does not understand: $query" );
	}
}

$GLOBALS['wpdb'] = new NJR_Wpdb_Double();

/**
 * The composition root, as much of it as `migrate_db()` reaches: the logger
 * asks it for the settings, to learn whether logging is switched on.
 */
class NextJsRevalidate {

	/** @var NextJsRevalidate|null */
	private static $instance;

	/** @var \NextJsRevalidate\Settings */
	public $settings;

	public static function init() {
		if ( ! isset( self::$instance ) ) {
			self::$instance           = new self();
			self::$instance->settings = new \NextJsRevalidate\Settings();
		}

		return self::$instance;
	}
}

// The subject
// ====

require_once __DIR__ . '/../include/Interfaces/Hookable.php';
require_once __DIR__ . '/../include/Abstracts/Base.php';
require_once __DIR__ . '/../include/Settings.php';
require_once __DIR__ . '/../include/Logger.php';

use NextJsRevalidate\Logger;
use NextJsRevalidate\Settings;

const LEDGER               = Settings::DB_VERSION_OPTION_NAME;
const ALLOW_REVALIDATE_ALL = Settings::SETTINGS_ALLOW_REVALIDATE_ALL_NAME;
const LEGACY_URL           = Settings::LEGACY_URL_OPTION_NAME;
const DOMAIN               = Settings::SETTINGS_DOMAIN_NAME;
const PATH_OPT             = Settings::SETTINGS_ENDPOINT_PATH_NAME;
const SECRET               = Settings::SETTINGS_SECRET_NAME;
const LOG_SUFFIX           = Logger::SUFFIX_OPTION_NAME;

$failures = 0;

/**
 * Put the fixture site in a known state and migrate it.
 *
 * @param array $options The option rows the site holds beforehand.
 * @param array $tables  The tables it holds, as name => number of rows.
 * @param array $cron    The cron events it has scheduled, as hook => timestamp.
 * @return Settings The instance which migrated it, for a second run.
 */
function migrate( array $options, array $tables = [], array $cron = [] ) {
	$GLOBALS['njr_test_options']         = $options;
	$GLOBALS['njr_test_writes']          = [];
	$GLOBALS['njr_test_settings_errors'] = [];
	$GLOBALS['njr_test_cron']            = $cron;
	$GLOBALS['wpdb']->tables             = $tables;
	$GLOBALS['wpdb']->statements         = [];

	// In the order `admin_init` runs them.
	$settings = new Settings();
	$settings->register_settings();
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
check_same(
	[ "SHOW TABLES LIKE 'wp\\_revalidate\\_queue'" ],
	$GLOBALS['wpdb']->statements,
	'every site is asked whether it still holds a queue table, fresh install included, and nothing more'
);

// A 1.4.x site: both migrations run, in order, on the one request. The option
// the 1.5.0 body carries over is the one the 1.6.0 body then drops. A switch
// holds `on`, which is what 1.4's checkbox posted and what the setting's
// sanitize callback, which the carried-over write goes through, keeps (#99).
migrate( [
	'nextjs_revalidate-allow_purge_all' => [ 'post' => 'on' ],
	'nextjs-revalidate-purge_all'       => [ 'post_type' => 'page' ],
	'nextjs-revalidate-queue'           => [ 'https://front-end.test/' ],
] );
check_same(
	[ ALLOW_REVALIDATE_ALL => [ 'post' => 'on' ], LEDGER => NJR_VERSION ],
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
	ALLOW_REVALIDATE_ALL => [ 'post' => 'on' ],
] );
check_same(
	[
		ALLOW_REVALIDATE_ALL => [ 'post' => 'on' ],
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

// #99 — the split writes the domain through the callback a save does, so the
// two have to agree on what a domain is. Every URL the cases above split was
// accepted by it, and none of them told an operator otherwise.
foreach ( [
	'https://front-end.test/api/revalidate',
	'http://host.docker.internal:8083/revalidate',
	'https://user:pass@front-end.test/api/revalidate',
	'https://front-end.test/api/revalidate?path=/hello/&secret=s3cret',
] as $legacy ) {
	migrate( [ LEGACY_URL => $legacy ] );
	check( array_key_exists( DOMAIN, options() ) && ! array_key_exists( LEGACY_URL, options() ), "the split of $legacy is stored and consumes the legacy URL" );
	check_same( [], $GLOBALS['njr_test_settings_errors'], "the split of $legacy adds no settings error" );
}

// A URL the rule would refuse is not split at all. Split anyway, its domain
// would be refused, and the site left with neither a domain nor the legacy URL
// it came from. Left instead, it re-runs on every admin request and writes
// nothing, which is what an unparseable one does.
$settings = migrate( [ LEGACY_URL => 'ftp://front-end.test/api/revalidate' ] );
check_same( [ 'update:' . LEDGER ], writes(), 'a legacy URL whose scheme is not http or https is neither split nor deleted' );
check_same( [], $GLOBALS['njr_test_settings_errors'], 'and it adds no settings error' );
$GLOBALS['njr_test_writes'] = [];
$settings->migrate_db();
check_same( [], writes(), 'and a second admin request writes nothing either' );

// A legacy option holding an empty value is still evidence of the release that
// wrote it, and `get_option()` cannot tell that row from an absent one — so the
// backfill must not ask by value.
migrate( [ 'nextjs-revalidate-queue' => '' ] );
check_same( [ LEDGER => NJR_VERSION ], options(), 'an empty legacy option still fingerprints the site' );

// A stamped site runs nothing, even holding an option a migration would
// otherwise claim: the ledger has the last word, not the data.
migrate( [
	LEDGER                              => NJR_VERSION,
	'nextjs_revalidate-allow_purge_all' => [ 'post' => 'on' ],
	ALLOW_REVALIDATE_ALL                => [ 'page' => 'on' ],
] );
check_same( [], writes(), 'a site stamped at the running version is not written to at all' );
check_same( [ 'page' => 'on' ], options()[ ALLOW_REVALIDATE_ALL ], 'a stamped site keeps an operator edit' );

// The same, one release behind: the ledger moves forward, no body runs.
migrate( [ LEDGER => '1.6.9', ALLOW_REVALIDATE_ALL => [ 'page' => 'on' ] ] );
check_same( [ 'update:' . LEDGER ], writes(), 'a site stamped 1.6.9 only re-stamps' );
check_same( NJR_VERSION, options()[ LEDGER ], 'the ledger moves to the running version' );

// Migrating twice is indistinguishable from migrating once, whatever the site
// does with its data in between. This is what the version comparison could not
// give, and what a migration that is not naturally idempotent will depend on.
$settings = migrate( [ 'nextjs_revalidate-allow_purge_all' => [ 'post' => 'on' ] ] );
update_option( ALLOW_REVALIDATE_ALL, [ 'edited-by-hand' => 'on' ] );
update_option( 'nextjs_revalidate-allow_purge_all', [ 'post' => 'on' ] );
$settings->migrate_db();
check_same( [ 'edited-by-hand' => 'on' ], options()[ ALLOW_REVALIDATE_ALL ], 'a second migration does not clobber an operator edit' );
check( array_key_exists( 'nextjs_revalidate-allow_purge_all', options() ), 'a second migration does not re-consume a legacy option' );

// Older code over newer data — a downgrade — must not walk the ledger back,
// which would make migrations the site has been through eligible again.
migrate( [ LEDGER => '9.9.9' ] );
check_same( [], writes(), 'a downgraded site keeps its higher DB version' );

// Versions are compared as versions. The scheme this replaced concatenated the
// digits, which made 1.7.0 (170) compare as *older* than 1.6.10 (1610) — one
// patch release away, the day it was written.
check( version_compare( '1.7.0', '1.6.10', '>' ), '1.7.0 is newer than 1.6.10' );

// 2.0.0 — the revalidation queue is dropped, and three settings with it.
// ====
//
// Guarded on the data, for the reason the URL split is: a 1.6.9 site upgrading
// straight to 2.0 holds no ledger and no fingerprint, is backfilled to the
// running release, and would never meet a `< 2.0.0` gate (ADR 0034, ADR 0017).
// So these cases hold whatever the ledger says, stamped sites included.

const QUEUE_TABLE   = 'wp_' . Settings::LEGACY_QUEUE_TABLE_NAME;
const QUEUE_CRON    = Settings::LEGACY_QUEUE_CRON_HOOK_NAME;
const QUEUE_RUNNING = '_transient_' . Settings::LEGACY_QUEUE_RUNNING_TRANSIENT_NAME;
const REMOVED       = [ Settings::LEGACY_FSE_ENDPOINT_PATH_NAME, Settings::LEGACY_REVALIDATE_ON_FSE_SAVE, Settings::LEGACY_REVALIDATE_ON_MENU_SAVE ];

/** What the site has logged, or '' when it has written nothing. */
function logged() {
	$path = Logger::path();
	return file_exists( $path ) ? (string) file_get_contents( $path ) : '';
}

/** Take the log directory away again, so the next case starts without one. */
function remove_log() {
	if ( ! is_dir( Logger::directory() ) ) return;

	foreach ( glob( Logger::directory() . '/{,.}[!.]*', GLOB_BRACE ) as $file ) unlink( $file );
	rmdir( Logger::directory() );
}

$logging = [ Settings::SETTINGS_DEBUG => [ 'enable-logs' => 'on' ], LOG_SUFFIX => 'abc123' ];

// A 1.7 site with rows still waiting: the table goes, the count goes to the log,
// and the drain it would have run is unscheduled along with its running count.
$settings = migrate(
	$logging + [
		LEDGER                                   => NJR_VERSION,
		QUEUE_RUNNING                            => 2,
		Settings::LEGACY_FSE_ENDPOINT_PATH_NAME  => '/api/revalidate-fse',
		Settings::LEGACY_REVALIDATE_ON_FSE_SAVE  => 'on',
		Settings::LEGACY_REVALIDATE_ON_MENU_SAVE => [ 'post' => 'on' ],
	],
	[ QUEUE_TABLE => 3 ],
	[ QUEUE_CRON => 1700000000 ]
);
check_same( [], $GLOBALS['wpdb']->tables, 'the queue table is dropped' );
check(
	false !== strpos( logged(), '🗑️ Upgraded to 2.0: dropped the revalidation queue, and the 3 path(s) still waiting in it' ),
	'the rows it still held are counted in the log'
);
check_same( [], $GLOBALS['njr_test_cron'], 'the queue cron is unscheduled' );
check( ! array_key_exists( QUEUE_RUNNING, options() ), 'the count of running drains goes with it' );
foreach ( REMOVED as $removed ) check( ! array_key_exists( $removed, options() ), "the removed setting $removed is deleted" );

// Any number of runs after the first finds nothing: a lookup, and no write.
$logged = logged();
$GLOBALS['njr_test_writes']       = [];
$GLOBALS['wpdb']->statements      = [];
$settings->migrate_db();
$settings->migrate_db();
check_same( [], writes(), 'a second admin request writes nothing' );
check_same( $logged, logged(), 'and logs nothing' );
check_same(
	[ "SHOW TABLES LIKE 'wp\\_revalidate\\_queue'", "SHOW TABLES LIKE 'wp\\_revalidate\\_queue'" ],
	$GLOBALS['wpdb']->statements,
	'and asks the database nothing but whether the table is back'
);
remove_log();

// An empty queue is dropped the same way, and says so.
migrate( $logging + [ LEDGER => NJR_VERSION ], [ QUEUE_TABLE => 0 ] );
check_same( [], $GLOBALS['wpdb']->tables, 'an empty queue table is dropped too' );
check( false !== strpos( logged(), 'and the 0 path(s) still waiting in it' ), 'and its count of none is logged' );
remove_log();

// A removed setting holding an empty value is still a row the site holds.
migrate( [ LEDGER => NJR_VERSION, Settings::LEGACY_REVALIDATE_ON_MENU_SAVE => '' ] );
check( ! array_key_exists( Settings::LEGACY_REVALIDATE_ON_MENU_SAVE, options() ), 'a removed setting holding an empty value is deleted' );

// A 1.7 site whose `CREATE TABLE` standard MySQL refused (#121) had no table,
// and could still hold a scheduled drain.
migrate( [ LEDGER => NJR_VERSION ], [], [ QUEUE_CRON => 1700000000 ] );
check_same( [], $GLOBALS['njr_test_cron'], 'a queue cron with no table beside it is unscheduled all the same' );

// The whole upgrade from 1.6.9 in one pass: no ledger, the single legacy URL,
// a queue table with rows in it, its cron, and the FSE switch 1.6 had.
migrate(
	[
		LEGACY_URL                              => 'https://front-end.test/api/revalidate',
		SECRET                                  => 's3cret',
		Settings::LEGACY_REVALIDATE_ON_FSE_SAVE => 'on',
	],
	[ QUEUE_TABLE => 1204 ],
	[ QUEUE_CRON => 1700000000 ]
);
check_same(
	[ LEDGER => NJR_VERSION, DOMAIN => 'https://front-end.test', PATH_OPT => '/api/revalidate', SECRET => 's3cret' ],
	options(),
	'a 1.6.9 site reaches the 2.0 shape of its options in one pass'
);
check_same( [], $GLOBALS['wpdb']->tables, 'and holds no queue table' );
check_same( [], $GLOBALS['njr_test_cron'], 'and no queue cron' );

// A site upgrading into ADR-0024 has its log at the legacy path, and
// `migrate_db()` moves it — on whatever request reaches it, so the log does not
// wait for somebody to open the logs setting.
$legacy = $GLOBALS['njr_test_uploads_dir'] . '/' . Logger::LEGACY_FILENAME;
file_put_contents( $legacy, "an old line\n" );
migrate( [ LEDGER => NJR_VERSION ] );
check( ! file_exists( $legacy ) && "an old line\n" === @file_get_contents( Logger::path() ), 'migrating moves the legacy log into the plugin directory' );
unlink( Logger::path() );
foreach ( array_keys( Logger::GUARDS ) as $guard ) unlink( Logger::directory() . '/' . $guard );
rmdir( Logger::directory() );

// The ledger describes this site's data, so it is torn down with it. Left
// behind, a later reinstall would read it and skip every migration. The log's
// suffix is internal state of the same kind.
$GLOBALS['njr_test_options'] = [ LEDGER => NJR_VERSION, DOMAIN => 'https://front-end.test', LEGACY_URL => 'https://front-end.test/api/revalidate', LOG_SUFFIX => 'abc123' ];
Settings::delete_settings();
check_same( [], options(), 'uninstalling takes the ledger and the log suffix with the settings' );

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

rmdir( $GLOBALS['njr_test_uploads_dir'] );

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
