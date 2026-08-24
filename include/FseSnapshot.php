<?php

namespace NextJsRevalidate;

use NextJsRevalidate\Abstracts\Base;
use NextJsRevalidate\Interfaces\Hookable;
use NextJsRevalidate\Traits\FrontEndRequest;
use WP_Error;
use WP_Post;

// Exit if accessed directly.
defined( 'ABSPATH' ) or die( 'Cheatin&#8217; uh?' );

/**
 * The front-end's copy of the FSE snapshot, kept from going stale.
 *
 * Next.js renders every page inside a WordPress FSE template, and holds the
 * whole template structure as one cached value behind a cache tag. Editing a
 * template or a template part therefore changes every page at once, and none of
 * them individually — so this is not a revalidation of anything. Nothing is
 * enqueued, no permalink is composed, and no URL is fanned out over: one request
 * to the FSE endpoint tells the front-end its snapshot is stale, and the pages
 * rebuild lazily from there.
 *
 * The category of event is the one `RevalidateAll::revalidate_all_after_menu_update()`
 * already reacts to — a global structure changed — and the answer is a different
 * shape only because the front-end has somewhere to put it.
 *
 * See `docs/adr/0014-an-fse-change-invalidates-a-snapshot.md`.
 *
 * @property Settings $settings The site's settings, from the composition root.
 */
class FseSnapshot extends Base implements Hookable {
	use FrontEndRequest;

	/**
	 * The post types the FSE snapshot is derived from.
	 *
	 * Polylang's translated parts — `footer___de`, per `PLL_FSE_Template_Slug` —
	 * are `wp_template_part` posts, so they are covered by this list rather than
	 * by anything of their own.
	 *
	 * `wp_navigation` is deliberately absent: menu items are fetched at request
	 * time by the front-end and are not in the snapshot at all, so invalidating
	 * it on a menu change would be pure waste.
	 */
	const POST_TYPES = [ 'wp_template', 'wp_template_part' ];

	/**
	 * Seconds to wait for the FSE endpoint to answer.
	 *
	 * Shorter than the minute a revalidation is given, because this request is
	 * made inside the site editor's own save request rather than from cron: what
	 * is on the other end invalidates a cache tag and returns, and a person is
	 * waiting for it. A front-end that cannot manage that in fifteen seconds is
	 * reported as unreachable rather than held on to.
	 */
	const REQUEST_TIMEOUT = 15;

	/**
	 * Whether something in this request has changed the FSE snapshot.
	 *
	 * The whole of the coalescing: a single site-editor save can reach more than
	 * one of the hooks below — saving a template that also drops a part, or
	 * switching a theme, which changes every template at once — and the front-end
	 * needs telling once either way.
	 *
	 * @var bool
	 */
	private bool $is_stale = false;

	public function register_hooks(): void {
		add_action( 'save_post_wp_template',      [$this, 'on_template_save'] );
		add_action( 'save_post_wp_template_part', [$this, 'on_template_save'] );

		// "Reset to theme default" in the site editor *deletes* the post and
		// reverts to the theme's own file, which changes the snapshot as much as
		// an edit does. There is no `save_post` for that.
		add_action( 'deleted_post', [$this, 'on_post_delete'], 10, 2 );

		add_action( 'switch_theme', [$this, 'on_theme_switch'] );
	}

	/**
	 * A template or a template part was saved.
	 *
	 * The hooks are the post-type-specific `save_post_{$post_type}`, so reaching
	 * here is already the whole of the test. A revision and an autosave are posts
	 * of type `revision` and never reach it.
	 *
	 * @param int $post_id The post that was saved.
	 * @return void
	 */
	public function on_template_save( $post_id = 0 ) {
		$this->mark_stale();
	}

	/**
	 * A post was deleted, which is a snapshot change when it was a template.
	 *
	 * The post is gone from the database by now, so its type is read from the
	 * object the hook carries. WordPress has passed one since 5.5; a site older
	 * than that falls back to the cache `get_post_type()` still holds — and can
	 * have no FSE templates in the first place.
	 *
	 * @param int          $post_id The post that was deleted.
	 * @param WP_Post|null $post    The post object, as it was.
	 * @return void
	 */
	public function on_post_delete( $post_id, $post = null ) {

		$post_type = ( $post instanceof WP_Post ) ? $post->post_type : get_post_type( $post_id );

		if ( ! in_array( $post_type, self::POST_TYPES, true ) ) return;

		$this->mark_stale();
	}

