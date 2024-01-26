<?php

namespace NextJsRevalidate\Cron;

use DateTime;
use DateTimeZone;
use NextJsRevalidate\Abstracts\Base;

class ScheduledPurges extends Base {

	const CRON_HOOK_NAME = 'nextjs-revalidate-scheduled_purges';
	const OPTION_NAME    = 'nextjs-revalidate-scheduled_purges';

	private $timezone;

	public function __construct() {
		add_action( self::CRON_HOOK_NAME, [$this, 'run_cron_hook'] );
		$this->timezone = new DateTimeZone( 'Europe/Zurich' ); // TODO: maybe use timezone set in WP settings
	}

	public function run_cron_hook() {

		$entries = get_option( self::OPTION_NAME, [] );
		$now = new DateTime('now', $this->timezone );

		$left_entries = [];
		foreach ($entries as $datetime => $urls) {

			$next_purge_datetime = new DateTime( $datetime );
			if ( $next_purge_datetime <= $now ) {
				foreach ($urls as $url) {
					$this->queue->add_item( $url, true );
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
	 * Schedule a purge to be performed
	 *
	 * @param String $datetime The date time string when to purge.
	 * @param String $url      The URL to purge.
	 *
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

		// Save new entries
		update_option( self::OPTION_NAME, $entries );

		$this->schedule_cron();

		return true;
	}
}
