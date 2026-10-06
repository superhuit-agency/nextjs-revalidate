<?php
/**
 * What Polylang's languages report — NextJsRevalidate\Integrations\Polylang.
 *
 * A language created, edited or deleted, and the default language changed,
 * each report one `settings` change — a language created, edited or deleted
 * also reports the `term` change of its term, since Polylang's `language`
 * taxonomy is publicly queryable and so revalidatable (ADR 0040); the `polylang` option's other keys, which
 * move paths or are bookkeeping, report nothing (ADR 0037). Languages are
 * driven through Polylang's own model, as its admin screens drive them. Runs
 * with Polylang loaded, which `.wp-env.tests.json` installs; without it, the
 * integration registers nothing, which
 * `tests/settings-integration-registration-test.php` asserts instead.
 *
 * @package NextJsRevalidate
 */

namespace NextJsRevalidate\Tests;

use NextJsRevalidate\Change;

class PolylangSettingsTest extends PendingChangesTestCase {

	/**
	 * Polylang's admin model, over the options of the site being served.
	 *
	 * @var \PLL_Admin_Model
	 */
	private $model;

	public function set_up() {
		parent::set_up();

		if ( ! defined( 'POLYLANG_VERSION' ) ) $this->markTestSkipped( 'Polylang is not installed.' );

		// Polylang readies its languages when it sets up a context, and a test
		// run, having no languages and no admin screen, sets up none.
		$this->model = new \PLL_Admin_Model( new \WP_Syntex\Polylang\Options\Options() );
		$this->model->languages->set_ready();

		$this->configure_site();
		$this->reset_pending_changes();
	}

	// Languages
	// ====

	public function test_adding_a_language_reports_a_change() {
		$english = $this->add_language( 'en_US', 'en' );

		$this->assertPendingChanges( [
			Change::term( $english->term_id, 'language', null, $this->side_of( $english->term_id ) ),
			Change::settings(),
		] );
	}

	public function test_editing_a_language_reports_a_change() {
		$english = $this->add_language( 'en_US', 'en' );
		$this->reset_pending_changes();

		$updated = $this->model->languages->update( [ 'lang_id' => $english->term_id, 'name' => 'Anglais' ] );

		$this->assertNotWPError( $updated );

		$side = $this->side_of( $english->term_id );
		$this->assertPendingChanges( [
			Change::term( $english->term_id, 'language', $side, $side ),
			Change::settings(),
		] );
	}

	public function test_deleting_a_language_reports_a_change() {
		$this->add_language( 'en_US', 'en' );
		$french = $this->add_language( 'fr_FR', 'fr' );
		$side   = $this->side_of( $french->term_id );
		$this->reset_pending_changes();

		$this->assertTrue( $this->model->languages->delete( $french->term_id ), 'The language was not deleted.' );

		$this->assertPendingChanges( [
			Change::term( $french->term_id, 'language', $side, null ),
			Change::settings(),
		] );
	}

	// The default language
	// ====

	/**
	 * Through Polylang's own model, as its Languages screen does it. Polylang
	 * stores the option at `shutdown`, after the pending changes are delivered,
	 * so the change is reported from the action it fires on the way.
	 */
	public function test_changing_the_default_language_reports_a_change() {
		$this->add_language( 'en_US', 'en' );
		$this->add_language( 'fr_FR', 'fr' );
		$this->reset_pending_changes();

		$this->model->languages->update_default( 'fr' );

		$this->assertPendingChanges( [ Change::settings() ] );
	}

	/**
	 * Written to the option directly, as WP-CLI or a migration would.
	 */
	public function test_writing_a_different_default_language_to_the_option_reports_a_change() {
		update_option( 'polylang', [ 'default_lang' => 'en', 'force_lang' => 1 ] );
		$this->reset_pending_changes();

		update_option( 'polylang', [ 'default_lang' => 'fr', 'force_lang' => 1 ] );

		$this->assertPendingChanges( [ Change::settings() ] );
	}

	/**
	 * Before Polylang 3.7 the model wrote the option in the same request, so
	 * both hooks fire; they are the same change.
	 */
	public function test_the_model_and_the_option_reporting_one_default_language_change_is_one_change() {
		$this->add_language( 'en_US', 'en' );
		$this->add_language( 'fr_FR', 'fr' );
		$this->reset_pending_changes();

		$this->model->languages->update_default( 'fr' );
		update_option( 'polylang', array_merge( (array) get_option( 'polylang' ), [ 'default_lang' => 'fr' ] ) );

		$this->assertPendingChanges( [ Change::settings() ] );
	}

	/**
	 * @dataProvider keys_that_are_not_site_settings
	 */
	public function test_changing_another_key_of_the_option_reports_nothing( $key, $before, $after ) {
		update_option( 'polylang', [ 'default_lang' => 'en', $key => $before ] );
		$this->reset_pending_changes();

		update_option( 'polylang', [ 'default_lang' => 'en', $key => $after ] );

		$this->assertNoPendingChanges();
	}

	public function keys_that_are_not_site_settings() {
		return [
			'force_lang'   => [ 'force_lang', 1, 2 ],
			'hide_default' => [ 'hide_default', true, false ],
			'rewrite'      => [ 'rewrite', true, false ],
			'version'      => [ 'version', '3.6', '3.7' ],
		];
	}

	// String translations
	// ====

	/**
	 * A translated site title or tagline, or any string registered with
	 * Polylang, saved from Languages → Translations: Polylang stores each
	 * language's in the language term's meta, and writes no option.
	 */
	public function test_saving_a_language_s_string_translations_reports_a_change() {
		$french = $this->add_language( 'fr_FR', 'fr' );
		$this->reset_pending_changes();

		$mo = new \PLL_MO();
		$mo->add_entry( $mo->make_entry( 'A site title', 'Un titre de site' ) );
		$mo->export_to_db( $french );

		$this->assertPendingChanges( [ Change::settings() ] );
	}

	/**
	 * Another term meta, of a language or of any term, is not a site setting.
	 */
	public function test_other_term_meta_reports_nothing() {
		$french = $this->add_language( 'fr_FR', 'fr' );
		$this->reset_pending_changes();

		update_term_meta( $french->term_id, 'njr_something_else', 'a value' );

		$this->assertNoPendingChanges();
	}

	// Fixtures
	// ====

	/**
	 * A language's term as a `term` change's side reports it.
	 *
	 * @param int $term_id
	 * @return array
	 */
	private function side_of( $term_id ) {
		$term = get_term( $term_id, 'language' );
		$this->assertInstanceOf( \WP_Term::class, $term, 'The language\'s term cannot be read back.' );

		return Change::term_side( $term->slug, Change::uri_of( get_term_link( $term ) ) );
	}

	/**
	 * Add a language through Polylang's model.
	 *
	 * @param string $locale
	 * @param string $slug
	 * @return \PLL_Language
	 */
	private function add_language( $locale, $slug ) {
		$added = $this->model->languages->add( [ 'locale' => $locale, 'slug' => $slug ] );
		$this->assertNotWPError( $added, "The $locale language was not added." );

		$this->model->clean_languages_cache();

		$language = $this->model->languages->get( $slug );
		$this->assertInstanceOf( \PLL_Language::class, $language, "The $locale language cannot be read back." );

		return $language;
	}
}
