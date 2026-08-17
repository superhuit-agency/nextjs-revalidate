<?php
/**
 * Hook registration — every Hookable, and the composition root that applies
 * them.
 *
 * Two things are asserted, and they are the two halves of ADR-0003:
 *
 *  1. **Constructing a Hookable touches no global state.** Every one of the
 *     eight classes is constructed with the recorder watching, and the recorder
 *     stays empty. This is the property the whole convention exists for: an
 *     instance can be obtained for a single method call without paying for the
 *     hooks.
 *  2. **`register_hooks()` registers exactly these hooks, in this order.** The
 *     order is load-bearing — WordPress runs same-hook, same-priority callbacks
 *     in registration order — and the refactor that separated the two acts
 *     could have reordered or dropped a registration without any other check in
 *     this repo noticing. The expected sequence below is what the plugin
 *     registered before that refactor, spelled out.
 *
 * The composition root is checked against the same sequence when the composer
 * autoloader is present: it is the root that decides the order, and a test that
 * only knew the per-class lists would not notice it being reordered. That half
 * is skipped rather than failed without `vendor/`, so this file keeps the one
 * property the standalone idiom is for — it needs nothing.
 *
 * The plugin has no test framework and no WordPress to boot, so this is a
 * standalone script: it stubs the two registration functions, drives the
 * classes through them, and exits non-zero on the first failing expectation.
 *
 * Run with `npm run test:php`, or `php tests/HookRegistrationTest.php`.
 * See `docs/adr/0003-explicit-hook-registration.md` and
 * `docs/adr/0008-two-testing-idioms.md`.
 */

if ( 'cli' !== PHP_SAPI ) die( 'This file must be run from the command line.' );

// The plugin files bail when this is not defined.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );

/**
 * Every registration made since the last reset, in order.
 *
 * Each entry is [ hook name, "Class::method", priority, accepted args ].
 *
 * @var array
 */
$GLOBALS['njr_test_registrations'] = [];

// WordPress stubs
// ====

function njr_test_record( $name, $callback, $priority, $accepted_args ) {
	$GLOBALS['njr_test_registrations'][] = [
		$name,
		is_array( $callback )
			? get_class( $callback[0] ) . '::' . $callback[1]
			: 'closure',
		$priority,
		$accepted_args,
	];
}

function add_action( $name, $callback, $priority = 10, $accepted_args = 1 ) {
	njr_test_record( $name, $callback, $priority, $accepted_args );
}

function add_filter( $name, $callback, $priority = 10, $accepted_args = 1 ) {
	njr_test_record( $name, $callback, $priority, $accepted_args );
}

// The composition root's own registrations are not hook registrations of a
// Hookable, and this file has nothing to say about them.
function register_activation_hook( $file, $callback ) {}
function register_deactivation_hook( $file, $callback ) {}
function register_uninstall_hook( $file, $callback ) {}
function plugin_dir_url( $file ) { return 'https://site.test/wp-content/plugins/nextjs-revalidate/'; }

/**
 * Everything recorded since the last reset, and reset.
 *
 * @return array
 */
function njr_test_taken() {
	$taken = $GLOBALS['njr_test_registrations'];

	$GLOBALS['njr_test_registrations'] = [];

	return $taken;
}

// The subject
// ====

require_once __DIR__ . '/../include/interfaces/Hookable.php';
require_once __DIR__ . '/../include/abstracts/Base.php';
require_once __DIR__ . '/../include/traits/AdminBarMenu.php';
require_once __DIR__ . '/../include/traits/SendbackUrl.php';
require_once __DIR__ . '/../include/I18n.php';
require_once __DIR__ . '/../include/Assets.php';
require_once __DIR__ . '/../include/Settings.php';
require_once __DIR__ . '/../include/Revalidate.php';
require_once __DIR__ . '/../include/Cron/ScheduledPurges.php';
require_once __DIR__ . '/../include/RevalidateAll.php';
require_once __DIR__ . '/../include/RevalidateQueue.php';
require_once __DIR__ . '/../include/RestApi.php';

use NextJsRevalidate\Assets;
use NextJsRevalidate\Cron\ScheduledPurges;
use NextJsRevalidate\I18n;
use NextJsRevalidate\Interfaces\Hookable;
use NextJsRevalidate\RestApi;
use NextJsRevalidate\Revalidate;
use NextJsRevalidate\RevalidateAll;
use NextJsRevalidate\RevalidateQueue;
use NextJsRevalidate\Settings;

// The expectations
// ====

/**
 * What each class registers, in the order it registers it.
 *
 * @var array class name => [ hook name, method, priority, accepted args ][]
 */
