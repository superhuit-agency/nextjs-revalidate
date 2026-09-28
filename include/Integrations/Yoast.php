<?php

namespace NextJsRevalidate\Integrations;

use NextJsRevalidate\Interfaces\Hookable;
use NextJsRevalidate\Traits\WhenPluginsLoaded;

// Exit if accessed directly.
defined( 'ABSPATH' ) or die( 'Cheatin&#8217; uh?' );

/**
 * The Yoast SEO integration: its SEO defaults are site settings.
 *
 * `wpseo_titles` holds the title templates, separators and schema defaults,
 * and `wpseo_social` the default social images and profiles — what a
 * front-end reading Yoast's data renders on every page. They are added to the
 * site setting options through the same filter a site uses, so saving either
 * reports a `settings` change like any of WordPress's own.
 *
 * Not `wpseo`: nothing the front-end renders lives there, and Yoast writes it
 * on its own — indexing progress, activation timestamps, notification and
 * tracking state — so watching it would rebuild every page from Yoast's
 * background work. A site whose front-end does read it adds it through the
 * filter.
 *
 * Supported, never required: when Yoast SEO is not there, this registers
 * nothing at all.
 *
 * See `docs/adr/0037-a-settings-change-reports-what-every-page-renders.md`.
 */
class Yoast implements Hookable {
	use WhenPluginsLoaded;


	/**
	 * Yoast's options that hold site settings.
	 */
	const OPTIONS = [ 'wpseo_titles', 'wpseo_social' ];

	/**
	 * Register the integration's hooks, once every plugin has declared itself
	 * — the same deferral, and for the same reason, as `Redirection`'s.
	 *
	 * @return void
	 */
	public function register_hooks(): void {

		$this->when_plugins_loaded( [$this, 'register_yoast_hooks'] );
	}

	/**
	 * Add Yoast's options to the site settings, if Yoast SEO is what this site
	 * runs.
	 *
	 * @return void
	 */
	public function register_yoast_hooks() {

		if ( ! defined( 'WPSEO_VERSION' ) ) return;

		add_filter( 'nextjs_revalidate_site_setting_options', [$this, 'add_site_setting_options'] );
	}

	/**
	 * The site setting options, with Yoast's.
	 *
	 * @param mixed $options The site setting options so far.
	 * @return mixed
	 */
	public function add_site_setting_options( $options ) {

		// Somebody else's filter returned something that is not a list; it is
		// passed on rather than repaired.
		if ( ! is_array( $options ) ) return $options;

		return array_values( array_unique( array_merge( $options, self::OPTIONS ) ) );
	}
}
