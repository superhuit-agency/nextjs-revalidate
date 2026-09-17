<?php
/**
 * Where Redirection keeps its database layer — across the two layouts it has had.
 *
 * The integration suite creates Redirection's tables itself, because the
 * WordPress test library activates no plugin and so nothing has run Redirection's
 * installer. Reaching that installer means naming files in another plugin, and
 * those files moved: 5.10.0 put the database classes under `includes/database/`
 * in a `Redirection\Database\` namespace, where up to 5.9.0 they were
 * unnamespaced files under `database/`. A bootstrap that names one layout dies
 * on the other before the first test runs (#115).
 *
 * This file is that lookup, and nothing else — no WordPress, no Redirection, no
 * database. It is a plain function rather than a block inside
 * `tests/integration/bootstrap.php` so that the choice between the layouts is
 * reachable by `tests/redirection-database-layout-test.php`, which runs in the
 * gate. The bootstrap itself runs only under Docker, so anything left inside it
 * is checked by nothing an unattended agent can run.
 *
 * @package NextJsRevalidate
 */

/**
 * The database entry point of the Redirection install in a directory.
 *
 * Answers with the files to require before the class can be used, and the class
 * whose `get_latest_database()` returns the installer. `null` means neither
 * known layout is there — upstream has moved these files again, and the caller
 * should say so rather than carry on without tables.
 *
 * The installer is reached through `get_latest_database()` rather than by naming
 * the schema class, because that method is what resolves the schema class, and
 * it is the name upstream has kept stable across the move.
 *
 * @param string $plugin_dir absolute path of the redirection plugin directory
 * @return array|null `[ 'requires' => string[], 'database_class' => string ]`, or null
 */
function njr_redirection_database_layout( string $plugin_dir ): ?array {
	$plugin_dir = rtrim( $plugin_dir, '/\\' );

	// 5.10.0 and later. `redirection.php` registers an autoloader over the whole
	// `Redirection\` namespace, so the class only has to be named — requiring
	// the file by hand would load one class and leave the schema and upgrader
	// classes it reaches for to that same autoloader anyway.
	if ( file_exists( "$plugin_dir/includes/database/class-database.php" ) ) {
		return [
			'requires'       => [],
			'database_class' => 'Redirection\\Database\\Database',
		];
	}

	// Up to 5.9.0. Nothing loads these: that release autoloads its
	// `Redirection\ImportExport\` namespace only, and the database layer is
	// pulled in by the admin, api and CLI entry points, none of which this suite
	// loads. So `class_exists( 'Red_Database' )` answers no on a site that is
	// running Redirection — a silent no rather than a failure, and the reason
	// this lookup is by file rather than by class. The order is dependencies
	// first: `database.php` is the last of the four.
	if ( file_exists( "$plugin_dir/database/database.php" ) ) {
		return [
			'requires'       => [
				"$plugin_dir/database/database-status.php",
				"$plugin_dir/database/database-upgrade.php",
				"$plugin_dir/database/database-upgrader.php",
				"$plugin_dir/database/database.php",
			],
			'database_class' => 'Red_Database',
		];
	}

	return null;
}
