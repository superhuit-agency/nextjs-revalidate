<?php
/**
 * Redirection's database layout — which files the integration suite requires, per release.
 *
 * The integration suite creates Redirection's tables in its own bootstrap, which
 * means naming files inside another plugin. Those names went stale: Redirection
 * 5.10.0 moved the database layer from `database/` to `includes/database/` and
 * put it under a `Redirection\Database\` namespace, and the bootstrap's
 * `require` of the old path killed every run on a fresh wp-env install before
 * the first test (#115).
 *
 * `njr_redirection_database_layout()` is the lookup that replaced it, and this
 * pins it. It needs no WordPress, no database and no Redirection — only
 * directories with the right files in them — so it is a standalone script per
 * ADR 0008, and runs in the gate. That is the point of it being a function at
 * all: the bootstrap it came out of runs only inside Docker, so nothing an
 * unattended agent can run would have noticed this logic breaking.
 *
 * What it cannot catch is the failure that caused #115 — upstream moving the
 * files a third time. Nothing here can; the fixtures are ours, not Redirection's.
 * What it holds is that both layouts still resolve, that the older one's
 * requires stay in dependency order, and that an unknown layout answers null so
 * the bootstrap can say so rather than carry on without tables.
 *
 * Run with `npm run test:php`, or `php tests/redirection-database-layout-test.php`.
 */

require_once dirname( __DIR__ ) . '/tests/integration/redirection-database.php';

$failures = 0;

/**
 * Create a file, and the directories above it, under a root.
 *
 * @param string $root absolute path of the fixture root
 * @param string $relative path of the file within it
 * @return void
 */
function njr_fixture_file( string $root, string $relative ): void {
	$path = "$root/$relative";

	if ( ! is_dir( dirname( $path ) ) ) {
		mkdir( dirname( $path ), 0777, true );
	}

	file_put_contents( $path, "<?php\n" );
}

/**
 * A fixture directory holding the files a Redirection release ships.
 *
 * @param string $name what this fixture stands for, used in its path
 * @param string[] $files paths relative to the plugin directory
 * @return string absolute path of the fixture's plugin directory
 */
function njr_fixture_plugin( string $name, array $files ): string {
	$root = sys_get_temp_dir() . '/njr-redirection-layout-' . getmypid() . "/$name/redirection";

	foreach ( $files as $file ) {
		njr_fixture_file( $root, $file );
	}

	if ( ! is_dir( $root ) ) {
		mkdir( $root, 0777, true );
	}

	return $root;
}

/**
 * Remove a directory and everything under it.
 *
 * @param string $path
 * @return void
 */
function njr_rmtree( string $path ): void {
	if ( ! is_dir( $path ) ) {
		return;
	}

	foreach ( scandir( $path ) ?: [] as $entry ) {
		if ( '.' === $entry || '..' === $entry ) continue;

		$child = "$path/$entry";

		is_dir( $child ) ? njr_rmtree( $child ) : unlink( $child );
	}

	rmdir( $path );
}

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

// The subject
// ====

// The files each layout is recognised by, alongside enough of its neighbours
// that a lookup keying on the wrong one would be visible here.
$njr_5_10 = [
	'redirection.php',
	'includes/database/class-database.php',
	'includes/database/class-status.php',
	'includes/database/class-upgrade.php',
	'includes/database/class-upgrader.php',
	'includes/database/schema/class-latest.php',
];

$njr_5_9 = [
	'redirection.php',
	'database/database.php',
	'database/database-status.php',
	'database/database-upgrade.php',
	'database/database-upgrader.php',
	'database/schema/latest.php',
];

// The expectations
// ====

// 5.10.0 and later: named, not required. Everything under `Redirection\` is
// autoloaded by `redirection.php` itself.
$dir    = njr_fixture_plugin( '5.10.0', $njr_5_10 );
$layout = njr_redirection_database_layout( $dir );

njr_expect( '5.10.0 resolves to the namespaced database class', 'Redirection\\Database\\Database', $layout['database_class'] ?? null );
njr_expect( '5.10.0 requires nothing by hand', [], $layout['requires'] ?? null );

// Up to 5.9.0: four files, dependencies before the class that uses them,
// absolute so the bootstrap can require them from anywhere.
$dir    = njr_fixture_plugin( '5.9.0', $njr_5_9 );
$layout = njr_redirection_database_layout( $dir );

njr_expect( '5.9.0 resolves to the unnamespaced database class', 'Red_Database', $layout['database_class'] ?? null );
njr_expect(
	'5.9.0 requires its four database files, dependencies first',
	[
		"$dir/database/database-status.php",
		"$dir/database/database-upgrade.php",
		"$dir/database/database-upgrader.php",
		"$dir/database/database.php",
	],
	$layout['requires'] ?? null
);

// A trailing slash is the caller's, not a layout: the bootstrap passes
// `dirname()`, which has none, and a caller that appends one gets the same
// paths rather than a doubled slash.
$layout = njr_redirection_database_layout( "$dir/" );

njr_expect(
	'a trailing slash on the directory changes nothing',
	[
		"$dir/database/database-status.php",
		"$dir/database/database-upgrade.php",
		"$dir/database/database-upgrader.php",
		"$dir/database/database.php",
	],
	$layout['requires'] ?? null
);

// Both, as a release that keeps the old files as shims would ship, or a
// half-finished update leaves on disk. The namespaced layout is the current one
// and wins; requiring 5.9.0's files alongside it would declare classes the
// autoloaded ones do not use.
$dir    = njr_fixture_plugin( 'both', array_merge( $njr_5_9, $njr_5_10 ) );
$layout = njr_redirection_database_layout( $dir );

njr_expect( 'the namespaced layout wins when both are present', 'Redirection\\Database\\Database', $layout['database_class'] ?? null );

// Neither. A Redirection whose database layer has moved again, which the
// bootstrap reports rather than running tests without tables.
$dir = njr_fixture_plugin( 'moved-again', [ 'redirection.php', 'includes/db/class-database.php' ] );

njr_expect( 'an unknown layout resolves to null', null, njr_redirection_database_layout( $dir ) );

// A directory with nothing in it at all — an install interrupted mid-download
// answers the same way, rather than by a file_exists() on a path below it.
$dir = njr_fixture_plugin( 'empty', [] );

njr_expect( 'an empty plugin directory resolves to null', null, njr_redirection_database_layout( $dir ) );

njr_rmtree( sys_get_temp_dir() . '/njr-redirection-layout-' . getmypid() );

printf( "\n%d failure(s)\n", $failures );
exit( $failures === 0 ? 0 : 1 );
