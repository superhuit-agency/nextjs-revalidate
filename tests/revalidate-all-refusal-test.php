<?php
/**
 * A refusal and an empty site are different answers — RevalidateAll::revalidate_all().
 *
 * `revalidate_all()` answers with a count, except on an **unconfigured site**,
 * where it refuses and answers `false`. Its caller reads that with
 * `false === $nb_added` and sends the operator to a refusal notice instead of a
 * count, so the two answers have to stay distinguishable by identity: `0` is a
 * site that had nothing revalidatable to enqueue, and it is not a refusal.
 *
 * The method was annotated `@return int` while returning `false`, which made
 * PHPStan call that refusal branch dead code — a live branch reported as
 * unreachable, because the docblock lied rather than because the code was
 * wrong (#82). The annotation is now `int|false`; this is the same claim, held
 * by a test rather than by a comment, so the next person to "simplify" the
 * branch away has to delete an expectation to do it.
 *
 * Reachable by stubbing a handful of WordPress functions, so it is a standalone
 * script — ADR 0008's rule. What the queue then does with the permalinks is
 * `tests/integration/RevalidateAllTermsTest.php`'s, against a real one.
 *
 * Run with `npm run test:php`, or `php tests/revalidate-all-refusal-test.php`.
 */

namespace NextJsRevalidate {

	/**
	 * The plugin's logger, reduced to the lines it was asked to write.
	 *
	 * The real one reads a setting and appends to a file in the uploads
	 * directory, neither of which exists here. A refusal leaves no other trace
	 * in this method, so the line is what the refusal is asserted on.
	 */
	class Logger {
		const INFO  = 0;
		const DEBUG = 1;
		const ERROR = 2;

		public static function log( $text, $currentFile, $level = self::INFO ) {
			$GLOBALS['njr_test_log'][] = $text;
		}
	}
}

namespace {

	// The plugin files bail when this is not defined.
	define( 'ABSPATH', __DIR__ . '/' );

	/**
	 * The permalinks the queue was handed, in order.
	 * @var array
	 */
	$GLOBALS['njr_test_enqueued'] = [];

	/**
	 * Everything the plugin logged during the last call.
	 * @var array
	 */
	$GLOBALS['njr_test_log'] = [];

	/**
	 * Whether the fixture site holds both settings a revalidation cannot be
	 * delivered without. Half-configured is unconfigured.
	 * @var bool
	 */
	$GLOBALS['njr_test_configured'] = true;

	/**
	 * The fixture site's posts, post type => post ids.
	 * @var array
	 */
	$GLOBALS['njr_test_posts'] = [];

	/**
	 * The fixture site's taxonomies, name => term ids.
	 * @var array
	 */
	$GLOBALS['njr_test_terms'] = [];

	// WordPress stubs
	// ====

	function add_action( $name, $callback, $priority = 10, $accepted_args = 1 ) {}
	function add_filter( $name, $callback, $priority = 10, $accepted_args = 1 ) {}

	function get_post_types( $args = [] ) {
		$types = array_keys( $GLOBALS['njr_test_posts'] );

		return array_combine( $types, $types );
	}

	function get_posts( $args = [] ) {
		return $GLOBALS['njr_test_posts'][ $args['post_type'] ] ?? [];
	}

	function get_taxonomies( $args = [] ) {
		$taxonomies = array_keys( $GLOBALS['njr_test_terms'] );

		return array_combine( $taxonomies, $taxonomies );
	}

	function get_terms( $args = [] ) {
		return $GLOBALS['njr_test_terms'][ $args['taxonomy'] ] ?? [];
	}

	function get_term_link( $term_id ) {
		return "https://example.test/term/$term_id/";
	}

	/**
	 * The site's settings, reduced to the one question the refusal asks.
	 */
	class NJR_Test_Settings {
		public function is_configured() {
			return $GLOBALS['njr_test_configured'];
		}

		public function missing_settings() {
			return $GLOBALS['njr_test_configured'] ? [] : [ 'domain', 'secret' ];
		}
	}

	/**
	 * `Revalidate`, reduced to the two questions revalidate-all puts to it: the
	 * permalink of a post, and whether a taxonomy's terms are candidates. Both
	 * gates have tests of their own — `tests/revalidatable-post-test.php` and
	 * `tests/revalidatable-taxonomy-test.php` — and nothing here has an opinion
	 * about either.
	 */
	class NJR_Test_Revalidate {
		public function get_post_permalink( $post_id ) {
			return "https://example.test/post/$post_id/";
		}

		public function should_revalidate_taxonomy( $taxonomy ) {
			return true;
		}
	}

