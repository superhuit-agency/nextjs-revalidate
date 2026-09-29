<?php
/**
 * The reordering integrations' registration — Integrations\NestedPages and
 * Integrations\SimpleCustomPostOrder.
 *
 * Each is supported, never required: with its plugin absent it registers
 * nothing at all. The integration suite runs with both plugins loaded, and a
 * process cannot unload them, so that is asserted here instead, where the
 * answer is decided by whether a constant exists. What each reports is
 * asserted in `tests/integration/NestedPagesOrderTest.php` and
 * `tests/integration/SimpleCustomPostOrderTest.php`. See
 * `docs/adr/0008-two-testing-idioms.md` for the split.
 *
 * Run with `npm run test:php`, or `php tests/reorder-integration-registration-test.php`.
 */

// The plugin files bail when this is not defined.
define( 'ABSPATH', __DIR__ . '/' );

/**
 * Registered action and filter callbacks. hook name => [ callable, priority ][]
 * @var array
 */
$GLOBALS['njr_test_hooks'] = [];

/**
 * Whether `plugins_loaded` has already fired.
 * @var bool
 */
$GLOBALS['njr_test_plugins_loaded'] = false;

// WordPress stubs
// ====

function add_action( $name, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['njr_test_hooks'][ $name ][] = [ $callback, $priority ];
}

function did_action( $name ) {
	return ( 'plugins_loaded' === $name && $GLOBALS['njr_test_plugins_loaded'] ) ? 1 : 0;
}

// The subject
// ====

require_once __DIR__ . '/../include/Interfaces/Hookable.php';
require_once __DIR__ . '/../include/Abstracts/Base.php';
require_once __DIR__ . '/../include/Traits/WhenPluginsLoaded.php';
require_once __DIR__ . '/../include/Integrations/NestedPages.php';
require_once __DIR__ . '/../include/Integrations/SimpleCustomPostOrder.php';

use NextJsRevalidate\Integrations\NestedPages;
use NextJsRevalidate\Integrations\SimpleCustomPostOrder;

// The expectations
// ====

$failures = 0;

/**
 * @param string $description What is being asserted.
 * @param array  $expected    hook name => priority, for every hook expected to have been registered.
 * @return void
 */
function njr_test_registered( $description, array $expected ) {
	global $failures;

	$actual = [];
	foreach ( $GLOBALS['njr_test_hooks'] as $name => $registrations ) {
		foreach ( $registrations as $registration ) $actual[ $name ] = $registration[1];
	}
	ksort( $actual );
	ksort( $expected );

	if ( $actual === $expected ) {
		printf( "ok   — %s\n", $description );
		return;
	}

	$failures++;
	printf( "FAIL — %s (expected %s, got %s)\n", $description, json_encode( $expected ), json_encode( $actual ) );
}

// Constructing an integration is not registering it.
foreach ( [ NestedPages::class, SimpleCustomPostOrder::class ] as $class ) {
	$GLOBALS['njr_test_hooks'] = [];
	new $class();
	njr_test_registered( "constructing $class registers nothing", [] );
}

// While the plugins are still loading, nothing can be said about which of them
// are there — the question waits for `plugins_loaded`.
foreach ( [ NestedPages::class, SimpleCustomPostOrder::class ] as $class ) {
	$GLOBALS['njr_test_hooks'] = [];
	( new $class() )->register_hooks();
	njr_test_registered( "$class waits for every plugin to have declared itself", [ 'plugins_loaded' => 10 ] );
}

// A site with neither plugin: no hook at all.
$GLOBALS['njr_test_plugins_loaded'] = true;

foreach ( [ NestedPages::class, SimpleCustomPostOrder::class ] as $class ) {
	$GLOBALS['njr_test_hooks'] = [];
	( new $class() )->register_hooks();
	njr_test_registered( "a site without the plugin $class integrates with registers nothing", [] );
}

// A site running both. The request is read ahead of the plugin's own handler,
// at priority 1 of the same action, or the order is already written.
define( 'NESTEDPAGES_DIR', __DIR__ );
define( 'SCPORDER_VERSION', '2.8.8' );

$GLOBALS['njr_test_hooks'] = [];
( new NestedPages() )->register_hooks();
njr_test_registered( 'a site running Nested Pages reads its sort first, and listens to each level it writes', [
	'wp_ajax_npsort'                  => 1,
	'nestedpages_posts_order_updated' => 10,
] );

$GLOBALS['njr_test_hooks'] = [];
( new SimpleCustomPostOrder() )->register_hooks();
njr_test_registered( 'a site running Simple Custom Post Order reads its two reorders first, and listens to their end', [
	'wp_ajax_update-menu-order' => 1,
	'wp_ajax_scpo_set_position' => 1,
	'scp_update_menu_order'     => 10,
] );

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
