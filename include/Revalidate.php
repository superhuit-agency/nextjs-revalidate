<?php

namespace NextJsRevalidate;

use NextJsRevalidate\Abstracts\Base;
use NextJsRevalidate\Interfaces\Hookable;
use NextJsRevalidate\Traits\AdminBarMenu;
use NextJsRevalidate\Traits\BlockEditorScreen;
use NextJsRevalidate\Traits\FrontEndRequest;
use NextJsRevalidate\Traits\SendbackUrl;
use WP_Admin_Bar;
use WP_Error;
use WP_Post;
use WP_Taxonomy;

// Exit if accessed directly.
defined( 'ABSPATH' ) or die( 'Cheatin&#8217; uh?' );

/**
 * @property RevalidateQueue $queue
 */
class Revalidate extends Base implements Hookable {
	use AdminBarMenu;
	use BlockEditorScreen;
	use FrontEndRequest;
	use SendbackUrl;

	public function register_hooks(): void {
		add_action( 'wp_after_insert_post', [$this, 'on_post_save'], 99, 4 );

		add_filter( 'page_row_actions', [$this, 'add_revalidate_row_action'], 20, 2 );
		add_filter( 'post_row_actions', [$this, 'add_revalidate_row_action'], 20, 2 );
		add_action( 'admin_init', [$this, 'revalidate_row_action'] );

		add_action( 'admin_init', [$this, 'register_bulk_actions'] );

		add_action( 'admin_bar_menu', [$this, 'admin_top_bar_menu'], 100 );
		add_action( 'admin_init', [$this, 'revalidate_current_post_action'] );

		add_action( 'admin_notices', [$this, 'purged_notice'] );
	}

	/**
	 * Determine if the given post is revalidatable, i.e. a post the front-end
	 * could hold a page for.
	 *
	 * Two independent axes, both of which must hold:
	 *  - its type is viewable — WordPress's own `publicly_queryable` test;
	 *  - its status is publish or private (a private or password protected post
	 *    still has a page), or it has just left publish for draft or trash.
	 *
	 * The site has the last word: the filter is applied after both axes and can
	 * admit any post, which is how a headless site whose types are not
	 * `publicly_queryable` keeps its pages revalidating.
	 *
	 * @param int          $post_id     The post ID.
	 * @param WP_Post|null $post_before Optional. The post as it was before the save
	 *                                  asking the question, when there is one.
	 *                                  Default null.
	 *
	 * @return bool Whether the post should be revalidated.
	 */
	function should_revalidate( $post_id, $post_before = null ) {

		$should_revalidate_post = $this->is_revalidatable( $post_id, $post_before );

		/**
		 * Filters whether to revalidate the given post on save.
		 *
		 * @param bool $should_revalidate_post Whether to revalidate the post on save.
		 * @param int  $post_id                The post ID.
		 */
		return apply_filters( 'nextjs_revalidate_purge_should_revalidate_post_on_save', $should_revalidate_post, $post_id );
	}

	/**
	 * Whether the post is revalidatable, before the site has its say.
	 *
	 * A revision stands for the post it belongs to; an autosave stands for
	 * nothing, and is never revalidatable.
	 *
	 * @param int          $post_id     The post ID.
	 * @param WP_Post|null $post_before Optional. The post as it was before the save
	 *                                  asking the question. Default null.
	 *
	 * @return bool
	 */
	private function is_revalidatable( $post_id, $post_before = null ) {

		// An autosave is not the saved content, it is a copy kept aside.
		if ( wp_is_post_autosave( $post_id ) !== false ) return false;

		// A revision has no page of its own, the post it belongs to has.
		$parent_post_id = wp_is_post_revision( $post_id );
		if ( false !== $parent_post_id ) $post_id = $parent_post_id;

		// Type axis. A type the front-end holds no page for is never revalidated,
		// whatever the status of its posts.
		$post_type = get_post_type( $post_id );
		if ( false === $post_type || ! is_post_type_viewable( $post_type ) ) return false;

		// Status axis. Private and password protected posts do have a page.
		if ( in_array( get_post_status( $post_id ), ['publish', 'private'], true ) ) return true;

		return $this->has_just_left_publish( $post_id, $post_before );
	}

