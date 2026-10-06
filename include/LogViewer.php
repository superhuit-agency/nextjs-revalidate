<?php

namespace NextJsRevalidate;

use NextJsRevalidate\Interfaces\Hookable;

// Exit if accessed directly.
defined( 'ABSPATH' ) or die( 'Cheatin&#8217; uh?' );

/**
 * The **log viewer**: the end of this site's **log file**, on the settings
 * screen's Debug tab, while logging is switched on.
 *
 * Rendered with the page, and refreshed over `admin-ajax.php` — not over REST,
 * because the `nextjs-revalidate/v1` namespace is the front-end's
 * secret-authenticated contract (ADR 0035) and carries no admin-only route.
 * The page and the refresh both go through `body()`, so they cannot disagree
 * about what the log holds or how it is shown.
 *
 * Strictly read-only: it reads the log through `Logger::existing_path()`, and
 * creates no file, no directory, no guard and no suffix. Only `Logger::log()`
 * does that (ADR 0024).
 *
 * Nothing is redacted on the way out. Whoever passes `manage_options` can read
 * the secret on the Next.js API tab already, and lines written before redaction
 * existed are shown as they were written.
 */
class LogViewer implements Hookable {

	/**
	 * The `wp_ajax_` action the refresh asks, and the nonce action it carries.
	 */
	const ACTION = 'nextjs_revalidate_log_tail';

	/**
	 * The settings field the viewer is rendered as, on the Debug tab.
	 */
	const FIELD_ID = 'log-viewer';

	/**
	 * How many lines the viewer shows, at most.
	 */
	const LINES = 200;

	public function register_hooks(): void {
		add_action( 'wp_ajax_' . self::ACTION, [$this, 'refresh'] );
	}

	/**
	 * Whether the Debug tab shows the viewer: only while logging is saved as
	 * on. With logging off the tab is what it was before the viewer existed,
	 * and says where the log is — even if there is one.
	 *
	 * @return bool
	 */
	public static function is_shown() {
		return Logger::is_enabled();
	}

