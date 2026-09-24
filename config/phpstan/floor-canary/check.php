<?php
/**
 * The canary — proof that `WordPressFloorRule` still reports what it exists to
 * report, run before the analysis that relies on it.
 *
 * The rule fails open. If it stops recognising the stubs — a new
 * `php-stubs/wordpress-stubs` moves its file, a PHPStan major changes what
 * reflection answers — it reports nothing, and an analysis that reports nothing
 * is green. That is #122's failure again, one level up: a check that has stopped
 * running looks exactly like one that passes.
 *
 * So this analyses `fixture.php` alone, with the project's own phpstan.neon —
 * the same rule, registered the same way, holding the same floor — and fails
 * unless the rule reports exactly the fixture's unguarded calls newer than
 * `wordpressFloor`, and nothing else is reported at all. Too few is a rule gone
 * blind; too many is one reporting guarded or older calls, or a fixture that
 * has stopped analysing cleanly.
 *
 * `--memory-limit=2G` for the reason ADR 0020 gives: the stubs are parsed here
 * as they are in the analysis. A run on one file neither reads nor writes the
 * result cache, so this costs the analysis after it nothing.
 *
 * Run by `npm run analyse:php`, ahead of the analysis, or on its own with
 * `php config/phpstan/floor-canary/check.php`. ADR 0030.
 */

$root     = dirname( __DIR__, 3 );
$fixture  = 'config/phpstan/floor-canary/fixture.php';
$rule     = 'nextjsRevalidate.wordpressFloor';
$failures = 0;

/** `5.6` as `5.6.0`, as the rule compares. */
function njr_full_version( string $version ): string {
	return implode( '.', array_pad( explode( '.', $version ), 3, '0' ) );
}

// The subject
// ====

$neon = @file_get_contents( "$root/phpstan.neon" );
if ( false === $neon || ! preg_match( '/^\s*wordpressFloor:\s*\'([^\']+)\'\s*$/m', $neon, $matches ) ) {
	fwrite( STDERR, "FAIL — phpstan.neon declares no `wordpressFloor`; there is no floor for the rule to hold\n" );
	exit( 1 );
}
$floor = $matches[1];

$lines = @file( "$root/$fixture" );
if ( false === $lines ) {
	fwrite( STDERR, "FAIL — $fixture cannot be read\n" );
	exit( 1 );
}

/** Line => what it calls, for every line the rule must report. */
$expected = [];
$marked   = 0;

foreach ( $lines as $index => $line ) {
	if ( ! preg_match( '/^\s*(.+?);?\s*\/\/ since (\d+\.\d+(?:\.\d+)?)(, guarded)?\s*$/', $line, $marker ) ) continue;

	$marked++;
	if ( empty( $marker[3] ) && version_compare( njr_full_version( $marker[2] ), njr_full_version( $floor ), '>' ) ) {
		$expected[ $index + 1 ] = $marker[1];
	}
}

if ( ! $expected ) {
	fwrite( STDERR, "FAIL — nothing in $fixture is newer than the $floor floor, so the canary proves nothing. Add a call to something core introduced after it.\n" );
	exit( 1 );
}

$process = proc_open(
	[ PHP_BINARY, "$root/vendor/bin/phpstan", 'analyse', '--no-progress', '--memory-limit=2G', '--error-format=json', "--configuration=$root/phpstan.neon", "$root/$fixture" ],
	[ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ],
	$pipes,
	$root
);
$stdout = stream_get_contents( $pipes[1] );
$stderr = stream_get_contents( $pipes[2] );
fclose( $pipes[1] );
fclose( $pipes[2] );
proc_close( $process );

$result = json_decode( (string) $stdout, true );

if ( ! is_array( $result ) || ! isset( $result['files'] ) ) {
	fwrite( STDERR, "FAIL — PHPStan did not answer with a report; what it said instead:\n$stdout\n$stderr\n" );
	exit( 1 );
}

// The expectations
// ====

$reported = [];

foreach ( $result['errors'] as $error ) {
	$failures++;
	printf( "FAIL — PHPStan reported an error of its own: %s\n", $error );
}

foreach ( $result['files'] as $messages ) {
	foreach ( $messages['messages'] as $message ) {
		if ( ( $message['identifier'] ?? null ) !== $rule ) {
			$failures++;
			printf( "FAIL — line %d: %s — the fixture should analyse clean but for the rule\n", $message['line'], $message['message'] );
			continue;
		}

		$reported[ $message['line'] ] = true;

		if ( ! isset( $expected[ $message['line'] ] ) ) {
			$failures++;
			printf( "FAIL — line %d is reported and should not be, at a %s floor: %s\n", $message['line'], $floor, $message['message'] );
		}
	}
}

foreach ( $expected as $line => $call ) {
	if ( isset( $reported[ $line ] ) ) {
		printf( "ok   — line %d, %s, is reported\n", $line, $call );
		continue;
	}

	$failures++;
	printf( "FAIL — line %d, %s, is newer than the %s floor and the rule did not report it. It has gone blind: the analysis after this proves nothing until it is fixed.\n", $line, $call, $floor );
}

printf( "ok   — %d of %d marked calls are at or below the floor, or guarded, and %s\n", $marked - count( $expected ), $marked, $failures ? 'checked above' : 'none is reported' );

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
