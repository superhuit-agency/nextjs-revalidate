<?php

namespace NextJsRevalidate\Integrations;

use NextJsRevalidate\Abstracts\Base;
use NextJsRevalidate\Change;
use NextJsRevalidate\Interfaces\Hookable;
use NextJsRevalidate\Revalidate;
use NextJsRevalidate\Traits\WhenPluginsLoaded;

// Exit if accessed directly.
defined( 'ABSPATH' ) or die( 'Cheatin&#8217; uh?' );

/**
 * The Polylang integration: its language list, its default language and its
 * string translations are site settings, and a post's translations move with
 * it.
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
 * A string translation — a translated site title or tagline, or any string
 * registered with Polylang — is kept in its language term's meta rather than in
 * an option, and saving one reports a `settings` change from that meta's hooks.
 *
 * With synchronisation on, Polylang copies what the site chose — the parent,
 * the order, the date, custom fields, the featured image, terms and so on — to
 * a post's translations with `$wpdb->update()` and the meta and term APIs, on
 * purpose, and never saves them: editing the French page edits the German
 * page, and only the French page is saved. So every save of a post reports its
 * translations too, where each stands, whichever field the site synchronises
 * and whether or not it changed: comparing them all is Polylang's business,
 * and a site has a handful of languages. A post's translations are also among
 * its **dependent posts**, so that one whose URI moved is reported from the
 * URI it had — and when the save changes the parent, their descendants are.
 *
 * Supported, never required: when Polylang is not there, this registers no
 * hooks at all.
 *
 * See `docs/adr/0037-a-settings-change-reports-what-every-page-renders.md`.
 *
 * @property-read \NextJsRevalidate\PendingChanges $pendingChanges The pending
 *                changes of this request, reached through `Base`.
 * @property-read \NextJsRevalidate\Revalidate     $revalidate     The post changes,
 *                reached through `Base`.
 */
class Polylang extends Base implements Hookable {
	use WhenPluginsLoaded;


	/**
	 * The taxonomy Polylang's languages are terms of.
	 */
	const LANGUAGE_TAXONOMY = 'language';

	/**
	 * The option Polylang's settings are stored in.
	 */
	const OPTION = 'polylang';

	/**
	 * The language term meta Polylang keeps that language's string
	 * translations in.
	 */
	const STRINGS_META_KEY = '_pll_strings_translations';

	/**
	 * Register the integration's hooks, once every plugin has declared itself
	 * — the same deferral, and for the same reason, as `Redirection`'s.
	 *
	 * @return void
	 */
	public function register_hooks(): void {

		$this->when_plugins_loaded( [$this, 'register_polylang_hooks'] );
	}

	/**
	 * Listen to Polylang's languages, if Polylang is what this site runs.
	 *
	 * @return void
	 */
	public function register_polylang_hooks() {

		if ( ! defined( 'POLYLANG_VERSION' ) ) return;

		add_action( 'created_' . self::LANGUAGE_TAXONOMY, [$this, 'report_settings_change'] );
		add_action( 'edited_' . self::LANGUAGE_TAXONOMY,  [$this, 'report_settings_change'] );
		add_action( 'delete_' . self::LANGUAGE_TAXONOMY,  [$this, 'report_settings_change'] );

		add_action( 'pll_update_default_lang', [$this, 'report_settings_change'] );
		add_action( 'update_option_' . self::OPTION, [$this, 'on_option_update'], 10, 2 );

		add_action( 'added_term_meta',   [$this, 'on_term_meta_change'], 10, 3 );
		add_action( 'updated_term_meta', [$this, 'on_term_meta_change'], 10, 3 );
		add_action( 'deleted_term_meta', [$this, 'on_term_meta_change'], 10, 3 );

		add_filter( 'nextjs_revalidate_dependent_posts', [$this, 'add_translations'], 10, 4 );

		// After `Revalidate`'s own, at 99, which reports a translation whose URI
		// moved from the URI it had: the pending changes keep the first
		// `before`, so this report, where it stands, has to come second.
		add_action( 'wp_after_insert_post', [$this, 'report_synchronised_translations'], 100 );
	}

