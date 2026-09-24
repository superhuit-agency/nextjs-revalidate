<?php

namespace NextJsRevalidate;

use NextJsRevalidate\Abstracts\Base;
use NextJsRevalidate\Interfaces\Hookable;
use NextJsRevalidate\Traits\FrontEndRequest;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) or die( 'Cheatin&#8217; uh?' );

/**
 * The **pending changes**: the changes this request has produced and not yet
 * delivered, per site, and their delivery.
 *
 * Code that sees something happen to a WordPress subject builds a `Change` and
 * hands it to `report()`. It joins the pending changes of the site current at
 * that moment, merged with any change already held for the same subject, and
 * the front-end is told about all of them at once when the request ends — one
 * `POST` per site, after the person who caused them has had their response.
 *
 * The pattern is the one the FSE snapshot shipped in 1.7.0, generalised from
 * one flag to every change: collect during the request, answer first, deliver
 * from `shutdown`. See `docs/adr/0034-changes-are-delivered-when-the-request-ends.md`.
 *
 * One request is one **revalidation**. It succeeds or fails as a whole, enters
 * the failure window as one outcome whatever number of changes it carried, and
 * a failure drops every one of them: delivery is at most once (ADR 0004).
 *
 * @property Settings $settings The settings of whichever site is current.
 */
class PendingChanges extends Base implements Hookable {
	use FrontEndRequest;

	/**
	 * How many changes a site may hold before they are sent without waiting
	 * for the request to end.
	 *
	 * A WP-CLI import is one process whose `shutdown` comes at the very end;
	 * without a cap it would build an unbounded body. Counted after merging,
	 * so a post saved a thousand times is still one.
	 */
	const CAP = 100;

	/**
	 * Seconds to wait for the front-end to answer.
	 *
	 * Marking cache tags stale takes a front-end milliseconds. On a server that
	 * cannot answer the editor first the editor waits for this, and a
	 * front-end that needs longer than it is a failure worth surfacing.
	 */
	const REQUEST_TIMEOUT = 5;

	/**
	 * The version of the request's contract. Raised only for a breaking change
	 * to it (ADR 0033, rule 2).
	 */
	const CONTRACT_VERSION = 2;

	/**
	 * The pending changes, per site: site ID => identity => change.
	 *
	 * Keyed by identity so that a second change to the same subject lands on
	 * the first, and keeps its place in the order the changes were produced.
	 *
	 * @var array<int, array<string, array>>
	 */
	private array $pending = [];

	public function register_hooks(): void {
		// `shutdown` runs after `exit()` too, so the redirect a theme switch or
		// a row action ends in does not skip it.
		add_action( 'shutdown', [$this, 'deliver'] );
	}

	/**
	 * Hand over a change: it joins the pending changes of the current site.
	 *
	 * In this order, and each step can end it:
	 *
	 *  1. **An unconfigured site refuses it**, now rather than at delivery, and
	 *     logs the refusal. A refused change is never held.
	 *  2. **The `nextjs_revalidate_change` filter** is applied, and can return
	 *     the change altered or not, or `false` to drop it.
	 *  3. **It merges** with a change already held for the same subject — the
	 *     state before the first, the state after the last. A post that ends
	 *     the request where it started, off the front-end, is then no change at
	 *     all and is let go.
	 *  4. **At the cap**, the site's pending changes are sent there and then, and
	 *     collecting starts again.
	 *
	 * @param array $change A change, as `Change` builds one.
	 * @return true|false|WP_Error True when the change is held for delivery (or
	 *                             merged into nothing), false when the filter
	 *                             dropped it, and the `not_configured` WP_Error
	 *                             when the site refused it.
	 */
	public function report( array $change ) {

		$subject = Change::is_change( $change ) ? $change['subject'] : '?';

		// A refusal rather than a failure: the front-end is asked nothing at
		// all, and nothing about this change outlives this line.
		if ( ! $this->settings->is_configured() ) {
			Logger::log(
				sprintf( '⛔ Refused a %s change — site not configured (missing: %s)', $subject, implode( ', ', $this->settings->missing_settings() ) ),
				__FILE__,
				Logger::ERROR
			);

			return $this->settings->not_configured_error();
		}

		/**
		 * Filters a change before it joins the pending changes.
		 *
		 * Return the change, altered or not, or `false` to drop it. What is
		 * returned is sent as it is, so it is the site's to keep in the shape
		 * its front-end reads — see ADR 0033 for the shapes this plugin sends.
		 *
		 * @param array|false $change The change, with its `subject` and that subject's
		 *                            fields — or `false`, which is what a callback
		 *                            returns to drop it.
		 */
		$filtered = apply_filters( 'nextjs_revalidate_change', $change );

		if ( false === $filtered ) {
			Logger::log( sprintf( '🚫 A %s change was dropped by the nextjs_revalidate_change filter', $subject ), __FILE__ );
			return false;
		}

		// Something that is not a change cannot be sent, and guessing what the
		// filter meant would send something nobody asked for.
		if ( ! Change::is_change( $filtered ) ) {
			Logger::log(
				sprintf( '❌ Dropped a %s change — the nextjs_revalidate_change filter returned something that is not a change', $subject ),
				__FILE__,
				Logger::ERROR
			);
			return false;
		}

		$site_id  = get_current_blog_id();
		$identity = Change::identity( $filtered );

		$held   = $this->pending[ $site_id ][ $identity ] ?? null;
		$merged = is_null( $held ) ? $filtered : Change::merge( $held, $filtered );

		if ( Change::is_void( $merged ) ) {
			unset( $this->pending[ $site_id ][ $identity ] );
			if ( empty( $this->pending[ $site_id ] ) ) unset( $this->pending[ $site_id ] );

			return true;
		}

		$this->pending[ $site_id ][ $identity ] = $merged;

		if ( count( $this->pending[ $site_id ] ) >= self::CAP ) $this->deliver_site( $site_id );

		return true;
	}

