<?php

namespace NextJsRevalidate;

use NextJsRevalidate\Abstracts\Base;
use NextJsRevalidate\Interfaces\Hookable;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) or die( 'Cheatin&#8217; uh?' );

/**
 * The probe: a revalidation the operator asks for directly, in order to observe
 * its outcome.
 *
 * The plugin's work is invisible by nature, and every other surface only ever
 * answers *later* — the queue drains on cron, a failure lands in the log if
 * logging is on, and the failure window needs three of them before it says
 * anything. This is the one place where an operator can ask the front-end a
 * question and read the answer, error message and all.
 *
 * It is not a read-only check. A probe rebuilds the path it names, on the live
 * front-end, exactly as any other revalidation would: it calls
 * `Revalidate::purge()` rather than building its own request, composes
 * `home_url( $path )` so that function's parameter keeps meaning one thing, and
 * reads the *saved* settings rather than the fields on screen — so it answers
 * "does this site revalidate right now", not "would these values work".
 *
 * **A probe outcome is never recorded in the failure window.** The window is a
 * sample of the queue's own traffic rather than a record of attempts, and a
 * probe enters at a rate set by how worried the operator is: admitting one would
 * let this button clear the degraded notice while the site was still serving
 * stale pages. The log file is not sampled by anything, so a probe *is* written
 * there, behind the same logs setting as everything else.
 * See `docs/adr/0013-a-probe-is-not-evidence.md`.
 *
 * @property Revalidate $revalidate
 */
class Probe extends Base implements Hookable {

	/**
	 * The name of the submit button that asks for a probe, and the nonce action
	 * of the form holding it.
	 *
	 * The form is the operator's own, posted to the settings page rather than to
	 * `options.php`: a probe intercepted from the settings form's submit would
	 * have to answer before that form was saved, silently dropping whatever the
	 * operator had typed into it.
	 */
	const ACTION = 'nextjs_revalidate-probe';

	/**
	 * The field holding the path to probe.
	 */
	const PATH_FIELD = 'nextjs_revalidate-probe_path';

	/**
	 * The query arg the sendback carries: the path that was probed.
	 *
	 * Both the marker saying this request comes back from a probe, and what the
	 * field is refilled with — an operator watching a front-end come back up
	 * probes the same path several times in a row.
	 */
	const SENDBACK_ARG = 'nextjs-revalidate-probed';

	/**
	 * The transient the outcome waits in, for the one request the sendback
	 * lands on.
	 *
	 * Per user, and consumed by the read that renders it. This is not the probe
	 * being *recorded*: it holds the answer across the redirect that keeps a
	 * refresh from re-probing, expires within the minute, and nothing computes
	 * anything from it.
	 */
	const RESULT_TRANSIENT = 'nextjs_revalidate-probe_result';

	/**
	 * Seconds the request running a probe is allowed to take.
	 *
	 * The purge keeps its 60 second timeout — shortening it for the comfort of
	 * someone watching a spinner would report `unreachable` for a slow front-end
	 * the queue would have revalidated fine — and 60 seconds of outbound request
	 * inside a `max_execution_time` commonly set to 30 fatals rather than
	 * answers. This is that margin, raised best effort.
	 */
	const TIME_LIMIT = 90;

	/**
	 * The path a probe asks about when the operator named none.
	 */
	const DEFAULT_PATH = '/';

	public function register_hooks(): void {
		add_action( 'admin_init', [$this, 'probe_action'] );

		add_action( 'admin_notices', [$this, 'probe_notice'] );
	}

	/**
	 * Run the probe this request asks for, then send the operator back to the
	 * settings screen to read the answer.
	 *
	 * Post/redirect/get, as every other action of this plugin is: a probe is a
	 * real rebuild of a real path, and a refresh of the answer must not quietly
	 * ask for another one.
	 *
	 * Does not return when the request is a probe: it ends in a redirect.
	 *
	 * @return void
	 */
	public function probe_action() {
		if ( ! isset( $_POST[ self::ACTION ] ) ) return;

		check_admin_referer( self::ACTION );

		// The same capability the settings page itself is behind: a probe
		// spends this site's secret on a request to its front-end.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Sorry, you are not allowed to probe the front-end of this site.', 'nextjs-revalidate' ) );
		}

		$path = self::path( self::submitted( $_POST[ self::PATH_FIELD ] ?? '' ) );

		self::store_result( $this->send( $path ) );

