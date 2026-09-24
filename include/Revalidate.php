<?php

namespace NextJsRevalidate;

use NextJsRevalidate\Abstracts\Base;
use NextJsRevalidate\Interfaces\Hookable;
use NextJsRevalidate\Traits\AdminBarMenu;
use NextJsRevalidate\Traits\BlockEditorScreen;
use NextJsRevalidate\Traits\SendbackUrl;
use WP_Admin_Bar;
use WP_Post;
use WP_Taxonomy;

// Exit if accessed directly.
defined( 'ABSPATH' ) or die( 'Cheatin&#8217; uh?' );

/**
 * The changes to a single post, from every entry point that produces one — a
 * save, a permanent delete, a row action, a bulk action, the admin bar — and
 * the gate they all ask first.
 *
 * Each reports a `post` change to the pending changes: the post as the
 * front-end saw it before and as it sees it after, `null` on a side where it
 * had or has no page. See `docs/adr/0033-the-plugin-reports-changes-not-tags.md`.
 *
 * @property PendingChanges $pendingChanges The pending changes, from the composition root.
 * @property Settings       $settings       The settings, from the composition root.
 */
class Revalidate extends Base implements Hookable {
	use AdminBarMenu;
	use BlockEditorScreen;
	use SendbackUrl;

	/**
	 * The posts whose save is under way in this request, keyed by ID: marked
	 * on `post_updated`, and let go by that post's own `wp_after_insert_post`.
	 *
	 * A revision stands for its post on save, but WordPress saves one *during*
	 * its post's save — from `post_updated`, or since 6.4 from
	 * `wp_after_insert_post` at priority 9 — and so before the post's own save
	 * reaches `on_post_save()`. The revision knows nothing of what the post was
	 * before that save. Reported, it would stand first in the pending changes,
	 * and the post as it now is would become the `before` of the merged change:
	 * a slug change would lose its old URI, and a publish would look like an
	 * edit. So a revision saved while its post's save is under way reports
	 * nothing, and leaves the change to the post's own save, which has both
	 * sides; a revision saved on its own stands for its post as it is.
	 *
	 * @var array<int, true>
	 */
	private array $saving = [];

	public function register_hooks(): void {
		// Ahead of core's `wp_save_post_revision`, at 10 on the same hook until
		// WordPress 6.4 — see `$saving`.
		add_action( 'post_updated', [$this, 'on_post_updated'], 1 );
		add_action( 'wp_after_insert_post', [$this, 'on_post_save'], 99, 4 );
		add_action( 'before_delete_post', [$this, 'on_post_delete'] );

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
	 *    still has a page), or it has just left the front-end.
	 *
	 * The site has the last word: the filter is applied after both axes and can
	 * admit any post, which is how a headless site whose types are not
	 * `publicly_queryable` keeps its pages revalidating. Admitting a post makes
	 * it a candidate and nothing more: what its change says is still read off
	 * its status, so a post admitted while it is on the front-end on neither
	 * side has no change to report.
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
		 * Filters whether the given post is revalidated.
		 *
		 * The v1 name of `nextjs_revalidate_should_revalidate_post`, renamed
		 * because every entry point asks it, not only a save. Applied first, so
		 * a callback on the new name has the last word.
		 *
		 * @deprecated 2.0.0 Use `nextjs_revalidate_should_revalidate_post`.
		 *
		 * @param bool $should_revalidate_post Whether the post is revalidated.
		 * @param int  $post_id                The post ID.
		 */
		$should_revalidate_post = apply_filters_deprecated(
			'nextjs_revalidate_purge_should_revalidate_post_on_save',
			[ $should_revalidate_post, $post_id ],
			'2.0.0',
			'nextjs_revalidate_should_revalidate_post'
		);

		/**
		 * Filters whether the given post is revalidated.
		 *
		 * @param bool $should_revalidate_post Whether the post is revalidated.
		 * @param int  $post_id                The post ID.
		 */
		return apply_filters( 'nextjs_revalidate_should_revalidate_post', $should_revalidate_post, $post_id );
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
		if ( $this->status_axis_admits( get_post_status( $post_id ) ) ) return true;

