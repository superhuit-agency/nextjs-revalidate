<?php
/**
 * The analysis pins its own memory limit — package.json's `analyse:php`.
 *
 * #96: the script used to inherit whatever the developer's `php.ini` said, and
 * on a stock 128M it does not report findings — it dies in a parallel worker
 * parsing the WordPress stubs, and what comes back is a crash notice or a bare
 * exit code rather than a verdict about the code. The reason it survived so
 * long is the result cache: once one run has completed, every later run reads
 * the cache instead of re-parsing and passes on 128M, so the failure only
 * reproduces behind `vendor/bin/phpstan clear-result-cache`. A regression here
 * is invisible to whoever introduces it and lands on whoever next analyses a
 * cold tree. That is what this holds.
 *
 * It asserts the flag is there and that the number is a finite one at or above
 * the 2G ADR 0020 settled on. The measured need is 896M green, 832M red on PHP
 * 7.4 against php-stubs/wordpress-stubs v6.9.4, but the floor here is the
 * decision rather than that measurement: a limit chosen to just clear today's
 * parse is one that brings #96 back on a routine `composer update`, because
 * that stub file grows with every WordPress release. `--memory-limit=-1` fails
 * here too: an unlimited gate cannot stop an analysis that genuinely runs away.
 * Where the script passes the flag more than once, the last one is what PHPStan
 * uses, so that is the one read. See ADR 0020.
 *
 * A standalone script per ADR 0008 — it reads one file and needs no WordPress,
 * no autoloader and no framework, so it runs in the gate rather than beside it.
 *
 * Run with `npm run test:php`, or `php tests/analyse-memory-limit-test.php`.
 */

$root     = dirname( __DIR__ );
$failures = 0;

/** The limit ADR 0020 pinned. Below this there is no margin over a growing stub file. */
const NJR_MEMORY_LIMIT_FLOOR = 2147483648; // 2G

/**
 * A PHP shorthand byte value — `2G`, `512M`, `1024` — as bytes, or null when it
 * is not one. `-1` is not: it is the absence of a limit, which is the thing
 * this test is against.
 */
function njr_shorthand_bytes( string $value ): ?int {
	if ( ! preg_match( '/^(\d+)([KMG]?)$/i', $value, $matches ) ) return null;

	$bytes  = (int) $matches[1];
	$suffix = strtoupper( $matches[2] );

	if ( 'K' === $suffix ) return $bytes * 1024;
	if ( 'M' === $suffix ) return $bytes * 1024 * 1024;
	if ( 'G' === $suffix ) return $bytes * 1024 * 1024 * 1024;

	return $bytes;
}

// The subject
// ====

$manifest = @file_get_contents( "$root/package.json" );

if ( false === $manifest ) {
	fwrite( STDERR, "FAIL — $root/package.json cannot be read; there is no script to check\n" );
	exit( 1 );
}

$package = json_decode( $manifest, true );
$script  = isset( $package['scripts']['analyse:php'] ) ? $package['scripts']['analyse:php'] : null;

if ( ! is_string( $script ) ) {
	fwrite( STDERR, "FAIL — package.json declares no `analyse:php` script\n" );
	exit( 1 );
}

// The expectations
// ====

if ( ! preg_match_all( '/--memory-limit=(\S+)/', $script, $matches ) ) {
	$failures++;
	printf(
		"FAIL — `analyse:php` passes no --memory-limit, so the analysis inherits php.ini and fatals in a parallel worker on the 128M default (#96)\n"
	);
}
else {
	// The last occurrence is the one PHPStan honours, so it is the one that counts.
	$pinned = end( $matches[1] );
	$limit  = njr_shorthand_bytes( $pinned );

	if ( null === $limit ) {
		$failures++;
		printf(
			"FAIL — `analyse:php` passes --memory-limit=%s, which is not a finite byte size; an unlimited gate cannot stop a runaway analysis\n",
			$pinned
		);
	}
	elseif ( $limit < NJR_MEMORY_LIMIT_FLOOR ) {
		$failures++;
		printf(
			"FAIL — `analyse:php` passes --memory-limit=%s; the analysis needs more than 832M on a cold result cache and the stubs grow with every WordPress release, so ADR 0020 pinned 2G and anything under it is on its way back to #96\n",
			$pinned
		);
	}
	else {
		printf( "ok   — `analyse:php` pins --memory-limit=%s\n", $pinned );
	}
}

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
