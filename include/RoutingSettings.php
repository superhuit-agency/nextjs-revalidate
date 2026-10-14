<?php

namespace NextJsRevalidate;

use NextJsRevalidate\Abstracts\Base;
use NextJsRevalidate\Interfaces\Hookable;

// Exit if accessed directly.
defined( 'ABSPATH' ) or die( 'Cheatin&#8217; uh?' );

/**
 * The **routing settings** WordPress stores as options, as a producer of
 * revalidate all changes.
 *
 * A routing setting decides which content lives at which path: the permalink
 * structure, the category and tag bases, the front page and the posts page,
 * the posts per page. One reports a revalidate all of the whole site when it is
 * added, updated to a different value, or deleted — whether or not the new
 * value moves a path in practice; however many a request saves, the pending
 * changes hold one.
 *
 * Not a site setting: expiring the tag every page carries does not fix pages
 * cached at paths that no longer hold them, "not found" answers cached at
 * paths that now do, or listings paginated by the old page size. A rewrite
 * flush from any other cause is not one either — plugins flush on activation
 * and on registering a post type. The list is not filterable; a site's own
 * routing setting is not caught.
 *
 * Polylang's routing settings are its integration's to report. See
 * `docs/adr/0044-a-routing-setting-reports-a-revalidate-all.md`.
 *
 * @property PendingChanges $pendingChanges The pending changes, from the composition root.
 */
class RoutingSettings extends Base implements Hookable {

	/**
	 * The routing settings WordPress owns.
	 */
	const OPTIONS = [
		'permalink_structure',
		'category_base',
		'tag_base',
		'show_on_front',
		'page_on_front',
		'page_for_posts',
		'posts_per_page',
	];

	public function register_hooks(): void {
		// WordPress fires none of these for an update to the value an option
		// already holds, so an unchanged save reports nothing on its own.
		add_action( 'added_option',   [$this, 'on_option_change'], 10, 1 );
		add_action( 'updated_option', [$this, 'on_option_change'], 10, 1 );
		add_action( 'deleted_option', [$this, 'on_option_change'], 10, 1 );
	}

	/**
	 * An option was added, updated to a different value, or deleted.
	 *
	 * @param mixed $option The option's name.
	 * @return void
	 */
	public function on_option_change( $option ) {
		if ( ! in_array( $option, self::OPTIONS, true ) ) return;

		$this->pendingChanges->report( Change::all() );
	}
}
