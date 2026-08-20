<?php
/**
 * Revalidatable redirect and its source path — Integrations\Redirection.
 *
 * The decision a redirect change makes before it ever reaches the queue: is
 * this redirect a candidate at all, and which path does its source name? Both
 * are reachable by stubbing a handful of WordPress functions, so both are a
 * standalone script — ADR 0008's rule, and the reason it exists: this is a
 * suite the sandbox of ADR 0006 can run, where the wp-env one cannot.
 *
 * What the redirect *is* stays duck-typed here, exactly as the integration
 * reads it: a fixture object carrying the four methods it asks of a redirect
 * stands in for Redirection's `Red_Item`. That those four methods are the ones
 * upstream actually has, and that the queue then holds what this asserts it was
 * handed, is `tests/integration/RedirectRevalidationTest.php`'s to prove —
 * against the real plugin, on a real queue. Nothing here has an opinion about
 * either.
 *
 * Run with `npm run test:php`, or `php tests/revalidatable-redirect-test.php`.
 */

namespace NextJsRevalidate {

	/**
	 * The plugin's logger, reduced to the lines it was asked to write.
	 *
	 * The real one reads a setting and appends to a file in the uploads
	 * directory, neither of which exists here. A skipped redirect leaves no
	 * other trace, so the lines are what a skip is asserted on.
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
	 * The permalinks the queue was handed, in order. [permalink, priority][]
	 * @var array
	 */
	$GLOBALS['njr_test_enqueued'] = [];

	/**
	 * Everything the plugin logged while handling the last redirect.
	 * @var array
	 */
	$GLOBALS['njr_test_log'] = [];

	/**
	 * Whether the fixture site's permalinks carry a trailing slash.
	 * @var bool
	 */
	$GLOBALS['njr_test_trailing_slash'] = true;

	/**
	 * The directory the fixture site is served from, '' for a site at the root
	 * of its domain.
	 * @var string
	 */
	$GLOBALS['njr_test_home_path'] = '';

	/**
	 * The fixture site's home url, without a trailing slash.
	 */
	define( 'NJR_TEST_HOME', 'https://example.test' );

	// WordPress stubs
	// ====

	function add_action( $name, $callback, $priority = 10, $accepted_args = 1 ) {}

	function did_action( $name ) {
		return 1;
	}

