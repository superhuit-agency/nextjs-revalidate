<?php
/**
 * The queue table's schema is one every supported database can create — issue #121.
 *
 * `RevalidateQueue::create_table()` declared `UNIQUE KEY permalink (permalink)`
 * over a `TEXT` column with no prefix length. MariaDB accepts that: from 10.4 it
 * supports a `UNIQUE` constraint over a `BLOB`/`TEXT` column by maintaining a
 * hidden hash column behind it, which is what `SHOW INDEX` on wp-env's database
 * reports as `Index_type: HASH, Sub_part: NULL`. Standard MySQL has no
 * equivalent — a `BLOB`/`TEXT` column in a key requires an explicit prefix
 * length there, and without one the statement is error 1170 — and `dbDelta()`
 * inspects nothing afterwards, so the site is left with no queue table at all
 * and every enqueue on it fails.
 *
 * The rule this holds is therefore the general one, over every key the table
 * declares rather than over the one that was wrong: **no key names a
 * `BLOB`/`TEXT` column without a prefix length**. Beneath it sits the decision
 * of ADR 0027 — the dedup the queue depends on is keyed on a fixed-width hash of
 * the permalink rather than on a *prefix* of it, because a prefix key refuses
 * two distinct permalinks that happen to share their first n characters.
 *
 * What it cannot catch is the failure itself: nothing here runs a `CREATE
 * TABLE`, on MySQL or on anything else. It reads the statement this plugin
 * composes and holds it to the rule the two engines disagree about, which is the
 * most an environment with no database can say — and the sandbox an unattended
 * agent works in has none (ADR 0006). The integration suite's `QueueSchemaTest`
 * is where the key is read back off a real table.
 *
 * A standalone script per ADR 0008 — it reads one file and needs no WordPress,
 * no autoloader and no framework, so it runs in the gate rather than beside it.
 *
 * Run with `npm run test:php`, or `php tests/queue-schema-portability-test.php`.
 */

$root     = dirname( __DIR__ );
$failures = 0;

/**
 * The column types no index can name without a prefix length.
 *
 * MySQL's rule, which MariaDB's long-unique feature is the exception to. `json`
 * is here because it cannot be indexed at all, prefix or no prefix.
 */
const NJR_UNPREFIXABLE_TYPES = [
	'tinytext', 'text', 'mediumtext', 'longtext',
	'tinyblob', 'blob', 'mediumblob', 'longblob',
	'json',
];

/**
 * @param string $label what is being asserted
 * @param mixed  $expected
 * @param mixed  $actual
 * @return void
 */
function njr_expect( string $label, $expected, $actual ): void {
	global $failures;

	if ( $expected === $actual ) {
		printf( "ok   — %s\n", $label );
		return;
	}

	$failures++;
	printf(
		"FAIL — %s\n       expected %s\n       got      %s\n",
		$label,
		json_encode( $expected ),
		json_encode( $actual )
	);
}

/**
 * The body of the `CREATE TABLE` statement the queue composes — everything
 * between its outermost parentheses, as one line per declaration.
 *
 * @param string $source the file declaring it
 * @return string[]|null the declarations, or null when no statement is found
 */
function njr_create_table_declarations( string $source ): ?array {
	// Anchored on the assignment rather than on the words, which the docblock
	// above the statement says too.
	if ( ! preg_match( '/\$sql\s*=\s*"CREATE TABLE[^(]*\((.*)\)\s*\$charset_collate/s', $source, $matches ) ) return null;

	$declarations = [];

	foreach ( explode( "\n", $matches[1] ) as $line ) {
		$line = trim( rtrim( trim( $line ), ',' ) );

		if ( '' !== $line ) $declarations[] = $line;
	}

	return $declarations;
}

/**
 * The columns a `CREATE TABLE` body declares, as name => type.
 *
 * The type is the word alone — `text`, `char`, `bigint` — with any width and any
 * `unsigned` left off, because what decides whether a column can be keyed is the
 * type and nothing else.
 *
 * @param string[] $declarations
 * @return array<string, string>
 */
function njr_columns( array $declarations ): array {
	$columns = [];

	foreach ( $declarations as $declaration ) {
		// A key declaration, not a column: PRIMARY KEY, UNIQUE KEY, KEY, INDEX.
		if ( preg_match( '/^(PRIMARY\s+KEY|UNIQUE\s+KEY|UNIQUE|KEY|INDEX)\b/i', $declaration ) ) continue;

		if ( ! preg_match( '/^`?(\w+)`?\s+([a-z]+)/i', $declaration, $matches ) ) continue;

		$columns[ $matches[1] ] = strtolower( $matches[2] );
	}

	return $columns;
}

/**
 * The keys a `CREATE TABLE` body declares.
 *
 * Each entry is the kind of key, and the columns it names — each of those a
 * name and the prefix length it carries, or null for the whole column.
 *
 * @param string[] $declarations
 * @return array<int, array{kind: string, columns: array<string, int|null>}>
 */
