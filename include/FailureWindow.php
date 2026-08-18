<?php

namespace NextJsRevalidate;

use NextJsRevalidate\Abstracts\Base;
use NextJsRevalidate\Traits\BlockEditorScreen;
use WP_Error;

/**
 * The failure window, and the degraded revalidation it answers for.
 *
 * The plugin's work is invisible by nature: a revalidation that fails leaves a
 * successful save behind it and a front-end page that is quietly out of date.
 * The log says so, but logging is off by default, so it only ever reaches
 * someone who already suspects a problem. This is the surface for everyone
 * else.
 *
 * A **condition**, not a record of events. The window holds the outcomes of the
 * last few attempts, `is_degraded()` reads three failures out of them, and the
 * notice appears and disappears with that answer — nothing accumulates, nothing
 * expires, and there is nothing for an operator to acknowledge. See
 * `docs/adr/0007-degraded-revalidation-is-a-condition.md`.
 *
 * The state operations are static because the window is per-site state read
 * from the site currently being served, in the way `Logger::log()` and
 * `Settings::delete_settings()` are; the instance the composition root builds
 * exists to render the notice.
 *
 * @property Settings $settings
 */
class FailureWindow extends Base {
	use BlockEditorScreen;

	/**
	 * The option the window is kept in.
	 *
	 * An option rather than a transient: a transient is evictable, and an
	 * external object cache dropping this one would take the warning with it
	 * while the front-end is still broken — this class's own failure mode,
	 * reintroduced through a storage choice.
	 */
	const OPTION_NAME = 'nextjs_revalidate-failure_window';

	/**
	 * How many attempt outcomes the window holds.
	 */
	const LENGTH = 10;

	/**
	 * How many of them must be failures for the site to be degraded.
	 *
	 * A window rather than a run of consecutive failures, because a run is
	 * blind to a front-end that is merely flaky: one failing half the time
	 * never reaches three in a row, and stays silent while half the site goes
	 * stale. Three in ten is a real problem however they arrived, and a site
	 * with only four attempts on record still trips at three — three failures
	 * out of four is unambiguous.
	 */
	const DEGRADED_AT = 3;

	public function __construct() {
		add_action( 'admin_notices', [$this, 'degraded_notice'] );
		add_action( 'admin_enqueue_scripts', [$this, 'enqueue_editor_notice'], 11 );
	}

	/**
	 * The outcomes of the last attempts made on the site currently being
	 * served, oldest first.
	 *
	 * Read defensively and never cached: the option belongs to whichever site
	 * is current, and a site can hold a row of any shape — one written by an
	 * older release, or by something else entirely. Anything that is not an
	 * outcome is not evidence, and is dropped rather than counted.
	 *
	 * @return array<int, array{failed: bool, code: string}>
	 */
	public static function outcomes() {

		$stored = get_option( self::OPTION_NAME, [] );
		if ( !is_array($stored) ) return [];

		$outcomes = [];
		foreach ( $stored as $entry ) {
			if ( !is_array($entry) || !isset($entry['failed']) ) continue;

			$code = $entry['code'] ?? '';

			$outcomes[] = [
				'failed' => (bool) $entry['failed'],
				'code'   => is_string($code) ? $code : '',
			];
		}

		return array_slice( $outcomes, -self::LENGTH );
	}

	/**
	 * The failures among them, oldest first.
	 *
	 * @return array<int, array{failed: bool, code: string}>
	 */
	public static function failures() {

		$failures = [];
		foreach ( self::outcomes() as $outcome ) {
			if ( $outcome['failed'] ) $failures[] = $outcome;
		}

		return $failures;
	}

	/**
	 * Record the outcome of one revalidation attempt, dropping the oldest
	 * outcome once the window is full.
	 *
	 * Everything that is not a success is a failure, whether or not it named a
	 * cause: the condition is about failure, not about diagnosis, and an
	 * unnamed cause is not a reason to stay silent.
	 *
	 * A **refusal** is not an outcome to record and must not reach here — an
	 * unconfigured site was never attempted against the front-end, so it is no
	 * evidence about the front-end's health.
	 *
	 * @param true|WP_Error $outcome What `Revalidate::purge()` answered.
	 * @return void
	 */
	public static function record( $outcome ) {

		$failed = ( true !== $outcome );
		$code   = is_wp_error( $outcome ) ? (string) $outcome->get_error_code() : '';

		$outcomes   = self::outcomes();
		$outcomes[] = [
			'failed' => $failed,
			'code'   => $failed ? $code : '',
		];

		// Not autoloaded: the drain writes this once per attempt, and an
		// autoloaded option would flush the whole alloptions cache each time.
		update_option( self::OPTION_NAME, array_slice( $outcomes, -self::LENGTH ), false );
	}