	function apply_filters( $name, $value, ...$args ) {
		return $value;
	}

	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}

	function untrailingslashit( $string ) {
		return rtrim( $string, '/\\' );
	}

	function trailingslashit( $string ) {
		return untrailingslashit( $string ) . '/';
	}

	function user_trailingslashit( $string, $type_of_url = '' ) {
		return $GLOBALS['njr_test_trailing_slash']
			? trailingslashit( $string )
			: untrailingslashit( $string );
	}

	function home_url( $path = '' ) {
		$home = untrailingslashit( NJR_TEST_HOME . $GLOBALS['njr_test_home_path'] );

		return $path ? $home . '/' . ltrim( $path, '/' ) : $home;
	}

	function is_wp_error( $thing ) {
		return false;
	}

	/**
	 * The revalidation queue, reduced to what it was handed.
	 *
	 * Reached through `Base::__get()`, which asks the composition root for it —
	 * so the fixture below is the plugin's singleton as far as the integration
	 * can tell.
	 */
	class NJR_Test_Queue {
		public function add_item( $permalink, $priority = 10 ) {
			$GLOBALS['njr_test_enqueued'][] = [ $permalink, $priority ];
			return true;
		}
	}

	class NextJsRevalidate {
		public $queue;

		private static $instance;

		public static function init() {
			if ( ! isset( self::$instance ) ) {
				self::$instance        = new self();
				self::$instance->queue = new NJR_Test_Queue();
			}

			return self::$instance;
		}
	}

	/**
	 * A redirect, as the integration reads one: an object answering the four
	 * methods it asks of it, and nothing more.
	 *
	 * `get_match_type()` is there to be ignored. A redirect matched on a cookie,
	 * a referrer or a user agent still has one source path, and the predicate
	 * never asks — which is a claim worth a fixture that could answer.
	 */
	class NJR_Test_Redirect {
		private $values;

		public function __construct( array $values = [] ) {
			$this->values = array_merge(
				[
					'id'         => 1,
					'url'        => '/a-source-path',
					'regex'      => false,
					'enabled'    => true,
					'match_type' => 'url',
				],
				$values
			);
		}

		public function get_id() {
			return $this->values['id'];
		}

		public function get_url() {
			return $this->values['url'];
		}

		public function is_regex() {
			return $this->values['regex'];
		}

		public function is_enabled() {
			return $this->values['enabled'];
		}

		public function get_match_type() {
			return $this->values['match_type'];
		}
	}

	// The subject
	// ====

	require_once __DIR__ . '/../include/Interfaces/Hookable.php';
	require_once __DIR__ . '/../include/Abstracts/Base.php';
	require_once __DIR__ . '/../include/Integrations/Redirection.php';

	// The expectations
	// ====

	$failures = 0;

	/**
	 * Hand the given redirect to the action Redirection fires when one is
	 * created, as its create call site does: the redirect's id, then the
	 * redirect.
	 *
	 * A Hookable is safe to construct for a single method call, and nothing
	 * here registers a hook.
	 *
	 * @param array $values What this redirect is about.
	 * @return void
	 */
	function njr_test_redirect_created( array $values = [] ) {
		$GLOBALS['njr_test_enqueued'] = [];
		$GLOBALS['njr_test_log']      = [];

		$redirect = new NJR_Test_Redirect( $values );

		( new NextJsRevalidate\Integrations\Redirection() )
			->on_redirect_updated( $redirect->get_id(), $redirect );
	}

	/**
	 * @param string $description What is being asserted.
	 * @param array  $expected    The expected [permalink, priority] pairs.
	 * @return void
	 */
	function njr_test_enqueued( $description, array $expected ) {
		global $failures;

		$actual = $GLOBALS['njr_test_enqueued'];

		if ( $actual === $expected ) {
			printf( "ok   — %s\n", $description );
			return;
		}

		$failures++;
		printf(
			"FAIL — %s (expected %s, got %s)\n",
			$description,
			json_encode( $expected ),
			json_encode( $actual )
		);
	}

	/**
	 * @param string $description What is being asserted.
	 * @param string $needle      What the log is expected to name.
	 * @return void
	 */
	function njr_test_logged( $description, $needle ) {
		global $failures;

		$log = implode( "\n", $GLOBALS['njr_test_log'] );

		if ( false !== strpos( $log, $needle ) ) {
			printf( "ok   — %s\n", $description );
			return;
		}

		$failures++;
		printf( "FAIL — %s (expected a line naming %s, got %s)\n", $description, $needle, $log ?: 'nothing' );
	}

	// The everyday case, and the priority it is enqueued at: a redirect
	// revalidation holds no special place in the queue.
	njr_test_redirect_created( [ 'url' => '/about-us' ] );
	njr_test_enqueued(
		'an enabled redirect with a literal source enqueues that path, at the default priority',
		[ [ 'https://example.test/about-us/', 10 ] ]
	);

	// A source names one path however it was stored — by an import, by an older
	// version of Redirection, or by hand.
	njr_test_redirect_created( [ 'url' => '/a-page?ref=newsletter' ] );
	njr_test_enqueued(
		'a source stored with a query string enqueues only its path',
		[ [ 'https://example.test/a-page/', 10 ] ]
	);

	njr_test_redirect_created( [ 'url' => 'https://an-old-domain.test/a-page/' ] );
	njr_test_enqueued(
		'a source stored with a domain enqueues only its path',
		[ [ 'https://example.test/a-page/', 10 ] ]
	);

	// The site's own convention, whichever way it goes: the front-end keys on
	// the exact path string, so a source has to arrive in the form the rest of
	// the queue already holds.
	$GLOBALS['njr_test_trailing_slash'] = false;

	njr_test_redirect_created( [ 'url' => '/about-us/' ] );
	njr_test_enqueued(
		'a site whose permalinks carry no trailing slash enqueues the path without one',
		[ [ 'https://example.test/about-us', 10 ] ]
	);

	$GLOBALS['njr_test_trailing_slash'] = true;

	// A source that names no path of this site to rebuild.
	foreach ( [ '/' => 'the bare site root', '' => 'an empty source', '   ' => 'a source of whitespace', '?ref=newsletter' => 'a query string alone' ] as $url => $description ) {
		njr_test_redirect_created( [ 'url' => $url ] );
		njr_test_enqueued( "$description enqueues nothing", [] );
	}

	// A regular expression source matches an unbounded set of paths, so there is
	// no single path to rebuild — and never a revalidate all.
	njr_test_redirect_created( [ 'url' => '/blog/(.*)', 'regex' => true ] );
	njr_test_enqueued( 'a regular expression source enqueues nothing', [] );
	njr_test_logged( 'a regular expression source is logged', '/blog/(.*)' );

	// Redirection's post slug monitor creates its trashed-post redirects
	// disabled, which makes this an everyday case rather than a hypothetical.
	njr_test_redirect_created( [ 'url' => '/a-trashed-page', 'enabled' => false ] );
	njr_test_enqueued( 'a redirect created disabled enqueues nothing', [] );

	// A site served from a subdirectory. A source names a path from the domain
	// root — Redirection matches against the request uri, and its monitor stores
	// the path component of a permalink — so the directory the site is served
	// from is already in the source, and the permalink must not name it twice.
	$GLOBALS['njr_test_home_path'] = '/blog';

	njr_test_redirect_created( [ 'url' => '/blog/about-us' ] );
	njr_test_enqueued(
		'a site served from a subdirectory names that directory once',
		[ [ 'https://example.test/blog/about-us/', 10 ] ]
	);

	$GLOBALS['njr_test_home_path'] = '';

	// Match type is irrelevant: a redirect matched on a cookie, a referrer or a
	// user agent still has a single source path.
	njr_test_redirect_created( [ 'url' => '/seen-by-one-browser', 'match_type' => 'agent' ] );
	njr_test_enqueued(
		'a non url match type with a literal source enqueues that path',
		[ [ 'https://example.test/seen-by-one-browser/', 10 ] ]
	);

	printf( "\n%d failure(s)\n", $failures );
	exit( $failures === 0 ? 0 : 1 );
}
