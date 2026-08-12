<?php
/**
 * Tests for the migration ledger — `Settings::migrate_db()`.
 *
 * Run with `npm run test:php`, or `php tests/test-migrate-db.php`.
 *
 * The repository has no test suite and no WordPress to run against, so this
 * file is deliberately self-contained: it stubs the handful of option
 * functions the migrations touch with an in-memory store, loads `Settings`
 * directly, and drives it through the upgrade paths. Nothing here requires
 * composer, WordPress or a database.
 *
 * @package NextJsRevalidate
 */

namespace {

	define( 'NJR_VERSION', '1.7.0' );

	/** The site's options, keyed by option name. */
	$njr_options = [];

	/** Every write, in order, so a test can assert nothing was written at all. */
	$njr_writes = [];

	function njr_reset_options( array $options = [] ) {
		global $njr_options, $njr_writes;
		$njr_options = $options;
		$njr_writes  = [];
	}

	function get_option( $option, $default = false ) {
		global $njr_options;
		return array_key_exists( $option, $njr_options ) ? $njr_options[$option] : $default;
	}

	function update_option( $option, $value ) {
		global $njr_options, $njr_writes;
		$njr_writes[] = "update:$option";
		$njr_options[$option] = $value;
		return true;
	}

	function add_option( $option, $value = '' ) {
		global $njr_options;
		if ( array_key_exists( $option, $njr_options ) ) return false;
		return update_option( $option, $value );
	}

	function delete_option( $option ) {
		global $njr_options, $njr_writes;
		if ( ! array_key_exists( $option, $njr_options ) ) return false;
		$njr_writes[] = "delete:$option";
		unset( $njr_options[$option] );
		return true;
	}

	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		return true;
	}

	function __( $text, $domain = null ) { return $text; }

	require_once __DIR__ . '/../include/abstracts/Base.php';
	require_once __DIR__ . '/../include/Settings.php';
}

namespace NextJsRevalidate {

	const ALLOW_REVALIDATE_ALL = Settings::SETTINGS_ALLOW_REVALIDATE_ALL_NAME;
	const LEDGER               = Settings::DB_VERSION_OPTION_NAME;

	$failures = 0;
	$run      = 0;

	function assert_that( $condition, $message ) {
		global $failures, $run;
		$run++;
		if ( $condition ) return;
		$failures++;
		fwrite( STDERR, "  ✗ $message\n" );
	}

	function assert_same( $expected, $actual, $message ) {
		assert_that(
			$expected === $actual,
			sprintf( "%s\n      expected: %s\n      actual:   %s", $message, var_export( $expected, true ), var_export( $actual, true ) )
		);
	}

	function options() {
		global $njr_options;
		return $njr_options;
	}

	function writes() {
		global $njr_writes;
		return $njr_writes;
	}

	function migrate( array $options ) {
		\njr_reset_options( $options );
		( new Settings() )->migrate_db();
	}

	echo "Settings::migrate_db()\n";


	// A fresh install has no legacy options, so no migration body may touch it.
	// Its data shape is already the running code's; it is only stamped.
	migrate( [] );
	assert_same( [ LEDGER => NJR_VERSION ], options(), 'fresh install is stamped and otherwise untouched' );
	assert_same( [ 'update:' . LEDGER ], writes(), 'fresh install writes nothing but the ledger' );


	// A 1.4.x site: both migrations run, in order, on the one request. The
	// option 1.5.0 carries over is the one 1.6.0 then drops.
	migrate( [
		'nextjs_revalidate-allow_purge_all' => [ 'post' => '1' ],
		'nextjs-revalidate-purge_all'       => [ 'post_type' => 'page' ],
		'nextjs-revalidate-queue'           => [ 'https://example.com/' ],
	] );
	assert_same(
		[
			ALLOW_REVALIDATE_ALL => [ 'post' => '1' ],
			LEDGER               => NJR_VERSION,
		],
		options(),
		'1.4.x → 1.7.0 carries the renamed option over and drops the queue options'
	);


