<?php
/**
 * A refusal and a success are different answers — RevalidateAll::revalidate_all().
 *
 * `revalidate_all()` reports one `all` change and answers `true`, except on an
 * **unconfigured site**, where it refuses and answers `false`. Its caller reads
 * that with `false === $reported` and sends the operator to a refusal notice
 * instead of a success notice, so the two answers have to stay distinguishable
 * by identity.
 *
 * Until v2 the success answer was a count, and `0` — a site with nothing
 * revalidatable — was the falsy answer that was not a refusal (#82). A change
 * has no count, but it keeps a falsy answer that is not a refusal: the
 * `nextjs_revalidate_change` filter dropping the change, which is the site's
 * own decision. This holds the distinction by a test rather than by a comment,
 * so the next person to "simplify" the branch away has to delete an
 * expectation to do it.
 *
 * Reachable by stubbing a handful of WordPress functions, so it is a standalone
 * script — ADR 0008's rule. What the change carries is
 * `tests/revalidate-all-change-test.php`'s, through the real pending changes.
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
	 * The changes the pending changes were handed, in order.
	 * @var array
	 */
	$GLOBALS['njr_test_reported'] = [];

	/**
	 * What the pending changes answer a report with: `true` for a change held,
	 * `false` for one the `nextjs_revalidate_change` filter dropped.
	 * @var mixed
	 */
	$GLOBALS['njr_test_report_answer'] = true;

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
	 * Every post-shaped question the site was asked. Revalidate all asks none.
	 * @var array
	 */
	$GLOBALS['njr_test_post_queries'] = [];

	// WordPress stubs
	// ====

	class WP_Error {}

	function add_action( $name, $callback, $priority = 10, $accepted_args = 1 ) {}
	function add_filter( $name, $callback, $priority = 10, $accepted_args = 1 ) {}

	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}

	function get_posts( $args = [] ) {
		$GLOBALS['njr_test_post_queries'][] = $args;
		return [];
	}

	function get_taxonomies( $args = [] ) {
		return [ 'category' => 'category' ];
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
	 * `Revalidate`, reduced to the one question revalidate all puts to it:
	 * whether a taxonomy's archives are candidates. That has a test of its
	 * own — `tests/revalidatable-taxonomy-test.php` — and nothing here has an
	 * opinion about it.
	 */
	class NJR_Test_Revalidate {
		public function should_revalidate_taxonomy( $taxonomy ) {
			return true;
		}
	}

	/**
	 * The pending changes, reduced to what they were handed.
	 */
	class NJR_Test_PendingChanges {
		public function report( array $change ) {
			$GLOBALS['njr_test_reported'][] = $change;

			return $GLOBALS['njr_test_report_answer'];
		}
	}

	// The plugin singleton, reduced to the three collaborators `Abstracts\Base`
	// forwards to it that revalidate all reaches.
	class NextJsRevalidate {
		public $settings;
		public $revalidate;
		public $pendingChanges;

		private static $instance;

		public static function init() {
			if ( ! isset( self::$instance ) ) self::$instance = new static();
			return self::$instance;
		}

		private function __construct() {
			$this->settings       = new NJR_Test_Settings();
			$this->revalidate     = new NJR_Test_Revalidate();
			$this->pendingChanges = new NJR_Test_PendingChanges();
		}
	}

	// The subject
	// ====

	require_once __DIR__ . '/../include/Interfaces/Hookable.php';
	require_once __DIR__ . '/../include/Abstracts/Base.php';
	require_once __DIR__ . '/../include/Traits/AdminBarMenu.php';
	require_once __DIR__ . '/../include/Traits/SendbackUrl.php';
	require_once __DIR__ . '/../include/Change.php';
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
	 * Run revalidate all against a fixture site.
	 *
	 * @param bool   $configured Whether the site holds the two settings.
	 * @param string $type       The post type, or 'all'.
	 * @param mixed  $answer     What the pending changes answer the report with.
	 * @return mixed What `revalidate_all()` answered.
	 */
	function njr_test_revalidate_all( $configured, $type = 'all', $answer = true ) {
		$GLOBALS['njr_test_configured']    = $configured;
		$GLOBALS['njr_test_report_answer'] = $answer;
		$GLOBALS['njr_test_reported']      = [];
		$GLOBALS['njr_test_log']           = [];

		return ( new NextJsRevalidate\RevalidateAll() )->revalidate_all( $type );
	}

	// An unconfigured site refuses: nothing is reported, because nothing it
	// reported could be delivered.
	foreach ( [ 'all', 'post' ] as $type ) {
		$answer = njr_test_revalidate_all( false, $type );

		njr_test_expect(
			"an unconfigured site answers false to revalidate all ($type)",
			false,
			$answer
		);
		njr_test_expect(
			"a refusal reports nothing ($type)",
			[],
			$GLOBALS['njr_test_reported']
		);
		njr_test_expect(
			"the refusal says which settings are missing ($type)",
			1,
			count( preg_grep( '/^⛔ Refused revalidate all \(' . $type . '\) — site not configured \(missing: domain, secret\)$/u', $GLOBALS['njr_test_log'] ) )
		);
	}

	// A configured site reports one change and answers true.
	$answer = njr_test_revalidate_all( true );

	njr_test_expect(
		'a configured site answers true',
		true,
		$answer
	);
	njr_test_expect(
		'true is not a refusal — the caller sends the operator to the success notice',
		false,
		false === $answer
	);
	njr_test_expect(
		'having reported exactly one change',
		1,
		count( $GLOBALS['njr_test_reported'] )
	);
	njr_test_expect(
		'and nothing was logged, because nothing was refused',
		[],
		$GLOBALS['njr_test_log']
	);

	// A change the filter drops is the site's decision, and not a refusal: the
	// operator is not told the site is unconfigured when it is not.
	$answer = njr_test_revalidate_all( true, 'post', false );

	njr_test_expect(
		'a change the nextjs_revalidate_change filter dropped is not a refusal',
		true,
		$answer
	);

	// However many posts the site holds, none is asked about.
	njr_test_expect(
		'revalidate all queries no post, of the whole site or of one type',
		[],
		$GLOBALS['njr_test_post_queries']
	);

	printf( "\n%d failure(s)\n", $failures );
	exit( $failures === 0 ? 0 : 1 );
}
