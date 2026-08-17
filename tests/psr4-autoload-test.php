<?php
/**
 * PSR-4 autoloading — composer.json's `autoload.psr-4` against what include/ declares.
 *
 * The plugin has no test framework and no WordPress to boot, so this is a
 * standalone script. It needs no autoloader either, and deliberately so: it
 * reads the mapping out of composer.json and resolves it by hand, the way
 * Composer's ClassLoader does, so it tests the rule rather than the generated
 * vendor/ that happens to be on disk.
 *
 * What it holds: every type under include/ is reachable through PSR-4 alone.
 * PSR-4 matches the namespace prefix *and* the path case-sensitively, so both
 * halves of #73 fail here — a prefix spelled `NextjsRevalidate\` and a directory
 * spelled `traits/` under a `Traits` namespace segment. Neither is visible at
 * runtime while a classmap is also generated, which is why this asserts on the
 * mapping instead of on `class_exists()`.
 *
 * Run with `npm run test:php`, or `php tests/psr4-autoload-test.php`.
 */

$root     = dirname( __DIR__ );
$failures = 0;

/**
 * Read the name that follows a `namespace`, `class`, `interface` or `trait`
 * keyword, or '' when none does — `Foo::class`, or an anonymous class.
 *
 * PHP 8 lumps a qualified name into one T_NAME_QUALIFIED token where 7.4 emits
 * a run of T_STRING and T_NS_SEPARATOR, and this runs on both.
 *
 * @param array $tokens token_get_all() output
 * @param int   $i      index of the keyword token
 * @return string
 */
function njr_read_name( array $tokens, int $i ): string {
	$parts = [];

	for ( $j = $i + 1; $j < count( $tokens ); $j++ ) {
		$token = $tokens[ $j ];

		if ( is_array( $token ) && T_WHITESPACE === $token[0] ) {
			if ( $parts ) break;
			continue;
		}
		if ( ! is_array( $token ) ) break;

		$is_name = T_STRING === $token[0]
			|| T_NS_SEPARATOR === $token[0]
			|| ( defined( 'T_NAME_QUALIFIED' ) && T_NAME_QUALIFIED === $token[0] );

		if ( ! $is_name ) break;

		$parts[] = $token[1];
	}

	return implode( '', $parts );
}

/**
 * The fully qualified name of the first type a file declares, or null.
 *
 * @param string $path
 * @return string|null
 */
function njr_declared_type( string $path ): ?string {
	$tokens    = token_get_all( (string) file_get_contents( $path ) );
	$namespace = '';
	$previous  = null;

	for ( $i = 0; $i < count( $tokens ); $i++ ) {
		$token = $tokens[ $i ];

		if ( ! is_array( $token ) ) {
			$previous = $token;
			continue;
		}
		if ( T_WHITESPACE === $token[0] || T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) {
			continue;
		}

		if ( T_NAMESPACE === $token[0] ) {
			$namespace = njr_read_name( $tokens, $i );
		}
		elseif ( in_array( $token[0], [ T_CLASS, T_INTERFACE, T_TRAIT ], true ) ) {
			// `Foo::class` is a T_CLASS as well; the `::` before it is the tell.
			$is_declaration = ! ( is_array( $previous ) && T_DOUBLE_COLON === $previous[0] );
			$name           = $is_declaration ? njr_read_name( $tokens, $i ) : '';

			if ( '' !== $name ) {
				return '' === $namespace ? $name : "$namespace\\$name";
			}
		}

		$previous = $token;
	}

	return null;
}

/**
 * Every .php file under a directory, as paths relative to the repo root, with
 * the case the filesystem actually holds.
 *
 * Built from the directory entries rather than from a glob or a file_exists()
 * probe on a computed path: a case-insensitive filesystem answers yes to
 * `include/traits/` either way, and the mismatch this test exists for would
 * disappear on exactly the machines where it is hardest to spot.
 *
 * @param string $root repo root, absolute
 * @param string $relative directory to walk, relative to the root
 * @return string[]
 */
function njr_php_files( string $root, string $relative ): array {
	$files = [];

	foreach ( scandir( "$root/$relative" ) ?: [] as $entry ) {
		if ( '.' === $entry || '..' === $entry ) continue;

		$path = "$relative/$entry";

		if ( is_dir( "$root/$path" ) ) {
			$files = array_merge( $files, njr_php_files( $root, $path ) );
		}
		elseif ( '.php' === substr( $entry, -4 ) ) {
			$files[] = $path;
		}
	}

	sort( $files );
	return $files;
}

/**
 * Where PSR-4 says a type lives, resolved as Composer's ClassLoader resolves
 * it: longest matching prefix wins, and the match is case-sensitive.
 *
 * @param string $fqcn
 * @param array  $prefixes composer.json's autoload.psr-4, prefix => dir or dirs
 * @return string[] candidate paths relative to the repo root, longest prefix first
 */
function njr_psr4_paths( string $fqcn, array $prefixes ): array {
	$matched = [];

	foreach ( $prefixes as $prefix => $dirs ) {
		if ( 0 !== strncmp( $fqcn, $prefix, strlen( $prefix ) ) ) continue;
		$matched[ $prefix ] = (array) $dirs;
	}

	uksort( $matched, function( $a, $b ) { return strlen( $b ) - strlen( $a ); } );

	$paths = [];
	foreach ( $matched as $prefix => $dirs ) {
		$tail = str_replace( '\\', '/', substr( $fqcn, strlen( $prefix ) ) ) . '.php';
		foreach ( $dirs as $dir ) {
			$paths[] = rtrim( $dir, '/' ) . "/$tail";
		}
	}

	return $paths;
}

// The subject
// ====

$composer = json_decode( (string) file_get_contents( "$root/composer.json" ), true );
$prefixes = $composer['autoload']['psr-4'] ?? [];

// The expectations
// ====

foreach ( njr_php_files( $root, 'include' ) as $file ) {
	$fqcn = njr_declared_type( "$root/$file" );

	if ( null === $fqcn ) {
		$failures++;
		printf( "FAIL — %s declares no class, interface or trait for PSR-4 to resolve\n", $file );
		continue;
	}

	$candidates = njr_psr4_paths( $fqcn, $prefixes );

	if ( in_array( $file, $candidates, true ) ) {
		printf( "ok   — %s resolves to %s\n", $fqcn, $file );
	}
	elseif ( ! $candidates ) {
		$failures++;
		printf( "FAIL — %s (%s) matches no PSR-4 prefix in composer.json\n", $fqcn, $file );
	}
	else {
		$failures++;
		printf(
			"FAIL — %s resolves to %s, but the file is %s\n",
			$fqcn,
			implode( ' or ', $candidates ),
			$file
		);
	}
}

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