$expected_per_class = [

	I18n::class => [
		[ 'init',          'load_plugin_textdomain', 10, 1 ],
		[ 'switch_locale', 'load_plugin_textdomain', 10, 1 ],
	],

	Assets::class => [
		[ 'init',                  'register_assets',          10, 1 ],
		[ 'admin_enqueue_scripts', 'enqueue_admin_assets',     10, 1 ],
		[ 'admin_enqueue_scripts', 'enqueue_editor_assets',    10, 1 ],
		[ 'admin_enqueue_scripts', 'enqueue_settings_assets',  10, 1 ],
	],

	Settings::class => [
		[ 'admin_menu',    'add_page',            10, 1 ],
		[ 'admin_init',    'register_fields',     10, 1 ],
		[ 'admin_init',    'migrate_db',          10, 1 ],
		[ 'admin_notices', 'unconfigured_notice', 10, 1 ],
	],

	Revalidate::class => [
		[ 'wp_after_insert_post', 'on_post_save',                    99, 4 ],
		[ 'page_row_actions',     'add_revalidate_row_action',       20, 2 ],
		[ 'post_row_actions',     'add_revalidate_row_action',       20, 2 ],
		[ 'admin_init',           'revalidate_row_action',           10, 1 ],
		[ 'admin_init',           'register_bulk_actions',           10, 1 ],
		[ 'admin_bar_menu',       'admin_top_bar_menu',             100, 1 ],
		[ 'admin_init',           'revalidate_current_post_action',  10, 1 ],
		[ 'admin_notices',        'purged_notice',                   10, 1 ],
	],

	ScheduledPurges::class => [
		[ ScheduledPurges::CRON_HOOK_NAME, 'run_cron_hook', 10, 1 ],
	],

	RevalidateAll::class => [
		[ 'admin_bar_menu',      'admin_top_bar_menu',                100, 1 ],
		[ 'admin_notices',       'revalidated_notice',                 10, 1 ],
		[ 'admin_init',          'revalidate_all_pages_action',        10, 1 ],
		[ 'wp_update_nav_menu',  'revalidate_all_after_menu_update',   10, 1 ],
	],

	RevalidateQueue::class => [
		[ 'admin_init',                     'action_reset_queue',  10, 1 ],
		[ 'admin_init',                     'ajax_queue_progress', 10, 1 ],
		[ 'admin_notices',                  'admin_queue_notice',  10, 1 ],
		[ RevalidateQueue::CRON_HOOK_NAME,  'run_cron',            10, 1 ],
	],

	RestApi::class => [
		[ 'rest_api_init', 'register_routes', 10, 1 ],
	],
];

/**
 * What the composition root registers on its own behalf, after the Hookables.
 *
 * The activation, deactivation and uninstall hooks are not in this list: they
 * go through `register_*_hook()` rather than `add_action()`, and are stubbed
 * out above.
 *
 * @var array
 */
$expected_of_the_root = [
	[ 'wp_initialize_site', 'NextJsRevalidate::setup_new_site', 100, 1 ],
];

/**
 * The same expectations as one flat sequence, as the plugin registers them.
 *
 * The array above is keyed in the composition root's construction order, which
 * is the order the root registers in, so the concatenation is the whole of this
 * plugin's WordPress surface in registration order.
 *
 * @var array
 */
$expected_sequence = [];
foreach ( $expected_per_class as $class => $registrations ) {
	foreach ( $registrations as $registration ) {
		$expected_sequence[] = [
			$registration[0],
			$class . '::' . $registration[1],
			$registration[2],
			$registration[3],
		];
	}
}
$expected_sequence = array_merge( $expected_sequence, $expected_of_the_root );

// The run
// ====

$failures = 0;

/**
 * @param string $description What the expectation says.
 * @param mixed  $expected    The expected value.
 * @param mixed  $actual      What the subject answered.
 * @return void
 */
function njr_test_expect( $description, $expected, $actual ) {
	global $failures;

	if ( $expected === $actual ) {
		echo "ok   — $description" . PHP_EOL;
		return;
	}

	$failures++;
	echo "FAIL — $description" . PHP_EOL;
	echo '       expected: ' . json_encode( $expected ) . PHP_EOL;
	echo '       actual:   ' . json_encode( $actual ) . PHP_EOL;
}

// Construction, on its own, registers nothing. Each class is constructed while
// the recorder watches; what it recorded is the whole of the claim.
/** @var Hookable[] $instances */
$instances = [];
foreach ( array_keys( $expected_per_class ) as $class ) {
	$instances[ $class ] = new $class();

	njr_test_expect(
		"constructing $class registers no hooks",
		[],
		njr_test_taken()
	);
}

// register_hooks() registers exactly the expected list, in order.
foreach ( $expected_per_class as $class => $registrations ) {
	$instances[ $class ]->register_hooks();

	$expected = [];
	foreach ( $registrations as $registration ) {
		$expected[] = [ $registration[0], $class . '::' . $registration[1], $registration[2], $registration[3] ];
	}

	njr_test_expect(
		"$class registers its hooks, in order",
		$expected,
		njr_test_taken()
	);
}

// The composition root registers all of them, in construction order.
//
// The plugin file loads the composer autoloader before it builds anything, and
// returns early without it — so with no `vendor/` there is nothing to observe
// and the check is skipped rather than reported as a failure. Every expectation
// above still runs, which is what keeps this file runnable with nothing
// installed.
if ( file_exists( ABSPATH . 'vendor/autoload.php' ) ) {

	require_once ABSPATH . 'nextjs-revalidate.php';

	njr_test_expect(
		'the composition root registers every hook, in construction order',
		$expected_sequence,
		njr_test_taken()
	);
}
else {
	echo 'skip — the composition root: vendor/autoload.php is missing, so the plugin file returns before it builds anything. Run `composer install`.' . PHP_EOL;
}

echo PHP_EOL . "$failures failure(s)" . PHP_EOL;

exit( $failures === 0 ? 0 : 1 );
