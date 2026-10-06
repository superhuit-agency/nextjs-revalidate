<?php
/**
 * The site setting integrations' registration — Integrations\Yoast and
 * Integrations\Polylang.
 *
 * Each is supported, never required: with its plugin absent it registers
 * nothing at all. That is the one behaviour of #171 the integration suite cannot
 * see — it runs with both plugins loaded, and a process cannot unload them — so
 * it is asserted here instead, where the answer is decided by whether a
 * constant exists. What each reports is asserted in
 * `tests/integration/YoastSettingsTest.php` and
 * `tests/integration/PolylangSettingsTest.php`. See
 * `docs/adr/0008-two-testing-idioms.md` for the split.
 *
 * Run with `npm run test:php`, or `php tests/settings-integration-registration-test.php`.
 */

// The plugin files bail when this is not defined.
define( 'ABSPATH', __DIR__ . '/' );

/**
 * Registered action and filter callbacks. hook name => callable[]
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
	$GLOBALS['njr_test_hooks'][ $name ][] = $callback;
}

function add_filter( $name, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['njr_test_hooks'][ $name ][] = $callback;
}

function did_action( $name ) {
	return ( 'plugins_loaded' === $name && $GLOBALS['njr_test_plugins_loaded'] ) ? 1 : 0;
}

// The subject
// ====

require_once __DIR__ . '/../include/Interfaces/Hookable.php';
require_once __DIR__ . '/../include/Abstracts/Base.php';
require_once __DIR__ . '/../include/Traits/WhenPluginsLoaded.php';
require_once __DIR__ . '/../include/Integrations/Yoast.php';
require_once __DIR__ . '/../include/Integrations/Polylang.php';

use NextJsRevalidate\Integrations\Polylang;
use NextJsRevalidate\Integrations\Yoast;

// The expectations
// ====

$failures = 0;

/**
 * @param string $description What is being asserted.
 * @param array  $expected    The hook names expected to have been registered.
 * @return void
 */
function njr_test_registered( $description, array $expected ) {
	global $failures;

	$actual = array_keys( $GLOBALS['njr_test_hooks'] );
	sort( $actual );
	sort( $expected );

	if ( $actual === $expected ) {
		printf( "ok   — %s\n", $description );
		return;
	}

	$failures++;
	printf(
		"FAIL — %s (expected %s, got %s)\n",
		$description,
		implode( ', ', $expected ) ?: 'nothing',
		implode( ', ', $actual ) ?: 'nothing'
	);
}

/**
 * @param string $description What is being asserted.
 * @param mixed  $expected
 * @param mixed  $actual
 * @return void
 */
function njr_test_same( $description, $expected, $actual ) {
	global $failures;

	if ( $expected === $actual ) {
		printf( "ok   — %s\n", $description );
		return;
	}

	$failures++;
	printf( "FAIL — %s (expected %s, got %s)\n", $description, var_export( $expected, true ), var_export( $actual, true ) );
}

$polylang_hooks = [
	'created_language',
	'edited_language',
	'delete_language',
	'nextjs_revalidate_should_revalidate_taxonomy',
	'pll_update_default_lang',
	'update_option_polylang',
	'added_term_meta',
	'updated_term_meta',
	'deleted_term_meta',
	'nextjs_revalidate_dependent_posts',
	'wp_after_insert_post',
];

// Constructing an integration is not registering it.
foreach ( [ Yoast::class, Polylang::class ] as $class ) {
	$GLOBALS['njr_test_hooks'] = [];
	new $class();
	njr_test_registered( "constructing $class registers nothing", [] );
}

// While the plugins are still loading, nothing can be said about which of them
// are there — the question waits for `plugins_loaded`.
foreach ( [ Yoast::class, Polylang::class ] as $class ) {
	$GLOBALS['njr_test_hooks'] = [];
	( new $class() )->register_hooks();
	njr_test_registered( "$class waits for every plugin to have declared itself", [ 'plugins_loaded' ] );
}

// A site with neither plugin: no hook at all.
$GLOBALS['njr_test_plugins_loaded'] = true;

$GLOBALS['njr_test_hooks'] = [];
( new Yoast() )->register_hooks();
njr_test_registered( 'a site without Yoast SEO registers nothing', [] );

$GLOBALS['njr_test_hooks'] = [];
( new Polylang() )->register_hooks();
njr_test_registered( 'a site without Polylang registers nothing', [] );

// A site running both.
define( 'WPSEO_VERSION', '26.0' );
define( 'POLYLANG_VERSION', '3.7' );

$GLOBALS['njr_test_hooks'] = [];
( new Yoast() )->register_hooks();
njr_test_registered( 'a site running Yoast SEO adds to the site setting options', [ 'nextjs_revalidate_site_setting_options' ] );

$GLOBALS['njr_test_hooks'] = [];
( new Polylang() )->register_hooks();
njr_test_registered( 'a site running Polylang listens to its languages, its default language, its string translations and its translations', $polylang_hooks );

// Polylang's `language` taxonomy is publicly queryable, and a site setting
// rather than a term the front-end shows: the integration declines it, and
// leaves every other verdict as it found it (ADR 0040).
njr_test_same( 'Polylang declines its `language` taxonomy', false, ( new Polylang() )->decline_language_taxonomy( true, 'language' ) );
njr_test_same( 'Polylang leaves a viewable taxonomy admitted', true, ( new Polylang() )->decline_language_taxonomy( true, 'category' ) );
njr_test_same( 'Polylang leaves a declined taxonomy declined', false, ( new Polylang() )->decline_language_taxonomy( false, 'post_translations' ) );

// What Yoast adds, and what it leaves out: `wpseo` is Yoast's own bookkeeping
// (ADR 0037).
njr_test_same(
	'Yoast SEO adds its titles and social options, and not `wpseo`',
	[ 'blogname', 'wpseo_titles', 'wpseo_social' ],
	( new Yoast() )->add_site_setting_options( [ 'blogname' ] )
);

njr_test_same(
	'an option a site already added is not listed twice',
	[ 'wpseo_titles', 'wpseo_social' ],
	( new Yoast() )->add_site_setting_options( [ 'wpseo_titles' ] )
);

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
