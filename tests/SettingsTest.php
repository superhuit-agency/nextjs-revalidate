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

function add_option( $name, $value = '' ) {
	if ( array_key_exists( $name, $GLOBALS['njr_test_options'] ) ) return false;

	$GLOBALS['njr_test_options'][ $name ] = $value;
	return true;
}

function update_option( $name, $value ) {
	$GLOBALS['njr_test_options'][ $name ] = $value;
	return true;
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

// The gate a new install starts with, and an existing site does not.
// ====
//
// #30 — an FSE change invalidates the front-end's snapshot, but only on a site
// that says so in as many words. The empty value reads as off like every other
// setting's, because a site upgrading into this release and a site that has
// just switched the gate off store exactly the same row — and of those two, the
// one that must not start making requests is the site whose front-end may not
// serve the endpoint at all. `define_settings()` is what puts the `on` in a new
// install's row; see the seeding cases below.

$fse_save = NextJsRevalidate\Settings::SETTINGS_REVALIDATE_ON_FSE_SAVE;

foreach ( [
	[ 'a site holding no row does not invalidate the snapshot', [],                        false ],
	[ 'a site holding the empty value does not either',         [ $fse_save => '' ],       false ],
	[ 'a row stored as false is an absent row',                 [ $fse_save => false ],    false ],
	[ 'a site seeded on at setup invalidates the snapshot',     [ $fse_save => 'on' ],     true  ],
	[ 'a site that switched it off does not',                   [ $fse_save => 'off' ],    false ],
	// The switch has exactly two positions, and only one of them is written.
	[ 'whitespace is a field nobody filled in',                 [ $fse_save => '  ' ],     false ],
	[ 'a value nothing writes is not an on switch',             [ $fse_save => 'banana' ], false ],
] as [ $description, $options, $expected ] ) {
	$GLOBALS['njr_test_options'] = $options;

	$actual = $settings->revalidates_on_fse_save();

	if ( $actual === $expected ) {
		printf( "ok   — %s\n", $description );
	}
	else {
		$failures++;
		printf( "FAIL — %s (expected %s, got %s)\n", $description, var_export( $expected, true ), var_export( $actual, true ) );
	}
}

// Which site starts with the gate on.
// ====
//
// #30 — the decision is taken once, at setup, on evidence about the site: a
// site holding none of this plugin's rows has never run it and is seeded `on`;
// a site reached by an upgrade, a reactivation or a network sweep holds rows
// already and keeps the empty value, which reads as off. A version gate could
// not tell those apart — every site predating the ledger is backfilled to the
// release that introduces it — and neither could the stored value, because the
// upgraded site and the site that has just switched the gate off store the
// same empty row.

foreach ( [
	[
		'a new install is seeded with the gate on',
		[],
		true,
	],
	[
		'a site set up by an earlier release keeps the gate off',
		[ NextJsRevalidate\Settings::SETTINGS_DOMAIN_NAME => 'https://front-end.test' ],
		false,
	],
	[
		'a site holding nothing but an empty row keeps the gate off',
		[ NextJsRevalidate\Settings::SETTINGS_SECRET_NAME => '' ],
		false,
	],
	[
		'a 1.6.x site holding only the legacy URL keeps the gate off',
		[ NextJsRevalidate\Settings::LEGACY_URL_OPTION_NAME => 'https://front-end.test/api/revalidate' ],
		false,
	],
	[
		'a site holding only the migration ledger keeps the gate off',
		[ NextJsRevalidate\Settings::DB_VERSION_OPTION_NAME => '1.6.0' ],
		false,
	],
	[
		'setting a site up twice does not seed it again',
		[],
		false,
		'twice',
	],
	[
		'setting up an operator who switched it on leaves the row alone',
		[ $fse_save => 'on' ],
		true,
	],
] as $case ) {
	[ $description, $options, $expected ] = $case;

	$GLOBALS['njr_test_options'] = $options;

	$settings->define_settings();

	// The second setup is the reactivation: the rows the first one created are
	// exactly the evidence that says this site is not new any more.
	if ( isset($case[3]) ) {
		$GLOBALS['njr_test_options'][ $fse_save ] = '';
		$settings->define_settings();
	}

	$actual = $settings->revalidates_on_fse_save();

	if ( $actual === $expected ) {
		printf( "ok   — %s\n", $description );
	}
	else {
		$failures++;
		printf( "FAIL — %s (expected %s, got %s)\n", $description, var_export( $expected, true ), var_export( $actual, true ) );
	}
}

$GLOBALS['njr_test_options'] = [ $domain => 'https://front-end.test', $secret => 's3cret' ];

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
