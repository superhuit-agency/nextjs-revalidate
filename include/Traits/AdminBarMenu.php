<?php

namespace NextJsRevalidate\Traits;

use WP_Admin_Bar;

trait AdminBarMenu {

	/**
	 * Add the plugin menu to the admin top bar, unless it is already there.
	 *
	 * The "Purge caches" menu holds both the purge all entries and the purge
	 * of the post being edited. Either of them may be the only one displayed,
	 * so both add the menu and the first one to run wins.
	 *
	 * @param WP_Admin_Bar $admin_bar
	 * @return void
	 */
	protected function add_admin_bar_menu( WP_Admin_Bar $admin_bar ) {
		if ( ! is_null( $admin_bar->get_node( 'nextjs-revalidate' ) ) ) return;

		$admin_bar->add_menu( [
			'id'     => 'nextjs-revalidate',
			'title'  => _x( 'Purge caches', 'Admin top bar menu', 'nextjs-revalidate'),
			'meta'   => [
				'class' => "nextjs-revalidate",
			]
		] );
	}
}
