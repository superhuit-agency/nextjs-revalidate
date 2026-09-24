<?php
/**
 * The canary for `WordPressFloorRule`: uses it must report, and uses it must
 * not. Never the plugin's code — outside the analysed paths, and never shipped.
 *
 * Every use is marked `// since X`, the release core's `@since` gives it in the
 * stubs, and `, guarded` when it sits inside the check that makes it safe.
 * `check.php` reads `wordpressFloor` out of phpstan.neon and expects exactly the
 * unguarded lines newer than it to be reported — so the floor can move without
 * this file changing, as long as something here stays above it.
 *
 * Each line holds one way the rule can go blind. `is_block_theme()` is 5.9 on a
 * 3.4 class, so it is only reported while the rule reads a method's own
 * `@since`; `do_action()` names a hook that is also a newer function's name,
 * and must not be; `class_exists( 'WP_Theme' )` covers nothing newer than
 * `WP_Theme`, so it guards no 5.9 method of it.
 */
function njr_floor_canary( WP_Screen $screen, ?WP_Theme $theme ) {
	wp_is_block_theme(); // since 5.9.0
	$tags = new WP_HTML_Tag_Processor( '' ); // since 6.2.0
	$tags->next_tag(); // since 6.2.0
	WP_Theme_JSON_Resolver::get_merged_data(); // since 5.8.0
	$theme->is_block_theme(); // since 5.9.0
	$schema = WP_Theme_JSON::LATEST_SCHEMA; // since 5.8.0
	call_user_func( 'wp_is_block_theme' ); // since 5.9.0
	array_map( [ 'WP_Theme_JSON_Resolver', 'get_merged_data' ], [] ); // since 5.8.0
	do_action( 'wp_is_block_theme' ); // since 1.2.0
	$screen->is_block_editor(); // since 5.0.0
	get_post( 1 ); // since 1.5.1

	if ( function_exists( 'wp_is_block_theme' ) ) wp_is_block_theme(); // since 5.9.0, guarded
	if ( function_exists( 'wp_is_block_theme' ) ) call_user_func( 'wp_is_block_theme' ); // since 5.9.0, guarded
	if ( class_exists( 'WP_HTML_Tag_Processor' ) ) new WP_HTML_Tag_Processor( '' ); // since 6.2.0, guarded
	if ( class_exists( 'WP_HTML_Tag_Processor' ) ) $tags->next_tag(); // since 6.2.0, guarded
	if ( class_exists( 'WP_Theme' ) ) $theme->is_block_theme(); // since 5.9.0
}

class NJR_Floor_Canary extends WP_HTML_Tag_Processor {} // since 6.2.0
