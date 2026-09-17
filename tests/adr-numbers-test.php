<?php
/**
 * ADR numbers — every number in docs/adr/ names exactly one document.
 *
 * The number is the identity of a decision in this repo: cross-references are
 * bare — `ADR 0004`, `ADR-0008`, `(ADR 0015)` — with no title alongside them,
 * so a number held by two documents makes every reference to it resolve to
 * nothing in particular.
 *
 * It has now happened twice. #97 found three collisions and renumbered them;
 * #104, which did that renumbering, took four numbers that were free when its
 * branch was cut and landed after #30 and #36 had taken two of them (#109). The
 * shape is the same both times and does not depend on anyone being careless:
 * two branches are open at once, each takes the next free number, the filenames
 * differ, and git merges them without a conflict. Nothing looked at the numbers
 * again afterwards. This does.
 *
 * A standalone script per ADR 0008 — it needs no WordPress, no autoloader and no
 * framework, only the names of the files on disk — so it runs in the gate an
 * unattended agent is judged by, which is where a collision is cheapest to fix.
 *
 * What it cannot catch: a pull request's checks run against `main` as it was
 * when the branch was last pushed, so a collision formed by *another* merge in
 * between is invisible to that pull request. CI already runs on `push` to `main`
 * as well, which makes such a collision red on `main` straight away rather than
 * found weeks later — but red *after* the merge. Catching it before is branch
 * protection's "require branches to be up to date before merging", a repo-admin
 * setting rather than anything this repository can hold.
 *
 * Run with `npm run test:php`, or `php tests/adr-numbers-test.php`.
 */

$root      = dirname( __DIR__ );
$directory = 'docs/adr';
$failures  = 0;

// The subject
// ====

$entries = @scandir( "$root/$directory" );

if ( false === $entries ) {
	fwrite( STDERR, "FAIL — $root/$directory cannot be read; there are no ADR numbers to check\n" );
	exit( 1 );
}

/** @var array<string, string[]> number => the documents claiming it */
$claimed = [];

foreach ( $entries as $entry ) {
	if ( '.md' !== substr( $entry, -3 ) ) continue;

	// Anything else in here cannot be referred to by number, and would slip past
	// the check below rather than collide in it — so it fails on its own terms.
	if ( ! preg_match( '/^(\d{4})-/', $entry, $matches ) ) {
		$failures++;
		printf( "FAIL — %s/%s carries no four-digit ADR number\n", $directory, $entry );
		continue;
	}

	$claimed[ $matches[1] ][] = $entry;
}

ksort( $claimed );

// The expectations
// ====

foreach ( $claimed as $number => $documents ) {
	if ( 1 === count( $documents ) ) {
		printf( "ok   — %s names %s/%s\n", $number, $directory, $documents[0] );
		continue;
	}

	$failures++;
	printf(
		"FAIL — %s names %d documents: %s\n",
		$number,
		count( $documents ),
		implode( ', ', array_map( function ( $document ) use ( $directory ) {
			return "$directory/$document";
		}, $documents ) )
	);
}

if ( ! $claimed && 0 === $failures ) {
	$failures++;
	printf( "FAIL — %s holds no numbered documents; this test is checking nothing\n", $directory );
}

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