	/**
	 * Whether the post has just left publish for draft or trash.
	 *
	 * The front-end still holds the page the post had while published, so that
	 * page is revalidated one last time to make it go away.
	 *
	 * @param int          $post_id     The post ID.
	 * @param WP_Post|null $post_before The post as it was before the save.
	 *
	 * @return bool
	 */
	private function has_just_left_publish( $post_id, $post_before ) {
		if ( ! $post_before instanceof WP_Post ) return false;
		if ( 'publish' !== $post_before->post_status ) return false;

		return in_array( get_post_status( $post_id ), ['draft', 'trash'], true );
	}

	/**
	 * The post types this plugin offers its actions for.
	 *
	 * An offer, and not a gate. Nothing here decides whether a revalidation is
	 * enqueued — `should_revalidate()` is asked about every post by every entry
	 * point, and it alone answers that. This decides only what an operator is
	 * shown: the "Purge caches" bulk action, the two settings toggle lists, and
	 * the post types a revalidate all walks.
	 *
	 * The axis is the one the gate's own type axis uses,
	 * `is_post_type_viewable()`, rather than the `public` these selections asked
	 * for until #53. `public` and `publicly_queryable` default to each other but
	 * are registered independently, so the two disagreed in both directions: a
	 * type registered `public => true, publicly_queryable => false` was offered
	 * a bulk action that purged nothing and two toggles that did nothing, and
	 * one registered the other way round had a real front-end page and was
	 * offered none of it.
	 *
	 * Attachments are never offered: an uploaded file is not a Next.js route,
	 * and `get_post_permalink()` refuses one whatever its type says.
	 *
	 * Asking the same core function the gate asks is what keeps the offer and
	 * the gate from drifting again: a site overriding viewability through
	 * core's own `is_post_type_viewable` filter moves both at once. The
	 * plugin's `nextjs_revalidate_purge_should_revalidate_post_on_save` filter
	 * cannot move this one — it answers about a single post, and no list of
	 * types can be derived from it.
	 *
	 * See `docs/adr/0025-viewability-selects-the-post-types-offered.md`.
	 *
	 * @return string[] The post type names, keyed by themselves.
	 */
	public function offered_post_types() {

		$post_types = array_filter( get_post_types(), 'is_post_type_viewable' );

		unset( $post_types['attachment'] );

		return $post_types;
	}

	function on_post_save( $post_id, $post, $update, $post_before ) {

		// Bail for a post that is not revalidatable
		if ( ! $this->should_revalidate( $post_id, $post_before ) ) return;

		// Bail early if current request is for saving the metaboxes. (To not duplicate the purge query)
		if ( isset($_REQUEST['meta-box-loader']) ) return;

		$post_permalink = ( $this->has_just_left_publish( $post_id, $post_before )
			// We take the permalink from the previous post, in order to get the correct permalink
			// (otherwise it would be the draft permalink like "/?page_id=9999/" which doesn't work with the revalidate API)
			? get_permalink( $post_before )
			: $this->get_post_permalink( $post_id, false )
		);

		// Bail for a post holding no front-end page to rebuild
		if ( empty($post_permalink) ) return;

		// Ensure we do not fire this action twice. Safekeeping
		remove_action( 'wp_after_insert_post', [$this, 'on_post_save'], 99 );

		$this->queue->add_item(
			$post_permalink
		);
	}

	/**
	 * Ask the front-end to rebuild the page held for the given permalink.
	 *
	 * Delivery is at most once: the drain deletes the queue entry before it gets
	 * here, so what this returns is the only trace a revalidation which did not
	 * succeed will ever leave. It therefore names *which* failure happened
	 * rather than collapsing every one of them into a bare false — `unreachable`
	 * and `http_401` send an operator to completely different places.
	 * See `docs/adr/0004-at-most-once-revalidation.md`.
	 *
	 * @param string $permalink The permalink to revalidate.
	 *
	 * @return true|WP_Error True when the front-end rebuilt the page. Otherwise
	 *                       a WP_Error whose code names the outcome:
	 *                       `not_configured` when the site could not deliver at
	 *                       all, `unreachable` when the front-end was not
	 *                       reached, `no_response` when it answered without a
	 *                       status, `http_{status}` when it answered with one
	 *                       other than 200, and `exception` when the attempt
	 *                       threw.
	 */
	function purge( $permalink ) {

		// A refusal rather than a failure: the front-end is asked nothing at
		// all. `add_item()` refuses at enqueue time, so the drain reaches this
		// only for items enqueued while the site was still configured and
		// drained after its settings were cleared — the same refusal, given
		// later. It is also the guard for any other caller.
		if ( !$this->settings->is_configured() ) return $this->settings->not_configured_error();

		// The transport, and the naming of what comes back, are shared with the
		// FSE snapshot invalidation — see `Traits\FrontEndRequest`. A minute is
		// what a rebuild is given: this runs from the queue's cron, never from
		// the request an editor is waiting on.
		return $this->send_front_end_request( $this->build_revalidate_uri( $permalink ), 60 );
	}

