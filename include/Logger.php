<?php

namespace NextJsRevalidate;

use NextJsRevalidate;

class Logger {

	public const INFO  = 0;
	public const DEBUG = 1;
	public const ERROR = 2;

	public const FILENAME = 'nextjs-revalidate.log';

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

		// Do not log if setting disabled
		// The switch field submits `on` when checked, and nothing at all when unchecked.
		$debug = NextJsRevalidate::init()->settings->debug ?: [];
		if ( ! filter_var( $debug['enable-logs'] ?? false, FILTER_VALIDATE_BOOLEAN ) ) return;

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

		$dirs = wp_upload_dir();
		$logFile = trailingslashit($dirs['basedir']) . self::FILENAME;

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
			$logFile
		);
	}
}
