<?php
/**
 * The WordPress floor — what `Requires at least` declares, in every place that
 * declares it, and the hooks the analysis cannot hold to it.
 *
 * #122: the header said `5.0.0` while the hook the headline feature hangs on,
 * `wp_after_insert_post`, has existed only since 5.6.0. A site on 5.2–5.5 was
 * told the plugin was compatible, installed it, configured a domain and a
 * secret, and got a plugin that activates, renders every admin surface, answers
 * its REST routes, purges all on demand — and revalidates nothing when a post is
 * saved. The failure is silent by construction: a hook that does not exist does
 * not fire, and nothing anywhere is in a position to notice.
 *
 * The header is not documentation. WordPress.org reads it to decide which sites
 * are offered the plugin, and core reads it to decide whether the plugin may be
 * activated at all (`validate_plugin_requirements()`, since WordPress 5.2.0), so
 * the number being wrong is the whole of the bug. ADR 0027 settled it at 5.6.
 *
 * `npm run analyse:php` holds every core function, method and class the plugin
 * calls to that floor (ADR 0028). This holds the rest:
 *
 * 1. The four places that state the floor agree: the plugin header, which core
 *    reads; `readme.txt`, which WordPress.org reads; README.md, which a person
 *    reads; and `wordpressFloor` in phpstan.neon, which the analysis reads.
 *    A floor moved in three of them is a gate enforcing the wrong number.
 * 2. `Tested up to` agrees between the two files that declare it, and is not
 *    below the floor. They disagreed before — `6.2` against `6.1` — which is
 *    what a header nothing reads back looks like.
 * 3. The floor is written `MAJOR.MINOR`. Core compares with
 *    `version_compare( $wp_version, $required, '>=' )`, and `$wp_version` on a
 *    WordPress 5.6 install is the string `5.6` — so a `5.6.0` header excludes
 *    the release it names on the cores that site runs. Asserted by running that
 *    comparison against the release the floor names, so the failure says why.
 * 4. Every hook that sets a floor of its own is still registered the way that
 *    needs it, and none is above the floor. Hooks are the half the analysis
 *    cannot see — the stubs carry no `do_action()` to read a `@since` from — so
 *    they are listed here by hand. Reaching for a hook newer than the floor
 *    means adding it below and raising the floor.
 *
 * A standalone script per ADR 0008 — it reads the declaring files and the
 * analysed source, and needs no WordPress, no autoloader and no framework, so it
 * runs in the gate rather than beside it.
 *
 * Run with `npm run test:php`, or `php tests/wordpress-floor-test.php`.
 */

$root     = dirname( __DIR__ );
$failures = 0;

/**
 * The hooks that set a floor, newest first: the release each arrived in, and
 * the number of arguments the plugin's callback accepts — `deleted_post` only
 * passes its second, the post, since 5.5.0.
 */
const NJR_FLOOR_HOOKS = [
	'wp_after_insert_post' => [ 'since' => '5.6', 'args' => 1 ],
	'deleted_post'         => [ 'since' => '5.5', 'args' => 2 ],
	'wp_initialize_site'   => [ 'since' => '5.1', 'args' => 1 ],
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

/** `a says x, b says y` — what a disagreement prints. */
function njr_each_says( array $values ): string {
	$said = [];
	foreach ( $values as $file => $value ) $said[] = "$file says $value";

	return implode( ', ', $said );
}

/**
 * Every `add_action()` / `add_filter()` in a PHP file, as the hook name and the
 * number of arguments its callback accepts. Read from tokens, so a hook named
 * in a comment is not a hook registered.
 *
 * @return array<int, array{string, int}>
 */
function njr_registered_hooks( string $contents ): array {
	$tokens = array_values( array_filter( token_get_all( $contents ), function ( $token ) {
		return ! is_array( $token ) || ! in_array( $token[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true );
	} ) );
	$text  = function ( $token ) { return is_array( $token ) ? $token[1] : $token; };
	$hooks = [];

	foreach ( $tokens as $i => $token ) {
		if ( ! in_array( ltrim( $text( $token ), '\\' ), [ 'add_action', 'add_filter' ], true ) ) continue;
		if ( '(' !== $text( $tokens[ $i + 1 ] ?? '' ) ) continue;

		// The arguments, split on the commas at the call's own depth.
		$arguments = [ '' ];
		$depth     = 0;
		for ( $j = $i + 2; $j < count( $tokens ); $j++ ) {
			$piece = $text( $tokens[ $j ] );

			if ( in_array( $piece, [ ')', ']', '}' ], true ) ) {
				if ( 0 === $depth ) break;
				$depth--;
			}
			if ( in_array( $piece, [ '(', '[', '{' ], true ) ) $depth++;
			if ( ',' === $piece && 0 === $depth ) {
				$arguments[] = '';
				continue;
			}

			$arguments[ count( $arguments ) - 1 ] .= $piece;
		}

		if ( ! preg_match( '/^([\'"])([^\'"]+)\1$/', $arguments[0], $name ) ) continue;

		$hooks[] = [ $name[2], isset( $arguments[3] ) ? (int) $arguments[3] : 1 ];
	}

	return $hooks;
}

/** Every PHP file the analysis covers — `include/`, at any depth, plus the plugin file. */
function njr_analysed_files( string $root ): array {
	$files = [ "$root/nextjs-revalidate.php" ];

	if ( is_dir( "$root/include" ) ) {
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( "$root/include" ) ) as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) $files[] = $file->getPathname();
		}
	}

	return $files;
}