		return $this->has_just_left_front_end( $post_id, $post_before );
	}

	/**
	 * Whether the status axis admits the given status, i.e. whether a post
	 * holding it has a page on the front-end right now.
	 *
	 * The one place the axis is spelled out, because a post having just left the
	 * front-end is a question asked *against* it rather than a second list of
	 * statuses which would have to be kept in step with this one.
	 *
	 * Deliberately not core's `is_post_status_viewable()`, which rejects
	 * `private`: a private post still has a page, and re-admitting it is the
	 * status-axis exception `docs/adr/0005-post-type-viewability-gates-revalidation.md`
	 * records.
	 *
	 * @param string|false $post_status The post status, or false when the post has none.
	 *
	 * @return bool
	 */
	private function status_axis_admits( $post_status ) {
		return in_array( $post_status, ['publish', 'private'], true );
	}

	/**
	 * Whether the post has just left the front-end.
	 *
	 * The save that changed it moved it from a status the status axis admits to
	 * one it does not — draft, pending, future, trash, or any status an
	 * editorial workflow plugin registers. The front-end still holds the page
	 * the post had, so the change reports the URI the post had *before* the
	 * save and none after it, and the front-end can make that page a 404.
	 *
	 * Asked against the status axis rather than as a list of destinations: an
	 * allowlist would have to name every custom status a site can register, and
	 * would go out of step with the axis the day it is widened. So `publish →
	 * private` is correctly not a leaving — the axis still admits the post and
	 * revalidates it on its own — while `private → trash` is one.
	 *
	 * @param int          $post_id     The post ID.
	 * @param WP_Post|null $post_before The post as it was before the save.
	 *
	 * @return bool
	 */
	private function has_just_left_front_end( $post_id, $post_before ) {
		if ( ! $post_before instanceof WP_Post ) return false;
		if ( ! $this->status_axis_admits( $post_before->post_status ) ) return false;

		return ! $this->status_axis_admits( get_post_status( $post_id ) );
	}

	/**
	 * The post types this plugin offers its actions for.
	 *
	 * An offer, and not a gate. Nothing here decides whether a change is
	 * reported — `should_revalidate()` is asked about every post by every entry
	 * point, and it alone answers that. This decides only what an operator is
	 * shown: the "Revalidate" bulk action, the allow revalidate all toggles, and
	 * the admin bar's revalidate all entries.
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
	 * plugin's `nextjs_revalidate_should_revalidate_post` filter
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

	/**
	 * A post's save has started, and the revisions WordPress saves on its way
	 * are part of the post's own save rather than saves of theirs — see
	 * `$saving`.
	 *
	 * @param int $post_id The post being saved.
	 * @return void
	 */
	public function on_post_updated( $post_id ) {
		$this->saving[ (int) $post_id ] = true;
	}

	/**
	 * A post was saved: report it as the front-end saw it before the save and
	 * as it sees it after.
	 *
	 * An edit reports two equal sides and a slug change two different URIs; a
	 * publish reports no `before`, and a post leaving the front-end no `after`.
	 * Every save is reported, however many a request makes of one post: the
	 * pending changes merge them into the first `before` and the last `after`.
	 *
	 * @param int          $post_id     The post that was saved.
	 * @param WP_Post      $post        The post as it now is.
	 * @param bool         $update      Whether the save was an update.
	 * @param WP_Post|null $post_before The post as it was before the save, null for a new one.
	 * @return void
	 */
	function on_post_save( $post_id, $post, $update, $post_before ) {

		$revision_of = wp_is_post_revision( $post_id );

		// This post's save has reached its end.
		if ( false === $revision_of ) unset( $this->saving[ (int) $post_id ] );

		// A revision saved on the way through its post's own save, which is
		// what reports the change.
		else if ( isset( $this->saving[ (int) $revision_of ] ) ) return;

		// Bail for a post that is not revalidatable
		if ( ! $this->should_revalidate( $post_id, $post_before ) ) return;

		// Bail early if current request is for saving the metaboxes. (To not duplicate the change)
		if ( isset($_REQUEST['meta-box-loader']) ) return;

		$change = ( false === $revision_of
			? $this->post_change(
				$post_id,
				( $post_before instanceof WP_Post ) ? $this->front_end_uri( $post_before ) : null,
				$this->front_end_uri( get_post( $post_id ) )
			)
			// A revision stands for its post, as the post is: nothing about the
			// revision says what the post was before.
			: $this->standing_post_change( $revision_of )
		);

		// Bail for a post holding no front-end page on either side
		if ( is_null($change) ) return;

		$this->pendingChanges->report( $change );
	}

	/**
	 * A post is about to be permanently deleted.
	 *
	 * `wp_delete_post()` fires no save hook at all — `before_delete_post`,
	 * `deleted_post` and `after_delete_post`, none of which `on_post_save()`
	 * hangs on — so without this the front-end kept serving the cached page of
	 * content that no longer exists, indefinitely. Trashing hid the gap:
	 * `wp_trash_post()` routes through `wp_insert_post()` and does fire the save
	 * hook. See #77.
	 *
	 * The question asked is the ordinary one, of the post as it stands just
	 * before it is gone and with no post before it: a publish or private post is
	 * revalidatable and reported with no `after`, so the front-end can make its
	 * page a 404, while a post already in the trash is not — trashing it
	 * reported that page gone already, and the front-end has had no reason to
	 * cache it since. That leaves the `wp_scheduled_delete` sweep and Empty
	 * Trash reporting nothing, which is the right answer rather than a
	 * remaining gap, and the delete of a post that never reached the trash
	 * reporting the one change that used to be missing.
	 *
	 * This hangs on `before_delete_post` rather than on either hook that follows
	 * it because the URI is composed from a row `deleted_post` no longer has.
	 *
	 * @param int $post_id The post about to be deleted.
	 * @return void
	 */
	public function on_post_delete( $post_id ) {

		// A revision is deleted in its own right whenever the revision limit
		// trims one, and in bulk just after the post it belongs to reaches here.
		// Neither is that post leaving the front-end, and a revision has no page
		// of its own — so unlike a save, which stands for the post it is a
		// revision of, a deleted revision stands for nothing.
		if ( false !== wp_is_post_revision( $post_id ) ) return;

		// Bail for a post that is not revalidatable
		if ( ! $this->should_revalidate( $post_id ) ) return;

		$change = $this->post_change( $post_id, $this->front_end_uri( get_post( $post_id ) ), null );

		// Bail for a post holding no front-end page to take down
		if ( is_null($change) ) return;

		$this->pendingChanges->report( $change );
	}

	/**
	 * A post change, or null for one that would say nothing.
	 *
	 * Nothing, when neither side is on the front-end — a change with both sides
	 * `null` does not exist — and for an attachment, which is an uploaded file
	 * rather than a page the front-end holds.
	 *
	 * @param int         $post_id The post ID.
	 * @param string|null $before  Its URI before, or null when it had no page.
	 * @param string|null $after   Its URI after, or null when it has none.
	 *
	 * @return array|null The change, as `Change::post()` builds one.
	 */
	private function post_change( $post_id, ?string $before, ?string $after ) {
		if ( is_null($before) && is_null($after) ) return null;

		$post_type = get_post_type( $post_id );
		if ( false === $post_type || 'attachment' === $post_type ) return null;

		return Change::post( (int) $post_id, $post_type, $before, $after );
	}

	/**
	 * The post as it stands, on both sides: what a manual action reports, and
	 * what a revision saved on its own stands for.
	 *
	 * @param int $post_id The post ID.
	 * @return array|null The change, or null when the post has no page.
	 */
	private function standing_post_change( $post_id ) {
		$uri = $this->front_end_uri( get_post( $post_id ) );

		return $this->post_change( $post_id, $uri, $uri );
	}

	/**
	 * The post as the front-end sees it: the URI of its page, from the domain
	 * root, or null when the front-end holds no page for it.
	 *
	 * Read off the status axis, which is the whole of the difference between
	 * the two sides of a change: a post whose status the axis admits is on the
	 * front-end, and one whose status it does not is not. Handed the post as
	 * it was before a save, this is the URI that post had then — which is why
	 * a post leaving the front-end is reported at the page the front-end
	 * cached, rather than at the unpublished shape it has after, `/?p=42`.
	 *
	 * The URI is the path v1 sent as `path`.
	 *
	 * @param WP_Post|null $post The post, as it was or as it is.
	 * @return string|null
	 */
	private function front_end_uri( $post ) {
		if ( ! $post instanceof WP_Post ) return null;

		// An uploaded file is not a Next.js route.
		if ( 'attachment' === $post->post_type ) return null;

		if ( ! $this->status_axis_admits( $post->post_status ) ) return null;

		$permalink = get_permalink( $post );
		if ( empty($permalink) || $this->is_uploaded_file_url( $permalink ) ) return null;

		$uri = wp_make_link_relative( $permalink );

		return ( '' === $uri ) ? null : $uri;
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
					esc_attr( sprintf( __('Revalidate post “%s”', 'nextjs-revalidate'), get_the_title($post)) ),
					__('Revalidate', 'nextjs-revalidate'),
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
	 * Report the given post as it stands, on both sides.
	 *
	 * The one path every manual revalidation of a single post goes through —
	 * the row action, the bulk action and the admin top bar entry — so that
	 * what is revalidatable cannot differ between the entry that offers it and
	 * the one that performs it.
	 *
	 * @param int $post_id The post ID.
	 * @return bool Whether the change joined the pending changes.
	 */
	private function report_post( $post_id ) {

		// Retired in 2.0: a post change is keyed by its ID, and carries no
		// permalink left for this filter to rewrite. Named to a site still
		// hooking it, rather than silently no longer applied.
		if ( has_filter( 'nextjs_revalidate_purge_action_permalink' ) ) {
			_deprecated_hook(
				'nextjs_revalidate_purge_action_permalink',
				'2.0.0',
				'nextjs_revalidate_change',
				__( 'It is no longer applied: a post change carries no permalink to rewrite.', 'nextjs-revalidate' )
			);
		}

		if ( ! $this->should_revalidate( $post_id ) ) return false;

		$change = $this->standing_post_change( $post_id );
		if ( is_null($change) ) return false;

		// No `is_configured()` guard here on purpose: the pending changes refuse
		// an unconfigured site at the door and log the change they refused.
		// Guarding again here would return the same answer with the diagnostic
		// silently dropped. A refusal comes back as a WP_Error, which is truthy
		// — ask whether the change is held, not whether something came back.
		return true === $this->pendingChanges->report( $change );
	}

	/**
	 * Report the given post as it stands, then send the user back with the
	 * outcome in the `nextjs-revalidate-purged` query arg — the post ID when
	 * its change joined the pending changes, `0` when it did not.
	 *
	 * Does not return: the request ends in a redirect.
	 *
	 * @param int    $post_id  The post ID.
	 * @param string $sendback The url to redirect to.
	 * @return void
	 */
	private function purge_post_and_redirect( $post_id, $sendback ) {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( __( 'Sorry, you are not allowed to revalidate this post.', 'nextjs-revalidate' ) );
		}

		$is_added = $this->report_post( $post_id );

		wp_safe_redirect(
			add_query_arg( [ 'nextjs-revalidate-purged' => $is_added ? $post_id : 0 ], $sendback )
		);
		exit;
	}

	/**
	 * Admin
	 * Display the "Revalidate this page" entry in the admin top bar
	 * when editing a post the front-end could hold a page for.
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
			'title'  => _x( 'Revalidate this page', 'Admin top bar menu', 'nextjs-revalidate' ),
			'href'   => esc_url(
				wp_nonce_url(
					add_query_arg( [ 'nextjs-revalidate-purge-post' => $post_id ], $edit_link ),
					"nextjs-revalidate-purge_$post_id"
				)
			),
			'meta'   => [
				'title' => _x( 'Tell the front-end to revalidate the page currently being edited.', 'Admin top bar menu', 'nextjs-revalidate' ),
			]
		] );
	}

	/**
	 * Get the id of the post currently being edited,
	 * if the current user can revalidate it.
	 *
	 * The revalidation is offered on the edit screen of an existing post only:
	 * a post being created has no page on the front-end yet.
	 *
	 * @return int|null The post ID. Null if there is nothing to revalidate here.
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
	 * Revalidate the post being edited,
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
	 * Register the "Revalidate" bulk action, on the list screen of every post
	 * type this plugin offers its actions for.
	 *
	 * A type it does not offer used to get the action anyway — and the action
	 * then revalidated nothing, because the gate declines every one of its posts.
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
		$bulk_actions['nextjs_revalidate-bulk_purge'] = __( 'Revalidate', 'nextjs-revalidate' );
		return $bulk_actions;
	}

	function revalidate_bulk_action( $redirect_url, $action, $post_ids ) {
		if ($action === 'nextjs_revalidate-bulk_purge') {

			$purged = 0;
			foreach ($post_ids as $post_id) {
				if ( ! current_user_can( 'edit_post', $post_id ) ) continue;
				if ( $this->report_post( $post_id ) ) $purged++;
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
				? sprintf( __( '“%s”: the revalidation was sent to the front-end.', 'nextjs-revalidate' ), get_the_title($post_id) )
				: __( 'Unable to revalidate. Please try again or contact an administrator.', 'nextjs-revalidate' )
			),
		];
	}

	/**
	 * The revalidation notice to hand over to the block editor, if this screen is one.
	 *
	 * Core hides every `admin_notices` output on a block editor screen — see
	 * `body.js.block-editor-page #wpbody-content > div:not(.block-editor)` in
	 * core's editor stylesheet — so the "Revalidate this page" entry, which lives
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
					? sprintf( _n( '%d post: the revalidation was sent to the front-end.', '%d posts: the revalidation was sent to the front-end.', $nb_purged, 'nextjs-revalidate' ), $nb_purged )
					: __( 'Unable to revalidate. Please try again or contact an administrator.', 'nextjs-revalidate' )
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