	/**
	 * The changes the current site holds, in the order they were produced.
	 *
	 * @return array[]
	 */
	public function pending() {
		return array_values( $this->pending[ get_current_blog_id() ] ?? [] );
	}

	/**
	 * Deliver every site's pending changes, one request per site, once the
	 * editor has had their response.
	 *
	 * @return void
	 */
	public function deliver() {

		if ( empty( $this->pending ) ) return;

		$this->close_request();

		foreach ( array_keys( $this->pending ) as $site_id ) $this->deliver_site( $site_id );
	}

	/**
	 * Deliver one site's pending changes, from whichever site is current.
	 *
	 * A change belongs to the site that was current when it was produced, and
	 * is sent with that site's endpoint and secret, logged in that site's log
	 * and recorded in that site's failure window. The current site at
	 * `shutdown` is whatever the request last left it as, so the site is
	 * switched to rather than assumed.
	 *
	 * @param int $site_id
	 * @return void
	 */
	private function deliver_site( $site_id ) {

		$changes = array_values( $this->pending[ $site_id ] ?? [] );

		// Taken before the request rather than after it: whatever fires from
		// here on — a chunk sent mid-request goes on collecting — these have
		// had their one attempt.
		unset( $this->pending[ $site_id ] );

		if ( empty( $changes ) ) return;

		$switched = ( get_current_blog_id() !== $site_id );
		if ( $switched ) switch_to_blog( $site_id );

		try {
			$this->send( $changes );
		} finally {
			if ( $switched ) restore_current_blog();
		}
	}

	/**
	 * Send the current site's changes in one request, and record the one
	 * outcome it had.
	 *
	 * @param array[] $changes
	 * @return true|WP_Error What the request answered, or the `not_configured`
	 *                       refusal for a site that could not send it at all.
	 */
	private function send( array $changes ) {

		$what = self::describe( $changes );

		// A site whose settings were cleared after its changes were produced:
		// the same refusal `report()` gives, given later. The front-end is asked
		// nothing, so the failure window is told nothing.
		if ( ! $this->settings->is_configured() ) {
			Logger::log(
				sprintf( '⛔ Refused %s — site not configured (missing: %s)', $what, implode( ', ', $this->settings->missing_settings() ) ),
				__FILE__,
				Logger::ERROR
			);

			return $this->settings->not_configured_error();
		}

		$outcome = $this->send_front_end_changes(
			$this->settings->endpoint_url(),
			[
				'version' => self::CONTRACT_VERSION,
				'changes' => $changes,
			],
			self::REQUEST_TIMEOUT
		);

		// One outcome however many changes the request carried: the front-end
		// answered once, for all of them.
		FailureWindow::record( $outcome );

		if ( is_wp_error( $outcome ) ) {
			Logger::log(
				sprintf( '❌ Failed to revalidate %s — %s: %s', $what, $outcome->get_error_code(), $outcome->get_error_message() ),
				__FILE__,
				Logger::ERROR
			);
		}
		else {
			Logger::log( sprintf( '✅ Revalidated %s', $what ), __FILE__ );
		}

		return $outcome;
	}

	/**
	 * How many changes, and of which subjects, for a log line.
	 *
	 * `3 changes (post ×2, templates)`: the subjects in the order they first
	 * appear, each counted when there is more than one.
	 *
	 * @param array[] $changes
	 * @return string
	 */
	private static function describe( array $changes ) {

		$subjects = [];
		foreach ( $changes as $change ) {
			$subject = (string) $change['subject'];
			$subjects[ $subject ] = ( $subjects[ $subject ] ?? 0 ) + 1;
		}

		$named = [];
		foreach ( $subjects as $subject => $count ) {
			$named[] = ( $count > 1 ) ? sprintf( '%s ×%d', $subject, $count ) : (string) $subject;
		}

		return sprintf(
			'%d %s (%s)',
			count( $changes ),
			1 === count( $changes ) ? 'change' : 'changes',
			implode( ', ', $named )
		);
	}

	/**
	 * Answer the editor before asking another host anything.
	 *
	 * `shutdown` runs after the request has produced its response, but produced
	 * is not delivered: under PHP-FPM the response sits in the buffer until
	 * the process ends, so without this the person who pressed Save waits out
	 * our timeout as well as their own save. Flushing here is what makes the
	 * delivery asynchronous *from the editor's side*, and it costs nothing
	 * that matters — the outcome is still awaited, recorded and logged.
	 *
	 * Nothing after this point can add to the response, which is precisely the
	 * property being relied on: `shutdown` has no output left to produce, and
	 * the SAPIs with no such call are left running as they did. Never called
	 * for a chunk sent at the cap, which happens while the response is still
	 * being produced.
	 *
	 * @return void
	 */
	private function close_request() {

		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
			return;
		}

		// LiteSpeed's equivalent, under its own name.
		if ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}
	}
}
