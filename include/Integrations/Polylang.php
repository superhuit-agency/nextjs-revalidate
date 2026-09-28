<?php

namespace NextJsRevalidate\Integrations;

use NextJsRevalidate\Abstracts\Base;
use NextJsRevalidate\Change;
use NextJsRevalidate\Interfaces\Hookable;

// Exit if accessed directly.
defined( 'ABSPATH' ) or die( 'Cheatin&#8217; uh?' );

/**
 * The Polylang integration: its language list and its default language are
 * site settings.
 *
 * A language is a term of Polylang's `language` taxonomy rather than an
 * option, so no site setting option can catch it: creating, editing or
 * deleting one reports a `settings` change from the term's own hooks.
 *
 * The default language lives in the `polylang` option, alongside keys that are
 * not site settings: `hide_default`, `force_lang` and `rewrite` decide whether a
 * language prefix is in the path at all, which moves paths (#172), and
 * `version` is bookkeeping a Polylang upgrade rewrites. So a write of that
 * option reports a change when its `default_lang` differs, and only then.
 *
 * Polylang 3.7 and later hold that option in memory and write it on `shutdown`,
 * after the pending changes are delivered. A change of default language made
 * through Polylang is therefore reported from `pll_update_default_lang`, which
 * it fires while the request is still running; the option's own hook still
 * catches a write made directly, from WP-CLI or a migration. When both fire
 * they are the same change.
 *
 * Supported, never required: when Polylang is not there, this registers no
 * hooks at all.
 *
 * See `docs/adr/0037-a-settings-change-reports-what-every-page-renders.md`.
 *
 * @property-read \NextJsRevalidate\PendingChanges $pendingChanges The pending
 *                changes of this request, reached through `Base`.
 */
class Polylang extends Base implements Hookable {

	/**
	 * The taxonomy Polylang's languages are terms of.
	 */
	const LANGUAGE_TAXONOMY = 'language';

	/**
	 * The option Polylang's settings are stored in.
	 */
	const OPTION = 'polylang';

	/**
	 * Register the integration's hooks, once every plugin has declared itself
	 * — the same deferral, and for the same reason, as `Redirection`'s.
	 *
	 * @return void
	 */
	public function register_hooks(): void {

		if ( did_action( 'plugins_loaded' ) ) {
			$this->register_polylang_hooks();
			return;
		}

		add_action( 'plugins_loaded', [$this, 'register_polylang_hooks'] );
	}

	/**
	 * Listen to Polylang's languages, if Polylang is what this site runs.
	 *
	 * @return void
	 */
	public function register_polylang_hooks() {

		if ( ! defined( 'POLYLANG_VERSION' ) ) return;

		add_action( 'created_' . self::LANGUAGE_TAXONOMY, [$this, 'on_language_change'] );
		add_action( 'edited_' . self::LANGUAGE_TAXONOMY,  [$this, 'on_language_change'] );
		add_action( 'delete_' . self::LANGUAGE_TAXONOMY,  [$this, 'on_language_change'] );

		add_action( 'pll_update_default_lang', [$this, 'on_language_change'] );
		add_action( 'update_option_' . self::OPTION, [$this, 'on_option_update'], 10, 2 );
	}

	/**
	 * A language was created, edited or deleted, or the default language was
	 * changed through Polylang.
	 *
	 * @return void
	 */
	public function on_language_change() {
		$this->pendingChanges->report( Change::settings() );
	}

	/**
	 * The `polylang` option was written with a different value — which is a
	 * site setting change only when the default language is what differs.
	 *
	 * @param mixed $old_value The option before.
	 * @param mixed $value     The option after.
	 * @return void
	 */
	public function on_option_update( $old_value, $value ) {
		if ( self::default_language( $old_value ) === self::default_language( $value ) ) return;

		$this->on_language_change();
	}

	/**
	 * The default language a value of the `polylang` option names.
	 *
	 * @param mixed $options
	 * @return mixed The `default_lang`, or null when there is none.
	 */
	private static function default_language( $options ) {
		return is_array( $options ) ? ( $options['default_lang'] ?? null ) : null;
	}
}
