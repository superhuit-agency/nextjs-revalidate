<?php

namespace NextJsRevalidate;

use DateTime;
use DateTimeZone;
use NextJsRevalidate;
use RollingCurl\Request;
use RollingCurl\RollingCurl;
use WP_Admin_Bar;

class PurgeAll {

	const CRON_HOOK_NAME = 'nextjs-revalidate-purge_all';
	const OPTION_NAME    = 'nextjs-revalidate-purge_all';

	const BATCH_SIZE_MAX = 500;
	const MAX_SIMULTANEOUS_REQUESTS = 10;

	private DateTimeZone $timezone;
	private ?NextJsRevalidate $njr = null;

	public function __construct() {
		$this->timezone = new DateTimeZone( 'Europe/Zurich' ); // TODO: maybe use timezone set in WP settings

		add_action( 'admin_bar_menu', [$this, 'admin_top_bar_menu'], 100 );
		add_action( 'admin_notices', [$this, 'purged_notice'] );

		add_action( 'admin_init', [$this, 'purge_all_progress'] );
		add_action( 'admin_init', [$this, 'revalidate_all_pages_action'] );

		add_action( self::CRON_HOOK_NAME, [$this, 'run_cron_hook'] );
	}

	function getOptions() {
		return get_option( self::OPTION_NAME, [] );
	}

	function getMainInstance() {
		if ( is_null($this->njr) ) $this->njr = NextJsRevalidate::init();
		return $this->njr;
	}

	/**
	 * Admin
	 */
	function admin_top_bar_menu( WP_Admin_Bar $admin_bar ) {

		$admin_bar->add_menu( [
			'id'     => 'nextjs-revalidate',
			'title'  => _x( 'Purge caches', 'Admin top bar menu', 'nextjs-revalidate'),
			'meta'   => [
				'class' => "nextjs-revalidate",
			]
		] );

		$admin_bar->add_node( [
			'id'     => "nextjs-revalidate-all-pages",
			'parent' => 'nextjs-revalidate',
			'title'  => _x( 'All pages', 'Admin top bar menu', 'nextjs-revalidate' ),
			'href'   => esc_url(
				wp_nonce_url(
					add_query_arg( 'action', 'nextjs-revalidate-purge-all' ),
					'nextjs-revalidate-purge-all'
				)
			),
			'meta'   => [
				'title' => _x( 'Purging all cache may take some time according to the number of pages to purge.', 'Admin top bar menu', 'nextjs-revalidate' ),
			]
		] );

	}
	function purged_notice() {
		if ( !$this->is_purging_all() ) return;

		if ( isset($_GET['nextjs-revalidate-purge-all']) && $_GET['nextjs-revalidate-purge-all'] === 'already-running') {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				__( 'Purging all caches. is already running. Please wait until the end to restart the purge.', 'nextjs-revalidate' )
			);
		}

