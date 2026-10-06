<?php

namespace NextJsRevalidate;

// Exit if accessed directly.
defined( 'ABSPATH' ) or die( 'Cheatin&#8217; uh?' );

/**
 * The end of a file, read from the end.
 *
 * What the **log viewer** shows of the **log file**. Plain PHP and no
 * WordPress, so that the one property that matters — the lines come from
 * the end of the file and not from loading it — is pinned by a standalone
 * script (`tests/log-tail-test.php`).
 *
 * Read-only by construction: the file is opened for reading and nothing else,
 * and a file that is not there is an answer, not something to create.
 */
final class LogTail {

	/**
	 * How much is read at a time, both backwards for the lines and forwards
	 * for the count.
	 */
	public const CHUNK_SIZE = 65536;

	/**
	 * The most the backwards read holds at once, whatever the lines it is
	 * looking for weigh. A log whose last lines are longer than this together
	 * shows fewer of them, and the status line says how many.
	 */
	public const MAX_BYTES = 1048576;

	/**
	 * The last lines of a file, oldest first, with how many it holds and how
	 * big it is.
	 *
	 * The lines are found by reading backwards from the end in chunks, so
	 * their cost depends on how long they are and not on how long the file
	 * is. The count needs every newline in the file, so when the lines read
	 * do not reach its start it is taken in a second, forward pass that holds
	 * one chunk at a time: the time it takes grows with the file, the memory
	 * it takes does not.
	 *
	 * @param string $file  The file to read.
	 * @param int    $count How many lines, at most.
	 *
	 * @return array{lines: string[], total: int, size: int}|null Null when
	 *         there is no file to read, or it cannot be read.
	 */
	public static function read( $file, $count ) {
		if ( ! is_file( $file ) ) return null;

		$handle = @fopen( $file, 'rb' );
		if ( false === $handle ) return null;

		$stat = fstat( $handle );
		$size = is_array( $stat ) ? (int) $stat['size'] : 0;

		if ( 0 === $size || $count < 1 ) {
			fclose( $handle );
			return [ 'lines' => [], 'total' => 0, 'size' => $size ];
		}

		// A file ends with the newline of its last line, and that newline
		// starts nothing: it is set aside before the lines are counted.
		fseek( $handle, -1, SEEK_END );
		$terminated = "\n" === fread( $handle, 1 );

		$position = $size;
		$buffer   = '';

		// Enough newlines that the last `$count` lines are whole: the part
		// before the first of them may be cut off, and is dropped below.
		while ( $position > 0 && strlen( $buffer ) < self::MAX_BYTES ) {
			$newlines = substr_count( $buffer, "\n" ) - ( $terminated && '' !== $buffer ? 1 : 0 );
			if ( $newlines >= $count ) break;

			$length    = (int) min( self::CHUNK_SIZE, $position );
			$position -= $length;

			fseek( $handle, $position );
			$buffer = (string) fread( $handle, $length ) . $buffer;
		}

		if ( $terminated ) $buffer = substr( $buffer, 0, -1 );

		$lines = explode( "\n", $buffer );

		// The read stopped short of the file's start, so its first line is
		// only the end of one.
		if ( $position > 0 ) array_shift( $lines );

		$lines = array_slice( $lines, -$count );

		// Read whole, the buffer already holds the count.
		$total = 0 === $position
			? substr_count( $buffer, "\n" ) + 1
			: self::count_lines( $handle, $terminated );

		fclose( $handle );

		return [ 'lines' => $lines, 'total' => $total, 'size' => $size ];
	}

	/**
	 * How many lines a file holds, read forwards one chunk at a time.
	 *
	 * @param resource $handle     The file, open for reading.
	 * @param bool     $terminated Whether its last line ends with a newline.
	 *
	 * @return int
	 */
	private static function count_lines( $handle, $terminated ) {
		rewind( $handle );

		$total = 0;
		while ( ! feof( $handle ) ) {
			$chunk = fread( $handle, self::CHUNK_SIZE );
			if ( false === $chunk || '' === $chunk ) break;
			$total += substr_count( $chunk, "\n" );
		}

		// A last line without its newline is a line all the same.
		return $terminated ? $total : $total + 1;
	}
}