	// A 1.5.x site: the rename already happened, so only 1.6.0 runs. The
	// giveaway is that no `allow_revalidate_all` appears out of nowhere.
	migrate( [
		'nextjs-revalidate-queue'          => [ 'https://example.com/' ],
		'nextjs-revalidate-revalidate_all' => [ 'post_type' => 'page' ],
	] );
	assert_same( [ LEDGER => NJR_VERSION ], options(), '1.5.x → 1.7.0 drops the queue options only' );
	assert_that( ! array_key_exists( ALLOW_REVALIDATE_ALL, options() ), '1.5.x → 1.7.0 does not re-run the 1.5.0 rename' );


	// A legacy option holding an empty value is still evidence of the release
	// that wrote it: `get_option()` cannot tell it from an absent one, so the
	// backfill must not ask by value.
	migrate( [ 'nextjs-revalidate-queue' => '' ] );
	assert_same( [ LEDGER => NJR_VERSION ], options(), 'an empty legacy option still fingerprints the site' );


	// A site already stamped runs nothing, even holding options a migration
	// would otherwise claim — the ledger has the last word, not the data.
	migrate( [
		LEDGER                              => NJR_VERSION,
		'nextjs_revalidate-allow_purge_all' => [ 'post' => '1' ],
		ALLOW_REVALIDATE_ALL                => [ 'page' => '1' ],
	] );
	assert_same( [], writes(), 'a stamped site at the current version writes nothing' );
	assert_same( [ 'page' => '1' ], options()[ALLOW_REVALIDATE_ALL], 'a stamped site keeps operator edits' );


	// The same, one release behind: the ledger moves forward, no body runs.
	migrate( [ LEDGER => '1.6.9', ALLOW_REVALIDATE_ALL => [ 'page' => '1' ] ] );
	assert_same( [ 'update:' . LEDGER ], writes(), 'a stamped 1.6.9 site only re-stamps' );
	assert_same( NJR_VERSION, options()[LEDGER], 'the ledger moves to the running version' );


	// Running the migrations twice must be indistinguishable from running them
	// once, whatever the site does with its data in between — this is what the
	// version comparison this replaced could not give, and what a migration
	// that is not naturally idempotent will depend on.
	\njr_reset_options( [ 'nextjs_revalidate-allow_purge_all' => [ 'post' => '1' ] ] );
	$settings = new Settings();
	$settings->migrate_db();
	\update_option( ALLOW_REVALIDATE_ALL, [ 'edited-by-hand' => '1' ] );
	\update_option( 'nextjs_revalidate-allow_purge_all', [ 'post' => '1' ] );
	$settings->migrate_db();
	assert_same( [ 'edited-by-hand' => '1' ], options()[ALLOW_REVALIDATE_ALL], 'a second run does not clobber an operator edit' );
	assert_that( array_key_exists( 'nextjs_revalidate-allow_purge_all', options() ), 'a second run does not re-consume a legacy option' );


	// Older code over newer data — a downgrade — must not walk the ledger back,
	// which would make migrations the site has been through eligible again.
	migrate( [ LEDGER => '9.9.9' ] );
	assert_same( [], writes(), 'a downgraded site keeps its higher DB version' );


	// Versions are compared as versions. The scheme this replaced concatenated
	// digits, making 1.7.0 (170) compare as older than 1.6.10 (1610).
	assert_that( version_compare( '1.7.0', '1.6.10', '>' ), '1.7.0 is newer than 1.6.10' );


	// The plugin version has one source of truth. A hardcoded `NJR_VERSION` is
	// how it drifted to 1.6.0 while the plugin shipped 1.6.9, and a ledger
	// stamped with a stale constant re-runs migrations forever.
	$plugin_file = file_get_contents( __DIR__ . '/../nextjs-revalidate.php' );
	assert_that(
		preg_match( '/^\s*\*\s*Version:\s*\d+\.\d+\.\d+/m', $plugin_file ) === 1,
		'the plugin header declares a version'
	);
	assert_that(
		preg_match( "/define\(\s*'NJR_VERSION'\s*,\s*['\"]/", $plugin_file ) !== 1,
		'NJR_VERSION is derived from the header, not hardcoded beside it'
	);


	printf( "\n%d assertions, %d failed\n", $run, $failures );
	exit( $failures === 0 ? 0 : 1 );
}
