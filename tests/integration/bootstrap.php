<?php
/**
 * Bootstrap for the integration suite.
 *
 * Boots the WordPress test library wp-env mounts at WP_TESTS_DIR, with this
 * plugin loaded, and creates the revalidation queue's table before the first
 * test runs.
 *
 * Run with `npm run test:integration`. See `docs/adr/0008-two-testing-idioms.md`
 * for why this suite exists alongside the standalone scripts of
 * `npm run test:php`, and why the queue table is created here rather than by a
 * test.
 *
 * @package NextJsRevalidate
 */

$njr_plugin_dir = dirname( __DIR__, 2 );

$njr_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $njr_tests_dir ) {
	echo 'WP_TESTS_DIR is not set. This suite runs inside wp-env\'s test site, which' . PHP_EOL
		. 'sets it: run `npm run test:integration` rather than phpunit directly.' . PHP_EOL;
	exit( 1 );
}
$njr_tests_dir = rtrim( $njr_tests_dir, '/\\' );

// The WordPress test bootstrap requires the PHPUnit Polyfills and does not ship
// them: wp-env clones wordpress-develop's `tests/phpunit` only, without its
// vendor directory. Loading the plugin's own copy here is the route WordPress
// documents for plugin integration tests, and keeps the polyfills a dev
// dependency that `composer install --no-dev` leaves out of a release.
$njr_polyfills = $njr_plugin_dir . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
if ( ! file_exists( $njr_polyfills ) ) {
	echo 'The PHPUnit Polyfills are missing. Run `composer install` in the plugin directory.' . PHP_EOL;
	exit( 1 );
}
require_once $njr_polyfills;

require_once $njr_tests_dir . '/includes/functions.php';

// Load the plugin as a mu-plugin would be loaded: early, unconditionally, and
// without an activation. Nothing the activation hook does happens here, which
// is why the queue table is created by hand below.
tests_add_filter(
	'muplugins_loaded',
	function () use ( $njr_plugin_dir ) {
		require $njr_plugin_dir . '/nextjs-revalidate.php';
	}
);

// The Redirection plugin, which `.wp-env.tests.json` installs alongside this one, so
// the integration that listens to its redirects can be exercised. Loaded after
// this plugin, as WordPress would load it, and only if it is there: an
// integration is supported, never required, and this suite has to be able to
// run without it.
$njr_redirection = dirname( $njr_plugin_dir ) . '/redirection/redirection.php';
if ( file_exists( $njr_redirection ) ) {
	tests_add_filter(
		'muplugins_loaded',
		function () use ( $njr_redirection ) {
			require_once $njr_redirection;
		}
	);
}

require $njr_tests_dir . '/includes/bootstrap.php';

// Test cases and helpers autoload through composer's `autoload-dev`, so adding
// one is adding a file rather than a file and a line here.

// The queue table is created once, here, outside any transaction — a test
// cannot create it, because the DDL would commit the transaction that isolates
// that test. QueueTestCase empties the table between tests instead.
NextJsRevalidate::init()->queue->create_table();

// Redirection's tables, for the same reason and in the same place. The test
// library activates no plugin, so nothing has run Redirection's installer.
//
// The installer is reached through `get_latest_database()` rather than by
// naming the latest schema class, because that method is what includes the
// schema file that class lives in — and it is the name upstream keeps stable
// across the move under a namespace.
//
// Two layouts are supported, because the move happened in a Redirection
// release rather than in this repo, and a checkout can be pinned to either:
//
// - **5.5 and later** — `includes/database/`, class
//   `Redirection\Database\Database`, reached through the plugin's own
//   autoloader, which now registers the whole `Redirection\` namespace
//   against `includes/`. Nothing needs requiring by hand.
// - **Before that** — `database/`, class `Red_Database`, and no autoloading
//   that reaches it: `redirection.php` required its models at boot and
//   nothing else, leaving the database layer to the admin, api and CLI entry
//   points, none of which this suite loads.
//
// Asking `class_exists()` about the installer without doing this answers no on
// a site that *is* running Redirection, and that is a silent no rather than a
// failure: no tables, and every redirect test failing on a
// `Red_Group::create()` that returns false. So a Redirection that is present
// but matches neither layout is a hard failure here, where it names itself.
if ( isset( $njr_redirection ) && file_exists( $njr_redirection ) ) {
	$njr_redirection_dir = dirname( $njr_redirection );

	if ( file_exists( $njr_redirection_dir . '/includes/database/class-database.php' ) ) {
		$njr_redirection_database = 'Redirection\\Database\\Database';
	}
	elseif ( file_exists( $njr_redirection_dir . '/database/database.php' ) ) {
		require_once $njr_redirection_dir . '/database/database-status.php';
		require_once $njr_redirection_dir . '/database/database-upgrade.php';
		require_once $njr_redirection_dir . '/database/database-upgrader.php';
		require_once $njr_redirection_dir . '/database/database.php';

		$njr_redirection_database = 'Red_Database';
	}
	else {
		echo "integration bootstrap: Redirection is installed at $njr_redirection_dir, but its database layer is at neither `includes/database/` nor `database/`. Its layout has changed again; the redirect tests would otherwise run against tables nothing created.\n";
		exit( 1 );
	}

	call_user_func( [ $njr_redirection_database, 'get_latest_database' ] )->install();
}
