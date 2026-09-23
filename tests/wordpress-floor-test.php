<?php
/**
 * The WordPress floor — what `Requires at least` declares, in every file that
 * declares it.
 *
 * #122: the header said `5.0.0` while the hook the headline feature hangs on,
 * `wp_after_insert_post`, has existed only since 5.6.0. A site on 5.0–5.5 was
 * told the plugin was compatible, installed it, configured a domain and a
 * secret, and got a plugin that activates, renders every admin surface, answers
 * its REST routes, purges all on demand — and revalidates nothing when a post is
 * saved. The failure is silent by construction: a hook that does not exist does
 * not fire, and nothing anywhere is in a position to notice.
 *
 * The header is not documentation. WordPress.org reads it to decide which sites
 * are offered the plugin, and core reads it to decide whether the plugin may be
 * activated at all (`validate_plugin_requirements()`, since WordPress 5.2.0), so
 * the number being wrong is the whole of the bug. ADR 0027 settled it at 5.6 and
 * recorded the sweep it came from; this holds what that sweep concluded.
 *
 * Four things, in order of how quietly they rot:
 *
 * 1. The three files that state a floor agree with each other. Two of them
 *    already disagreed about `Tested up to` — `6.2` against `6.1` — which is
 *    what a header nothing reads back looks like.
 * 2. The floor is the one ADR 0027 settled on, and `Tested up to` is not below
 *    it.
 * 3. It is written `MAJOR.MINOR`. Core compares with
 *    `version_compare( $wp_version, $required, '>=' )`, and `$wp_version` on a
 *    WordPress 5.6 install is the string `5.6` — so a `5.6.0` header excludes
 *    the release it names. The old `5.0.0` carried exactly that, unnoticed
 *    behind a floor that was wrong by five releases anyway. Asserted by running
 *    that comparison against the release the floor names, rather than by
 *    matching the shape, so the failure says why.
 * 4. Every surface in ADR 0027's table is still used. A table describing calls
 *    the plugin has since dropped is one that holds the floor too high and
 *    nobody re-checks.
 *
 * What it cannot hold is the direction that matters most: a *newly added* call
 * to an API newer than the floor is invisible here. Deciding that needs core's
 * own `@since` annotations, which live in a Composer dev dependency, and this
 * runs before `composer install` in the gate and reads nothing outside the
 * repository. So ADR 0027's table is maintained by hand, and reaching for a core
 * API introduced after the floor means adding a row and raising the header.
 *
 * A standalone script per ADR 0008 — it reads the declaring files and the
 * analysed source, and needs no WordPress, no autoloader and no framework, so it
 * runs in the gate rather than beside it.
 *
 * Run with `npm run test:php`, or `php tests/wordpress-floor-test.php`.
 */

$root     = dirname( __DIR__ );
$failures = 0;

/** The floor ADR 0027 settled on: the newest core API this plugin calls. */
const NJR_WORDPRESS_FLOOR = '5.6';

/**
 * The surfaces that set it — ADR 0027's table, as something executable.
 *
 * Each is the newest thing this plugin uses from its WordPress release, and each
 * key is a literal that must still appear somewhere in the analysed source.
 */
const NJR_FLOOR_SURFACES = [
	'wp_after_insert_post' => '5.6', // action
	'deleted_post'         => '5.5', // action, in its two-argument form
	'is_taxonomy_viewable' => '5.1',
	'wp_initialize_site'   => '5.1', // action
	'is_block_editor'      => '5.0', // WP_Screen::is_block_editor()
];

/**
 * One `Key: value` header out of a plugin file or a readme, or null when the
 * file does not declare it. The leading ` * ` of a docblock header is optional,
 * which is the only difference between the two formats.
 */
function njr_header( string $contents, string $key ): ?string {
	$pattern = '/^(?:[ \t]*\*[ \t]*)?' . preg_quote( $key, '/' ) . ':[ \t]*(\S+)[ \t]*$/m';

	return preg_match( $pattern, $contents, $matches ) ? $matches[1] : null;
}

/** The newest of a set of version strings, by `version_compare` rather than by string order. */
function njr_newest( array $versions ): string {
	return array_reduce(
		$versions,
		function ( $newest, $version ) {
			return ( null === $newest || version_compare( $version, $newest, '>' ) ) ? $version : $newest;
		}
	);
}

/** Every PHP file the analysis covers — `include/`, at any depth, plus the plugin file. */
function njr_analysed_source( string $root ): string {
	$source = (string) @file_get_contents( "$root/nextjs-revalidate.php" );

	if ( ! is_dir( "$root/include" ) ) return $source;

	$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( "$root/include" ) );

	foreach ( $files as $file ) {
		if ( $file->isFile() && 'php' === $file->getExtension() ) {
			$source .= (string) @file_get_contents( $file->getPathname() );
		}
	}

	return $source;
}

// The subject
// ====

/**
 * Where the floor is stated, and how to read it out.
 *
 * `readme.txt` is the operative one — it is what WordPress.org parses — and the
 * plugin header is what core parses at activation. README.md states it in prose
 * for a human, which is exactly the copy that drifts without anyone noticing.
 */
$stating = [
	'nextjs-revalidate.php' => function ( $contents ) { return njr_header( $contents, 'Requires at least' ); },
	'readme.txt'            => function ( $contents ) { return njr_header( $contents, 'Requires at least' ); },
	'README.md'             => function ( $contents ) {
		return preg_match( '/^-\s+Requires WordPress\s+(\S+?)\+\s*$/m', $contents, $matches ) ? $matches[1] : null;
	},
];

