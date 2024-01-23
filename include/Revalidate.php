<?php

namespace NextJsRevalidate;

use DateTime;
use DateTimeZone;
use NextJsRevalidate;
use WP_Post;

// Exit if accessed directly.
defined( 'ABSPATH' ) or die( 'Cheatin&#8217; uh?' );

class Revalidate {

	private $timezone;

	const OPTION_NAME = 'nextjs-revalidate-queue';
	const CRON_HOOK_NAME = 'nextjs-revalidate-queue';
	const CRON_TRANSCIENT_NAME = 'nextjs_revalidate-doing_cron-queue';

	const MAX_NB_RUNNING_CRON = 4;

	/**
	 * Constructor.
	 */
	function __construct() {
		$this->timezone = new DateTimeZone( get_option('timezone_string') ?: 'Europe/Zurich' );

		add_action( 'wp_after_insert_post', [$this, 'on_post_save'], 99 );

		add_filter( 'page_row_actions', [$this, 'add_revalidate_row_action'], 20, 2 );
		add_filter( 'post_row_actions', [$this, 'add_revalidate_row_action'], 20, 2 );
		add_action( 'admin_init', [$this, 'revalidate_row_action'] );

		add_action( 'admin_init', [$this, 'register_bulk_actions'] );

		add_action( 'admin_notices', [$this, 'purged_notice'] );

		add_action( self::CRON_HOOK_NAME, [ $this, 'run_cron' ] );
		$this->schedule_next_cron();
	}

	function on_post_save( $post_id ) {
		// Bail for post type not viewable, nor autosave or revision, as in some cases it saves a draft!
		if ( !is_post_publicly_viewable($post_id) || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id) ) return;

		// Bail early if current request is for saving the metaboxes. (To not duplicate the purge query)
		if ( isset($_REQUEST['meta-box-loader']) ) return;

		// Ensure we do not fire this action twice. Safekeeping
		remove_action( 'wp_after_insert_post', [$this, 'on_post_save'], 99 );

