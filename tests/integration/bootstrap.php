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
	echo 'WP_TESTS_DIR is not set. This suite runs inside wp-env\'s tests environment,' . PHP_EOL
		. 'which sets it: run `npm run test:integration` rather than phpunit directly.' . PHP_EOL;
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

require $njr_tests_dir . '/includes/bootstrap.php';

// The queue table is created once, here, outside any transaction — a test
// cannot create it, because the DDL would commit the transaction that isolates
// that test. QueueTestCase empties the table between tests instead.
NextJsRevalidate::init()->queue->create_table();

require_once __DIR__ . '/QueueTestCase.php';