	/**
	 * The revalidation queue, reduced to what it was handed.
	 */
	class NJR_Test_Queue {
		public function add_item( $permalink, $priority = 10 ) {
			$GLOBALS['njr_test_enqueued'][] = $permalink;

			return 1;
		}
	}

	// The plugin singleton, reduced to the three collaborators `Abstracts\Base`
	// forwards to it.
	class NextJsRevalidate {
		public $settings;
		public $revalidate;
		public $queue;

		private static $instance;

		public static function init() {
			if ( ! isset( self::$instance ) ) self::$instance = new static();
			return self::$instance;
		}

		private function __construct() {
			$this->settings   = new NJR_Test_Settings();
			$this->revalidate = new NJR_Test_Revalidate();
			$this->queue      = new NJR_Test_Queue();
		}
	}

	// The subject
	// ====

	require_once __DIR__ . '/../include/Interfaces/Hookable.php';
	require_once __DIR__ . '/../include/Abstracts/Base.php';
	require_once __DIR__ . '/../include/Traits/AdminBarMenu.php';
	require_once __DIR__ . '/../include/Traits/SendbackUrl.php';
	require_once __DIR__ . '/../include/RevalidateAll.php';

	// The expectations
	// ====

	$failures = 0;

	/**
	 * @param string $description
	 * @param mixed  $expected
	 * @param mixed  $actual
	 */
	function njr_test_expect( $description, $expected, $actual ) {
		global $failures;

		if ( $actual === $expected ) {
			printf( "ok   — %s\n", $description );
			return;
		}

		$failures++;
		printf(
			"FAIL — %s\n       expected %s, got %s\n",
			$description,
			var_export( $expected, true ),
			var_export( $actual, true )
		);
	}

	/**
	 * Run revalidate-all against a fixture site.
	 *
	 * @param bool  $configured Whether the site holds the two settings.
	 * @param array $posts      post type => post ids.
	 * @param array $terms      taxonomy => term ids.
	 * @return mixed What `revalidate_all()` answered.
	 */
	function njr_test_revalidate_all( $configured, array $posts = [], array $terms = [] ) {
		$GLOBALS['njr_test_configured'] = $configured;
		$GLOBALS['njr_test_posts']      = $posts;
		$GLOBALS['njr_test_terms']      = $terms;
		$GLOBALS['njr_test_enqueued']   = [];
		$GLOBALS['njr_test_log']        = [];

		return ( new NextJsRevalidate\RevalidateAll() )->revalidate_all();
	}

	// An unconfigured site refuses: nothing is enqueued, because nothing it
	// accepted could be delivered.
	$answer = njr_test_revalidate_all( false, [ 'post' => [ 1, 2 ] ], [ 'category' => [ 7 ] ] );

	njr_test_expect(
		'an unconfigured site answers false, not a count',
		false,
		$answer
	);
	njr_test_expect(
		'the refusal branch the caller reads with `false === $nb_added` is live',
		true,
		false === $answer
	);
	njr_test_expect(
		'a refusal enqueues nothing, however much the site holds',
		[],
		$GLOBALS['njr_test_enqueued']
	);
	njr_test_expect(
		'the refusal says which settings are missing',
		1,
		count( preg_grep( '/not configured \(missing: domain, secret\)/', $GLOBALS['njr_test_log'] ) )
	);

	// A configured site with nothing to revalidate answers zero. Falsy, and not
	// a refusal — the distinction the caller's `===` exists to make.
	$answer = njr_test_revalidate_all( true );

	njr_test_expect(
		'a configured site with nothing revalidatable answers 0',
		0,
		$answer
	);
	njr_test_expect(
		'0 is not a refusal',
		false,
		false === $answer
	);
	njr_test_expect(
		'and nothing was logged, because nothing was refused',
		[],
		$GLOBALS['njr_test_log']
	);

	// A configured site answers with the number of nodes it enqueued — posts of
	// every type, then the terms of every revalidatable taxonomy.
	$answer = njr_test_revalidate_all(
		true,
		[ 'post' => [ 1, 2 ], 'page' => [ 3 ] ],
		[ 'category' => [ 7, 8 ] ]
	);

	njr_test_expect(
		'a configured site answers with the number of nodes it enqueued',
		5,
		$answer
	);
	njr_test_expect(
		'which is the count of what actually reached the queue',
		5,
		count( $GLOBALS['njr_test_enqueued'] )
	);

	printf( "\n%d failure(s)\n", $failures );
	exit( $failures === 0 ? 0 : 1 );
}
