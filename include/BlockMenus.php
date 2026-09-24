<?php

namespace NextJsRevalidate;

use NextJsRevalidate\Abstracts\Base;
use NextJsRevalidate\Interfaces\Hookable;
use WP_Post;

// Exit if accessed directly.
defined( 'ABSPATH' ) or die( 'Cheatin&#8217; uh?' );

/**
 * Block menus — the `wp_navigation` posts the Site Editor and the Navigation
 * block save — as a producer of `menu` changes.
 *
 * A block menu saved, trashed, restored or permanently deleted reports one
 * `menu` change carrying its post ID and no locations: block menus are not
 * assigned to theme locations, and the front-end renders one by its ID. The
 * change has the classic menu's shape on purpose (`RevalidateAll::on_menu_update()`
 * builds the other), so a front-end reads a menu the same way whichever kind it
 * is. A classic menu's term ID and a block menu's post ID can collide; the cost
 * is one extra `menu:{id}` entry expired, and no field tells them apart.
 *
 * A producer of its own, and not a widening of the post gate: `wp_navigation`
 * is not viewable, so `Revalidate::should_revalidate()` declines it and goes on
 * declining it, and no other non-viewable post type gains a change from here.
 *
 * Until v2 nothing reported a block menu, and the front-end's hourly timer hid
 * that. v2's front-end has no timer. See
 * `docs/adr/0033-the-plugin-reports-changes-not-tags.md`, "Amended when the
 * front-end dropped its timer".
 *
 * @property PendingChanges $pendingChanges The pending changes, from the composition root.
 */
class BlockMenus extends Base implements Hookable {

	/**
	 * The post type a block menu is.
	 */
	const POST_TYPE = 'wp_navigation';

	public function register_hooks(): void {
		// A publish, an update — the Site Editor's over REST as much as any
		// other — a trash and a restore from the trash all go through
		// `wp_insert_post()`. A revision is a post of type `revision`, and never
		// reaches this hook.
		add_action( 'save_post_wp_navigation', [$this, 'on_block_menu_save'], 10, 2 );

		// A permanent delete fires no save hook at all.
		add_action( 'deleted_post', [$this, 'on_post_delete'], 10, 2 );
	}

	/**
	 * A block menu was saved.
	 *
	 * The hook is the post-type-specific `save_post_{$post_type}`, so reaching
	 * here already says the post is a block menu.
	 *
	 * @param int          $post_id The block menu's post ID.
	 * @param WP_Post|null $post    The block menu as it now is.
	 * @return void
	 */
	public function on_block_menu_save( $post_id, $post = null ) {
		if ( $post instanceof WP_Post && self::is_auto_draft( $post ) ) return;

		$this->report_block_menu( $post_id );
	}

	/**
	 * A post was permanently deleted, which is a block menu change when it was
	 * a block menu.
	 *
	 * The post is gone from the database by now, so its type is read from the
	 * object the hook carries — as `FseSnapshot::on_post_delete()` does, and
	 * for the same reason. A WordPress without that object has no block menus.
	 *
	 * @param int          $post_id The post that was deleted.
	 * @param WP_Post|null $post    The post object, as it was.
	 * @return void
	 */
	public function on_post_delete( $post_id, $post = null ) {
		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type ) return;

		// WordPress sweeps its stale auto-drafts through here.
		if ( self::is_auto_draft( $post ) ) return;

		$this->report_block_menu( $post_id );
	}

	/**
	 * Whether a block menu is an auto-draft: the placeholder WordPress creates
	 * before anything is saved, which no front-end has ever rendered.
	 *
	 * @param WP_Post $post
	 * @return bool
	 */
	private static function is_auto_draft( WP_Post $post ) {
		return 'auto-draft' === $post->post_status;
	}

	/**
	 * Report that a block menu changed.
	 *
	 * Every save of it in one request is the same change, and the pending
	 * changes collapse identical changes into one.
	 *
	 * @param int $post_id The block menu's post ID.
	 * @return void
	 */
	private function report_block_menu( $post_id ) {
		$this->pendingChanges->report( Change::menu( (int) $post_id, [] ) );
	}
}