	function build_revalidate_uri( $permalink ) {
		return add_query_arg(
			[
				'path'   => wp_make_link_relative( $permalink ),
				'secret' => $this->settings->secret
			],
			$this->settings->revalidate_endpoint_url()
		);
	}

	function add_revalidate_row_action( $actions, $post ) {
		if ( $post instanceof WP_Post || is_array( $actions ) ) {
			if ( $this->settings->is_configured() ) {

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

		$post_id = intval( $_GET['post'] );

		check_admin_referer( "nextjs-revalidate-purge_$post_id" );

		$this->purge_post_and_redirect( $post_id, $this->get_sendback_url() );
	}

	/**
	 * Add the permalink of the given post to the purge queue.
	 *
	 * The one path every purge of a single post goes through — the row action,
	 * the bulk action and the admin top bar entry — so that what is purgeable
	 * cannot differ between the entry that offers the purge and the one that
	 * performs it.
	 *
	 * @param int $post_id The post ID.
	 * @return bool Whether the permalink was added to the queue.
	 */
	private function queue_post_purge( $post_id ) {
		// No `is_configured()` guard here on purpose: the queue refuses an
		// unconfigured site at the door and logs the permalink it refused.
		// Guarding again here would return the same answer with the diagnostic
		// silently dropped.
		$permalink = $this->get_post_permalink( $post_id );

		/**
		 * Filters the permalink to be added to the purge queue.
		 * Return false to prevent the permalink to be added to the purge queue.
		 *
		 * @param string|false $permalink The post permalink. False if the post is not public.
		 * @param int          $post_id   The post ID.
		 */
		$permalink = apply_filters( 'nextjs_revalidate_purge_action_permalink', $permalink, $post_id );

		if ( empty($permalink) ) return false;

		// A refusal comes back as a WP_Error, which is truthy — ask whether the
		// item was queued, not whether something was returned.
		$is_added = $this->queue->add_item( $permalink );
		return ( $is_added && !is_wp_error($is_added) );
	}

	/**
	 * Purge the cache of the given post, then send the user back with the
	 * outcome in the `nextjs-revalidate-purged` query arg — the post ID when
	 * the purge was queued, `0` when it was not.
	 *
	 * Does not return: the request ends in a redirect.
	 *
	 * @param int    $post_id  The post ID.
	 * @param string $sendback The url to redirect to.
	 * @return void
	 */
	private function purge_post_and_redirect( $post_id, $sendback ) {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( __( 'Sorry, you are not allowed to purge the cache of this post.', 'nextjs-revalidate' ) );
		}

		$is_added = $this->queue_post_purge( $post_id );

		wp_safe_redirect(
			add_query_arg( [ 'nextjs-revalidate-purged' => $is_added ? $post_id : 0 ], $sendback )
		);
		exit;
	}

	/**
	 * Admin
	 * Display the "Purge this page" entry in the admin top bar
	 * when editing a post whose cache we could purge.
	 */
	function admin_top_bar_menu( WP_Admin_Bar $admin_bar ) {
		$post_id = $this->get_edited_post_id();
		if ( is_null($post_id) ) return;

		$edit_link = get_edit_post_link( $post_id, 'raw' );
		if ( empty($edit_link) ) return;

		$this->add_admin_bar_menu( $admin_bar );

		$admin_bar->add_node( [
			'id'     => 'nextjs-revalidate-current-post',
			'parent' => 'nextjs-revalidate',
			'title'  => _x( 'Purge this page', 'Admin top bar menu', 'nextjs-revalidate' ),
			'href'   => esc_url(
				wp_nonce_url(
					add_query_arg( [ 'nextjs-revalidate-purge-post' => $post_id ], $edit_link ),
					"nextjs-revalidate-purge_$post_id"
				)
			),
			'meta'   => [
				'title' => _x( 'Purge the cache of the page currently being edited.', 'Admin top bar menu', 'nextjs-revalidate' ),
			]
		] );
	}

	/**
	 * Get the id of the post currently being edited,
	 * if its cache can be purged by the current user.
	 *
	 * The purge is offered on the edit screen of an existing post only:
	 * a post being created has no permalink to purge yet.
	 *
	 * @return int|null The post ID. Null if there is nothing to purge here.
	 */
	private function get_edited_post_id() {
		if ( ! is_admin() || ! function_exists('get_current_screen') ) return null;

		$screen = get_current_screen();
		if ( is_null($screen) || $screen->base !== 'post' || $screen->action === 'add' ) return null;

		$post = get_post();
		if ( ! ($post instanceof WP_Post) ) return null;

		if ( ! $this->settings->is_configured() ) return null;
		if ( ! current_user_can( 'edit_post', $post->ID ) ) return null;

		// Bail for not viewable post
		if ( false === $this->get_post_permalink( $post->ID ) ) return null;

		return $post->ID;
	}

	/**
	 * Purge the cache of the post being edited,
	 * triggered by the admin top bar entry.
	 *
	 * The action travels in its own query arg: the link is followed from the
	 * edit screen, where the `action` arg is `post.php`'s own.
	 */
	function revalidate_current_post_action() {
		if ( ! isset($_GET['nextjs-revalidate-purge-post']) ) return;

		$post_id = intval( $_GET['nextjs-revalidate-purge-post'] );

		check_admin_referer( "nextjs-revalidate-purge_$post_id" );

		$sendback = get_edit_post_link( $post_id, 'raw' );
		if ( empty($sendback) ) $sendback = $this->get_sendback_url();

		$this->purge_post_and_redirect( $post_id, $sendback );
	}

	/**
	 * Register the "Purge caches" bulk action, on the list screen of every post
	 * type this plugin offers its actions for.
	 *
	 * A type it does not offer used to get the action anyway — and the action
	 * then purged nothing, because the gate declines every one of its posts.
	 * See `offered_post_types()`.
	 */
	function register_bulk_actions() {
		if ( !$this->settings->is_configured() ) return false;

		foreach ($this->offered_post_types() as $post_type) {
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
				if ( ! current_user_can( 'edit_post', $post_id ) ) continue;
				if ( $this->queue_post_purge( $post_id ) ) $purged++;
			}

			$redirect_url = add_query_arg('nextjs-revalidate-bulk-purged', $purged, $this->get_sendback_url($redirect_url));
		}

		return $redirect_url;
	}

