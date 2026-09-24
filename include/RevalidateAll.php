<?php

namespace NextJsRevalidate;

use NextJsRevalidate\Abstracts\Base;
use NextJsRevalidate\Interfaces\Hookable;
use NextJsRevalidate\Traits\AdminBarMenu;
use NextJsRevalidate\Traits\SendbackUrl;
use WP_Admin_Bar;

/**
 * @property Revalidate     $revalidate
 * @property PendingChanges $pendingChanges
 * @property Settings       $settings
 */
class RevalidateAll extends Base implements Hookable {
	use AdminBarMenu;
	use SendbackUrl;

	public function register_hooks(): void {
		add_action( 'admin_bar_menu', [$this, 'admin_top_bar_menu'], 100 );
		add_action( 'admin_notices', [$this, 'revalidated_notice'] );

		add_action( 'admin_init', [$this, 'revalidate_all_pages_action'] );

		add_action( 'wp_update_nav_menu', [$this, 'on_menu_update'] );
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
	 * Display the outcome of a revalidate all, once its action has redirected back
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

		// No page count: a revalidate all is one change, and which pages it
		// covers is the front-end's to work out.
		printf(
			'<div class="notice notice-success"><p>%s</p></div>',
			esc_html__( 'Revalidate all: the revalidation was sent to the front-end.', 'nextjs-revalidate' )
		);
	}

	/**
	 * Revalidate all action
	 */
	function revalidate_all_pages_action() {
		if ( ! (isset( $_GET['action'] ) && $_GET['action'] === 'nextjs-revalidate-revalidate-all')  ) return;

		check_admin_referer( 'nextjs-revalidate-revalidate-all' );

		// The type travels to the front-end in the change, so it is read as
		// what a post type name is: a key, as `register_post_type()` makes it.
		$type = ( isset($_GET['nextjs-revalidate-type']) && is_string($_GET['nextjs-revalidate-type']) )
			? sanitize_key( wp_unslash( $_GET['nextjs-revalidate-type'] ) )
			: 'all';

		$reported = $this->revalidate_all( $type );
		$sendback = add_query_arg(
			( false === $reported
				? [ 'nextjs-revalidate-revalidate-all-refused' => 1 ]
				: [ 'nextjs-revalidate-revalidate-all' => 1 ]
			),
			$this->get_sendback_url()
		);

		wp_safe_redirect( $sendback );
		exit;
	}

	/**
	 * A menu was saved: report one `menu` change, naming the theme locations
	 * the menu is assigned to.
	 *
	 * The locations are read as the hook fires. The menus screen stores an
	 * existing menu's locations before it saves the menu, so a location ticked
	 * in the same save is already among them. A menu assigned to no location
	 * is reported all the same, with none: a block, a widget or the front-end
	 * itself may render it by its ID.
	 *
	 * Until v2 a menu save ran a revalidate all for every post type ticked in
	 * the "revalidate on menu save" setting, which existed only to bound the
	 * cost of that walk. One change has no cost to bound, so the setting went
	 * with the walk — see `docs/adr/0033-the-plugin-reports-changes-not-tags.md`.
	 *
	 * @param int $menu_id The menu's term ID.
	 * @return void
	 */
	function on_menu_update( $menu_id ) {
		$menu_id = (int) $menu_id;

		$locations = [];
		foreach ( (array) get_nav_menu_locations() as $location => $assigned ) {
			if ( (int) $assigned === $menu_id ) $locations[] = (string) $location;
		}

		$this->pendingChanges->report( Change::menu( $menu_id, $locations ) );
	}

	/**
	 * Report a revalidate all as one `all` change: of the whole site, or of
	 * one post type and the revalidatable taxonomies registered for it.
	 *
	 * Never the pages it covers. Which cache entries the change expires is the
	 * front-end's decision, so nothing here reads a post: the whole site needs
	 * nothing but the change, and a post type needs only its taxonomies, which
	 * only WordPress knows. A site holding thousands of posts costs what an
	 * empty one does.
	 *
	 * A named type is the caller's own instruction, and is reported whatever
	 * this plugin would have offered of its own accord.
	 *
	 * @param string $type Optional. The post type to revalidate, or 'all' for
	 *                     the whole site. Default 'all'.
	 * @return bool True when the change was reported, false on a refusal — an
	 *              unconfigured site, where nothing was reported because
	 *              nothing reported could be delivered. A change the
	 *              `nextjs_revalidate_change` filter then drops was still
	 *              reported: dropping it is the site's decision, not a refusal.
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

		$change = ( $type === 'all' )
			? Change::all()
			: Change::all( $type, array_values( $this->revalidatable_taxonomies( $type ) ) );

		return !is_wp_error( $this->pendingChanges->report( $change ) );
	}

	/**
	 * The taxonomies a revalidate all of one post type names in its change.
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
