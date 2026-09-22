<?php
/**
 * Revalidate all enqueues the archives of a revalidatable taxonomy — issue #54.
 *
 * The seam is the revalidation queue's contents: each test registers a taxonomy
 * whose `public` and `publicly_queryable` say different things, gives it a term,
 * runs revalidate all, and asserts whether that term's archive is in the queue.
 * The gate and the selector are both pinned by
 * `tests/revalidatable-taxonomy-test.php`, which needs no WordPress; what only
 * this suite can see is that a real `get_term_link()` reaches the queue, and
 * that a term of a taxonomy core would not route reaches nothing.
 *
 * Assertions are on the presence of one permalink rather than on the whole
 * queue: revalidate all also enqueues every published post of the type, and
 * what those are is the test site's business rather than this file's.
 *
 * @package NextJsRevalidate
 */

namespace NextJsRevalidate\Tests;

class RevalidateAllTermsTest extends QueueTestCase {

	/**
	 * The taxonomies this test registered, to be unregistered after it.
	 *
	 * @var string[]
	 */
	private $registered = [];

	public function set_up() {
		parent::set_up();

		// A term link is composed from the permastruct when there is one, and
		// from a query var when there is not. Which one this site has is the
		// test's decision rather than whatever the environment was left holding.
		$this->set_permalink_structure( '/%postname%/' );

		$this->configure_site();
	}

	public function tear_down() {
		foreach ( $this->registered as $taxonomy ) unregister_taxonomy( $taxonomy );
		$this->registered = [];

		parent::tear_down();
	}

	// The two directions #54 is about
	// ====

	/**
	 * A taxonomy WordPress will route a query for has a front-end archive, and
	 * `public => false` does not change that. This direction used to be missed
	 * entirely: nothing enqueued, nothing logged, the archive never updating.
	 */
	public function test_a_term_of_a_queryable_taxonomy_that_is_not_public_is_revalidated() {
		$term_id = $this->term_of( 'njr_queryable_not_public', [ 'public' => false, 'publicly_queryable' => true ] );

		$this->revalidate_all_posts();

		$this->assertContains( get_term_link( $term_id ), $this->queue_permalinks() );
	}

	/**
	 * And a taxonomy it will not route has no archive to rebuild, whatever its
	 * `public` says. This direction used to enqueue every one of its terms.
	 */
	public function test_a_term_of_a_public_taxonomy_that_is_not_queryable_is_not_revalidated() {
		$term_id = $this->term_of( 'njr_public_not_queryable', [ 'public' => true, 'publicly_queryable' => false ] );

		$this->revalidate_all_posts();

		$this->assertNotContains( get_term_link( $term_id ), $this->queue_permalinks() );
	}

	/**
	 * The ordinary case, so that the two above are read as the exceptions they
	 * are rather than as the whole rule.
	 */
	public function test_a_term_of_an_ordinary_taxonomy_is_revalidated() {
		$term_id = $this->term_of( 'njr_ordinary', [ 'public' => true ] );

		$this->revalidate_all_posts();

		$this->assertContains( get_term_link( $term_id ), $this->queue_permalinks() );
	}

	// The site has the last word
	// ====

	/**
	 * The gate is asked about every registered taxonomy, so the filter can keep
	 * a whole taxonomy's archives out of a purge all.
	 */
	public function test_the_filter_declines_a_viewable_taxonomy() {
		$term_id = $this->term_of( 'njr_filtered_out', [ 'public' => true ] );

		$this->with_verdict( 'njr_filtered_out', false, function() {
			$this->revalidate_all_posts();
		});

		$this->assertNotContains( get_term_link( $term_id ), $this->queue_permalinks() );
	}

	/**
	 * And can admit one WordPress would never route — the headless case the
	 * hatch exists for, and the one a `public`-shaped pre-selector would defeat
	 * silently. See ADR 0022.
	 */
	public function test_the_filter_admits_a_taxonomy_that_is_not_viewable() {
		$term_id = $this->term_of( 'njr_filtered_in', [ 'public' => false, 'publicly_queryable' => false ] );

		$this->with_verdict( 'njr_filtered_in', true, function() {
			$this->revalidate_all_posts();
		});

		$this->assertContains( get_term_link( $term_id ), $this->queue_permalinks() );
	}

	// Fixtures
	// ====

	/**
	 * Register a taxonomy on `post` and give it one term.
	 *
	 * @param string $taxonomy The taxonomy name.
	 * @param array  $args     The registration arguments to override.
	 *
	 * @return int The term id.
	 */
	private function term_of( $taxonomy, array $args ) {
		register_taxonomy( $taxonomy, 'post', array_merge( [ 'hierarchical' => true ], $args ) );
		$this->registered[] = $taxonomy;

		return $this->factory()->term->create( [ 'taxonomy' => $taxonomy ] );
	}

	/**
	 * Run $during with the gate forced to $verdict for one taxonomy, and the
	 * filter removed again afterwards.
	 *
	 * @param string   $taxonomy The taxonomy to force a verdict for.
	 * @param bool     $verdict  The verdict to force.
	 * @param callable $during   What to run while it is forced.
	 *
	 * @return void
	 */
	private function with_verdict( $taxonomy, $verdict, callable $during ) {
		$forced = function( $should_revalidate, $taxonomy_name ) use ( $taxonomy, $verdict ) {
			return ( $taxonomy_name === $taxonomy ? $verdict : $should_revalidate );
		};

		add_filter( 'nextjs_revalidate_should_revalidate_taxonomy', $forced, 10, 2 );
		$during();
		remove_filter( 'nextjs_revalidate_should_revalidate_taxonomy', $forced, 10 );
	}

	/**
	 * Run revalidate all over the `post` type, which is what the taxonomies
	 * above are registered for.
	 *
	 * Through the plugin's own singleton: revalidate all reads the settings and
	 * writes the queue of the site currently being served, and a second instance
	 * would be a second answer to both.
	 *
	 * @return void
	 */
	private function revalidate_all_posts() {
		\NextJsRevalidate::init()->revalidateAll->revalidate_all( 'post' );
	}
}
