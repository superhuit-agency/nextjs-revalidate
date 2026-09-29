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
 * is reported, with its descendants.
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
		$options['sync'] = [ 'post_parent' ];

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
	 * An edit moves no translation, and none is reported.
	 */
	public function test_editing_a_translated_page_reports_only_that_page() {
		list( $page_fr ) = $this->translated_pages( 'page-fr', 'seite' );

		$this->reset_pending_changes();

		wp_update_post( [ 'ID' => $page_fr, 'post_content' => 'Modifié.' ] );

		$this->assertPendingChanges( [ Change::post( $page_fr, 'page', '/page-fr/', '/page-fr/' ) ] );
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
