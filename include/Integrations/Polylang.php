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
 * That taxonomy is `publicly_queryable`, so it would pass the taxonomy gate
 * like any other, and it is declined through
 * `nextjs_revalidate_should_revalidate_taxonomy`. A language is a site setting,
 * not a term the front-end shows: as a revalidatable taxonomy, every
 * translated post would list its language in `terms`, so every save would
 * expire the term every page of that language may carry, and a language added
 * or edited would report a `term` change next to its `settings` one.
 * Polylang's other taxonomies — `term_language`, `post_translations` and
 * `term_translations` — are registered `public => false` and are not viewable,
 * so the gate declines them already.
 *
 * Polylang's **routing settings** — `default_lang`, `hide_default`,
 * `force_lang`, `rewrite`, `domains` and `redirect_lang`, in the `polylang`
 * option — decide whether a language prefix or domain is in the path, and
 * which language an unprefixed path answers in. A change to any of them
 * reports one revalidate all of the whole site, not a `settings` change: a
 * default language changed moves paths too. The option's other keys —
 * `version`, `sync` and the rest — are bookkeeping, and report nothing.
 *
 * Polylang 3.7 and later hold that option in memory and write it on `shutdown`
 * at priority 1000, after the pending changes are delivered; its settings page
 * and its REST route write only into that memory, and it fires no action for
 * these keys. So just before delivery, in the same `shutdown`, the routing keys
 * in its options in memory are compared with the stored option, which still
 * holds what was there before. One comparison catches every writer that goes
 * through Polylang's options: the settings page, REST, changing the default
 * language, renaming its slug, adding the first language, the importer. The
 * option's own hook still catches a write made directly — from WP-CLI, a
 * migration, or Polylang's wizard. When both see a change, it is one.
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
 * See `docs/adr/0037-a-settings-change-reports-what-every-page-renders.md`,
 * and `docs/adr/0044-a-routing-setting-reports-a-revalidate-all.md` for the
 * routing settings.
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
	 * The keys of the `polylang` option that are routing settings.
	 */
	const ROUTING_KEYS = [ 'default_lang', 'hide_default', 'force_lang', 'rewrite', 'domains', 'redirect_lang' ];

	/**
	 * The `shutdown` priority the comparison runs at: before the pending
	 * changes are delivered, at the default 10, and long before Polylang
	 * writes its option, at 1000.
	 */
	const COMPARISON_PRIORITY = 9;

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

		add_filter( 'nextjs_revalidate_should_revalidate_taxonomy', [$this, 'decline_language_taxonomy'], 10, 2 );

		add_action( 'shutdown', [$this, 'compare_routing_settings'], self::COMPARISON_PRIORITY );
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
	 * deleted, or its string translations were saved.
	 *
	 * @return void
	 */
	public function report_settings_change() {
		$this->pendingChanges->report( Change::settings() );
	}

	/**
	 * Leave Polylang's `language` taxonomy out of the revalidatable taxonomies
	 * — see the class docblock.
	 *
	 * So no language is reported as a `term` change, listed in a post's
	 * `terms`, or named by revalidate all of a post type. At the default
	 * priority, so a site that does tag pages with a language's term can admit
	 * it again from a later one.
	 *
	 * @param mixed $should_revalidate Whether the taxonomy's terms are revalidated.
	 * @param mixed $taxonomy_name     The taxonomy name.
	 * @return mixed
	 */
	public function decline_language_taxonomy( $should_revalidate, $taxonomy_name ) {
		return self::LANGUAGE_TAXONOMY === $taxonomy_name ? false : $should_revalidate;
	}

	/**
	 * The `polylang` option was written with a different value — which is a
	 * routing setting change only when a routing key is what differs.
	 *
	 * Not while `shutdown` runs: a write before the comparison is in the
	 * stored option it compares, and Polylang's own save, after delivery,
	 * writes the options in memory it has already compared. Reporting from
	 * there would only log a change that nothing is left to send.
	 *
	 * @param mixed $old_value The option before.
	 * @param mixed $value     The option after.
	 * @return void
	 */
	public function on_option_update( $old_value, $value ) {
		if ( doing_action( 'shutdown' ) ) return;
		if ( ! self::routing_differs( $old_value, $value ) ) return;

		$this->pendingChanges->report( Change::all() );
	}

	/**
	 * Compare the routing keys of Polylang's options in memory with the
	 * stored option, and report a revalidate all when one differs — see the
	 * class docblock.
	 *
	 * Nothing to compare when no Polylang is running — a front end with no
	 * language yet sets none up — or when no option is stored yet.
	 *
	 * @return void
	 */
	public function compare_routing_settings() {
		$in_memory = $this->running_options();
		if ( null === $in_memory ) return;

		if ( ! self::routing_differs( get_option( self::OPTION ), $in_memory ) ) return;

		$this->pendingChanges->report( Change::all() );
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

		// Unlike `Revalidate::report_post_save()`, no bail on a `meta-box-loader`
		// request. Polylang copies a synchronised custom field to the translations
		// as it is written, and a metabox's field is written in that request,
		// after the block editor's REST save has already been reported: the
		// translations stand as the front-end will see them only now. Skipping
		// it would drop the report that is right, to save one extra request
		// when nothing synchronised changed.
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
	 * Polylang 3.7 and later hold in memory — see the class docblock.
	 *
	 * @return array The `sync` list, empty when there is none.
	 */
	private function synchronised_fields() {
		$options = $this->running_options();
		if ( null === $options ) return [];

		$sync = $options['sync'] ?? null;

		return is_array( $sync ) ? $sync : [];
	}

	/**
	 * The running Polylang's options: an array before 3.7, an `ArrayAccess`
	 * since, which reads each through its `get()`.
	 *
	 * @return array|\ArrayAccess|null Null when no Polylang is running.
	 */
	private function running_options() {
		$options = is_object( $GLOBALS['polylang'] ?? null ) ? ( $GLOBALS['polylang']->options ?? null ) : null;

		return ( is_array( $options ) || $options instanceof \ArrayAccess ) ? $options : null;
	}

	/**
	 * Whether two values of Polylang's options differ in a routing key.
	 *
	 * Only the keys both hold are compared: a key one side lacks is one that
	 * side leaves to Polylang's default, and comparing it would report on
	 * every request until Polylang next writes its option. Nothing at all is
	 * compared when either side is not options.
	 *
	 * @param mixed $before The options before: the stored option.
	 * @param mixed $after  The options after.
	 * @return bool
	 */
	private static function routing_differs( $before, $after ) {
		if ( ! self::is_options( $before ) || ! self::is_options( $after ) ) return false;

		foreach ( self::ROUTING_KEYS as $key ) {
			if ( ! self::holds( $before, $key ) || ! self::holds( $after, $key ) ) continue;

			if ( self::normalised( $before[ $key ] ) !== self::normalised( $after[ $key ] ) ) return true;
		}

		return false;
	}

	/**
	 * @param mixed $options
	 * @return bool
	 * @phpstan-assert-if-true array|\ArrayAccess $options
	 */
	private static function is_options( $options ) {
		return is_array( $options ) || $options instanceof \ArrayAccess;
	}

	/**
	 * Whether options hold a key: Polylang's own `ArrayAccess` answers for the
	 * keys it registered.
	 *
	 * @param array|\ArrayAccess $options
	 * @param string             $key
	 * @return bool
	 */
	private static function holds( $options, $key ) {
		return is_array( $options ) ? array_key_exists( $key, $options ) : $options->offsetExists( $key );
	}

	/**
	 * A value of a routing key with its type told apart no more than Polylang
	 * tells it apart: the stored option can hold `"1"` or `1` where memory
	 * holds `true`, a string where memory holds an int, and an empty value of
	 * any type for false.
	 *
	 * @param mixed $value
	 * @return string|array<array-key, mixed> An empty value as `''`, any other
	 *                                        scalar as a string, a map with
	 *                                        its empty entries dropped and its
	 *                                        keys sorted.
	 */
	private static function normalised( $value ) {
		if ( empty( $value ) ) return '';
		if ( true === $value ) return '1';
		if ( is_scalar( $value ) ) return (string) $value;
		if ( ! is_array( $value ) ) return '';

		$normalised = [];
		foreach ( $value as $key => $entry ) {
			$entry = self::normalised( $entry );
			if ( '' !== $entry ) $normalised[ $key ] = $entry;
		}
		if ( empty( $normalised ) ) return '';

		ksort( $normalised );

		return $normalised;
	}
}
