<?php

namespace NextJsRevalidate;

use NextJsRevalidate\Abstracts\Base;
use NextJsRevalidate\Interfaces\Hookable;

// Exit if accessed directly.
defined( 'ABSPATH' ) or die( 'Cheatin&#8217; uh?' );

/**
 * The **site settings** stored as options, as a producer of `settings` changes.
 *
 * An option counts as a site setting when its name is on the list
 * `nextjs_revalidate_site_setting_options` returns — WordPress's own by
 * default, and whatever an integration or the site adds. One on the list
 * reports a `settings` change when it is added, updated to a different value,
 * or deleted; however many a request saves, the pending changes hold one.
 *
 * Options that move which content lives at which path — the permalink
 * structure, the front page, the posts per page — are site-wide too, and are
 * deliberately not on the list: expiring the tag every page carries does not
 * fix what they leave stale. See
 * `docs/adr/0037-a-settings-change-reports-what-every-page-renders.md`.
 *
 * @property PendingChanges $pendingChanges The pending changes, from the composition root.
 */
class SiteSettings extends Base implements Hookable {

	/**
	 * The options WordPress renders as they are, on any page.
	 */
	const DEFAULT_OPTIONS = [
		'blogname',
		'blogdescription',
		'date_format',
		'time_format',
		'timezone_string',
		'gmt_offset',
		'home',
		'site_icon',
		'WPLANG',
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
		if ( ! is_string( $option ) || ! self::is_site_setting( $option ) ) return;

		$this->pendingChanges->report( Change::settings() );
	}

	/**
	 * Whether an option is a site setting.
	 *
	 * The list is read every time an option changes rather than once at load,
	 * so a filter added late — by a theme, or by an integration waiting for
	 * `plugins_loaded` — still counts.
	 *
	 * @param string $option The option's name.
	 * @return bool
	 */
	public static function is_site_setting( string $option ): bool {

		/**
		 * Filters which options are site settings: values the front-end renders
		 * as they are, on any page, whose change reports a `settings` change.
		 *
		 * Add an option the front-end renders; remove a default it does not.
		 * Do not add one that moves which content lives at which path — see
		 * ADR 0037.
		 *
		 * @param string[] $option_names The site setting options.
		 */
		$options = apply_filters( 'nextjs_revalidate_site_setting_options', self::DEFAULT_OPTIONS );

		return in_array( $option, $options, true );
	}
}
