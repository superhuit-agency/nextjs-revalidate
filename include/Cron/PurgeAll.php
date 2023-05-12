<?php

namespace NextJsRevalidate\Cron;

use DateTime;
use DateTimeZone;
use NextJsRevalidate;

class PurgeAll {

	const CRON_HOOK_NAME = 'nextjs-revalidate-purge_all';
	const OPTION_NAME    = 'nextjs-revalidate-purge_all';

	const BATCH_SIZE = 50;

	private $timezone;

	public function __construct() {
		add_action( self::CRON_HOOK_NAME, [$this, 'run_cron_hook'] );
		$this->timezone = new DateTimeZone( 'Europe/Zurich' ); // TODO: maybe use timezone set in WP settings
	}

	public function run_cron_hook() {
		$njr = NextJsRevalidate::init();

		$purge_all = get_option( self::OPTION_NAME, [] );

		// Bail early if status not running
		if ( $purge_all['status'] !== 'running') return;

		for ($i=0; $i < self::BATCH_SIZE; $i++) {
			$node = array_shift( $purge_all['nodes'] );

			switch ($node['type']) {
				case 'term':
					$url = get_term_link( $node['id'] );
					break;

				case 'post':
				default:
					$url = get_permalink( $node['id'] );
					break;
			}


			if ( $url !== false ) $purged = $njr->revalidate->purge( $url );
			else error_log( "Could not retrive URL for node: " . json_encode($node) );


			update_option( self::OPTION_NAME, $purge_all );
		}

		if ( empty($purge_all['nodes']) ) {
			$purge_all['status'] = 'done';
			update_option( self::OPTION_NAME, $purge_all );
		}
		else {
			$this->schedule_next_cron();
		}
	}

	public function schedule_next_cron() {
		if ( wp_next_scheduled(self::CRON_HOOK_NAME) ) return;

		$purge_all = get_option( self::OPTION_NAME, [] );
		if ( $purge_all['status'] !== 'running') return;
		if ( empty($purge_all['nodes']) ) {
			$purge_all['status'] = 'done';
			update_option( self::OPTION_NAME, $purge_all );
			return;
		}

		$next_purge_datetime = new DateTime( 'now', $this->timezone );
		wp_schedule_single_event( $next_purge_datetime->getTimestamp(), self::CRON_HOOK_NAME );
	}

	public static function unschedule_cron() {
		wp_unschedule_hook( self::CRON_HOOK_NAME );
	}

	/**
	 * Register the cron to purge all caches
	 */
	public function start() {
		$this->schedule_next_cron();
	}
}