	/**
	 * Whether the site currently being served has degraded revalidation.
	 *
	 * Computed when it is asked for, the way `Settings::is_configured()` is,
	 * rather than a flag some earlier code path set. Never a statement about
	 * any one revalidation.
	 *
	 * @return bool
	 */
	public static function is_degraded() {
		return count( self::failures() ) >= self::DEGRADED_AT;
	}

	/**
	 * Forget everything the window holds.
	 *
	 * @return void
	 */
	public static function clear() {
		delete_option( self::OPTION_NAME );
	}

	/**
	 * The error code of the most recent failure that carries one.
	 *
	 * Not always the most recent failure's: a failure can arrive without a
	 * code, and skipping it names a cause where the alternative names nothing.
	 *
	 * @return string Empty when no failure in the window named a cause.
	 */
	public static function last_failure_code() {

		foreach ( array_reverse( self::failures() ) as $failure ) {
			if ( '' !== $failure['code'] ) return $failure['code'];
		}

		return '';
	}

	/**
	 * The distinct causes the window's failures name, an unnamed cause being
	 * one of them.
	 *
	 * Only ever counted: the notice names one code and says that others
	 * occurred, because a list of three codes is a notice nobody reads.
	 *
	 * @return string[]
	 */
	public static function failure_codes() {

		$codes = [];
		foreach ( self::failures() as $failure ) {
			$codes[ $failure['code'] ] = true;
		}

		return array_map( 'strval', array_keys( $codes ) );
	}

	/**
	 * What the current screen has to say about degraded revalidation, if it
	 * has anything to say to whoever is reading it.
	 *
	 * The notice itself rather than its markup: a block editor screen renders
	 * the same words through `core/notices` instead of printing them, so the
	 * question of *what to say* is answered once here and the two renderers
	 * only decide how.
	 *
	 * The text is unescaped — each renderer escapes for its own destination,
	 * and the link travels beside the message rather than inside it so a
	 * renderer that cannot take markup still gets both.
	 *
	 * @return array{message: string, action_label: string, action_url: string}|null
	 *         Null when this screen says nothing.
	 */
	public function get_degraded_notice() {

		// Yields to the unconfigured notice. The two are nearly exclusive
		// already, since an unconfigured site refuses at enqueue and never
		// attempts anything; the overlap is a site that was configured and
		// failing and then lost a setting, where the window is evidence about a
		// configuration that no longer exists and the missing setting is the
		// thing to fix.
		if ( !$this->settings->is_configured() ) return null;

		if ( !self::is_degraded() ) return null;

		$can_configure = current_user_can( 'manage_options' );

		// The same audience as the unconfigured notice: whoever can do
		// something about it, and whoever's edits are being dropped.
		if ( !$can_configure && !current_user_can( 'edit_posts' ) ) return null;

		$nb_attempts = count( self::outcomes() );
		$nb_failures = count( self::failures() );

		$message = sprintf(
			/* translators: 1: number of failed revalidations. 2: number of attempts on record. */
			__( 'Next.js revalidate is not keeping this site up to date — %1$d of the last %2$d revalidations failed. Content is still saved, but the front-end is serving pages which are quietly out of date.', 'nextjs-revalidate' ),
			$nb_failures,
			$nb_attempts
		);

		$code = self::last_failure_code();
		if ( '' !== $code ) {
			$message .= ' ' . sprintf(
				/* translators: %s: the cause of the most recent failure. */
				__( 'Most recent error: %s.', 'nextjs-revalidate' ),
				self::describe_code( $code )
			);
		}

		if ( count( self::failure_codes() ) > 1 ) {
			$message .= ' ' . __( 'Other errors occurred as well.', 'nextjs-revalidate' );
		}

		if ( !$can_configure ) {
			return [
				'message'      => $message . ' ' . __( 'Please contact a site administrator.', 'nextjs-revalidate' ),
				'action_label' => '',
				'action_url'   => '',
			];
		}

		// Nothing to link to from the screen the link would lead to.
		$on_settings_page = ( isset($_GET['page']) && $_GET['page'] === Settings::PAGE_NAME );

		return [
			'message'      => $message,
			'action_label' => $on_settings_page ? '' : __( 'Check the Next.js revalidate settings', 'nextjs-revalidate' ),
			'action_url'   => $on_settings_page ? '' : admin_url( 'options-general.php?page=' . Settings::PAGE_NAME ),
		];
	}