		$this->add_to_queue( get_permalink( $post_id ) );
	}

	function purge( $permalink ) {

		$njr = NextJsRevalidate::init();
		if ( !$njr->settings->is_configured() ) return false;

		try {
			$response = wp_remote_get(
				$this->build_revalidate_uri( $permalink ),
				[ 'timeout' => 60 ]
			);

			if ( is_wp_error($response) ) throw new \Exception("Unable to revalidate $permalink", 1);
			return $response['response']['code'] === 200;
		} catch (\Throwable $th) {
			return false;
		}
	}

	function build_revalidate_uri( $permalink ) {
		$njr = NextJsRevalidate::init();
		return add_query_arg(
			[
				'path'   => wp_make_link_relative( $permalink ),
				'secret' => $njr->settings->secret
			],
			$njr->settings->url
		);
	}

	function add_revalidate_row_action( $actions, $post ) {

		if ( $post instanceof WP_Post || is_array( $actions ) ) {

			$njr = NextJsRevalidate::init();
			if ( $njr->settings->is_configured() ) {

				$actions['revalidate'] = sprintf(
					'<a href="%s" aria-label="%s">%s</a>',
					wp_nonce_url(
						add_query_arg(
							[
								'action'    => 'nextjs-revalidate-purge',
								'post'      => $post->ID,
							]
						),
						"nextjs-revalidate-purge_{$post->ID}"
					),
					esc_attr( sprintf( __('Purge cache of post “%s”', 'nextjs-revalidate'), get_the_title($post)) ),
					__('Purge cache', 'nextjs-revalidate'),
				);

			}
		}


		return $actions;
	}

	function revalidate_row_action() {
		if ( ! (isset( $_GET['action'] ) && $_GET['action'] === 'nextjs-revalidate-purge' && isset($_GET['post']))  ) return;

		check_admin_referer( "nextjs-revalidate-purge_{$_GET['post']}" );

		$permalink = get_permalink( $_GET['post'] );

		/**
		 * Fires before adding the post to the revalidation queue.
		 *
		 * @param int $post_id The post ID.
		 * @param string|false $permalink The post permalink. False if the post is not public.
		 */
		do_action( 'nextjs_revalidate_purge_action', $_GET['post'], $permalink );

		if ( false !== $permalink ) $this->add_to_queue( $permalink );

		$sendback  = $this->get_sendback_url();

		wp_safe_redirect(
			add_query_arg( [ 'nextjs-revalidate-purged' => (false !== $permalink ? $_GET['post'] : 0) ], $sendback )
		);
		exit;
	}

	/**
	 * Register "Purge caches" bulk action.
	 * All public post types, except "attachment" one
	 */
	function register_bulk_actions() {
		$njr = NextJsRevalidate::init();
		if ( !$njr->settings->is_configured() ) return false;

		$post_types = get_post_types([ 'public' => true ]);

		unset( $post_types['attachment'] );

		foreach ($post_types as $post_type) {
			add_filter( "bulk_actions-edit-$post_type", [$this, 'add_revalidate_bulk_action'], 99 );
			add_filter( "handle_bulk_actions-edit-$post_type",  [$this, 'revalidate_bulk_action'], 10, 3 );
		}
	}

	function add_revalidate_bulk_action( $bulk_actions ) {
		$bulk_actions['nextjs_revalidate-bulk_purge'] = __( 'Purge caches', 'nextjs-revalidate' );
		return $bulk_actions;
	}

	function revalidate_bulk_action( $redirect_url, $action, $post_ids ) {
		if ($action === 'nextjs_revalidate-bulk_purge') {

			$purged = 0;
			foreach ($post_ids as $post_id) {
				$permalink = get_permalink( $post_id );

				/**
				 * Fires before adding the post to the revalidation queue.
				 *
				 * @param int $post_id The post ID.
				 * @param string|false $permalink The post permalink. False if the post is not public.
				 */
				do_action( 'nextjs_revalidate_purge_action', $post_id, $permalink );

				if ( false !== $permalink ) {
					$this->add_to_queue( $permalink );
					$purged++;
				}
			}

			$redirect_url = add_query_arg('nextjs-revalidate-bulk-purged', $purged, $this->get_sendback_url($redirect_url));
		}

		return $redirect_url;
	}

	function purged_notice() {
		if ( isset( $_GET['nextjs-revalidate-purged'] ) ) {

			$success = boolval($_GET['nextjs-revalidate-purged']);
			printf(
				'<div class="notice notice-%s"><p>%s</p></div>',
				$success ? 'success' : 'error',
				($success
					? sprintf( __( '“%s” cache will be purged shortly.', 'nextjs-revalidate' ), get_the_title($_GET['nextjs-revalidate-purged']) )
					: __( 'Unable to purge cache. Please try again or contact an administrator.', 'nextjs-revalidate' )
				)
			);
		}

		if ( isset($_GET['nextjs-revalidate-bulk-purged']) ) {

			$nb_purged = intval($_GET['nextjs-revalidate-bulk-purged']);
			$success = $nb_purged > 0;

			printf(
				'<div class="notice notice-%s"><p>%s</p></div>',
				$success ? 'success' : 'error',
				($success
					? sprintf( _n( '%d cache will be purged shortly.', '%d caches will be purged shortly.', $nb_purged, 'nextjs-revalidate' ), $nb_purged )
					: __( 'Unable to purge cache. Please try again or contact an administrator.', 'nextjs-revalidate' )
				)
			);
		}
	}

	function get_sendback_url( $sendback = null) {
		if ( empty($sendback) ) $sendback  = wp_get_referer();

		if ( ! $sendback ) {
			$sendback = admin_url( 'edit.php' );
			$post_type = get_post_type($_GET['post']);
			if ( ! empty( $post_type ) ) {
				$sendback = add_query_arg( 'post_type', $post_type, $sendback );
			}
		}

		$sendback = remove_query_arg(
			[ 'action',
				'trashed',
				'untrashed',
				'deleted',
				'ids',
				'nextjs-revalidate-purged',
				'nextjs-revalidate-bulk-purged'
			],
			$sendback
		);

		return $sendback;
	}

	/**
	 * Schedule the cron for the next sync sync
	 */
	private function schedule_next_cron() {
		if ( wp_next_scheduled(self::CRON_HOOK_NAME) ) return;

		// Do not schedule if queue is empty
		global $wpdb;
		$queue = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name = %s", self::OPTION_NAME ) );
		$queue = maybe_unserialize( $queue );
		if ( !is_array($queue) || count($queue) === 0 ) return;

		$next_cron_datetime = new DateTime( 'now', $this->timezone );
		wp_schedule_single_event( $next_cron_datetime->getTimestamp(), self::CRON_HOOK_NAME );
	}

	public static function unschedule_cron() {
		wp_unschedule_hook( self::CRON_HOOK_NAME );
	}

	private function add_running_cron() {
		$nb_running_cron = get_transient( self::CRON_TRANSCIENT_NAME );
		$nb_running_cron = intval(false === $nb_running_cron ? 0 : $nb_running_cron);

		if ( $nb_running_cron >= self::MAX_NB_RUNNING_CRON ) return false;

		$nb_running_cron++;
		set_transient( self::CRON_TRANSCIENT_NAME, $nb_running_cron, 3600 );

		$this->schedule_next_cron();

		return $nb_running_cron;
	}

	private function remove_running_cron() {
		$nb_running_cron = get_transient( self::CRON_TRANSCIENT_NAME );
		$nb_running_cron = intval(false === $nb_running_cron ? 0 : $nb_running_cron);

		$nb_running_cron--;
		if ( $nb_running_cron > 0 ) set_transient( self::CRON_TRANSCIENT_NAME, $nb_running_cron, 3600 );
		else delete_transient( self::CRON_TRANSCIENT_NAME );

		return true;
	}

	/**
	 * Run the cron
	 * Will run multiple items in the queue until the max execution time is reached
	 */
	public function run_cron() {
		$n_cron = $this->add_running_cron();
		if ( false === $n_cron ) return;

		$start_time = time();

		// get max php exec time
		$max_exec_time = ini_get('max_execution_time');
		$max_exec_time = $max_exec_time ? $max_exec_time : 60;

		// Remove 5% as a safety margin
		$max_exec_time = $max_exec_time * 0.95;

		do {
			$permalink = $this->get_next_item_in_queue();

			if ( $permalink ) $this->purge( $permalink );

		} while ($permalink && $max_exec_time > (time() - $start_time) );

		$this->remove_running_cron();

		$this->schedule_next_cron();
	}

	private function get_next_item_in_queue() {
		global $wpdb;

		$wpdb->query('START TRANSACTION');

		$queue = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name = %s FOR UPDATE", self::OPTION_NAME ) );
		$queue = maybe_unserialize( $queue );

		$permalink = array_shift( $queue );

		$wpdb->update(
			$wpdb->options,
			[ 'option_value' => maybe_serialize( $queue ) ],
			[ 'option_name'  => self::OPTION_NAME ]
		);

		$wpdb->query('COMMIT');

		return $permalink;
	}

	public function get_queue($force_from_db = false) {
		if($force_from_db) {
			wp_cache_delete(SELF::OPTION_NAME, 'options' );
		}

		return get_option( self::OPTION_NAME, [] );
	}

	private function save_queue( $value ) {
		return update_option( self::OPTION_NAME, $value, false );
	}

	public function add_to_queue( $permalink )  {
		global $wpdb;

		$wpdb->query('START TRANSACTION');

		$queue_option = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name = %s FOR UPDATE", self::OPTION_NAME));
		$queue = maybe_unserialize($queue_option);

		if (!is_array($queue)) {
			$queue = [];
			$wpdb->insert(
				$wpdb->options,
				[
					'option_name'  => self::OPTION_NAME,
					'option_value' => maybe_serialize( [] )
				]
			);
		}

		if (!in_array($permalink, $queue)) {
			$queue[] = $permalink;

			$wpdb->update(
				$wpdb->options,
				[ 'option_value' => maybe_serialize( $queue ) ],
				[ 'option_name'  => self::OPTION_NAME ]
			);
		}

		$wpdb->query('COMMIT');

		// No need to schedule a cron if queue is empty
		if (empty($queue)) return;

		// Make sure a cron is schedule
		$this->schedule_next_cron();
	}
}