// The subject
// ====

/**
 * Where the floor is stated, and the pattern that reads it out. The plugin
 * header comes first: it is what core enforces, so it is what the others are
 * measured against.
 */
$floor_patterns = [
	'nextjs-revalidate.php' => '/^[ \t]*\*[ \t]*Requires at least:[ \t]*(\S+)[ \t]*$/m',
	'readme.txt'            => '/^Requires at least:[ \t]*(\S+)[ \t]*$/m',
	'README.md'             => '/^-\s+Requires WordPress\s+(\S+?)\+\s*$/m',
	'phpstan.neon'          => '/^\s*wordpressFloor:\s*\'([^\']+)\'\s*$/m',
];

/** The two that also carry a `Tested up to`. */
$tested_in = [ 'nextjs-revalidate.php', 'readme.txt' ];

$floors = [];
$tested = [];

foreach ( $floor_patterns as $file => $pattern ) {
	$contents = @file_get_contents( "$root/$file" );

	if ( false === $contents ) {
		$failures++;
		printf( "FAIL — %s cannot be read; the floor it states is unverifiable\n", $file );
		continue;
	}

	if ( preg_match( $pattern, $contents, $matches ) ) {
		$floors[ $file ] = $matches[1];
	} else {
		$failures++;
		printf( "FAIL — %s states no WordPress floor\n", $file );
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

if ( ! isset( $floors['nextjs-revalidate.php'] ) ) {
	printf( "FAIL — without the plugin header's floor there is nothing to hold the rest to\n\n%d failure(s)\n", $failures + 1 );
	exit( 1 );
}

$floor = $floors['nextjs-revalidate.php'];

// The expectations
// ====

// 1. Every place that states a floor states the header's.
if ( count( array_unique( $floors ) ) > 1 ) {
	$failures++;
	printf( "FAIL — the stated floors disagree: %s. Moving the floor is one edit in each (ADR 0027).\n", njr_each_says( $floors ) );
} else {
	printf( "ok   — all %d places stating a floor say %s\n", count( $floors ), $floor );
}

// 2. `Tested up to` says one number, at or above the floor.
if ( count( array_unique( $tested ) ) > 1 ) {
	$failures++;
	printf( "FAIL — `Tested up to` disagrees between files: %s\n", njr_each_says( $tested ) );
} elseif ( $tested ) {
	printf( "ok   — both files declaring one are tested up to %s\n", reset( $tested ) );
}

foreach ( $tested as $file => $value ) {
	if ( version_compare( $value, $floor, '<' ) ) {
		$failures++;
		printf( "FAIL — %s is tested up to %s, below the %s floor\n", $file, $value, $floor );
		continue;
	}

	printf( "ok   — %s is tested up to %s, at or above the floor\n", $file, $value );
}

// 3. `MAJOR.MINOR`, because a third part excludes the release it names.
foreach ( array_unique( $floors ) as $stated ) {
	// What `$wp_version` is on the release the floor names: `5.6`, never `5.6.0`.
	$release = implode( '.', array_slice( explode( '.', $stated ), 0, 2 ) );

	if ( ! version_compare( $release, $stated, '>=' ) ) {
		$failures++;
		printf(
			"FAIL — WordPress %s does not satisfy a floor of %s. Core compares with `version_compare( \$wp_version, \$required, '>=' )` and `\$wp_version` carries no third part, so `%s` locks the plugin out of the very release it claims. Write the floor as MAJOR.MINOR.\n",
			$release,
			$stated,
			$stated
		);
		continue;
	}

	printf( "ok   — WordPress %s itself satisfies a floor of %s\n", $release, $stated );
}

// 4. Every hook that sets a floor is registered the way that needs it, and none is above it.
$registered = [];

foreach ( njr_analysed_files( $root ) as $path ) {
	$contents = @file_get_contents( $path );

	if ( false === $contents ) {
		$failures++;
		printf( "FAIL — %s cannot be read; the hooks it registers are unverifiable\n", $path );
		continue;
	}

	foreach ( njr_registered_hooks( $contents ) as [ $hook, $args ] ) {
		$registered[ $hook ] = max( $args, $registered[ $hook ] ?? 0 );
	}
}

foreach ( NJR_FLOOR_HOOKS as $hook => $needs ) {
	if ( version_compare( $needs['since'], $floor, '>' ) ) {
		$failures++;
		printf( "FAIL — %s needs WordPress %s, above the %s floor. Raise the floor (ADR 0027).\n", $hook, $needs['since'], $floor );
		continue;
	}

	if ( ( $registered[ $hook ] ?? 0 ) < $needs['args'] ) {
		$failures++;
		printf(
			"FAIL — %s is listed as needing WordPress %s%s, and nothing registers it that way any more. Re-run ADR 0027's sweep: the floor may now be lower.\n",
			$hook,
			$needs['since'],
			$needs['args'] > 1 ? " with {$needs['args']} arguments" : ''
		);
		continue;
	}

	printf( "ok   — %s (WordPress %s) is still registered, at or below the floor\n", $hook, $needs['since'] );
}

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
