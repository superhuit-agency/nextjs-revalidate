<?php
/**
 * The canary for `WordPressFloorRule`: calls it must report, and calls it must
 * not. Never the plugin's code — outside the analysed paths, and never shipped.
 *
 * Every call is marked `// since X`, the release core's `@since` gives it in the
 * stubs, and `, guarded` when it sits inside the check that makes it safe.
 * `check.php` reads `wordpressFloor` out of phpstan.neon and expects exactly the
 * unguarded lines newer than it to be reported — so the floor can move without
 * this file changing, as long as something here stays above it.
 */
function njr_floor_canary( WP_Screen $screen ) {
	wp_is_block_theme(); // since 5.9.0
	$tags = new WP_HTML_Tag_Processor( '' ); // since 6.2.0
	$tags->next_tag(); // since 6.2.0
	WP_Theme_JSON_Resolver::get_merged_data(); // since 5.8.0
	$screen->is_block_editor(); // since 5.0.0
	get_post( 1 ); // since 1.5.1

	if ( function_exists( 'wp_is_block_theme' ) ) wp_is_block_theme(); // since 5.9.0, guarded
	if ( class_exists( 'WP_HTML_Tag_Processor' ) ) new WP_HTML_Tag_Processor( '' ); // since 6.2.0, guarded
}