	/**
	 * Tell whoever is looking at the admin that the front-end is not being
	 * rebuilt.
	 *
	 * Not dismissible, and not confined to the settings screen, for the reason
	 * the unconfigured notice is neither: a stale page pages nobody, so a
	 * notice someone has to go looking for is the silence this exists to break.
	 * It needs no dismissal — it is a condition, and it goes away when the
	 * window stops saying so.
	 */
	public function degraded_notice() {

		// A block editor screen hides what is printed here and is handed the
		// same notice through `core/notices` instead. Printing it anyway would
		// only risk saying it twice the day core stops hiding it.
		if ( $this->is_block_editor_screen() ) return;

		$notice = $this->get_degraded_notice();
		if ( is_null($notice) ) return;

		$message = esc_html( $notice['message'] );

		if ( '' !== $notice['action_url'] ) {
			$message .= sprintf(
				' <a href="%s">%s</a>',
				esc_url( $notice['action_url'] ),
				esc_html( $notice['action_label'] )
			);
		}

		printf(
			'<div class="notice notice-error nextjs-revalidate-degraded__notice"><p>%s</p></div>',
			$message
		);
	}

	/**
	 * The degraded notice to hand over to the block editor, if this screen is
	 * one.
	 *
	 * The post edit screen is where the operator this notice exists for
	 * actually is: an author saving a post is told the save succeeded, and the
	 * revalidation it produced is the one being dropped. A warning that reaches
	 * every admin screen except that one leaves the silence in place exactly
	 * where it costs the most.
	 *
	 * Not dismissible there either, for the reason it is not here: it is a
	 * condition, not an event, and there is nothing to acknowledge.
	 *
	 * @return array{status: string, message: string, actions: array<int, array{label: string, url: string}>}|null
	 *         Same family as `Revalidate::get_block_editor_purged_notice()`.
	 */
	public function get_block_editor_degraded_notice() {

		if ( ! $this->is_block_editor_screen() ) return null;

		$notice = $this->get_degraded_notice();
		if ( is_null($notice) ) return null;

		return [
			'status'  => 'error',
			'message' => $notice['message'],
			'actions' => ( '' !== $notice['action_url'] )
				? [ [ 'label' => $notice['action_label'], 'url' => $notice['action_url'] ] ]
				: [],
		];
	}

	/**
	 * Hand the block editor the degraded notice, on the script `Assets`
	 * registers for the purpose.
	 *
	 * Priority 11: `Assets` registers the handle at 10, and nothing can be
	 * localized onto a script that is not registered yet. A site whose assets
	 * have never been built has no handle to localize onto and no editor
	 * notice — the same silence a missing script leaves everywhere else.
	 *
	 * @return void
	 */
	public function enqueue_editor_notice() {

		if ( ! wp_script_is( Assets::EDITOR_SCRIPT_HANDLE, 'registered' ) ) return;

		$notice = $this->get_block_editor_degraded_notice();
		if ( is_null($notice) ) return;

		wp_localize_script( Assets::EDITOR_SCRIPT_HANDLE, 'nextjs_revalidate_degraded_notice', $notice );
		wp_enqueue_script( Assets::EDITOR_SCRIPT_HANDLE );
	}

	/**
	 * What an error code means, for an operator who has never seen one.
	 *
	 * This is why the window stores codes at all. A notice saying that
	 * revalidations are failing produces a support ticket; one saying the
	 * front-end rejected the secret produces a fix. The code itself is always
	 * shown alongside, because it is what a log line and a search match on.
	 *
	 * @param string $code The error code a failed attempt returned.
	 * @return string
	 */
	private static function describe_code( $code ) {

		if ( 'unreachable' === $code ) {
			$what = __( 'the front-end could not be reached', 'nextjs-revalidate' );
		}
		else if ( in_array( $code, ['http_401', 'http_403'], true ) ) {
			$what = __( 'the front-end rejected the secret', 'nextjs-revalidate' );
		}
		else if ( 0 === strpos( $code, 'http_' ) ) {
			$what = sprintf(
				/* translators: %s: an HTTP status code, e.g. 500. */
				__( 'the front-end answered %s', 'nextjs-revalidate' ),
				substr( $code, strlen('http_') )
			);
		}
		else {
			return $code;
		}

		return sprintf( '%s (%s)', $what, $code );
	}
}
