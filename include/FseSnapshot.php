<?php

namespace NextJsRevalidate;

use NextJsRevalidate\Abstracts\Base;
use NextJsRevalidate\Interfaces\Hookable;
use WP_Post;

// Exit if accessed directly.
defined( 'ABSPATH' ) or die( 'Cheatin&#8217; uh?' );

/**
 * The front-end's copy of the FSE snapshot, kept from going stale.
 *
 * Next.js renders every page inside a WordPress FSE template, and holds the
 * whole template structure as one cached value behind a cache tag. Editing a
 * template or a template part therefore changes every page at once, and none of
 * them individually: what this reports is a `templates` change, never naming
 * which template, and the front-end decides what that expires.
 *
 * A producer and nothing else. The change goes into the **pending changes**
 * like any other, and travels in the same request to the same endpoint; the
 * coalescing this class once did by hand — one telling per request however
 * many hooks fire — is what the pending changes do for every subject, since
 * identical `templates` changes collapse into one.
 *
 * See `docs/adr/0018-an-fse-change-invalidates-a-snapshot.md`, as amended by
 * `docs/adr/0034-changes-are-delivered-when-the-request-ends.md`.
 *
 * @property PendingChanges $pendingChanges The pending changes, from the composition root.
 */
class FseSnapshot extends Base implements Hookable {

	/**
	 * The post types the FSE snapshot is derived from.
	 *
	 * Polylang's translated parts — `footer___de`, per `PLL_FSE_Template_Slug` —
	 * are `wp_template_part` posts, so they are covered by this list rather than
	 * by anything of their own.
	 *
	 * `wp_navigation` is deliberately absent: menu items are fetched at request
	 * time by the front-end and are not in the snapshot at all, so reporting
	 * the templates as changed on a menu change would be pure waste. A block
	 * menu reports a `menu` change of its own, from `BlockMenus`.
	 */
	const POST_TYPES = [ 'wp_template', 'wp_template_part' ];

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
		$this->report_templates();
	}

	/**
	 * A post was deleted, which is a snapshot change when it was a template.
	 *
	 * The post is gone from the database by now, so its type is read from the
	 * object the hook carries. WordPress has passed one since 5.5, and the site
	 * editor needs 5.9, so every site that can reach this has it. The
	 * `get_post_type()` fallback is for an older WordPress and answers `false`
	 * there rather than a type — `wp_delete_post()` cleans the post cache before
	 * firing this hook, so there is nothing left to read. A site that old has no
	 * FSE templates to miss.
	 *
	 * @param int          $post_id The post that was deleted.
	 * @param WP_Post|null $post    The post object, as it was.
	 * @return void
	 */
	public function on_post_delete( $post_id, $post = null ) {

		$post_type = ( $post instanceof WP_Post ) ? $post->post_type : get_post_type( $post_id );

		if ( ! in_array( $post_type, self::POST_TYPES, true ) ) return;

		$this->report_templates();
	}

	/**
	 * The theme was switched, which changes every template at once.
	 *
	 * @return void
	 */
	public function on_theme_switch() {
		$this->report_templates();
	}

	/**
	 * Report that the templates changed.
	 *
	 * @return void
	 */
	private function report_templates() {
		$this->pendingChanges->report( Change::templates() );
	}
}
