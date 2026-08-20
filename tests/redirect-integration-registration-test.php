<?php
/**
 * The redirect integration's registration — Integrations\Redirection.
 *
 * An integration is supported, never required: with the Redirection plugin
 * absent this one registers nothing at all, and the plugin behaves as it did
 * before it existed. That is the one behaviour of #9 the integration suite
 * cannot see — it runs with Redirection loaded, and a process cannot unload it
 * — so it is asserted here instead, where the answer is decided by whether a
 * constant exists.
 *
 * The decision taken before the queue is reached — whether a redirect is a
 * candidate at all, and which path its source names — is stubbed the same way,
 * in `tests/revalidatable-redirect-test.php`. Everything else about redirect
 * revalidation is asserted on the revalidation queue itself, in
 * `tests/integration/RedirectRevalidationTest.php`. See
 * `docs/adr/0008-two-testing-idioms.md` for the split.
 *
 * Run with `npm run test:php`, or `php tests/redirect-integration-registration-test.php`.
 */

// The plugin files bail when this is not defined.
define( 'ABSPATH', __DIR__ . '/' );

/**
 * Registered action callbacks. action name => callable[]
 * @var array
 */
$GLOBALS['njr_test_actions'] = [];

/**
 * Whether `plugins_loaded` has already fired.
 * @var bool
 */
$GLOBALS['njr_test_plugins_loaded'] = false;

// WordPress stubs
// ====

function add_action( $name, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['njr_test_actions'][ $name ][] = $callback;
}

function did_action( $name ) {
	return ( 'plugins_loaded' === $name && $GLOBALS['njr_test_plugins_loaded'] ) ? 1 : 0;
}

// The subject
// ====

require_once __DIR__ . '/../include/interfaces/Hookable.php';
require_once __DIR__ . '/../include/abstracts/Base.php';
require_once __DIR__ . '/../include/Integrations/Redirection.php';

// The expectations
// ====

$failures = 0;

/**
 * @param string $description What is being asserted.
 * @param array  $expected    The action names expected to have been registered.
 * @return void
 */
function njr_test_registered( $description, array $expected ) {
	global $failures;

	$actual = array_keys( $GLOBALS['njr_test_actions'] );
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

$redirect_actions = [
	'redirection_redirect_updated',
	'redirection_redirect_deleted',
	'redirection_redirect_enabled',
	'redirection_redirect_disabled',
];

// Constructing the integration is not registering it: registration is a
// separate act, owned by the composition root, so an object that hooks nothing
// on the way in is one a caller can construct for a single method call — which
// is exactly what `tests/revalidatable-redirect-test.php` does.
$GLOBALS['njr_test_actions'] = [];
new NextJsRevalidate\Integrations\Redirection();
njr_test_registered( 'constructing the integration registers nothing', [] );

// While the plugins are still loading, nothing can be said about which of them
// are there — the question waits for `plugins_loaded`.
$GLOBALS['njr_test_actions'] = [];
( new NextJsRevalidate\Integrations\Redirection() )->register_hooks();
njr_test_registered(
	'registration waits for every plugin to have declared itself',
	[ 'plugins_loaded' ]
);

// A site without Redirection: no redirect hook is registered at all.
$GLOBALS['njr_test_plugins_loaded'] = true;

$GLOBALS['njr_test_actions'] = [];
( new NextJsRevalidate\Integrations\Redirection() )->register_hooks();
njr_test_registered( 'a site without Redirection registers no redirect hook', [] );

// A site running Redirection: the four actions a redirect changes through.
define( 'REDIRECTION_VERSION', '5.9.0' );

$GLOBALS['njr_test_actions'] = [];
( new NextJsRevalidate\Integrations\Redirection() )->register_hooks();
njr_test_registered( 'a site running Redirection listens to its redirect actions', $redirect_actions );

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
