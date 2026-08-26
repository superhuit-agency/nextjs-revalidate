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
 * either. `Red_Item` is declared all the same, because the enable and disable
 * events reach it by name to read a redirect back from its id: what stands in
 * for it is a lookup table over those same fixtures, not a redirect.
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
	 * Registered filter callbacks. filter name => callable[]
	 * @var array
	 */
	$GLOBALS['njr_test_filters'] = [];

	/**
	 * The source paths the redirect filter was handed while handling the last
	 * redirect, in order.
	 * @var array
	 */
	$GLOBALS['njr_test_filter_saw'] = [];

	/**
	 * The redirects this fixture site stores, by id. The enable and disable
	 * events are the only ones that read them back: those two carry an id and
	 * nothing else, so the integration loads the redirect to find its source.
	 * @var array
	 */
	$GLOBALS['njr_test_redirects'] = [];

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

	function add_filter( $name, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['njr_test_filters'][ $name ][] = $callback;
	}

	function apply_filters( $name, $value, ...$args ) {
		foreach ( $GLOBALS['njr_test_filters'][ $name ] ?? [] as $callback ) {
			$value = call_user_func_array( $callback, array_merge( [ $value ], $args ) );
		}

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

	/**
	 * Redirection's own class, reduced to the one static call the integration
	 * makes of it. Named by string there — `class_exists( 'Red_Item' )`, then
	 * `Red_Item::get_by_id()` — because the enable and disable actions carry an
	 * id and nothing else, so the redirect has to be read back to find its
	 * source. The fixture site's redirects are what it reads back.
	 */
	class Red_Item {
		public static function get_by_id( $id ) {
			return $GLOBALS['njr_test_redirects'][ intval( $id ) ] ?? null;
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
		njr_test_arrange();

		$redirect = new NJR_Test_Redirect( $values );

		( new NextJsRevalidate\Integrations\Redirection() )
			->on_redirect_updated( $redirect->get_id(), $redirect );
	}

	/**
	 * Forget what the last event did, so the next one is asserted on its own.
	 * Filters are left where they are: a test attaches one before it fires the
	 * event, exactly as a site does.
	 *
	 * @return void
	 */
	function njr_test_arrange() {
		$GLOBALS['njr_test_enqueued']   = [];
		$GLOBALS['njr_test_log']        = [];
		$GLOBALS['njr_test_filter_saw'] = [];
	}

	/**
	 * Hand both states of a redirect to the update action, as Redirection's
	 * update call site does on the versions that carry the previous state.
	 *
	 * @param array $before What the redirect was.
	 * @param array $after  What it now is.
	 *
	 * @return void
	 */
	function njr_test_redirect_updated( array $before, array $after ) {
		njr_test_arrange();

		( new NextJsRevalidate\Integrations\Redirection() )
			->on_redirect_updated( new NJR_Test_Redirect( $before ), new NJR_Test_Redirect( $after ) );
	}

	/**
	 * @param array $values What this redirect is about.
	 * @return void
	 */
	function njr_test_redirect_deleted( array $values = [] ) {
		njr_test_arrange();

		( new NextJsRevalidate\Integrations\Redirection() )
			->on_redirect_deleted( new NJR_Test_Redirect( $values ) );
	}

	/**
	 * @param array $values What this redirect is about.
	 * @return void
	 */
	function njr_test_redirect_enabled( array $values = [] ) {
		$redirect = njr_test_store_redirect( $values, [ 'enabled' => true ] );

		njr_test_arrange();

		( new NextJsRevalidate\Integrations\Redirection() )->on_redirect_enabled( $redirect->get_id() );
	}

	/**
	 * A redirect is already stored as disabled by the time the disable action
	 * fires — that it stopped being enabled is the change the front-end has not
	 * heard about — so the fixture is stored the same way.
	 *
	 * @param array $values What this redirect is about.
	 * @return void
	 */
	function njr_test_redirect_disabled( array $values = [] ) {
		$redirect = njr_test_store_redirect( $values, [ 'enabled' => false ] );

		njr_test_arrange();

		( new NextJsRevalidate\Integrations\Redirection() )->on_redirect_disabled( $redirect->get_id() );
	}

	/**
	 * Put a redirect where `Red_Item::get_by_id()` will find it.
	 *
	 * @param array $values    What this redirect is about.
	 * @param array $overrides What the event fixes about it.
	 *
	 * @return NJR_Test_Redirect
	 */
	function njr_test_store_redirect( array $values, array $overrides ) {
		$redirect = new NJR_Test_Redirect( array_merge( $values, $overrides ) );

		$GLOBALS['njr_test_redirects'][ $redirect->get_id() ] = $redirect;

		return $redirect;
	}

	/**
	 * Attach the given callback as the site's only redirect filter.
	 *
	 * @param callable $callback
	 * @return void
	 */
	function njr_test_filter( callable $callback ) {
		$GLOBALS['njr_test_filters'] = [];

		add_filter( 'nextjs_revalidate_should_revalidate_redirect', $callback, 10, 3 );
	}

	/**
	 * A site with nothing attached to the filter, which is every site until
	 * someone writes the escape hatch.
	 *
	 * @return void
	 */
	function njr_test_no_filter() {
		$GLOBALS['njr_test_filters'] = [];
	}

	/**
	 * A filter that records every path it is handed and answers `$verdict` —
	 * or leaves the verdict alone when that is null.
	 *
	 * @param bool|null $verdict What the filter returns.
	 * @return void
	 */
	function njr_test_watching_filter( $verdict = null ) {
		njr_test_filter( function ( $should_revalidate, $path, $redirect ) use ( $verdict ) {
			$GLOBALS['njr_test_filter_saw'][] = $path;

			return null === $verdict ? $should_revalidate : $verdict;
		} );
	}

	/**
	 * A filter that declines the given source paths and leaves every other one
	 * alone — the escape hatch as a site would actually write it.
	 *
	 * @param array $paths The source paths this site resolves some other way.
	 * @return void
	 */
	function njr_test_declining_filter( array $paths ) {
		njr_test_filter( function ( $should_revalidate, $path, $redirect ) use ( $paths ) {
			$GLOBALS['njr_test_filter_saw'][] = $path;

			return in_array( $path, $paths, true ) ? false : $should_revalidate;
		} );
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

	/**
	 * @param string $description What is being asserted.
	 * @param array  $expected    The source paths the filter is expected to
	 *                            have been handed, in order.
	 *
	 * @return void
	 */
	function njr_test_filter_saw( $description, array $expected ) {
		global $failures;

		$actual = $GLOBALS['njr_test_filter_saw'];

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

	// The site has the last word
	// ====
	//
	// The escape hatch of issue #60: a site whose front-end resolves redirects
	// some other way — from build time configuration, from middleware, from
	// anywhere a per path revalidation does not reach — declines them without
	// giving up the revalidations it does want. It is the filter the redirect
	// rules end with, mirroring the one `should_revalidate()` ends with for a
	// post.

	njr_test_declining_filter( [ '/handled-by-the-front-end/' ] );

	njr_test_redirect_created( [ 'url' => '/handled-by-the-front-end' ] );
	njr_test_enqueued( 'a filter declining a source path enqueues nothing for it', [] );
	njr_test_logged(
		'a declined source path is logged, since nothing else records it',
		'a filter declined the revalidation of /handled-by-the-front-end/'
	);

	njr_test_redirect_created( [ 'url' => '/an-ordinary-redirect' ] );
	njr_test_enqueued(
		'a filter declines the paths it names, not redirect revalidation as a whole',
		[ [ 'https://example.test/an-ordinary-redirect/', 10 ] ]
	);

	// What the filter is handed is the path that would have been enqueued —
	// normalised, so a site matches on the same string the queue would hold
	// rather than on whatever the source happened to be stored as.
	njr_test_watching_filter();

	njr_test_redirect_created( [ 'url' => 'https://an-old-domain.test/a-page?ref=newsletter' ] );
	njr_test_filter_saw( 'the filter is handed the normalised source path', [ '/a-page/' ] );
	njr_test_enqueued(
		'a filter that decides nothing changes nothing',
		[ [ 'https://example.test/a-page/', 10 ] ]
	);

	// The redirect travels with the path, so a site can decline by anything the
	// rule holds rather than by its source alone.
	njr_test_filter( function ( $should_revalidate, $path, $redirect ) {
		return 7 === $redirect->get_id() ? false : $should_revalidate;
	} );

	njr_test_redirect_created( [ 'id' => 7, 'url' => '/declined-by-its-rule' ] );
	njr_test_enqueued( 'the filter is handed the redirect the path is the source of', [] );

	// The filter has the last word downward only. A redirect the rules already
	// turned away never reaches it: there is no single path to be asked about,
	// so returning true cannot resurrect one.
	njr_test_watching_filter( true );

	njr_test_redirect_created( [ 'url' => '/blog/(.*)', 'regex' => true ] );
	njr_test_enqueued( 'a filter returning true does not resurrect a regular expression source', [] );
	njr_test_filter_saw( 'a regular expression source is never put to the filter', [] );

	njr_test_redirect_created( [ 'url' => '/a-trashed-page', 'enabled' => false ] );
	njr_test_enqueued( 'a filter returning true does not resurrect a disabled redirect', [] );
	njr_test_filter_saw( 'a disabled redirect is never put to the filter', [] );

	njr_test_redirect_created( [ 'url' => '/' ] );
	njr_test_enqueued( 'a filter returning true does not resurrect a source that names no path', [] );
	njr_test_filter_saw( 'a source that names no path is never put to the filter', [] );

	// Every event that enqueues a source path asks, not only the one that
	// creates a redirect. Updating is below: it is the one event that can ask
	// twice.
	$njr_test_events = [
		'creating a redirect'   => function ( $url ) { njr_test_redirect_created( [ 'url' => $url ] ); },
		'deleting a redirect'   => function ( $url ) { njr_test_redirect_deleted( [ 'url' => $url ] ); },
		'enabling a redirect'   => function ( $url ) { njr_test_redirect_enabled( [ 'url' => $url ] ); },
		'disabling a redirect'  => function ( $url ) { njr_test_redirect_disabled( [ 'url' => $url ] ); },
	];

	foreach ( $njr_test_events as $event => $fire ) {
		njr_test_no_filter();
		$fire( '/a-changed-path' );
		njr_test_enqueued(
			"$event revalidates its source path with no filter attached",
			[ [ 'https://example.test/a-changed-path/', 10 ] ]
		);

		njr_test_declining_filter( [ '/a-changed-path/' ] );
		$fire( '/a-changed-path' );
		njr_test_enqueued( "$event asks the filter, which can decline it", [] );
		njr_test_filter_saw( "$event puts its source path to the filter", [ '/a-changed-path/' ] );
	}

	// An update that changes the source leaves two paths stale, and each is a
	// question of its own: a site can decline the one it resolves some other
	// way and keep the other.
	njr_test_no_filter();

	njr_test_redirect_updated( [ 'url' => '/the-old-source' ], [ 'url' => '/the-new-source' ] );
	njr_test_enqueued(
		'an update carrying the previous state revalidates both paths with no filter attached',
		[ [ 'https://example.test/the-old-source/', 10 ], [ 'https://example.test/the-new-source/', 10 ] ]
	);

	njr_test_declining_filter( [ '/the-old-source/' ] );

	njr_test_redirect_updated( [ 'url' => '/the-old-source' ], [ 'url' => '/the-new-source' ] );
	njr_test_filter_saw(
		'an update puts the old and the new source path to the filter independently',
		[ '/the-old-source/', '/the-new-source/' ]
	);
	njr_test_enqueued(
		'declining the path a redirect stopped redirecting keeps the one it now redirects',
		[ [ 'https://example.test/the-new-source/', 10 ] ]
	);

	njr_test_declining_filter( [ '/the-new-source/' ] );

	njr_test_redirect_updated( [ 'url' => '/the-old-source' ], [ 'url' => '/the-new-source' ] );
	njr_test_enqueued(
		'declining the new source path keeps the one the redirect stopped redirecting',
		[ [ 'https://example.test/the-old-source/', 10 ] ]
	);

	njr_test_no_filter();

	printf( "\n%d failure(s)\n", $failures );
	exit( $failures === 0 ? 0 : 1 );
}
