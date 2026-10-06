<?php
/**
 * What a save reports for the translations Polylang moves along with it —
 * NextJsRevalidate\Integrations\Polylang, through the dependent posts.
 *
 * With page parents synchronised, moving the French page under another parent
 * moves the German page under that parent's German translation. Polylang writes
 * the translation's row with `$wpdb->update()`, on purpose, and never saves it,
 * so nothing but the French page's own save knows the German page moved (#180).
 * The translations are that save's dependent posts, and each one whose URI moved
 * is reported from the URI it had, with its descendants. With anything
 * synchronised, every save reports the translations where they stand, since
 * Polylang may have written any synchronised field of theirs.
 *
 * Polylang's `language` taxonomy is declined by the integration, so a post's
 * language is never part of its `terms`, nor named by revalidate all, while
 * its other taxonomies are reported as on any site (ADR 0040).
 *
 * Driven through Polylang's own post handling and synchronisation, over its
 * model, as its admin screens drive them.
 *
 * @package NextJsRevalidate
 */

namespace NextJsRevalidate\Tests;

use NextJsRevalidate\Change;

class PolylangTranslationsTest extends PendingChangesTestCase {

	/**
	 * The Polylang object, as the admin sets it up.
	 *
	 * @var \PLL_Admin
	 */
	private $polylang;