	/**
	 * Answer a refresh with the viewer's body, as `render()` printed it.
	 *
	 * Asked only by somebody who may open the settings screen, with the nonce
	 * that screen printed. With logging switched off since the page was loaded
	 * the answer says so, and the body is the note saying it, not lines.
	 *
	 * @return void Ends the request.
	 */
	public function refresh() {
		// The capability the settings screen and the probe are behind.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				[
					'reason'  => 'forbidden',
					'message' => __( 'Sorry, you are not allowed to read the log of this site.', 'nextjs-revalidate' ),
				],
				403
			);
		}

		if ( false === check_ajax_referer( self::ACTION, 'nonce', false ) ) {
			wp_send_json_error(
				[
					'reason'  => 'nonce',
					'message' => __( 'This page has expired. Reload it to refresh the log.', 'nextjs-revalidate' ),
				],
				403
			);
		}

		wp_send_json_success(
			[
				'enabled' => Logger::is_enabled(),
				'html'    => self::body(),
			]
		);
	}

	/**
	 * Print the viewer: its controls, and the body a refresh replaces.
	 *
	 * The settings field callback, so it is only reached while `is_shown()`.
	 *
	 * @return void
	 */
	public static function render() {
		$messages = [
			'liveOff'     => __( 'Live refresh stopped: logging has been switched off.', 'nextjs-revalidate' ),
			'liveExpired' => __( 'Live refresh stopped: this page has expired. Reload it to refresh the log.', 'nextjs-revalidate' ),
			'liveFailed'  => __( 'Live refresh stopped: the log could not be refreshed.', 'nextjs-revalidate' ),
			'expired'     => __( 'This page has expired. Reload it to refresh the log.', 'nextjs-revalidate' ),
			'failed'      => __( 'The log could not be refreshed.', 'nextjs-revalidate' ),
		];
		?>
		<div
			class="njr-log-viewer"
			data-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
			data-action="<?php echo esc_attr( self::ACTION ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( self::ACTION ) ); ?>"
			data-messages="<?php echo esc_attr( (string) wp_json_encode( $messages ) ); ?>"
		>
			<p class="njr-log-viewer__controls">
				<button type="button" class="button-link njr-log-viewer__refresh"><?php esc_html_e( 'Refresh', 'nextjs-revalidate' ); ?></button>
				<?php
					// No name, so the settings form never submits it, and
					// autocomplete off, so a browser never restores it: live
					// refresh is off on every page load, and saved nowhere.
				?>
				<label class="njr-log-viewer__live-label">
					<input type="checkbox" class="njr-log-viewer__live" autocomplete="off" />
					<?php esc_html_e( 'Live refresh, every 5 seconds', 'nextjs-revalidate' ); ?>
				</label>
				<span class="njr-log-viewer__live-status" role="status" aria-live="polite"></span>
			</p>
			<div class="njr-log-viewer__body"><?php echo self::body(); // Escaped as it is composed. ?></div>
		</div>
		<?php
	}

	/**
	 * What the viewer shows: a status line over the end of the log, a note
	 * that nothing has been logged yet, or a note that logging is off.
	 *
	 * The one function the page and the refresh both print, so they cannot
	 * disagree. Every part of it is escaped here.
	 *
	 * @return string HTML.
	 */
	public static function body() {
		if ( ! Logger::is_enabled() ) {
			return sprintf(
				'<p class="njr-log-viewer__note">%s</p>',
				esc_html__( 'Logging has been switched off since this page was loaded, so there is no log to show. Reload the page to see the Debug tab as it is saved.', 'nextjs-revalidate' )
			);
		}

		$refreshed = wp_date( 'H:i:s' );
		$tail      = self::tail();

		// Decided on the file's count, not on the lines read: a last line too
		// long for the read cap leaves none to show, and the log is not empty.
		if ( null === $tail || 0 === $tail['total'] ) {
			return sprintf(
				'<p class="njr-log-viewer__note">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: the time the log was read, e.g. 14:05:09 */
						__( 'Nothing has been logged yet — refreshed %s', 'nextjs-revalidate' ),
						$refreshed
					)
				)
			);
		}

		$status = sprintf(
			/* translators: 1: how many lines are shown, 2: how many lines the log holds, 3: the size of the log, e.g. 1.2 MB, 4: the time the log was read, e.g. 14:05:09 */
			_n(
				'Showing the last %1$s of %2$s line (%3$s) — refreshed %4$s',
				'Showing the last %1$s of %2$s lines (%3$s) — refreshed %4$s',
				$tail['total'],
				'nextjs-revalidate'
			),
			number_format_i18n( count( $tail['lines'] ) ),
			number_format_i18n( $tail['total'] ),
			size_format( $tail['size'], 1 ),
			$refreshed
		);

		return sprintf(
			'<p class="njr-log-viewer__status">%s</p><pre class="njr-log-viewer__log" tabindex="0" aria-label="%s">%s</pre>',
			esc_html( $status ),
			esc_attr__( 'The end of the log file', 'nextjs-revalidate' ),
			implode( "\n", array_map( [ self::class, 'line' ], $tail['lines'] ) )
		);
	}

	/**
	 * The end of this site's log, or null when it has none.
	 *
	 * @return array{lines: string[], total: int, size: int}|null
	 */
	public static function tail() {
		$path = Logger::existing_path();

		return null === $path ? null : LogTail::read( $path, self::LINES );
	}

	/**
	 * One line of the log, escaped, and tinted when it is an error.
	 *
	 * Escaped exactly as written, rather than through `esc_html()`: that one
	 * leaves an entity already in the line alone — a logged `&amp;` would read
	 * as `&` — and empties a whole line holding a byte that is not UTF-8,
	 * where this shows the line with that byte replaced.
	 *
	 * @param string $line
	 *
	 * @return string HTML.
	 */
	private static function line( $line ) {
		$escaped = htmlspecialchars( $line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );

		return false !== strpos( $line, "\t[ERROR]\t" )
			? '<span class="njr-log-viewer__error">' . $escaped . '</span>'
			: $escaped;
	}
}