	/**
	 * The notice describing the purge the current request comes back from,
	 * if it comes back from one.
	 *
	 * @return array|null [ 'status' => 'success'|'error', 'message' => string ]
	 *                    Null when the request is not the sendback of a purge.
	 */
	public function get_purged_notice() {
		if ( ! isset( $_GET['nextjs-revalidate-purged'] ) ) return null;

		$post_id = intval( $_GET['nextjs-revalidate-purged'] );
		$success = $post_id > 0;

		return [
			'status'  => $success ? 'success' : 'error',
			'message' => ($success
				? sprintf( __( '“%s” cache will be purged shortly.', 'nextjs-revalidate' ), get_the_title($post_id) )
				: __( 'Unable to purge cache. Please try again or contact an administrator.', 'nextjs-revalidate' )
			),
		];
	}

	/**
	 * The purge notice to hand over to the block editor, if this screen is one.
	 *
	 * Core hides every `admin_notices` output on a block editor screen — see
	 * `body.js.block-editor-page #wpbody-content > div:not(.block-editor)` in
	 * core's editor stylesheet — so the "Purge this page" entry, which lives
	 * inside the editor, would otherwise report nothing at all. There the
	 * notice is dispatched to `core/notices` from the editor script instead.
	 *
	 * @return array|null Same shape as `get_purged_notice()`.
	 */
	public function get_block_editor_purged_notice() {
		return $this->is_block_editor_screen() ? $this->get_purged_notice() : null;
	}

