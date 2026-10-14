<?php
/**
 * What Polylang's languages report — NextJsRevalidate\Integrations\Polylang.
 *
 * A language created, edited or deleted reports one `settings` change, and no
 * `term` change: the integration declines Polylang's `language` taxonomy (ADR
 * 0040). A routing key of the `polylang` option — the default language among
 * them — reports one revalidate all, whether it is written to the option or
 * changed in Polylang's options in memory; its other keys are bookkeeping and
 * report nothing (ADR 0044). Languages are driven through Polylang's own
 * model, as its admin screens drive them. Runs
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

		// The running Polylang, whose options in memory the integration
		// compares with the stored option. A context is set up only once a
		// site has languages, and a test run has none to begin with.
		$links_model         = $this->model->get_links_model();
		$GLOBALS['polylang'] = new \PLL_Admin( $links_model );

		$this->configure_site();
		$this->reset_pending_changes();
	}

	public function tear_down() {
		unset( $GLOBALS['polylang'] );

		parent::tear_down();
	}

	// Languages
	// ====

	public function test_adding_a_language_reports_a_change() {
		$this->add_language( 'en_US', 'en' );

		$this->assertPendingChanges( [ Change::settings() ] );
	}

	public function test_editing_a_language_reports_a_change() {
		$english = $this->add_language( 'en_US', 'en' );
		$this->reset_pending_changes();

		$updated = $this->model->languages->update( [ 'lang_id' => $english->term_id, 'name' => 'Anglais' ] );

		$this->assertNotWPError( $updated );
		$this->assertPendingChanges( [ Change::settings() ] );
	}

	public function test_deleting_a_language_reports_a_change() {
		$this->add_language( 'en_US', 'en' );
		$french = $this->add_language( 'fr_FR', 'fr' );
		$this->reset_pending_changes();

		$this->assertTrue( $this->model->languages->delete( $french->term_id ), 'The language was not deleted.' );

		$this->assertPendingChanges( [ Change::settings() ] );
	}

	// Routing settings
	// ====

	/**
	 * Through Polylang's own model, as its Languages screen does it. Polylang
	 * 3.7 and later write the option at `shutdown`, after the pending changes
	 * are delivered, so the change is found by comparing its options in memory
	 * with the stored option just before delivery — and the model's own action
	 * reports nothing (ADR 0044).
	 */
	public function test_changing_the_default_language_reports_a_revalidate_all() {
		$this->add_language( 'en_US', 'en' );
		$this->add_language( 'fr_FR', 'fr' );
		$this->store_options();

		$this->model->languages->update_default( 'fr' );

		$this->assertNoPendingChanges( 'The default language change was reported from the model, not the comparison.' );

		$this->compare_before_delivery();

		$this->assertPendingChanges( [ Change::all() ] );
	}

	/**
	 * Renaming the default language's slug writes the default language too,
	 * without the action `update_default()` fires. The language's own term
	 * hook reports the `settings` change beside it.
	 */
	public function test_renaming_the_default_language_s_slug_reports_a_revalidate_all() {
		$english = $this->add_language( 'en_US', 'en' );
		$this->add_language( 'fr_FR', 'fr' );
		$this->store_options();

		$updated = $this->model->languages->update( [ 'lang_id' => $english->term_id, 'slug' => 'english' ] );
		$this->assertNotWPError( $updated );

		$this->compare_before_delivery();

		$pending = $this->pending_changes()->pending();
		$this->assertContains( Change::all(), $pending );
		foreach ( $pending as $change ) $this->assertContains( $change, [ Change::settings(), Change::all() ] );
	}

	/**
	 * The first language added becomes the default language.
	 */
	public function test_adding_the_first_language_reports_a_revalidate_all() {
		$this->store_options();

		$this->add_language( 'en_US', 'en' );
		$this->compare_before_delivery();

		$this->assertPendingChanges( [ Change::settings(), Change::all() ] );
	}

	/**
	 * Polylang's settings page and its REST route write only into its options
	 * in memory, and fire no action for these keys.
	 *
	 * @dataProvider routing_keys_in_memory
	 */
	public function test_changing_a_routing_key_in_memory_reports_a_revalidate_all( $key, $value ) {
		$this->add_language( 'en_US', 'en' );
		$this->add_language( 'fr_FR', 'fr' );
		$this->store_options();

		$errors = $this->model->options->set( $key, $value );
		$this->assertFalse( $errors->has_errors(), "Polylang refused $key: " . $errors->get_error_message() );

		$this->compare_before_delivery();

		$this->assertPendingChanges( [ Change::all() ] );
	}

	public function routing_keys_in_memory() {
		return [
			'hide_default'  => [ 'hide_default', false ],
			'force_lang'    => [ 'force_lang', 2 ],
			'rewrite'       => [ 'rewrite', false ],
			'domains'       => [ 'domains', [ 'fr' => 'https://fr.example.test' ] ],
			'redirect_lang' => [ 'redirect_lang', true ],
		];
	}

	/**
	 * Several routing keys changed at once, as the settings page saves them,
	 * are one revalidate all.
	 */
	public function test_changing_several_routing_keys_in_memory_is_one_revalidate_all() {
		$this->add_language( 'en_US', 'en' );
		$this->store_options();

		$this->model->options->set( 'force_lang', 2 );
		$this->model->options->set( 'hide_default', false );
		$this->model->options->set( 'rewrite', false );

		$this->compare_before_delivery();

		$this->assertPendingChanges( [ Change::all() ] );
	}

	public function test_changing_another_key_in_memory_reports_nothing() {
		$this->add_language( 'en_US', 'en' );
		$this->store_options();

		$this->model->options['version'] = '99.0';
		$this->model->options['sync']    = [ 'menu_order' ];

		$this->compare_before_delivery();

		$this->assertNoPendingChanges();
	}

	/**
	 * The stored option can hold `"1"` or `1` where memory holds `true`, and a
	 * string where it holds an int: the same value, told apart only by type.
	 *
	 * @dataProvider values_that_differ_only_in_type
	 */
	public function test_a_routing_key_stored_with_another_type_reports_nothing( $key, $stored ) {
		$this->add_language( 'en_US', 'en' );
		$this->store_options();

		update_option( 'polylang', array_merge( (array) get_option( 'polylang' ), [ $key => $stored ] ) );

		$this->compare_before_delivery();

		$this->assertNoPendingChanges();
	}

	public function values_that_differ_only_in_type() {
		return [
			'hide_default "1"' => [ 'hide_default', '1' ],
			'hide_default 1'   => [ 'hide_default', 1 ],
			'rewrite "1"'      => [ 'rewrite', '1' ],
			'force_lang "1"'   => [ 'force_lang', '1' ],
			'redirect_lang ""' => [ 'redirect_lang', '' ],
			'redirect_lang 0'  => [ 'redirect_lang', 0 ],
		];
	}

	public function test_nothing_changed_reports_nothing() {
		$this->add_language( 'en_US', 'en' );
		$this->store_options();

		$this->compare_before_delivery();

		$this->assertNoPendingChanges();
	}

	/**
	 * With no Polylang running — no languages yet, on the front end — there is
	 * nothing in memory to compare.
	 */
	public function test_no_running_polylang_reports_nothing() {
		$this->add_language( 'en_US', 'en' );
		$this->store_options();
		$this->model->options->set( 'force_lang', 2 );

		unset( $GLOBALS['polylang'] );
		$this->compare_before_delivery();

		$this->assertNoPendingChanges();
	}

	/**
	 * The comparison runs before the pending changes are delivered, in the
	 * same `shutdown`: after, whatever it found would be held with nothing
	 * left to send it.
	 */
	public function test_the_comparison_runs_before_delivery() {
		$compare = has_action( 'shutdown', [ \NextJsRevalidate::init()->polylang, 'compare_routing_settings' ] );
		$deliver = has_action( 'shutdown', [ $this->pending_changes(), 'deliver' ] );

		$this->assertIsInt( $compare, 'The comparison is not hooked to shutdown.' );
		$this->assertIsInt( $deliver, 'The delivery is not hooked to shutdown.' );
		$this->assertLessThan( $deliver, $compare );
	}

	/**
	 * Written to the option directly, as WP-CLI, a migration or Polylang's
	 * wizard would.
	 */
	public function test_writing_a_different_default_language_to_the_option_reports_a_revalidate_all() {
		update_option( 'polylang', [ 'default_lang' => 'en', 'force_lang' => 1 ] );
		$this->reset_pending_changes();

		update_option( 'polylang', [ 'default_lang' => 'fr', 'force_lang' => 1 ] );

		$this->assertPendingChanges( [ Change::all() ] );
	}

	/**
	 * Before Polylang 3.7 the model wrote the option in the same request, so
	 * the option's hook and the comparison both see it; they are the same
	 * change.
	 */
	public function test_the_model_and_the_option_reporting_one_default_language_change_is_one_change() {
		$this->add_language( 'en_US', 'en' );
		$this->add_language( 'fr_FR', 'fr' );
		$this->store_options();

		$this->model->languages->update_default( 'fr' );
		update_option( 'polylang', array_merge( (array) get_option( 'polylang' ), [ 'default_lang' => 'fr' ] ) );
		$this->compare_before_delivery();

		$this->assertPendingChanges( [ Change::all() ] );
	}

	/**
	 * Written to the option directly. A routing key reports a revalidate all
	 * (ADR 0044); `version` is bookkeeping a Polylang upgrade rewrites, and a
	 * value told apart only by its type is the same value.
	 *
	 * @dataProvider keys_that_are_not_site_settings
	 */
	public function test_writing_another_key_of_the_option( $key, $before, $after, $expected ) {
		update_option( 'polylang', [ 'default_lang' => 'en', $key => $before ] );
		$this->reset_pending_changes();

		update_option( 'polylang', [ 'default_lang' => 'en', $key => $after ] );

		$this->assertPendingChanges( $expected );
	}

	public function keys_that_are_not_site_settings() {
		return [
			'force_lang'                => [ 'force_lang', 1, 2, [ Change::all() ] ],
			'hide_default'              => [ 'hide_default', true, false, [ Change::all() ] ],
			'rewrite'                   => [ 'rewrite', true, false, [ Change::all() ] ],
			'domains'                   => [ 'domains', [], [ 'fr' => 'https://fr.example.test' ], [ Change::all() ] ],
			'redirect_lang'             => [ 'redirect_lang', false, true, [ Change::all() ] ],
			'version'                   => [ 'version', '3.6', '3.7', [] ],
			'hide_default "1" for true' => [ 'hide_default', true, '1', [] ],
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
	 * Store Polylang's options in memory, as its own save at `shutdown` would,
	 * so a test starts from options that match, and from no change.
	 *
	 * @return void
	 */
	private function store_options() {
		update_option( 'polylang', $this->model->options->get_all() );
		$this->reset_pending_changes();
	}

	/**
	 * What runs on `shutdown` just before the pending changes are delivered.
	 *
	 * @return void
	 */
	private function compare_before_delivery() {
		\NextJsRevalidate::init()->polylang->compare_routing_settings();
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
