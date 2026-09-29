<?php

namespace NextJsRevalidate\Cron;

use DateTime;
use DateTimeZone;
use NextJsRevalidate\Abstracts\Base;
use NextJsRevalidate\Change;
use NextJsRevalidate\Interfaces\Hookable;
use NextJsRevalidate\Logger;

/**
 * The **scheduled purges**: paths registered to be revalidated at a future
 * time, each reported as a path change by the cron request that finds it due.
 *
 * @property \NextJsRevalidate\PendingChanges $pendingChanges
 */
class ScheduledPurges extends Base implements Hookable {

	const CRON_HOOK_NAME = 'nextjs-revalidate-scheduled_purges';
	const OPTION_NAME    = 'nextjs-revalidate-scheduled_purges';

	private $timezone;

	public function __construct() {
		$this->timezone = new DateTimeZone( 'Europe/Zurich' ); // TODO: maybe use timezone set in WP settings
	}

	public function register_hooks(): void {
		add_action( self::CRON_HOOK_NAME, [$this, 'run_cron_hook'] );
	}

	/**
	 * Report every scheduled purge whose time has passed, and forget it.
	 *
	 * Each URL of a due entry becomes a path change in this request's pending
	 * changes, delivered when the cron request ends. The entry is dropped
	 * whatever became of its changes: an unconfigured site refuses them, and a
	 * refusal is final rather than something to keep the entry for.
	 *
	 * @return void
	 */
	public function run_cron_hook() {

		$entries = get_option( self::OPTION_NAME, [] );
		$now = new DateTime('now', $this->timezone );

		$left_entries = [];
		foreach ($entries as $datetime => $urls) {

			$next_purge_datetime = new DateTime( $datetime );
			if ( $next_purge_datetime <= $now ) {
				foreach ($urls as $url) {
					$this->report_path( $url );
				}
			}
			else {
				$left_entries[$datetime] = $urls;
			}
		}

		// Update the entries with the ones that have not been executed yet.
		update_option( self::OPTION_NAME, $left_entries );

		$this->schedule_cron();
	}

	/**
	 * Report one due URL as a path change.
	 *
	 * Registered as it was given — a permalink as often as a path — and reduced
	 * to its `uri` here, when it is reported.
	 *
	 * @param mixed $url The URL the scheduled purge was registered for.
	 * @return void
	 */
	private function report_path( $url ) {

		$uri = Change::uri_of( $url );

		if ( null === $uri ) {
			Logger::log(
				sprintf( '❌ Dropped a scheduled purge of %s — it names no path', is_string( $url ) ? $url : gettype( $url ) ),
				__FILE__,
				Logger::ERROR
			);
			return;
		}

		// No `is_configured()` guard: the pending changes refuse an
		// unconfigured site at the door, and log the refusal.
		$this->pendingChanges->report( Change::path( $uri ) );
	}

	public function schedule_cron() {
		if ( wp_next_scheduled(self::CRON_HOOK_NAME) ) return;

		$entries = get_option( self::OPTION_NAME, [] );
		$entries_key = array_keys($entries);
		$next_purge = array_shift($entries_key);

		if ( empty($next_purge) ) return;

		$next_purge_datetime = new DateTime( $next_purge );
		wp_schedule_single_event( $next_purge_datetime->getTimestamp(), self::CRON_HOOK_NAME );
	}

	public static function unschedule_cron() {
		wp_unschedule_hook( self::CRON_HOOK_NAME );
	}

	/**
	 * Drop every scheduled purge registered on the site currently being served.
	 *
	 * Per-site state like the settings, and torn down with them: a scheduled
	 * purge outliving the plugin names a path on a front-end nothing is left to
	 * revalidate.
	 *
	 * @return void
	 */
	public static function delete_scheduled_purges() {
		delete_option( self::OPTION_NAME );
	}

	/**
	 * Schedule a purge to be performed
	 *
	 * @param String $datetime The date time string when to purge.
	 * @param String $url      The URL to purge.
	 *
	 * @return bool Whether this call registered the scheduled purge. False when
	 *              the URL is already registered for that date time, and false
	 *              when the write itself failed.
	 */
	public function schedule_purge( $datetime, $url ) {

		// Normalize datetime to same timezone
		$dt = new DateTime( $datetime );
		$dt->setTimezone( $this->timezone );
		$dt_str = $dt->format( 'c' );

		$entries = get_option( self::OPTION_NAME, [] );

		if ( !array_key_exists( $dt_str, $entries) ) $entries[ $dt_str ] = [ $url ];
		else if ( !in_array($url, $entries[ $dt_str]) ) $entries[ $dt_str ][] = $url;
		else return false; // Return false as no scheduled purge has been registered

		// Sort in chronological order
		ksort( $entries );

		// Save new entries. The answer is the write's, not a constant: the
		// entries always differ from the stored ones by the time we get here —
		// the duplicate case returned above — so a false is a failed write
		// rather than update_option()'s "nothing changed".
		$registered = update_option( self::OPTION_NAME, $entries );

		// Scheduled from what was actually stored, so a failed write leaves the
		// cron describing the entries that survived rather than the ones it
		// meant to add.
		$this->schedule_cron();

		return $registered;
	}
}