	function purged_notice() {
		$notice = $this->get_purged_notice();
		if ( ! is_null($notice) && ! $this->is_block_editor_screen() ) {
			printf(
				'<div class="notice notice-%s"><p>%s</p></div>',
				esc_attr( $notice['status'] ),
				$notice['message']
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

	/**
	 * Get the post permalink.
	 *
	 * If the post_id is a revision, we should get the permalink from the parent post_id
	 * for instance when saving, the post_id is the revision id, but we want to purge the parent post permalink
	 *
	 * An uploaded file is not a Next.js route: attachments hold no page the
	 * front-end could rebuild, so they have no permalink to purge.
	 *
	 * @param int  $post_id         The post ID.
	 * @param bool $check_if_public Optional. Whether to check if the post is public. Default true.
	 *
	 * @return string|false The post permalink. False if the post is not public.
	 */
	public function get_post_permalink( $post_id, $check_if_public = true ) {

		if ( $check_if_public ) {
			$is_public = $this->should_revalidate( $post_id );
			if ( !$is_public ) return false;
		}

		if ( 'attachment' === get_post_type( $post_id ) ) return false;

		// If post_id is a revision, we should get the permalink from the parent post_id
		$parent_post_id = wp_is_post_revision($post_id);
		$post_id_for_permalink = ( false !== $parent_post_id
			? $parent_post_id
			: $post_id
		);

		$permalink = get_permalink( $post_id_for_permalink );

		if ( $this->is_uploaded_file_url( $permalink ) ) return false;

		return $permalink;
	}

	/**
	 * Determine if the given taxonomy is revalidatable, i.e. one whose terms the
	 * front-end could hold archive pages for.
	 *
	 * One axis rather than the two a post has, because this asks about the
	 * taxonomy and not about any one term: a term has no status, and core gives
	 * it no second axis either — `is_term_publicly_viewable()` is, in full, a
	 * term-existence check plus this same question about its taxonomy. Asking it
	 * once per taxonomy rather than once per term is the difference between one
	 * call and tens of thousands of them on a purge all.
	 *
	 * The axis is `is_taxonomy_viewable()`, which for a taxonomy is a bare
	 * `publicly_queryable` with none of the `_builtin && public` fallback
	 * `is_post_type_viewable()` applies. Terms of a taxonomy that is not
	 * revalidatable produce no revalidation at all; they are not refused, they
	 * were never candidates.
	 *
	 * The site has the last word, as it does for a post: the filter is applied
	 * after the axis and can admit any taxonomy, which is how a headless site
	 * whose taxonomies are not `publicly_queryable` keeps its archives
	 * revalidating.
	 *
	 * See `docs/adr/0022-taxonomy-viewability-gates-term-revalidation.md`.
	 *
	 * @param string|WP_Taxonomy $taxonomy The taxonomy, or its name.
	 *
	 * @return bool Whether the taxonomy's terms should be revalidated.
	 */
	public function should_revalidate_taxonomy( $taxonomy ) {

		$taxonomy_object = ( $taxonomy instanceof WP_Taxonomy ? $taxonomy : get_taxonomy( $taxonomy ) );
		$taxonomy_name   = ( $taxonomy_object instanceof WP_Taxonomy ? $taxonomy_object->name : (string) $taxonomy );

		// A taxonomy nothing registered has no archive, and nothing to ask about.
		$should_revalidate_taxonomy = ( $taxonomy_object instanceof WP_Taxonomy && is_taxonomy_viewable( $taxonomy_object ) );

		/**
		 * Filters whether to revalidate the given taxonomy's terms.
		 *
		 * @param bool                $should_revalidate_taxonomy Whether to revalidate the taxonomy's terms.
		 * @param string              $taxonomy_name              The taxonomy name.
		 * @param WP_Taxonomy|false   $taxonomy_object            The taxonomy, or false when none is registered under that name.
		 */
		return apply_filters( 'nextjs_revalidate_should_revalidate_taxonomy', $should_revalidate_taxonomy, $taxonomy_name, $taxonomy_object );
	}

	/**
	 * Whether the given url points at a file in the uploads directory
	 * rather than at a page of the site.
	 *
	 * @param string|false $url
	 * @return bool
	 */
	private function is_uploaded_file_url( $url ) {
		if ( empty($url) ) return false;

		$uploads = wp_get_upload_dir();
		if ( empty($uploads['baseurl']) ) return false;

		$baseurl = wp_make_link_relative( $uploads['baseurl'] );
		if ( empty($baseurl) ) return false;

		return 0 === strpos( wp_make_link_relative( $url ), $baseurl );
	}
}