	public function set_up() {
		parent::set_up();

		if ( ! defined( 'POLYLANG_VERSION' ) ) $this->markTestSkipped( 'Polylang is not installed.' );

		$this->set_permalink_structure( '/%postname%/' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$options = new \WP_Syntex\Polylang\Options\Options();
		$options['sync'] = [ 'post_parent', 'menu_order' ];

		$model = new \PLL_Admin_Model( $options );
		$model->languages->set_ready();

		foreach ( [ [ 'fr_FR', 'fr' ], [ 'de_DE', 'de' ] ] as list( $locale, $slug ) ) {
			$this->assertNotWPError( $model->languages->add( [ 'locale' => $locale, 'slug' => $slug ] ) );
		}
		$model->clean_languages_cache();

		$links_model    = $model->get_links_model();
		$this->polylang = new \PLL_Admin( $links_model );

		// What the admin sets up once it has languages: the post handling that
		// fires `pll_save_post`, and the synchronisation listening to it.
		$this->polylang->posts = new \PLL_CRUD_Posts( $this->polylang );
		$this->polylang->sync  = new \PLL_Sync( $this->polylang );

		// Polylang loads its public functions, and sets the object they read,
		// only once it has set a context up.
		require_once POLYLANG_DIR . '/src/api.php';
		$GLOBALS['polylang'] = $this->polylang;

		$this->configure_site();
	}

	public function tear_down() {
		unset( $GLOBALS['polylang'] );

		parent::tear_down();
	}

	public function test_moving_a_page_reports_its_translation_moved_by_the_synchronisation() {
		list( $old_fr, $old_de ) = $this->translated_pages( 'ancien', 'alt' );
		list( $new_fr, $new_de ) = $this->translated_pages( 'nouveau', 'neu' );
		list( $page_fr, $page_de ) = $this->translated_pages( 'page-fr', 'seite', $old_fr, $old_de );

		$this->reset_pending_changes();

		wp_update_post( [ 'ID' => $page_fr, 'post_parent' => $new_fr ] );

		$this->assertSame( $new_de, get_post( $page_de )->post_parent, 'Polylang did not synchronise the parent.' );

		$this->assertPendingChanges( [
			Change::post( $page_fr, 'page', '/ancien/page-fr/', '/nouveau/page-fr/' ),
			Change::post( $page_de, 'page', '/alt/seite/', '/neu/seite/' ),
		] );
	}

	public function test_the_descendants_of_a_moved_translation_are_reported_too() {
		list( $old_fr, $old_de ) = $this->translated_pages( 'ancien', 'alt' );
		list( $new_fr, $new_de ) = $this->translated_pages( 'nouveau', 'neu' );
		list( $page_fr, $page_de ) = $this->translated_pages( 'page-fr', 'seite', $old_fr, $old_de );
		$child_de = $this->page( 'kind', 'de', $page_de );

		$this->reset_pending_changes();

		wp_update_post( [ 'ID' => $page_fr, 'post_parent' => $new_fr ] );

		$this->assertContains(
			Change::post( $child_de, 'page', '/alt/seite/kind/', '/neu/seite/kind/' ),
			$this->pending_changes()->pending()
		);
	}

	/**
	 * A synchronised order moves no URI, but the translation's listings are
	 * in another order all the same: it is reported where it is, as a
	 * reordered post is.
	 */
	public function test_a_translation_whose_order_the_synchronisation_changed_is_reported() {
		list( $page_fr, $page_de ) = $this->translated_pages( 'page-fr', 'seite' );

		$this->reset_pending_changes();

		wp_update_post( [ 'ID' => $page_fr, 'menu_order' => 5 ] );

		$this->assertSame( 5, get_post( $page_de )->menu_order, 'Polylang did not synchronise the order.' );

		$this->assertPendingChanges( [
			Change::post( $page_fr, 'page', '/page-fr/', '/page-fr/' ),
			Change::post( $page_de, 'page', '/seite/', '/seite/' ),
		] );
	}

	/**
	 * An edit moves no translation, but Polylang may have copied any field
	 * the site synchronises — a date, a custom field, a featured image — to
	 * it: each translation is reported where it stands.
	 */
	public function test_editing_a_translated_page_reports_its_translations_where_they_stand() {
		list( $page_fr, $page_de ) = $this->translated_pages( 'page-fr', 'seite' );

		$this->reset_pending_changes();

		wp_update_post( [ 'ID' => $page_fr, 'post_content' => 'Modifié.' ] );

		$this->assertPendingChanges( [
			Change::post( $page_fr, 'page', '/page-fr/', '/page-fr/' ),
			Change::post( $page_de, 'page', '/seite/', '/seite/' ),
		] );
	}

	/**
	 * With nothing synchronised, Polylang writes no translation, and none is
	 * reported.
	 */
	public function test_editing_a_translated_page_without_synchronisation_reports_only_that_page() {
		list( $page_fr ) = $this->translated_pages( 'page-fr', 'seite' );
		$this->polylang->options['sync'] = [];

		$this->reset_pending_changes();

		wp_update_post( [ 'ID' => $page_fr, 'post_content' => 'Modifié.' ] );

		$this->assertPendingChanges( [ Change::post( $page_fr, 'page', '/page-fr/', '/page-fr/' ) ] );
	}

	// The language taxonomy
	// ====

	/**
	 * A post's language is a site setting rather than a term the front-end
	 * shows: its `terms` list its category and not its language, so a save
	 * does not expire whatever carries the language's term.
	 */
	public function test_a_translated_posts_terms_list_its_category_and_not_its_language() {
		// In the post's language, or Polylang takes it off the post on save.
		$video = self::factory()->category->create( [ 'name' => 'Video', 'slug' => 'video' ] );
		$this->polylang->model->term->set_language( $video, 'fr' );

		$post = self::factory()->post->create( [
			'post_status'   => 'publish',
			'post_name'     => 'bonjour',
			'post_title'    => 'Bonjour',
			'post_category' => [ $video ],
		] );
		$this->polylang->model->post->set_language( $post, 'fr' );
		$this->assertSame( 'fr', pll_get_post_language( $post ), 'Polylang did not set the language.' );

		$this->reset_pending_changes();

		wp_update_post( [ 'ID' => $post, 'post_content' => 'Modifié.' ] );

		$uri   = $this->path_of( get_permalink( $post ) );
		$terms = [ Change::post_term( $video, 'category', 'video' ) ];

		$this->assertPendingChanges( [ Change::post( $post, 'post', $uri, $uri, $terms, $terms ) ] );
	}

	/**
	 * Polylang sets a post's language with `wp_set_object_terms()`, which
	 * for a revalidatable taxonomy is a write of the post's terms with no
	 * save. The `language` taxonomy is not one.
	 */
	public function test_setting_a_posts_language_is_not_a_write_of_its_terms() {
		$post = self::factory()->post->create( [ 'post_status' => 'publish', 'post_name' => 'hallo' ] );
		$this->polylang->model->post->set_language( $post, 'fr' );

		$this->reset_pending_changes();

		$this->polylang->model->post->set_language( $post, 'de' );

		$this->assertSame( 'de', pll_get_post_language( $post ), 'Polylang did not change the language.' );
		$this->assertPendingChanges( [] );
	}

	/**
	 * WordPress would route the `language` taxonomy, and the integration is
	 * what declines it — for revalidate all too, while the post type's other
	 * taxonomies are still named.
	 */
	public function test_revalidate_all_of_a_post_type_names_its_taxonomies_but_not_the_language() {
		$this->assertContains( 'language', get_object_taxonomies( 'post' ), 'Polylang did not register its taxonomy for posts.' );
		$this->assertTrue( is_taxonomy_viewable( 'language' ), 'The `language` taxonomy is no longer publicly queryable.' );

		$this->assertFalse( \NextJsRevalidate::init()->revalidate->should_revalidate_taxonomy( 'language' ) );

		$taxonomies = \NextJsRevalidate::init()->revalidateAll->revalidatable_taxonomies( 'post' );

		$this->assertNotContains( 'language', $taxonomies );
		$this->assertContains( 'category', $taxonomies );
		$this->assertContains( 'post_tag', $taxonomies );
	}

	// Fixtures
	// ====

	/**
	 * A French page and its German translation.
	 *
	 * @param string $fr_slug
	 * @param string $de_slug
	 * @param int    $fr_parent Optional. Default 0.
	 * @param int    $de_parent Optional. Default 0.
	 * @return int[] The French page's ID, then the German's.
	 */
	private function translated_pages( $fr_slug, $de_slug, $fr_parent = 0, $de_parent = 0 ) {
		$fr = $this->page( $fr_slug, 'fr', $fr_parent );
		$de = $this->page( $de_slug, 'de', $de_parent );

		$this->polylang->model->post->save_translations( $fr, [ 'fr' => $fr, 'de' => $de ] );

		return [ $fr, $de ];
	}

	/**
	 * A published page in a language.
	 *
	 * @param string $slug
	 * @param string $language
	 * @param int    $parent Optional. Default 0.
	 * @return int
	 */
	private function page( $slug, $language, $parent = 0 ) {
		$page = self::factory()->post->create( [
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_name'   => $slug,
			'post_title'  => $slug,
			'post_parent' => $parent,
		] );

		$this->polylang->model->post->set_language( $page, $language );

		return $page;
	}
}
