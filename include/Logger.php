<?php

namespace NextJsRevalidate;

class Logger {

	public const INFO  = 0;
	public const DEBUG = 1;
	public const ERROR = 2;

	/**
	 * Custom logging function
	 *
	 * @source https://stackoverflow.com/a/44745716/5078169
	 *
	 * @param string $text        Text/Message to log
	 * @param string $currentFile Filename of the file that is logging
	 * @param string $level       Logging level
	 *
	 * Will produce
	 * ------------
	 *
	 * [2017-03-20 3:35:43] [INFO] [file.php] Here we are
	 * [2017-03-20 3:35:43] [ERROR] [file.php] Not good
	 * [2017-03-20 3:35:43] [DEBUG] [file.php] Regex empty
	 */
	public static function log($text, $currentFile, $level= self::INFO) {
		switch (strtolower($level)) {
			case self::ERROR:
				$level='ERROR';
				break;

			case self::DEBUG:
				$level='DEBUG';
				break;

			case self::INFO:
			default:
				$level='INFO';
				break;
		}

		$filename  = basename($currentFile);
		$alignment = str_repeat(' ', 16 - strlen($filename));

		$dirs = wp_upload_dir();
		$logFile = $dirs['basedir'] . 'nextjs-revalidate.log';

		error_log(
			sprintf(
				"%s\t[%s]\t[%s]%s %s\n",
				date("[Y-m-d H:i:s]"),
				$level,
				$filename,
				$alignment,
				$text
			),
			3,
			$logFile
		);
	}
}
