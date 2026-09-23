<?php

namespace NextJsRevalidate;

use NextJsRevalidate;

class Logger {

	public const INFO  = 0;
	public const DEBUG = 1;
	public const ERROR = 2;

	/**
	 * The directory beneath the site's uploads directory that this plugin owns,
	 * and the only place its guards may be written: an `.htaccess` applies to
	 * everything beneath it, so one in uploads itself would deny the site's
	 * own media. See ADR-0024.
	 */
	public const DIRECTORY = 'nextjs-revalidate';

	/**
	 * The name the log had, directly in the uploads directory and the same on
	 * every install, until ADR-0024. Kept only so the migration can find it.
	 * Nothing writes here.
	 */
	public const LEGACY_FILENAME = 'nextjs-revalidate.log';

	/**
	 * The per-site suffix in the log's filename.
	 *
	 * Internal state rather than a setting, on the ledger's pattern: neither
	 * registered nor rendered, and torn down beside the ledger. Generated once
	 * and stored rather than derived from the salts, because salts are rotated
	 * — most often right after a compromise — and a derived name would leave
	 * the whole log behind under its old name at exactly that moment.
	 */
	public const SUFFIX_OPTION_NAME = 'nextjs_revalidate-log_suffix';

	/**
	 * Custom logging function
	 *
	 * @source https://stackoverflow.com/a/44745716/5078169
	 *
	 * @param string $text        Text/Message to log
	 * @param string $currentFile Filename of the file that is logging
	 * @param int    $level       Logging level — one of the constants above
	 *
	 * Will produce
	 * ------------
	 *
	 * [2017-03-20 3:35:43] [INFO] [file.php] Here we are
	 * [2017-03-20 3:35:43] [ERROR] [file.php] Not good
	 * [2017-03-20 3:35:43] [DEBUG] [file.php] Regex empty
	 */
	public static function log($text, $currentFile, $level= self::INFO) {

		// Do not log if setting disabled — and before anything below touches the
		// filesystem or the options, so a site that never logs gains neither.
		if ( ! self::is_enabled() ) return;

		// One of the constants above, so an int: the strtolower() that used to
		// sit here was a no-op on one, and matched nothing when handed a level's
		// name instead.
		switch ($level) {
			case self::ERROR:
				$label = 'ERROR';
				break;

			case self::DEBUG:
				$label = 'DEBUG';
				break;

			case self::INFO:
			default:
				$label = 'INFO';
				break;
		}

		$filename  = basename($currentFile);
		// Never a negative repeat count: a filename longer than the column is simply not padded.
		$alignment = str_repeat(' ', max(0, 16 - strlen($filename)));

		// Ensured on every write, not only by the migration: a fresh install, a
		// site created later on a network, or a directory somebody removed by
		// hand would otherwise lose the line, or keep it unguarded.
		if ( ! self::ensure_directory() ) return;

		error_log(
			sprintf(
				"%s\t[%s]\t[%s]%s %s\n",
				date("[Y-m-d H:i:s]"),
				$label,
				$filename,
				$alignment,
				$text
			),
			3,
			self::path()
		);
	}

	/**
	 * Whether the operator has switched logging on for this site.
	 *
	 * The switch field submits `on` when checked, and nothing at all when unchecked.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$debug = NextJsRevalidate::init()->settings->debug ?: [];
		return filter_var( $debug['enable-logs'] ?? false, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * The directory the log lives in, without a trailing slash.
	 *
	 * Follows `switch_to_blog()`, as `wp_upload_dir()` does, so each site of a
	 * network has its own.
	 *
	 * @return string
	 */
	public static function directory() {
		$dirs = wp_upload_dir();
		return trailingslashit($dirs['basedir']) . self::DIRECTORY;
	}

	/**
	 * The full path of this site's log — the one answer to "where is the log".
	 *
	 * Composed, never known in advance: the filename carries this site's
	 * suffix, which is generated and stored the first time it is asked for.
	 * Everything that reports or reads the log goes through here.
	 *
	 * @return string
	 */
	public static function path() {
		return trailingslashit( self::directory() ) . 'nextjs-revalidate-' . self::suffix() . '.log';
	}

	/**
	 * Move a log from the legacy path into the plugin's directory.
	 *
	 * Runs iff there is a log at the legacy path and none at the new one — a
	 * condition on the data rather than on the DB version, which makes it
	 * idempotent by construction, the way `Settings::split_legacy_url()` is.
	 * A version gate on a release this code does not yet stamp would re-fire
	 * on every admin request.
	 *
	 * Renamed, never copied and never deleted: the log is the only trace a
	 * failed revalidation leaves, and a plugin update is not a thing anybody
	 * expects to destroy their evidence. When both exist, both are left alone.
	 *
	 * @return void
	 */
	public static function migrate_legacy_log() {
		$dirs   = wp_upload_dir();
		$legacy = trailingslashit($dirs['basedir']) . self::LEGACY_FILENAME;

		// Checked before `path()` is, so a site with nothing to migrate is not
		// handed a suffix by the asking.
		if ( ! is_file($legacy) ) return;
		if ( file_exists( self::path() ) ) return;
		if ( ! self::ensure_directory() ) return;

		rename( $legacy, self::path() );
	}

	/**
	 * This site's filename suffix, generated and stored on first use.
	 *
	 * `add_option()` rather than `update_option()`, so that of two requests
	 * racing to generate one, the loser adopts the winner's instead of
	 * replacing it — a replaced suffix is a log orphaned under its old name.
	 *
	 * @return string
	 */
	private static function suffix() {
		$suffix = get_option( self::SUFFIX_OPTION_NAME );
		if ( is_string($suffix) && $suffix !== '' ) return $suffix;

		// Letters and digits only: it is part of a filename, and of a URL.
		$suffix = wp_generate_password( 32, false );
		if ( add_option( self::SUFFIX_OPTION_NAME, $suffix ) ) return $suffix;

		return (string) get_option( self::SUFFIX_OPTION_NAME );
	}

	/**
	 * Make sure the log's directory exists and holds both of its guards.
	 *
	 * Each guard is checked on its own, so one removed by hand is restored
	 * rather than assumed from the directory being there. The `.htaccess` is a
	 * real denial on Apache and ignored by nginx; `index.php` only suppresses a
	 * listing. Neither protects a known path on nginx, which is what the
	 * filename's suffix is for (ADR-0024).
	 *
	 * @return bool Whether the directory exists.
	 */
	private static function ensure_directory() {
		$directory = self::directory();
		if ( ! wp_mkdir_p($directory) ) return false;

		$guards = [
			'.htaccess' => "# Apache 2.4\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n\n# Apache 2.2\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n",
			'index.php' => "<?php\n// Silence is golden.\n",
		];

		foreach ( $guards as $name => $contents ) {
			$guard = trailingslashit($directory) . $name;
			if ( ! file_exists($guard) ) @file_put_contents( $guard, $contents );
		}

		return true;
	}
}
