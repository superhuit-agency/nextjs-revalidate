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
 * default, and whatever an integration or the site adds — or starts with a
 * prefix on it, written with a trailing `*`, for an option stored once per
 * language whose languages cannot be listed ahead of time. One on the list
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
		// The core Site Logo block's, which core keeps in step with the theme's
		// `custom_logo` mod.
		'site_logo',
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
		 * An entry ending in `*` names every option starting with what comes
		 * before it — `landbot_config_url_*` for `landbot_config_url_fr` and
		 * `landbot_config_url_de`. Do not add one that moves which content
		 * lives at which path — see ADR 0037.
		 *
		 * @param string[] $option_names The site setting options, and prefixes.
		 */
		$filtered = apply_filters( 'nextjs_revalidate_site_setting_options', self::DEFAULT_OPTIONS );

		// The docblock above is what a callback is given, not what it is held
		// to return. One that returns something else must not fatal every
		// option write on the site: it names no site setting instead.
		/** @var mixed $options */
		$options = $filtered;
		if ( ! is_array( $options ) ) return false;

		if ( in_array( $option, $options, true ) ) return true;

		foreach ( $options as $entry ) {
			if ( ! is_string( $entry ) || '*' !== substr( $entry, -1 ) ) continue;

			$prefix = substr( $entry, 0, -1 );

			// A bare `*` would name every option on the site — the cron array
			// and every transient among them — and report a settings change
			// from nearly every request. It names none.
			if ( '' === $prefix ) continue;

			if ( 0 === strpos( $option, $prefix ) ) return true;
		}

		return false;
	}
}
