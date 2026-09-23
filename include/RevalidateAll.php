<?php

namespace NextJsRevalidate;

use NextJsRevalidate\Abstracts\Base;
use NextJsRevalidate\Interfaces\Hookable;
use NextJsRevalidate\Traits\AdminBarMenu;
use NextJsRevalidate\Traits\SendbackUrl;
use WP_Admin_Bar;

/**
 * @property Revalidate $revalidate
 */
class RevalidateAll extends Base implements Hookable {
	use AdminBarMenu;
	use SendbackUrl;

	public function register_hooks(): void {
		add_action( 'admin_bar_menu', [$this, 'admin_top_bar_menu'], 100 );
		add_action( 'admin_notices', [$this, 'revalidated_notice'] );

		add_action( 'admin_init', [$this, 'revalidate_all_pages_action'] );

		add_action( 'wp_update_nav_menu', [$this, 'revalidate_all_after_menu_update'] );
	}

	/**
	 * Admin
	 * Display the Purge all pages/posts/... dropdown in Admin top bar
	 */
	function admin_top_bar_menu( WP_Admin_Bar $admin_bar ) {

		// An unconfigured site refuses every revalidation, so do not offer the
		// menu at all — the same way the row action and the bulk action are
		// not offered. The unconfigured notice says why.
		if ( !$this->settings->is_configured() ) return;

		$revalidate_all_opts = $this->settings->allow_revalidate_all;

		if ( empty($revalidate_all_opts) ) return;

		$offered = $this->revalidate->offered_post_types();

		$this->add_admin_bar_menu( $admin_bar );

		foreach ($revalidate_all_opts as $post_type => $allow) {
			if ( $allow !== 'on' ) continue;

			if ( $post_type === 'all') {
				$name = _x('All', 'Admin top bar menu', 'nextjs-revalidate' );
			}
			else {
				// A toggle stored for a post type this plugin does not offer —
				// one unregistered since it was ticked, or one the settings
				// page offered on the `public` it asked for before #53 — would
				// be an entry that purges nothing. The row itself is left in
				// the option: it is the operator's, and the next save of the
				// settings page drops it anyway, the form posting only the
				// switches it rendered.
				if ( !isset($offered[$post_type]) ) continue;

				$post_type_object = get_post_type_object( $post_type );
				if (!$post_type_object) continue;
				$name = $post_type_object->labels->name;
			}

			$admin_bar->add_node( [
				'id'     => "nextjs-revalidate-all-$post_type",
				'parent' => 'nextjs-revalidate',
				'title'  => $name,
				'href'   => esc_url(
					wp_nonce_url(
						add_query_arg(
							[
								'action'                 => 'nextjs-revalidate-revalidate-all',
								'nextjs-revalidate-type' => $post_type
							]
						),
						'nextjs-revalidate-revalidate-all'
					)
				),
				'meta'   => [
					'title' => _x( 'Purging all cache may take some time according to the number of pages to purge.', 'Admin top bar menu', 'nextjs-revalidate' ),
				]
			] );
		}
	}

	/**
	 * Display a success admin notice when all page revalidate has been triggered
	 */
	function revalidated_notice() {
		if ( isset($_GET['nextjs-revalidate-revalidate-all-refused']) ) {
			// The query arg is the only thing that puts this on screen, so
			// anyone can put it there. Say it to the people it is about.
			if ( !current_user_can('edit_posts') && !current_user_can('manage_options') ) return;

			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Revalidate all: nothing was queued, this site is not configured.', 'nextjs-revalidate' )
			);
			return;
		}

		if ( !isset($_GET['nextjs-revalidate-revalidate-all']) ) return;

