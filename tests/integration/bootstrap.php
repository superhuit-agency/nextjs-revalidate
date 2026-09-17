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
// Which files that takes depends on the Redirection release installed, and
// `redirection-database.php` is where that question is answered — both layouts,
// with the reasoning, in a function the gate can run. wp-env tracks Redirection's
// latest release rather than pinning one (ADR 0014), so this suite has to boot
// against whatever it downloaded, and against the older copy an install made
// before that release keeps until `wp-env start --update`.
if ( isset( $njr_redirection ) && file_exists( $njr_redirection ) ) {
	require_once __DIR__ . '/redirection-database.php';

	$njr_redirection_database = njr_redirection_database_layout( dirname( $njr_redirection ) );

	// Neither layout is there, which means upstream has moved these files again.
	// Said here rather than carried: without the installer there are no tables,
	// and every redirect test fails instead on a `Red_Group::create()` that
	// returns false — a silent no, three layers from its cause.
	if ( null === $njr_redirection_database ) {
		echo 'Redirection is installed, but neither database layout this suite knows is in' . PHP_EOL
			. dirname( $njr_redirection ) . '. Upstream has moved its database classes again:' . PHP_EOL
			. 'teach tests/integration/redirection-database.php the new layout.' . PHP_EOL;
		exit( 1 );
	}

	foreach ( $njr_redirection_database['requires'] as $njr_redirection_file ) {
		require_once $njr_redirection_file;
	}

	// The files are where the layout says, but the class they declared is not —
	// the same conclusion as above, one step later. Said here because
	// `call_user_func()` on a class that is gone warns and returns null on PHP
	// 7.4, and the fatal that follows names `install()` rather than the class.
	if ( ! class_exists( $njr_redirection_database['database_class'] ) ) {
		echo 'Redirection is installed, but its ' . $njr_redirection_database['database_class']
			. ' class was not found.' . PHP_EOL
			. 'Upstream has changed its database layer: teach' . PHP_EOL
			. 'tests/integration/redirection-database.php what it looks like now.' . PHP_EOL;
		exit( 1 );
	}

	$njr_redirection_installed = call_user_func(
		[ $njr_redirection_database['database_class'], 'get_latest_database' ]
	)->install();

	// The installer answers with a WP_Error rather than throwing, and a run that
	// ignored it would fail later and elsewhere, for the same reason as above.
	if ( is_wp_error( $njr_redirection_installed ) ) {
		echo 'Redirection\'s installer could not create its tables: '
			. $njr_redirection_installed->get_error_message() . PHP_EOL;
		exit( 1 );
	}
}
