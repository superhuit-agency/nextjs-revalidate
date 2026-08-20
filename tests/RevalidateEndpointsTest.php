<?php
/**
 * The revalidate endpoints — Settings::revalidate_endpoint_url() and ::fse_endpoint_url().
 *
 * The plugin has no test framework and no WordPress to boot, so this is a
 * standalone script (ADR-0008): it stubs the option read and the two slash
 * helpers the composition is pure with respect to, drives it through a set of
 * fixture sites, and exits non-zero on the first failing expectation.
 *
 * What it is here to pin is that a standard install composes byte-identically
 * to the single URL the operator used to type — #29 splits where the value is
 * *stored*, and is not licence to change where a revalidation is *sent*.
 *
 * Run with `npm run test:php`, or `php tests/RevalidateEndpointsTest.php`.
 */

// The plugin files bail when this is not defined.
define( 'ABSPATH', __DIR__ . '/' );

/**
 * The fixture site's option rows. option name => stored value.
 *
 * @var array
 */
$GLOBALS['njr_test_options'] = [];

// WordPress stubs
// ====

function add_action( $name, $callback, $priority = 10, $accepted_args = 1 ) {}

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['njr_test_options'] )
		? $GLOBALS['njr_test_options'][ $name ]
		: $default;
}

function untrailingslashit( $string ) {
	return rtrim( $string, '/\\' );
}

// The subject
// ====

require_once __DIR__ . '/../include/Interfaces/Hookable.php';
require_once __DIR__ . '/../include/Abstracts/Base.php';
require_once __DIR__ . '/../include/Settings.php';

use NextJsRevalidate\Settings;

$settings = new Settings();

const DOMAIN   = Settings::SETTINGS_DOMAIN_NAME;
const PATH_OPT = Settings::SETTINGS_ENDPOINT_PATH_NAME;
const FSE_PATH = Settings::SETTINGS_FSE_ENDPOINT_PATH_NAME;

$failures = 0;

function check_same( $expected, $actual, $description ) {
	global $failures;

	if ( $expected === $actual ) {
		printf( "ok   — %s\n", $description );
		return;
	}

	$failures++;
	printf( "FAIL — %s (expected %s, got %s)\n", $description, json_encode( $expected ), json_encode( $actual ) );
}

/** Put the fixture site in a known state. */
function site( array $options ) {
	$GLOBALS['njr_test_options'] = $options;
}

// The expectations
// ====

// The whole point of the split: a standard install fills in one field and gets
// the URL it used to type out in full.
site( [ DOMAIN => 'https://front-end.test' ] );
check_same(
	'https://front-end.test/api/revalidate',
	$settings->revalidate_endpoint_url(),
	'a domain alone composes the default revalidate path'
);
check_same(
	'https://front-end.test/api/revalidate-fse',
	$settings->fse_endpoint_url(),
	'a domain alone composes the default FSE path'
);

// A path an operator supplied wins over the default, verbatim — the string
// surgery this replaces could not survive a route named anything else.
site( [ DOMAIN => 'https://front-end.test', PATH_OPT => '/fr/api/purge', FSE_PATH => '/fr/api/purge-fse' ] );
check_same( 'https://front-end.test/fr/api/purge', $settings->revalidate_endpoint_url(), 'a custom revalidate path is used verbatim' );
check_same( 'https://front-end.test/fr/api/purge-fse', $settings->fse_endpoint_url(), 'a custom FSE path is used verbatim' );

// The two paths are independent: overriding one leaves the other on its default.
site( [ DOMAIN => 'https://front-end.test', FSE_PATH => '/api/fse' ] );
check_same( 'https://front-end.test/api/revalidate', $settings->revalidate_endpoint_url(), 'an FSE path does not disturb the revalidate path' );
check_same( 'https://front-end.test/api/fse', $settings->fse_endpoint_url(), 'the FSE path is overridden on its own' );

// Exactly one slash joins the two halves, whichever way the operator typed them.
site( [ DOMAIN => 'https://front-end.test/', PATH_OPT => '/api/revalidate' ] );
check_same( 'https://front-end.test/api/revalidate', $settings->revalidate_endpoint_url(), 'a trailing slash on the domain does not double up' );

site( [ DOMAIN => 'https://front-end.test', PATH_OPT => 'api/revalidate' ] );
check_same( 'https://front-end.test/api/revalidate', $settings->revalidate_endpoint_url(), 'a path without a leading slash still joins with one' );

site( [ DOMAIN => 'https://front-end.test/', PATH_OPT => 'api/revalidate' ] );
check_same( 'https://front-end.test/api/revalidate', $settings->revalidate_endpoint_url(), 'neither half carrying a slash still joins with one' );

// A path field holding only a slash is a field an operator cleared, not a
// request to revalidate against the domain root — which no Next.js app serves.
site( [ DOMAIN => 'https://front-end.test', PATH_OPT => '/' ] );
check_same( 'https://front-end.test/api/revalidate', $settings->revalidate_endpoint_url(), 'a path of "/" falls back to the default' );

// #65: a site can hold a row stored as false, which reads as the empty string.
site( [ DOMAIN => 'https://front-end.test', PATH_OPT => false ] );
check_same( 'https://front-end.test/api/revalidate', $settings->revalidate_endpoint_url(), 'a path stored as false falls back to the default' );

// A subdirectory install and a port survive composition untouched — both are
// part of the domain the operator entered, not something to be parsed out.
site( [ DOMAIN => 'http://localhost:8083', PATH_OPT => '/revalidate' ] );
check_same( 'http://localhost:8083/revalidate', $settings->revalidate_endpoint_url(), 'a port is carried through' );

site( [ DOMAIN => 'https://front-end.test/blog', PATH_OPT => '/api/revalidate' ] );
check_same( 'https://front-end.test/blog/api/revalidate', $settings->revalidate_endpoint_url(), 'a subdirectory domain is carried through' );

// Neither field is sanitised on save, and a domain or a path pasted in with
// surrounding whitespace composes a URL `wp_remote_get()` rejects — a
// revalidation that fails for a reason nothing on screen names.
site( [ DOMAIN => '  https://front-end.test  ' ] );
check_same( 'https://front-end.test/api/revalidate', $settings->revalidate_endpoint_url(), 'whitespace around the domain is trimmed off' );

site( [ DOMAIN => 'https://front-end.test', PATH_OPT => ' /api/purge ' ] );
check_same( 'https://front-end.test/api/purge', $settings->revalidate_endpoint_url(), 'whitespace around the path is trimmed off' );

// Trimmed before the fallback, so a field an operator left as spaces is a field
// they left empty, not a path of its own.
site( [ DOMAIN => 'https://front-end.test', PATH_OPT => '   ' ] );
check_same( 'https://front-end.test/api/revalidate', $settings->revalidate_endpoint_url(), 'a path of nothing but whitespace falls back to the default' );

site( [ DOMAIN => '   ' ] );
check_same( '', $settings->revalidate_endpoint_url(), 'a domain of nothing but whitespace composes nothing' );

// An unconfigured site composes nothing rather than a bare path. Nothing calls
// these without an `is_configured()` guard, and this is what keeps a mistake
// there from becoming a request to a relative URL.
site( [] );
check_same( '', $settings->revalidate_endpoint_url(), 'no domain composes no revalidate URL' );
check_same( '', $settings->fse_endpoint_url(), 'no domain composes no FSE URL' );

site( [ DOMAIN => '', PATH_OPT => '/api/revalidate' ] );
check_same( '', $settings->revalidate_endpoint_url(), 'a path without a domain composes nothing' );

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