function njr_keys( array $declarations ): array {
	$keys = [];

	foreach ( $declarations as $declaration ) {
		if ( ! preg_match( '/^(PRIMARY\s+KEY|UNIQUE\s+KEY|UNIQUE|KEY|INDEX)\s+(?:`?\w+`?\s*)?\(([^)]*\)?[^(]*)\)\s*$/i', $declaration, $matches ) ) continue;

		$columns = [];

		foreach ( explode( ',', $matches[2] ) as $column ) {
			if ( ! preg_match( '/`?(\w+)`?\s*(?:\((\d+)\))?/', trim( $column ), $parts ) ) continue;

			$columns[ $parts[1] ] = isset( $parts[2] ) ? (int) $parts[2] : null;
		}

		$keys[] = [
			'kind'    => strtoupper( preg_replace( '/\s+/', ' ', $matches[1] ) ),
			'columns' => $columns,
		];
	}

	return $keys;
}

// The subject
// ====

$source = @file_get_contents( "$root/include/RevalidateQueue.php" );

if ( false === $source ) {
	echo "FAIL — include/RevalidateQueue.php could not be read.\n";
	exit( 1 );
}

$declarations = njr_create_table_declarations( $source );

if ( null === $declarations ) {
	echo "FAIL — no CREATE TABLE statement found in include/RevalidateQueue.php.\n"
		. "       This test reads the statement the queue composes; if it has moved or been\n"
		. "       rewritten, teach njr_create_table_declarations() where it is now.\n";
	exit( 1 );
}

$columns = njr_columns( $declarations );
$keys    = njr_keys( $declarations );

// The expectations
// ====

// The statement parsed as a schema at all. Everything below is vacuous if it
// did not: a regex that matched nothing would report no offending key either.
njr_expect(
	'the queue table declares the four columns of an entry',
	[ 'id', 'permalink', 'permalink_hash', 'priority' ],
	array_keys( $columns )
);

njr_expect( 'the queue table declares two keys', 2, count( $keys ) );

// The rule the issue is about, over every key rather than over the one that was
// wrong. A key naming an unprefixable column is a statement standard MySQL
// refuses outright, and refuses in a way nothing in this plugin looks at.
foreach ( $keys as $key ) {
	foreach ( $key['columns'] as $column => $prefix ) {
		$type = $columns[ $column ] ?? 'an undeclared column';

		njr_expect(
			sprintf( '%s (%s) names no unprefixable column: %s is %s', $key['kind'], $column, $column, $type ),
			false,
			in_array( $type, NJR_UNPREFIXABLE_TYPES, true ) && null === $prefix
		);
	}
}

// ADR 0027, and the reason the answer is not `permalink(191)`: the permalink is
// stored whole, and what is keyed is a hash of it rather than a prefix of it. A
// prefix key is portable and would pass the rule above, and it refuses two
// distinct permalinks sharing their first n characters — a page that then never
// revalidates, reported to its caller as an entry it already had.
njr_expect( 'the permalink is stored whole, in a text column', 'text', $columns['permalink'] ?? null );

$unique = array_values( array_filter( $keys, function ( $key ) { return 'UNIQUE KEY' === $key['kind']; } ) );

njr_expect( 'exactly one unique key carries the dedup', 1, count( $unique ) );

njr_expect(
	'the dedup is keyed on the hash column, whole',
	[ 'permalink_hash' => null ],
	$unique[0]['columns'] ?? null
);

// The column's width and the digest that has to fit in it, which are declared in
// two places and are the same fact. Every width in the file is read, not only
// the one in the CREATE TABLE: the migration that adds the column to an existing
// table declares it too, and a column one character short of the digest would
// truncate every hash to a common prefix — turning the unique key from a dedup
// into a refusal of everything.
if ( ! preg_match( "/PERMALINK_HASH_ALGO\s*=\s*'([^']+)'/", $source, $matches ) ) {
	echo "FAIL — no PERMALINK_HASH_ALGO constant found in include/RevalidateQueue.php.\n";
	exit( 1 );
}

$algo = $matches[1];

njr_expect(
	sprintf( "'%s' is an algorithm this PHP has", $algo ),
	true,
	in_array( $algo, hash_algos(), true )
);

$digest_length = strlen( hash( $algo, 'https://front-end.test/hello-world/' ) );

preg_match_all( '/`?permalink_hash`?\s+char\((\d+)\)/i', $source, $matches );

njr_expect(
	'every declaration of the hash column states a width',
	true,
	count( $matches[1] ) > 0
);

foreach ( $matches[1] as $index => $width ) {
	njr_expect(
		sprintf( "declaration %d of permalink_hash is as wide as a %s digest", $index + 1, $algo ),
		$digest_length,
		(int) $width
	);
}

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