		printf(
			'<div class="notice notice-success"><p>%s</p></div>',
			sprintf(
				__( 'Purge all: %d pages added to purge. Please wait until all pages are purged.', 'nextjs-revalidate' ),
				$_GET['nextjs-revalidate-revalidate-all']
			)
		);
	}

	/**
	 * Revalidate all action
	 */
	function revalidate_all_pages_action() {
		if ( ! (isset( $_GET['action'] ) && $_GET['action'] === 'nextjs-revalidate-revalidate-all')  ) return;

		check_admin_referer( 'nextjs-revalidate-revalidate-all' );

		$nb_added = $this->revalidate_all( $_GET['nextjs-revalidate-type'] );
		$sendback = add_query_arg(
			( false === $nb_added
				? [ 'nextjs-revalidate-revalidate-all-refused' => 1 ]
				: [ 'nextjs-revalidate-revalidate-all' => $nb_added ]
			),
			$this->get_sendback_url()
		);

		wp_safe_redirect( $sendback );
		exit;
	}

	/**
	 * Revalidate all content after a menu update
	 *
	 * @param int $menu_id
	 * @return void
	 */
	function revalidate_all_after_menu_update( $menu_id ) {
		$revalidate_on_save = $this->settings->revalidate_on_menu_save;

		if (isset($revalidate_on_save['all']) && $revalidate_on_save['all'] === 'on') {
			$this->revalidate_all();
		}
		else {
			$offered = $this->revalidate->offered_post_types();

			foreach ($revalidate_on_save as $post_type => $enabled) {
				if ( $enabled !== 'on' ) continue;

				// A switch stored for a post type this plugin no longer offers
				// is one the settings page does not show, so it does not act
				// either — the same as the admin bar's purge-all entries. The
				// gate would decline its posts anyway; this spares the walk.
				if ( !isset($offered[$post_type]) ) continue;

				$this->revalidate_all($post_type);
			}
		}
	}

	/**
	 * Retrive all post type content nodes to revalidate, saves them in option
	 * and schedule the revalidate all cron to run.
	 *
	 * @param string $type Optional. The type of post type to revalidate. Default. 'all'.
	 * @return int The number of nodes added to revalidate
	 */
	function revalidate_all( $type = 'all' ) {
		if ( !$this->settings->is_configured() ) {
			Logger::log(
				sprintf( '⛔ Refused revalidate all (%s) — site not configured (missing: %s)', $type, implode(', ', $this->settings->missing_settings()) ),
				__FILE__,
				Logger::ERROR
			);
			return false;
		}

		$count = 0;
		if ( $type === 'all' ) {
			// The post types this plugin offers, rather than the `public` ones
			// this asked for until #53: a `public` type that is not viewable has
			// every one of its posts declined by the gate below, and a viewable
			// one that is not `public` was walked by nothing at all.
			//
			// A pre-filter, unlike the taxonomy selection further down, and
			// deliberately so — the gate for a post sits *below* this
			// enumeration, once per post, where the gate for a taxonomy sits
			// above it. Walking every registered type would mean reading every
			// revision, menu item and product variation on the site to be told
			// no. See `docs/adr/0025-viewability-selects-the-post-types-offered.md`.
			$post_types = $this->revalidate->offered_post_types();
		}
		else {
			// A named type is the caller's own instruction and is walked
			// whatever this plugin would have offered of its own accord. The
			// gate still answers for every post either way.
			$post_types = [ $type ];
		}

		foreach ($post_types as $post_type) {
			$posts = get_posts([
				'post_type'      => $post_type,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'post_status'    => ['publish', 'private'],
			]);

			foreach ($posts as $post_id) {
				$permalink = $this->revalidate->get_post_permalink( $post_id );

				// A post that is not revalidatable yields no permalink, and no queue item.
				if ( empty($permalink) ) continue;

				$this->queue->add_item( $permalink );
				$count++;
			}
		}

		foreach ($this->revalidatable_taxonomies( $type ) as $taxonomy) {
			$terms = get_terms([
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'ids',
			]);

			foreach ($terms as $term_id) {
				$this->queue->add_item( get_term_link( $term_id ) );
				$count++;
			}
		}

		return $count;
	}

	/**
	 * The taxonomies whose terms revalidate-all enumerates.
	 *
	 * Every registered taxonomy is offered to the gate, and the gate alone
	 * decides. Pre-selecting by `public` — which is what this did before #54 —
	 * put a second, unfilterable authority in front of a filterable one: a site
	 * could hook `nextjs_revalidate_should_revalidate_taxonomy` to admit its
	 * headless taxonomy and still get nothing, with no way to see why. That is
	 * worse than no gate, and it is the shape ADR 0005 dismantled on the post
	 * side.
	 *
	 * `public` and `publicly_queryable` are independent settings, so the old
	 * selector disagreed with viewability in both directions: a `public`
	 * taxonomy that is not `publicly_queryable` had every one of its terms
	 * enqueued, ungated; a `publicly_queryable` one that is not `public` was
	 * missed entirely, archive page and all, with nothing enqueued and nothing
	 * logged to say so.
	 *
	 * See `docs/adr/0022-taxonomy-viewability-gates-term-revalidation.md`.
	 *
	 * @param string $type Optional. The post type being revalidated, or 'all'.
	 *                     Anything else narrows the selection to the taxonomies
	 *                     registered for that post type. Default 'all'.
	 *
	 * @return string[] The taxonomy names, keyed by themselves.
	 */
	public function revalidatable_taxonomies( $type = 'all' ) {

		$args = [];
		if ( $type !== 'all' ) $args['object_type'] = [ $type ];

		return array_filter(
			get_taxonomies( $args ),
			[ $this->revalidate, 'should_revalidate_taxonomy' ]
		);
	}
}
