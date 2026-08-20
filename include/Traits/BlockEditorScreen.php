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

		return ! is_null($screen) && method_exists($screen, 'is_block_editor') && $screen->is_block_editor();
	}
}