		wp_safe_redirect(
			add_query_arg(
				[ self::SENDBACK_ARG => rawurlencode( $path ) ],
				admin_url( 'options-general.php?page=' . Settings::PAGE_NAME )
			) . '#tab-probe'
		);
		exit;
	}

	/**
	 * Ask the front-end to rebuild one path of this site, and answer with what
	 * it said.
	 *
	 * @param string $path A path of this site, as `path()` normalises it.
	 *
	 * @return array{status: string, message: string} What to tell the operator.
	 */
	public function send( $path ) {

		$permalink = home_url( $path );

		// Best effort, and a host that refuses this has been quietly killing
		// long cron drains all along. `function_exists()` rather than a call
		// in the dark: a function listed in `disable_functions` is reported as
		// absent, and calling it anyway only adds a warning to the diagnostic.
		if ( function_exists( 'set_time_limit' ) ) set_time_limit( self::TIME_LIMIT );

		$start   = microtime( true );
		$outcome = $this->revalidate->purge( $permalink );
		$elapsed = microtime( true ) - $start;

		// Deliberately no `FailureWindow::record()` here: see the class
		// docblock and ADR 0013. The window samples the queue's traffic, and a
		// probe is not drawn from it.
		$this->log_outcome( $permalink, $outcome, $elapsed );

		return self::describe( $permalink, $outcome );
	}

	/**
	 * One submitted value, as the string a form field is supposed to hand over.
	 *
	 * Anything can put an array where the form put a field, and
	 * `sanitize_text_field()` handed one fatals on PHP 8 — a crash where the
	 * honest reading is that nothing was typed.
	 *
	 * @param mixed $value
	 * @return string
	 */
	private static function submitted( $value ) {
		return is_string( $value ) ? sanitize_text_field( wp_unslash( $value ) ) : '';
	}

	/**
	 * The path a probe asks about, from whatever the operator typed in the
	 * field.
	 *
	 * Only ever a path: an operator pastes a permalink as readily as a path,
	 * and `home_url()` handed a whole URL composes nonsense. What a pasted URL
	 * carries that a path does not is *this* site's domain, which composition
	 * puts back — so keeping the path alone loses nothing.
	 *
	 * @param string $typed
	 * @return string A path with exactly one leading slash.
	 */
	public static function path( $typed ) {

		$typed = trim( (string) $typed );

		$parsed = wp_parse_url( $typed, PHP_URL_PATH );
		if ( is_string( $parsed ) ) $typed = $parsed;

		$typed = ltrim( $typed, '/' );

		return '' === $typed ? self::DEFAULT_PATH : '/' . $typed;
	}

	/**
	 * What to tell the operator about one outcome.
	 *
	 * The error code and the message the attempt came back with are both shown,
	 * verbatim: the message is what says *what happened*, and the code is what
	 * a log line and a search match on. Collapsing either into "the revalidation
	 * failed" is exactly what this button exists to stop.
	 *
	 * @param string        $permalink The permalink that was probed.
	 * @param true|WP_Error $outcome   What `Revalidate::purge()` answered.
	 *
	 * @return array{status: string, message: string}
	 */
	private static function describe( $permalink, $outcome ) {

		if ( ! is_wp_error( $outcome ) ) {
			return [
				'status'  => 'success',
				'message' => sprintf(
					/* translators: %s: the permalink the front-end was asked to rebuild. */
					__( 'The front-end rebuilt %s.', 'nextjs-revalidate' ),
					$permalink
				),
			];
		}

		// A refusal is not a failure: the front-end was asked nothing at all,
		// and the reason is about this site rather than about it. Its message
		// already names the settings that are missing.
		if ( 'not_configured' === $outcome->get_error_code() ) {
			return [
				'status'  => 'error',
				'message' => sprintf(
					/* translators: 1: the permalink nothing was sent for. 2: why nothing was sent. */
					__( 'Nothing was sent for %1$s. %2$s', 'nextjs-revalidate' ),
					$permalink,
					$outcome->get_error_message()
				),
			];
		}

		return [
			'status'  => 'error',
			'message' => sprintf(
				/* translators: 1: the permalink. 2: what the attempt answered. 3: the error code. */
				__( 'The front-end did not rebuild %1$s — %2$s (%3$s)', 'nextjs-revalidate' ),
				$permalink,
				$outcome->get_error_message(),
				$outcome->get_error_code()
			),
		];
	}

	/**
	 * Write what became of one probe.
	 *
	 * The drain's vocabulary, minus the two things a probe has not got: no queue
	 * id, because nothing was queued, and no priority, because nothing was
	 * ordered against anything else. The 🔎 is what tells an operator reading
	 * the log that this line is one they asked for.
	 *
	 * @param string        $permalink The permalink that was probed.
	 * @param true|WP_Error $outcome   What `Revalidate::purge()` answered.
	 * @param float         $elapsed   Seconds the attempt took.
	 *
	 * @return void
	 */
	private function log_outcome( $permalink, $outcome, $elapsed ) {

		$elapsed = round( $elapsed, 2 );

		if ( ! is_wp_error( $outcome ) ) {
			Logger::log( "🔎 Probe: ✅ Revalidated in {$elapsed}s {$permalink}", __FILE__ );
			return;
		}

		$is_refusal = ( 'not_configured' === $outcome->get_error_code() );

		Logger::log(
			sprintf(
				'🔎 Probe: %s %s after %ss %s — %s: %s',
				$is_refusal ? '⛔' : '❌',
				$is_refusal ? 'Refused' : 'Failed to revalidate',
				$elapsed,
				$permalink,
				$outcome->get_error_code(),
				$outcome->get_error_message()
			),
			__FILE__,
			Logger::ERROR
		);
	}

	/**
	 * Tell the operator what the front-end answered the probe they asked for.
	 *
	 * An admin notice rather than something rendered inside the probe panel:
	 * it is the answer to a question that was just asked, it is what every
	 * other outcome in this plugin is rendered as, and it is legible whichever
	 * tab the settings screen happens to open on.
	 *
	 * @return void
	 */
	public function probe_notice() {
		if ( ! isset( $_GET[ self::SENDBACK_ARG ] ) ) return;

		$result = self::take_result();
		if ( is_null( $result ) ) return;

		printf(
			'<div class="notice notice-%s nextjs-revalidate-probe__notice"><p>%s</p></div>',
			esc_attr( $result['status'] ),
			esc_html( $result['message'] )
		);
	}

	/**
	 * The probe panel of the settings screen: the field, the button, and what
	 * the operator needs to know before pressing it.
	 *
	 * Its own form, and therefore rendered beside the settings form rather than
	 * inside it — a form cannot be nested in another. Static because a probe
	 * keeps no state between the request that ran it and the request that
	 * renders the answer, so there is nothing here for an instance to hold.
	 *
	 * @return void
	 */
	public static function render_panel() {

		$path = self::path( self::submitted( $_GET[ self::SENDBACK_ARG ] ?? '' ) );

		?>
		<form class="njr-settings__probe-form" method="post" action="<?php echo esc_url( admin_url( 'options-general.php?page=' . Settings::PAGE_NAME ) ); ?>">
			<section id="tab-panel--probe" role="tabpanel" tabindex="-1" aria-labelledby="tab-probe" aria-hidden="true">
				<h2><?php _e( 'Probe the front-end', 'nextjs-revalidate' ); ?></h2>
				<p>
					<?php _e( 'Ask the front-end to rebuild one path now, and see what it answers — including the error, when there is one.', 'nextjs-revalidate' ); ?>
				</p>
				<?php wp_nonce_field( self::ACTION ); ?>
				<p>
					<label for="<?php echo esc_attr( self::PATH_FIELD ); ?>"><?php _e( 'Path to revalidate', 'nextjs-revalidate' ); ?></label>
					<input
						type="text"
						id="<?php echo esc_attr( self::PATH_FIELD ); ?>"
						name="<?php echo esc_attr( self::PATH_FIELD ); ?>"
						value="<?php echo esc_attr( $path ); ?>"
						placeholder="<?php echo esc_attr( self::DEFAULT_PATH ); ?>"
						class="regular-text code"
					/>
				</p>
				<p class="description">
					<?php _e( 'A path of this site, such as <code>/hello-world/</code>. The page is really rebuilt, exactly as it would be after an edit — this is not a dry run.', 'nextjs-revalidate' ); ?>
				</p>
				<p class="description">
					<?php _e( 'The saved settings are what a probe uses, not what is currently typed on the Next.js API tab — save there first, then probe.', 'nextjs-revalidate' ); ?>
				</p>
				<?php submit_button( __( 'Send probe', 'nextjs-revalidate' ), 'secondary', self::ACTION, false ); ?>
			</section>
		</form>
		<?php
	}

	/**
	 * Where this user's probe answer waits for the sendback.
	 *
	 * @return string
	 */
	private static function result_key() {
		return self::RESULT_TRANSIENT . '_' . get_current_user_id();
	}

	/**
	 * Keep one answer for the request the sendback lands on.
	 *
	 * @param array{status: string, message: string} $result
	 * @return void
	 */
	private static function store_result( array $result ) {
		set_transient( self::result_key(), $result, MINUTE_IN_SECONDS );
	}

	/**
	 * The answer this request comes back with, and forget it.
	 *
	 * Read defensively, like every other stored value this plugin reads back:
	 * the row belongs to whichever site is current and can hold anything.
	 *
	 * @return array{status: string, message: string}|null Null when there is
	 *                                                     nothing to say.
	 */
	private static function take_result() {

		$stored = get_transient( self::result_key() );

		if ( ! is_array( $stored ) ) return null;

		delete_transient( self::result_key() );

		if ( ! isset( $stored['status'], $stored['message'] ) ) return null;

		return [
			'status'  => 'success' === $stored['status'] ? 'success' : 'error',
			'message' => (string) $stored['message'],
		];
	}
}