	/**
	 * Report that a site setting changed: a language was created, edited or
	 * deleted, or the default language changed.
	 *
	 * @return void
	 */
	public function report_settings_change() {
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

		$this->report_settings_change();
	}

	/**
	 * A term meta was added, updated or deleted — which is a site setting
	 * change only when it holds a language's string translations.
	 *
	 * @param mixed $meta_id   The meta's ID, or IDs for a delete.
	 * @param mixed $object_id The term's ID.
	 * @param mixed $meta_key  The meta's key.
	 * @return void
	 */
	public function on_term_meta_change( $meta_id, $object_id, $meta_key ) {
		if ( self::STRINGS_META_KEY !== $meta_key ) return;

		$this->report_settings_change();
	}

	/**
	 * Add a post's translations to the posts its update may move: the
	 * synchronisation writes them without saving them.
	 *
	 * Every translation, whichever field the site synchronises: one whose URI
	 * did not move is not reported, and reading a handful of permalinks is
	 * cheaper than second-guessing Polylang's options. Their descendants only
	 * when the update changes the parent, which is the one synchronised field
	 * a child page's permalink is built from.
	 *
	 * @param mixed $post_ids    The dependent post IDs so far.
	 * @param mixed $post_id     The ID of the post about to be updated.
	 * @param mixed $post_before The post as it is before the update.
	 * @param mixed $data        The post's fields as they are about to be written.
	 * @return mixed
	 */
	public function add_translations( $post_ids, $post_id, $post_before, $data ) {

		// Somebody else's filter returned something that is not a list; it is
		// passed on rather than repaired.
		if ( ! is_array( $post_ids ) ) return $post_ids;

		$translations = array_diff( $this->translations( (int) $post_id ), [ (int) $post_id ] );
		if ( empty( $translations ) ) return $post_ids;

		$moves_parent = is_array( $data )
			&& $post_before instanceof \WP_Post
			&& Revalidate::update_changes( $post_before, $data, 'post_parent' )
			&& is_post_type_hierarchical( $post_before->post_type );

		foreach ( $translations as $translation_id ) {
			$post_ids[] = $translation_id;

			if ( $moves_parent ) {
				foreach ( $this->revalidate->descendants( $translation_id ) as $descendant_id ) $post_ids[] = $descendant_id;
			}
		}

		return $post_ids;
	}

	/**
	 * A post was saved: report its translations where they stand, when the
	 * site has Polylang synchronise anything — see the class docblock.
	 *
	 * @param int $post_id The post that was saved.
	 * @return void
	 */
	public function report_synchronised_translations( $post_id ) {

		if ( empty( $this->synchronised_fields() ) ) return;

		// A revision or an autosave is synchronised to nothing.
		if ( false !== wp_is_post_revision( $post_id ) || false !== wp_is_post_autosave( $post_id ) ) return;

		foreach ( array_diff( $this->translations( (int) $post_id ), [ (int) $post_id ] ) as $translation_id ) {
			$this->revalidate->report_post_from( $translation_id, null );
		}
	}

	/**
	 * The post's translations, as Polylang links them: the post's own ID among
	 * them, or none for a post it has not set up — Polylang sets nothing up on a
	 * site with no language.
	 *
	 * @param int $post_id
	 * @return int[]
	 */
	private function translations( $post_id ) {
		if ( ! function_exists( 'pll_get_post_translations' ) || empty( $GLOBALS['polylang'] ) ) return [];

		$translations = pll_get_post_translations( $post_id );

		return is_array( $translations ) ? array_map( 'intval', array_values( $translations ) ) : [];
	}

	/**
	 * The fields the site has Polylang synchronise between translations.
	 *
	 * Read off the running Polylang rather than the `polylang` option, which
	 * Polylang 3.7 and later hold in memory — see the class docblock. Its
	 * options are an array before 3.7, an `ArrayAccess` since.
	 *
	 * @return array The `sync` list, empty when there is none.
	 */
	private function synchronised_fields() {
		$options = is_object( $GLOBALS['polylang'] ?? null ) ? ( $GLOBALS['polylang']->options ?? null ) : null;
		if ( ! is_array( $options ) && ! $options instanceof \ArrayAccess ) return [];

		$sync = $options['sync'] ?? null;

		return is_array( $sync ) ? $sync : [];
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
