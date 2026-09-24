<?php
/**
 * Revalidate all of one post type names its revalidatable taxonomies — issue
 * #54, as v2 reports it (ADR 0033).
 *
 * The seam is the `all` change revalidate all reports: each test registers a
 * taxonomy whose `public` and `publicly_queryable` say different things, runs
 * revalidate all over `post`, and asserts whether the change's `taxonomies`
 * names it. The gate and the selector are both pinned by
 * `tests/revalidatable-taxonomy-test.php`, and the change's shape by
 * `tests/revalidate-all-change-test.php`, neither of which needs WordPress;
 * what only this suite can see is that a real `register_taxonomy()` and a real
 * `is_taxonomy_viewable()` reach the change.
 *
 * The change is read from the `nextjs_revalidate_change` filter and dropped
 * there, so nothing is left pending for the test process's `shutdown` to send.
 * Assertions are on the presence of one taxonomy rather than on the whole list:
 * which other taxonomies `post` has is the test site's business rather than
 * this file's.
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

	/**
	 * Every change revalidate all reported during the test.
	 *
	 * @var array[]
	 */
	private $reported = [];

	public function set_up() {
		parent::set_up();

		$this->configure_site();
	}

	public function tear_down() {
		foreach ( $this->registered as $taxonomy ) unregister_taxonomy( $taxonomy );
		$this->registered = [];
		$this->reported   = [];

		parent::tear_down();
	}

	// The two directions #54 is about
	// ====

	/**
	 * A taxonomy WordPress will route a query for has a front-end archive, and
	 * `public => false` does not change that. This direction used to be missed
	 * entirely: nothing revalidated, nothing logged, the archive never updating.
	 */
	public function test_a_queryable_taxonomy_that_is_not_public_is_named() {
		$this->register( 'njr_queryable_not_public', [ 'public' => false, 'publicly_queryable' => true ] );

		$this->assertContains( 'njr_queryable_not_public', $this->revalidate_all_posts() );
	}

	/**
	 * And a taxonomy it will not route has no archive to rebuild, whatever its
	 * `public` says. This direction used to revalidate every one of its terms.
	 */
	public function test_a_public_taxonomy_that_is_not_queryable_is_not_named() {
		$this->register( 'njr_public_not_queryable', [ 'public' => true, 'publicly_queryable' => false ] );

		$this->assertNotContains( 'njr_public_not_queryable', $this->revalidate_all_posts() );
	}

	/**
	 * The ordinary case, so that the two above are read as the exceptions they
	 * are rather than as the whole rule.
	 */
	public function test_an_ordinary_taxonomy_is_named() {
		$this->register( 'njr_ordinary', [ 'public' => true ] );

		$this->assertContains( 'njr_ordinary', $this->revalidate_all_posts() );
	}

	// The site has the last word
	// ====

	/**
	 * The gate is asked about every taxonomy registered for the type, so the
	 * filter can keep a whole taxonomy's archives out of a revalidate all.
	 */
	public function test_the_filter_declines_a_viewable_taxonomy() {
		$this->register( 'njr_filtered_out', [ 'public' => true ] );

		$taxonomies = $this->with_verdict( 'njr_filtered_out', false, function() {
			return $this->revalidate_all_posts();
		});

		$this->assertNotContains( 'njr_filtered_out', $taxonomies );
	}

	/**
	 * And can admit one WordPress would never route — the headless case the
	 * hatch exists for, and the one a `public`-shaped pre-selector would defeat
	 * silently. See ADR 0022.
	 */
	public function test_the_filter_admits_a_taxonomy_that_is_not_viewable() {
		$this->register( 'njr_filtered_in', [ 'public' => false, 'publicly_queryable' => false ] );

		$taxonomies = $this->with_verdict( 'njr_filtered_in', true, function() {
			return $this->revalidate_all_posts();
		});

		$this->assertContains( 'njr_filtered_in', $taxonomies );
	}

	// Fixtures
	// ====

	/**
	 * Register a taxonomy on `post`.
	 *
	 * @param string $taxonomy The taxonomy name.
	 * @param array  $args     The registration arguments to override.
	 *
	 * @return void
	 */
	private function register( $taxonomy, array $args ) {
		register_taxonomy( $taxonomy, 'post', array_merge( [ 'hierarchical' => true ], $args ) );
		$this->registered[] = $taxonomy;
	}

	/**
	 * Run $during with the gate forced to $verdict for one taxonomy, and the
	 * filter removed again afterwards.
	 *
	 * @param string   $taxonomy The taxonomy to force a verdict for.
	 * @param bool     $verdict  The verdict to force.
	 * @param callable $during   What to run while it is forced.
	 *
	 * @return mixed What $during answered.
	 */
	private function with_verdict( $taxonomy, $verdict, callable $during ) {
		$forced = function( $should_revalidate, $taxonomy_name ) use ( $taxonomy, $verdict ) {
			return ( $taxonomy_name === $taxonomy ? $verdict : $should_revalidate );
		};

		add_filter( 'nextjs_revalidate_should_revalidate_taxonomy', $forced, 10, 2 );
		$answer = $during();
		remove_filter( 'nextjs_revalidate_should_revalidate_taxonomy', $forced, 10 );

		return $answer;
	}

	/**
	 * Run revalidate all over the `post` type, which is what the taxonomies
	 * above are registered for, and answer the taxonomies its change named.
	 *
	 * Through the plugin's own singleton: revalidate all reads the settings and
	 * reports into the pending changes of the site currently being served, and
	 * a second instance would be a second answer to both. The change is read
	 * from the filter and dropped there, so nothing is left for `shutdown`.
	 *
	 * @return string[]
	 */
	private function revalidate_all_posts() {
		$capture = function( $change ) {
			$this->reported[] = $change;
			return false;
		};

		add_filter( 'nextjs_revalidate_change', $capture );
		$answer = \NextJsRevalidate::init()->revalidateAll->revalidate_all( 'post' );
		remove_filter( 'nextjs_revalidate_change', $capture );

		$this->assertTrue( $answer, 'a configured site does not refuse revalidate all' );
		$this->assertCount( 1, $this->reported, 'revalidate all reports exactly one change' );
		$this->assertSame( 'all',  $this->reported[0]['subject'] );
		$this->assertSame( 'post', $this->reported[0]['type'] );

		return $this->reported[0]['taxonomies'];
	}
}