	/**
	 * The theme was switched, which changes every template at once.
	 *
	 * @return void
	 */
	public function on_theme_switch() {
		$this->mark_stale();
	}

	/**
	 * Record that this request changed the snapshot, and arrange for the
	 * front-end to be told once, at the end of it.
	 *
	 * The telling is deferred to `shutdown` for two reasons, and neither is
	 * tidiness: it is what makes the coalescing whole — every hook of this
	 * request has fired by then, whatever order they came in — and it keeps a
	 * request to another host out of the middle of a save the editor is waiting
	 * on. `shutdown` runs after `exit()` too, so the redirect a theme switch
	 * ends in does not skip it.
	 *
	 * @return void
	 */
	private function mark_stale() {
		if ( $this->is_stale ) return;

		// Read at the moment of the change rather than at registration: an
		// operator switches this off long after the hooks were attached, and a
		// site whose front-end has not been upgraded yet would otherwise have no
		// way to stop the requests.
		if ( ! $this->settings->revalidates_on_fse_save() ) return;

		$this->is_stale = true;

		add_action( 'shutdown', [$this, 'invalidate_if_stale'] );
	}

	/**
	 * Tell the front-end, if this request has anything to tell it.
	 *
	 * @return void
	 */
	public function invalidate_if_stale() {
		if ( ! $this->is_stale ) return;

		// Cleared before the request rather than after it: whatever else fires
		// from here on, the front-end has already been told.
		$this->is_stale = false;

		$this->invalidate();
	}

	/**
	 * Tell the front-end that its FSE snapshot is stale.
	 *
	 * One request, no queue and no fan-out: what is on the other end invalidates
	 * a cache tag, and every page holding the snapshot rebuilds lazily from
	 * there. So there is nothing here to enqueue, nothing to retry, and — unlike
	 * a revalidation — no permalink whose failure could be recorded against it.
	 * The outcome goes to the log and nowhere else; in particular it is not a
	 * **failure** in the sense the failure window holds, which samples the
	 * queue's traffic only.
	 *
	 * @return true|WP_Error True when the front-end took it. Otherwise a
	 *                       WP_Error whose code names the outcome, as
	 *                       `Revalidate::purge()` does — with `not_configured`
	 *                       for the site that could not deliver at all.
	 */
	public function invalidate() {

		// A refusal rather than a failure: the front-end is asked nothing at all.
		if ( ! $this->settings->is_configured() ) {
			Logger::log(
				sprintf( '⛔ Refused the FSE snapshot invalidation — site not configured (missing: %s)', implode(', ', $this->settings->missing_settings()) ),
				__FILE__,
				Logger::ERROR
			);

			return $this->settings->not_configured_error();
		}

		$outcome = $this->send_front_end_request( $this->build_invalidate_uri(), self::REQUEST_TIMEOUT );

		$this->log_outcome( $outcome );

		return $outcome;
	}

	/**
	 * The URL an FSE snapshot invalidation is sent to.
	 *
	 * The secret travels as a query arg, exactly as it does for a revalidation:
	 * a second endpoint is not a reason to introduce a second thing on the auth
	 * path. There is no `path` arg — the snapshot is not held at a path.
	 *
	 * @return string
	 */
	public function build_invalidate_uri() {
		return add_query_arg(
			[ 'secret' => $this->settings->secret ],
			$this->settings->fse_endpoint_url()
		);
	}

	/**
	 * Record what the front-end answered.
	 *
	 * The site editor saves over REST from an admin that never reloads the page,
	 * so an admin notice would surface on some later, unrelated screen. The log
	 * is the only place an operator can be told.
	 *
	 * @param true|WP_Error $outcome What the request answered.
	 * @return void
	 */
	private function log_outcome( $outcome ) {

		if ( ! is_wp_error($outcome) ) {
			Logger::log( '✅ Invalidated the FSE snapshot', __FILE__ );
			return;
		}

		Logger::log(
			sprintf(
				'❌ Failed to invalidate the FSE snapshot — %s: %s',
				$outcome->get_error_code(),
				$outcome->get_error_message()
			),
			__FILE__,
			Logger::ERROR
		);
	}
}
