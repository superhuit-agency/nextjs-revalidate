<?php

namespace NextJsRevalidate\Traits;

/**
 * Whether the admin screen being rendered is a block editor one.
 *
 * Core hides every `admin_notices` output on a block editor screen — see
 * `body.js.block-editor-page #wpbody-content > div:not(.block-editor)` in
 * core's editor stylesheet — so a notice printed there is markup nobody
 * reads. Everything this plugin has to say on such a screen is dispatched to
 * `core/notices` from the editor script instead, and this is the question both
 * halves of that ask.
 */
trait BlockEditorScreen {

	/**
	 * @return bool
	 */
	protected function is_block_editor_screen() {
		if ( ! function_exists('get_current_screen') ) return false;

		$screen = get_current_screen();

		// No `method_exists()` guard on `is_block_editor()`: it has been on
		// `WP_Screen` since WordPress 5.0, which is the floor the plugin header
		// declares. The `is_null()` one stays — `get_current_screen()` answers
		// null outside the admin, and on an admin request before
		// `set_current_screen()` has run.
		return ! is_null($screen) && $screen->is_block_editor();
	}
}