		$numbers = $this->get_purge_all_progress_numbers();
		printf(
			'<div class="notice notice-info nextjs-revalidate-purge-all__notice"><p>%s</p></div>',
			sprintf(
				__( 'Purging all caches. Please wait… %s', 'nextjs-revalidate' ),
				sprintf('<span class="nextjs-revalidate-purge-all__progress">%s%% (%d/%s)</span>', $numbers->progress, $numbers->done, $numbers->total )
			)
		);
	}

	/**
	 * Helpers
	 */
	function is_purging_all() {
		$opts = $this->getOptions();
		return boolval( in_array($opts['status'] ?? '', ['running']) );
	}
	function get_purge_all_progress_numbers() {
		$opts = $this->getOptions();

		$todo     = count($opts['nodes'] ?? []);
		$total    = $opts['total'] ?? 0;
		$done     = max(0, $total - $todo);
		return (object)[
			'todo'     => $todo,
			'done'     => $done,
			'total'    => $total,
			'progress' => $total > 0 ? round( $done / $total * 100 ) : 0,
		];
	}

	/**
	 * Purge all action
	 */
	function revalidate_all_pages_action() {
		if ( ! (isset( $_GET['action'] ) && $_GET['action'] === 'nextjs-revalidate-purge-all')  ) return;

		check_admin_referer( 'nextjs-revalidate-purge-all' );

		$sendback = $this->getMainInstance()->revalidate->get_sendback_url();

		if ( $this->is_purging_all() ) $sendback = add_query_arg( ['nextjs-revalidate-purge-all' => 'already-running'], $sendback );
		else $this->purge_all();

		wp_safe_redirect( $sendback );
		exit;
	}

	/**
	 * Ajax callback to retrieve the purge all progress data
	 */
	function purge_all_progress() {
		if ( !isset($_GET['action']) || $_GET['action'] !== 'nextjs-revalidate-purge-all-progress' ) return;

		if ( false === check_ajax_referer( 'nextjs-revalidate-purge_all_progress' ) ) return;

		$opts = $this->getOptions();

		$numbers = $this->get_purge_all_progress_numbers();
		$done = $numbers->done;
		$total = $numbers->total;
		$progress = $numbers->progress;
		switch ( $opts['status'] ?? '' ) {
			case 'done':
				$status = 'done';

				break;
			case 'running':
				$status = 'running';

				break;
			default:
				$status = 'not-running';
		}

		wp_send_json_success( [
			'status' => $status,
			'done'   => $done ?? 0,
			'total'  => $total ?? 0,
			'progress'  => $progress ?? 0,
		] );
		exit;
	}

	/**
	 * Retrive all content nodes to purge, saves them in option
	 * and schedule the purge all cron to run.
	 */
	function purge_all() {
		if ( !$this->getMainInstance()->settings->is_configured() ) return false;

		$nodes = [];

		// retrieve all public post types
		$post_types = get_post_types([ 'public' => true ]);
		foreach ($post_types as $post_type) {
			if ( $post_type === 'attachment' ) continue; // skip attachments
			$posts = get_posts([
				'post_type'      => $post_type,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'post_status'    => 'publish',
			]);

			$nodes = array_merge( $nodes, array_map(function($p) { return ['key' => "post_$p", 'type' => 'post', 'id' => $p]; }, $posts) );
		}

		// retrieve all public taxonomies
		$taxonomies = get_taxonomies([ 'public' => true ]);
		foreach ($taxonomies as $taxonomy) {
			$terms = get_terms([
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'ids',
			]);
			$nodes = array_merge( $nodes, array_map(function($t) { return ['key'=> "term_$t", 'type' => 'term', 'id' => $t]; }, $terms) );
		}

		update_option( self::OPTION_NAME, [
			'status' => 'running',
			'nodes'  => $nodes,
			'total'  => count($nodes),
		] );

		$this->schedule_next_cron();
	}

	/**
	 * Run the purge all cron
	 */
	public function run_cron_hook() {

		if ( !$this->getMainInstance()->settings->is_configured() ) return false;

		$purge_all = $this->getOptions();

		// Bail early if status not running
		if ( $purge_all['status'] !== 'running') return;

		$rollingCurl = new RollingCurl();

		$bacth_count = min(count($purge_all['nodes']), self::BATCH_SIZE_MAX);
		for ($i=0; $i < $bacth_count; $i++) {
			$node = $purge_all['nodes'][$i];

			switch ($node['type']) {
				case 'term':
					$url = get_term_link( $node['id'] );
					break;

				case 'post':
				default:
					$url = get_permalink( $node['id'] );
					break;
			}

			$request = new Request( $this->getMainInstance()->revalidate->build_revalidate_uri( $url ));
			$request->addOptions([ CURLOPT_TIMEOUT => 60 ]);
			$request->setExtraInfo( $node['key'] );
			$rollingCurl->add( $request );
		}

		$rollingCurl
			->setCallback(function(Request $request, RollingCurl $rollingCurl) {
				$url = $request->getUrl();

				$opts = $this->getOptions();

				if ( false !== $url ) {
					$node_key = $request->getExtraInfo();
					$idx = array_search($node_key, array_column($opts['nodes'], 'key'));
					if ( $idx !== false ) {
						array_splice( $opts['nodes'], $idx, 1 );
						update_option( self::OPTION_NAME, $opts );
					}
				}
			})
			->setSimultaneousLimit(self::MAX_SIMULTANEOUS_REQUESTS)
			->execute();

		$opts = $this->getOptions();
		if ( empty($opts['nodes']) ) {
			$opts['status'] = 'done';
			update_option( self::OPTION_NAME, $opts );
		}
		else {
			$this->schedule_next_cron();
		}
	}

	/**
	 * Schedule the next cron for the purge all
	 */
	public function schedule_next_cron() {
		if ( wp_next_scheduled(self::CRON_HOOK_NAME) ) return;

		$opts = $this->getOptions();
		if ( $opts['status'] !== 'running') return;
		if ( empty($opts['nodes']) ) {
			$opts['status'] = 'done';
			update_option( self::OPTION_NAME, $opts );
			return;
		}

		$next_purge_datetime = new DateTime( 'now', $this->timezone );
		wp_schedule_single_event( $next_purge_datetime->getTimestamp(), self::CRON_HOOK_NAME );
	}

	/**
	 * Unschedule the cron for the purge all
	 */
	public static function unschedule_cron() {
		wp_unschedule_hook( self::CRON_HOOK_NAME );
	}
}
