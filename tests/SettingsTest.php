<?php
/**
 * Configured site — Settings::missing_settings(), Settings::is_configured() and
 * Settings::not_configured_error().
 *
 * The plugin has no test framework and no WordPress to boot, so this is a
 * standalone script: it stubs the two option reads the pair is pure with
 * respect to, drives them through a set of fixture sites, and exits non-zero on
 * the first failing expectation.
 *
 * Run with `npm run test:php`, or `php tests/SettingsTest.php`.
 */

// The plugin files bail when this is not defined.
define( 'ABSPATH', __DIR__ . '/' );

/**
 * The fixture site's option rows. option name => stored value.
 *
 * A name absent from this array is a site holding no row for that option,
 * which is not the same as a site holding an empty one.
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

function __( $text, $domain = null ) { return $text; }

class WP_Error {
	private $code;
	private $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}

// The subject
// ====

require_once __DIR__ . '/../include/Interfaces/Hookable.php';
require_once __DIR__ . '/../include/Abstracts/Base.php';
require_once __DIR__ . '/../include/Settings.php';

$settings = new NextJsRevalidate\Settings();

$domain = NextJsRevalidate\Settings::SETTINGS_DOMAIN_NAME;
$secret = NextJsRevalidate\Settings::SETTINGS_SECRET_NAME;

// The expectations
// ====

$cases = [
	// [ description, option rows, expected missing_settings() ]

	[
		'a site holding both settings is configured',
		[ $domain => 'https://front-end.test', $secret => 's3cret' ],
		[],
	],

	// #29 — the paths are optional by design: a site that supplies neither is
	// configured, and composes both endpoints from their defaults.
	[
		'a site holding no paths is still configured',
		[ $domain => 'https://front-end.test', $secret => 's3cret', NextJsRevalidate\Settings::SETTINGS_ENDPOINT_PATH_NAME => '', NextJsRevalidate\Settings::SETTINGS_FSE_ENDPOINT_PATH_NAME => '' ],
		[],
	],

	// Half-configured is unconfigured, and the pair names which half.
	[
		'a site holding no secret is missing the secret',
		[ $domain => 'https://front-end.test' ],
		[ 'secret' ],
	],
	[
		'a site holding no domain is missing the domain',
		[ $secret => 's3cret' ],
		[ 'domain' ],
	],
	[
		'a site holding neither is missing both',
		[],
		[ 'domain', 'secret' ],
	],

	// A row that exists but holds nothing means what an absent row means:
	// an operator who cleared the field has not configured the site.
	[
		'a site holding an empty domain is missing the domain',
		[ $domain => '', $secret => 's3cret' ],
		[ 'domain' ],
	],
	[
		'a site holding an empty secret is missing the secret',
		[ $domain => 'https://front-end.test', $secret => '' ],
		[ 'secret' ],
	],

	// #65: a site can hold a row stored as false. A read yields the setting's
	// own type, so the pair sees `''` rather than a boolean.
	[
		'a site holding a false domain is missing the domain',
		[ $domain => false, $secret => 's3cret' ],
		[ 'domain' ],
	],
	[
		'a site holding a false secret is missing the secret',
		[ $domain => 'https://front-end.test', $secret => false ],
		[ 'secret' ],
	],

	// A field holding nothing but whitespace is a field nobody filled in, and
	// `endpoint_url()` trims before composing — so reporting such a site as
	// configured would leave it unable to address its front-end, silently.
	[
		'a site holding a whitespace-only domain is missing the domain',
		[ $domain => '   ', $secret => 's3cret' ],
		[ 'domain' ],
	],
	[
		'a site holding a whitespace-only secret is missing the secret',
		[ $domain => 'https://front-end.test', $secret => "\t\n" ],
		[ 'secret' ],
	],

	// The order the two are named in does not depend on which is present.
	[
		'the missing settings are named domain first, secret second',
		[ $domain => '', $secret => '' ],
		[ 'domain', 'secret' ],
	],

	// A consequence of testing the values with `empty()`, recorded rather than
	// worked around: neither a revalidate domain nor a secret can meaningfully be
	// the string "0", so reading one as absent costs nothing.
	[
		'a secret of "0" reads as missing',
		[ $domain => 'https://front-end.test', $secret => '0' ],
		[ 'secret' ],
	],
];

$failures = 0;
foreach ( $cases as [ $description, $options, $expected ] ) {
	$GLOBALS['njr_test_options'] = $options;

	$missing = $settings->missing_settings();

	if ( $missing === $expected ) {
		printf( "ok   — %s\n", $description );
	}
	else {
		$failures++;
		printf( "FAIL — %s (expected %s, got %s)\n", $description, json_encode( $expected ), json_encode( $missing ) );
	}

	// `is_configured()` is defined in terms of the pair above, and the two can
	// never disagree: a site is configured exactly when nothing is missing.
	$expected_configured = ( $expected === [] );
	$actual_configured   = $settings->is_configured();

	if ( $actual_configured === $expected_configured ) {
		printf( "ok   — %s, and is_configured() agrees\n", $description );
	}
	else {
		$failures++;
		printf(
			"FAIL — %s, but is_configured() disagrees (expected %s, got %s)\n",
			$description,
			var_export( $expected_configured, true ),
			var_export( $actual_configured, true )
		);
	}
}

// The refusal itself
// ====
//
// Raised from two guards — `RevalidateQueue::add_item()` at enqueue time and
// `Revalidate::purge()` at delivery time — and declared here so they cannot
// drift apart. The *code* is the contract: `RestApi::process_items` reports it
// per item, and the queue drain branches on it to write ⛔ rather than ❌.

$refusal = $settings->not_configured_error();

foreach ( [
	[ $refusal instanceof WP_Error, 'an unconfigured site is refused with a WP_Error' ],
	[ 'not_configured' === $refusal->get_error_code(), 'the refusal is coded `not_configured`, which is what callers branch on' ],
	[ '' !== $refusal->get_error_message(), 'the refusal carries a message for whoever has to act on it' ],
	[ false !== strpos( $refusal->get_error_message(), 'secret' ), 'the message names the secret as one of the two things missing' ],
] as [ $condition, $description ] ) {
	if ( $condition ) {
		printf( "ok   — %s\n", $description );
	}
	else {
		$failures++;
		printf( "FAIL — %s\n", $description );
	}
}

// A setting read through `empty()` or `isset()` means what a read of it means.
// ====
//
// PHP routes both to `__isset()` rather than `__get()`, so a Settings without
// one answers "empty" for every setting on a fully configured site — silently,
// and only for code written the obvious way. It cost a debugging session on
// #29's own migration guard, which is why it is pinned here rather than merely
// worked around by reading into a local, as the cases above still do.
$GLOBALS['njr_test_options'] = [ $domain => 'https://front-end.test', $secret => 's3cret' ];

$empty_checks = [
	[ 'domain', false ],
	[ 'secret', false ],
	// A setting the site holds no row for really is empty.
	[ 'endpoint_path', true ],
];

foreach ( $empty_checks as [ $name, $expected_empty ] ) {
	$actual_empty = empty( $settings->$name );

	if ( $actual_empty === $expected_empty ) {
		printf( "ok   — empty(\$settings->%s) is %s, as a read of it is\n", $name, var_export( $expected_empty, true ) );
	}
	else {
		$failures++;
		printf( "FAIL — empty(\$settings->%s) is %s, but a read of it answers %s\n", $name, var_export( $actual_empty, true ), json_encode( $settings->$name ) );
	}
}

// Nothing outside the settings declaration is a setting, however it is asked.
if ( ! isset( $settings->not_a_setting ) ) {
	printf( "ok   — a name that is not a setting is not set\n" );
}
else {
	$failures++;
	printf( "FAIL — a name that is not a setting reads as set\n" );
}

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
