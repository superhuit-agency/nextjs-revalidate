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
 * An edit asks both questions twice. Redirection fires one action for creating
 * and updating a redirect, from two call sites whose first argument differs —
 * the new redirect's id, or the redirect's state before the edit — and when the
 * previous state is there, the redirect as it was and the redirect as it now is
 * are each put to the same rules, independently.
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

			// The real queue holds a permalink it already has exactly once — its
			// `permalink` column is unique and its insert is guarded by a count —
			// so this one does too. That is where an edit which left a source
			// alone stops costing a second revalidation: deduplication is the
			// queue's, not this integration's, and a fixture that recorded every
			// hand-off would assert the opposite of what a site would hold.
			foreach ( $GLOBALS['njr_test_enqueued'] as $item ) {
				if ( $item[0] === $permalink ) return true;
			}

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
	 * Hand the given redirects to the same action, as Redirection's update call
	 * site does on the versions that carry the previous state: the redirect as
	 * it was, then the redirect as it now is.
	 *
	 * Both stand for one redirect, so both carry the same id — what tells the
	 * two call sites apart is the argument's type, not its value.
	 *
	 * @param array $previous What this redirect was about before the edit.
	 * @param array $now      What it is about after it.
	 *
	 * @return void
	 */
	function njr_test_redirect_edited( array $previous, array $now ) {
		$GLOBALS['njr_test_enqueued'] = [];
		$GLOBALS['njr_test_log']      = [];

		( new NextJsRevalidate\Integrations\Redirection() )->on_redirect_updated(
			new NJR_Test_Redirect( $previous ),
			new NJR_Test_Redirect( $now )
		);
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

	// Editing
	// ====
	//
	// Everything above arrives through Redirection's create call site, whose
	// first argument is the new redirect's id. Everything below arrives through
	// its update one, whose first argument — on the versions that have it — is
	// the redirect as it was before the edit. Nothing normalises the two away:
	// the previous state is the only thing that knows the path an edited source
	// stopped redirecting from.

	// A numeric first argument is not a redirect, so a create enqueues the one
	// source it has. Redirection 5.9.0 and later fire an update this way too,
	// which is the same case seen from the other end: no previous state, no old
	// path, and the new source enqueued alone.
	njr_test_redirect_created( [ 'id' => 42, 'url' => '/a-created-source' ] );
	njr_test_enqueued(
		'a first argument that is the redirect\'s id enqueues the new source alone',
		[ [ 'https://example.test/a-created-source/', 10 ] ]
	);

	// The target moved and the source did not: the path the front-end still
	// sends visitors from is the one whose answer changed.
	njr_test_redirect_edited(
		[ 'url' => '/moved-target' ],
		[ 'url' => '/moved-target' ]
	);
	njr_test_enqueued(
		'changing a redirect\'s target enqueues its source path',
		[ [ 'https://example.test/moved-target/', 10 ] ]
	);

	// The interesting case: two paths are stale at once, the one that should
	// stop redirecting and the one that should start. Old first, because that is
	// the order they are handed over in and the queue drains in.
	njr_test_redirect_edited(
		[ 'url' => '/the-old-source' ],
		[ 'url' => '/the-new-source' ]
	);
	njr_test_enqueued(
		'changing a redirect\'s source enqueues the old path and the new one',
		[
			[ 'https://example.test/the-old-source/', 10 ],
			[ 'https://example.test/the-new-source/', 10 ],
		]
	);

	// Both sides are put to the rules independently, and an unchanged source
	// answers with the same path twice. It costs one revalidation, because the
	// queue holds a permalink it already has exactly once — see the amendment to
	// `docs/adr/0006-redirect-changes-revalidate-the-source-path.md`.
	njr_test_redirect_edited(
		[ 'url' => '/an-unchanged-source' ],
		[ 'url' => '/an-unchanged-source' ]
	);
	njr_test_enqueued(
		'an edit that leaves the source alone enqueues that path exactly once',
		[ [ 'https://example.test/an-unchanged-source/', 10 ] ]
	);

	// Independently means what it says: the side that is a candidate is
	// enqueued whatever the other side is.
	njr_test_redirect_edited(
		[ 'url' => '/a-literal-source' ],
		[ 'url' => '/a-literal-source/(.*)', 'regex' => true ]
	);
	njr_test_enqueued(
		'a source edited into a regular expression enqueues the old path only',
		[ [ 'https://example.test/a-literal-source/', 10 ] ]
	);
	njr_test_logged( 'the regular expression an edit produced is logged', '/a-literal-source/(.*)' );

	njr_test_redirect_edited(
		[ 'url' => '/blog/(.*)', 'regex' => true ],
		[ 'url' => '/blog-home' ]
	);
	njr_test_enqueued(
		'a regular expression source edited into a literal path enqueues the new path only',
		[ [ 'https://example.test/blog-home/', 10 ] ]
	);

	// A disabled redirect resolves to nothing on either side of the edit, so
	// the answers the front-end holds for both paths are already right.
	njr_test_redirect_edited(
		[ 'url' => '/an-old-source', 'enabled' => false ],
		[ 'url' => '/a-new-source', 'enabled' => false ]
	);
	njr_test_enqueued( 'editing a disabled redirect enqueues nothing', [] );

	// An editor can change the source and switch the redirect on in one save.
	// The path it never redirected from needs nothing; the one it now does.
	njr_test_redirect_edited(
		[ 'url' => '/never-redirected', 'enabled' => false ],
		[ 'url' => '/now-redirecting' ]
	);
	njr_test_enqueued(
		'an edit that switches a redirect on enqueues the path it starts redirecting',
		[ [ 'https://example.test/now-redirecting/', 10 ] ]
	);

	// A source that named no path leaves nothing behind to free.
	njr_test_redirect_edited(
		[ 'url' => '/' ],
		[ 'url' => '/a-source-at-last' ]
	);
	njr_test_enqueued(
		'an edit away from a source that named no path enqueues the new path only',
		[ [ 'https://example.test/a-source-at-last/', 10 ] ]
	);

	// Both paths are normalised the way a created redirect's source is: a
	// stored domain and query string are dropped, and the site's trailing slash
	// convention is applied, on the old side exactly as on the new one.
	njr_test_redirect_edited(
		[ 'url' => 'https://an-old-domain.test/an-old-source?ref=newsletter' ],
		[ 'url' => '/a-new-source' ]
	);
	njr_test_enqueued(
		'a stored domain and query string are dropped from both sides of an edit',
		[
			[ 'https://example.test/an-old-source/', 10 ],
			[ 'https://example.test/a-new-source/', 10 ],
		]
	);

	$GLOBALS['njr_test_trailing_slash'] = false;

	njr_test_redirect_edited(
		[ 'url' => '/the-old-source/' ],
		[ 'url' => '/the-new-source/' ]
	);
	njr_test_enqueued(
		'both paths follow the site\'s trailing slash convention',
		[
			[ 'https://example.test/the-old-source', 10 ],
			[ 'https://example.test/the-new-source', 10 ],
		]
	);

	$GLOBALS['njr_test_trailing_slash'] = true;

	printf( "\n%d failure(s)\n", $failures );
	exit( $failures === 0 ? 0 : 1 );
}