/** The two that also carry a `Tested up to`. */
$tested_in = [ 'nextjs-revalidate.php', 'readme.txt' ];

$floors   = [];
$tested   = [];
$readable = 0;

foreach ( $stating as $file => $read ) {
	$contents = @file_get_contents( "$root/$file" );

	if ( false === $contents ) {
		$failures++;
		printf( "FAIL — %s cannot be read; the floor it states is unverifiable\n", $file );
		continue;
	}

	$readable++;
	$floor = $read( $contents );

	if ( null === $floor ) {
		$failures++;
		printf( "FAIL — %s states no WordPress floor\n", $file );
	} else {
		$floors[ $file ] = $floor;
	}

	if ( in_array( $file, $tested_in, true ) ) {
		$value = njr_header( $contents, 'Tested up to' );

		if ( null === $value ) {
			$failures++;
			printf( "FAIL — %s declares no `Tested up to`\n", $file );
		} else {
			$tested[ $file ] = $value;
		}
	}
}

if ( 0 === $readable ) {
	printf( "\n%d failure(s)\n", $failures );
	exit( 1 );
}

// The expectations
// ====

// 1. Every file that states a floor states the same one.
if ( count( array_unique( $floors ) ) > 1 ) {
	$failures++;
	printf(
		"FAIL — the stated floors disagree: %s\n",
		implode( ', ', array_map(
			function ( $file, $floor ) { return "$file says $floor"; },
			array_keys( $floors ),
			$floors
		) )
	);
} elseif ( $floors ) {
	printf( "ok   — all %d files stating a floor say %s\n", count( $floors ), reset( $floors ) );
}

// 2. It is the floor ADR 0027 settled on.
foreach ( $floors as $file => $floor ) {
	if ( NJR_WORDPRESS_FLOOR !== $floor ) {
		$failures++;
		printf(
			"FAIL — %s states %s; ADR 0027 settled the floor at %s. Moving it is a decision to take in the ADR first.\n",
			$file,
			$floor,
			NJR_WORDPRESS_FLOOR
		);
		continue;
	}

	printf( "ok   — %s states the floor ADR 0027 settled on\n", $file );
}

// …and `Tested up to` says one number, at or above it.
if ( count( array_unique( $tested ) ) > 1 ) {
	$failures++;
	printf(
		"FAIL — `Tested up to` disagrees between files: %s\n",
		implode( ', ', array_map(
			function ( $file, $value ) { return "$file says $value"; },
			array_keys( $tested ),
			$tested
		) )
	);
} elseif ( $tested ) {
	printf( "ok   — both files declaring one are tested up to %s\n", reset( $tested ) );
}

foreach ( $tested as $file => $value ) {
	if ( ! isset( $floors[ $file ] ) ) continue;

	if ( version_compare( $value, $floors[ $file ], '<' ) ) {
		$failures++;
		printf( "FAIL — %s is tested up to %s, below the %s it requires\n", $file, $value, $floors[ $file ] );
		continue;
	}

	printf( "ok   — %s is tested up to %s, at or above its floor\n", $file, $value );
}

// 3. `MAJOR.MINOR`, because a third part excludes the release it names.
foreach ( array_unique( $floors ) as $floor ) {
	// What `$wp_version` is on the release the floor names: `5.6`, never `5.6.0`.
	$release = implode( '.', array_slice( explode( '.', $floor ), 0, 2 ) );

	if ( ! version_compare( $release, $floor, '>=' ) ) {
		$failures++;
		printf(
			"FAIL — WordPress %s does not satisfy a floor of %s. Core compares with `version_compare( \$wp_version, \$required, '>=' )` and `\$wp_version` carries no third part, so `%s` locks the plugin out of the very release it claims. Write the floor as MAJOR.MINOR.\n",
			$release,
			$floor,
			$floor
		);
		continue;
	}

	printf( "ok   — WordPress %s itself satisfies a floor of %s\n", $release, $floor );
}

// 4. Every surface the floor rests on is still used, and none is above it.
$source = njr_analysed_source( $root );

if ( '' === $source ) {
	$failures++;
	printf( "FAIL — none of the analysed source could be read; the floor's surfaces are unverifiable\n" );
}

foreach ( NJR_FLOOR_SURFACES as $surface => $since ) {
	if ( version_compare( $since, NJR_WORDPRESS_FLOOR, '>' ) ) {
		$failures++;
		printf( "FAIL — %s needs WordPress %s, above the floor of %s\n", $surface, $since, NJR_WORDPRESS_FLOOR );
		continue;
	}

	if ( '' !== $source && false === strpos( $source, $surface ) ) {
		$failures++;
		printf(
			"FAIL — %s is in ADR 0027's table as what puts the floor at %s, and nothing uses it any more. Re-run the sweep: the floor may now be lower.\n",
			$surface,
			$since
		);
		continue;
	}

	printf( "ok   — %s (WordPress %s) is still used\n", $surface, $since );
}

// Nothing above notices a table that no longer reaches the floor it explains.
if ( ! NJR_FLOOR_SURFACES ) {
	$failures++;
	printf( "FAIL — no surface is recorded as setting the floor; this test is checking nothing\n" );
} elseif ( NJR_WORDPRESS_FLOOR !== njr_newest( array_values( NJR_FLOOR_SURFACES ) ) ) {
	$failures++;
	printf(
		"FAIL — the floor is %s but the newest surface recorded needs %s. The two are the same statement and have to agree.\n",
		NJR_WORDPRESS_FLOOR,
		njr_newest( array_values( NJR_FLOOR_SURFACES ) )
	);
}

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
