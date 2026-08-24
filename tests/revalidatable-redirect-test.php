<?php
/**
 * Revalidatable redirect and its source path — Integrations\Redirection.
 *
 * The decision a redirect change makes before it ever reaches the queue: is
 * this redirect a candidate at all, and which path does its source name? Asked
 * of every event in a redirect's life — created, updated, deleted, disabled,
 * enabled — and of a bulk operation, which is those same events in a loop. All
 * of it is reachable by stubbing a handful of WordPress functions, so all of it
 * is a standalone script — ADR 0008's rule, and the reason it exists: this is a
 * suite the sandbox of ADR 0006 can run, where the wp-env one cannot.
 *
 * What the redirect *is* stays duck-typed here, exactly as the integration
 * reads it: a fixture object carrying the four methods it asks of a redirect
 * stands in for one of Redirection's. The single exception is `Red_Item`, whose
 * name the integration has to spell out to load a redirect from an id, so this
 * file spells it out too. That those methods are the ones upstream actually
 * has, and that the queue then holds what this asserts it was handed, is
 * `tests/integration/RedirectRevalidationTest.php`'s to prove — against the
 * real plugin, on a real queue. Nothing here has an opinion about either.
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
	 * The redirects `Red_Item::get_by_id()` answers with, id => redirect.
	 *
	 * Enabling and disabling carry only the redirect's id, so what this store
	 * holds is the whole of what those two handlers get to read.
	 *
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

	/**
	 * Redirection's own class, reduced to the one call this integration makes
	 * of it: loading a redirect by id.
	 *
	 * The one thing here that cannot be duck-typed. Everywhere else the
	 * integration asks an object whether it answers the four methods it needs;
	 * to load a redirect from an id it has to name a class, and `Red_Item` is
	 * the name it names. What `get_by_id()` hands back is duck-typed again,
	 * exactly as the fixture above is.
	 */
	class Red_Item {

		/**
		 * @param mixed $id The redirect's id.
		 * @return NJR_Test_Redirect|false Redirection answers false for an id
		 *                                 it holds no redirect for.
		 */
		public static function get_by_id( $id ) {
			$id = intval( $id );

			return isset( $GLOBALS['njr_test_redirects'][ $id ] )
				? $GLOBALS['njr_test_redirects'][ $id ]
				: false;
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
		njr_test_reset();

		$redirect = new NJR_Test_Redirect( $values );

		njr_test_integration()->on_redirect_updated( $redirect->get_id(), $redirect );
	}

	/**
	 * Take the fixture site back to holding no redirects, no queue entries and
	 * no log.
	 *
	 * @return void
	 */
	function njr_test_reset() {
		$GLOBALS['njr_test_enqueued']  = [];
		$GLOBALS['njr_test_log']       = [];
		$GLOBALS['njr_test_redirects'] = [];
	}

	/**
	 * The integration, built for a single call.
	 *
	 * A Hookable is always safe to construct for one method call, and nothing
	 * here registers a hook.
	 *
	 * @return NextJsRevalidate\Integrations\Redirection
	 */
	function njr_test_integration() {
		return new NextJsRevalidate\Integrations\Redirection();
	}

	/**
	 * A redirect Redirection has already written, and that `Red_Item::get_by_id()`
	 * can therefore find.
	 *
	 * @param array $values What this redirect is about.
	 * @return NJR_Test_Redirect
	 */
	function njr_test_store( array $values = [] ) {
		$redirect = new NJR_Test_Redirect( $values );

		$GLOBALS['njr_test_redirects'][ $redirect->get_id() ] = $redirect;

		return $redirect;
	}

	/**
	 * Hand the given redirect to the action Redirection fires when one is
	 * deleted, which carries the whole redirect: by the time it fires the row is
	 * gone, so there would be nothing left to load.
	 *
	 * @param array $values What this redirect was about.
	 * @return void
	 */
	function njr_test_redirect_deleted( array $values = [] ) {
		njr_test_reset();

		njr_test_integration()->on_redirect_deleted( new NJR_Test_Redirect( $values ) );
	}

	/**
	 * Fire the action Redirection fires when a redirect is enabled, which
	 * carries only its id.
	 *
	 * The redirect is stored as enabled first, because that is the order
	 * upstream writes in — the row is updated and the action fired after it —
	 * and that order is the whole reason the handler can read the redirect's
	 * source at all. That upstream really writes first is
	 * `tests/integration/RedirectRevalidationTest.php`'s to prove.
	 *
	 * @param array $values What this redirect is about.
	 * @return void
	 */
	function njr_test_redirect_enabled( array $values = [] ) {
		njr_test_reset();

		$redirect = njr_test_store( array_merge( $values, [ 'enabled' => true ] ) );

		njr_test_integration()->on_redirect_enabled( $redirect->get_id() );
	}

	/**
	 * The same, for a redirect that has just been disabled — stored disabled,
	 * because that is what it now is.
	 *
	 * @param array $values What this redirect is about.
	 * @return void
	 */
	function njr_test_redirect_disabled( array $values = [] ) {
		njr_test_reset();

		$redirect = njr_test_store( array_merge( $values, [ 'enabled' => false ] ) );

		njr_test_integration()->on_redirect_disabled( $redirect->get_id() );
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
	 * @param mixed  $expected    What it should be.
	 * @param mixed  $actual      What it is.
	 *
	 * @return void
	 */
	function njr_test_same( $description, $expected, $actual ) {
		global $failures;

		if ( $expected === $actual ) {
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
	 * The permalinks the queue was handed, without their priorities.
	 *
	 * @return string[]
	 */
	function njr_test_permalinks() {
		return array_column( $GLOBALS['njr_test_enqueued'], 0 );
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

	// The rest of a redirect's life
	// ====

	// Deleting gives the source path back to whatever lives there: it serves
	// its own page again, instead of staying stuck on a redirect that no longer
	// exists. The whole redirect travels with this action, so nothing is loaded.
	njr_test_redirect_deleted( [ 'url' => '/deleted' ] );
	njr_test_enqueued(
		'deleting an enabled redirect with a literal source enqueues that path',
		[ [ 'https://example.test/deleted/', 10 ] ]
	);

	// The same two non-candidates as on the way in, asked again on the way out:
	// a regular expression source names no single path however the redirect
	// leaves, and a disabled one was resolving to nothing already, so deleting
	// it changes nothing the front-end holds.
	njr_test_redirect_deleted( [ 'url' => '/blog/(.*)', 'regex' => true ] );
	njr_test_enqueued( 'deleting a redirect with a regular expression source enqueues nothing', [] );
	njr_test_logged( 'the deleted regular expression source is logged', '/blog/(.*)' );

	njr_test_redirect_deleted( [ 'url' => '/never-resolved', 'enabled' => false ] );
	njr_test_enqueued( 'deleting a redirect that was already disabled enqueues nothing', [] );

	// Disabling is a real off switch rather than a delayed one. Only the id
	// travels with it, so the redirect is loaded to find its source — and by
	// then it is stored as disabled — which makes this the one path that must
	// not ask whether the redirect is enabled, because the answer would be "no"
	// for every redirect ever disabled, and that it stopped being enabled is
	// precisely the change the front-end has not heard about.
	njr_test_redirect_disabled( [ 'id' => 7, 'url' => '/switched-off' ] );
	njr_test_enqueued(
		'disabling a redirect enqueues its source path, from its id alone',
		[ [ 'https://example.test/switched-off/', 10 ] ]
	);

	// Re-enabling puts the redirect back in service, and the front-end is still
	// holding the answer from while it was off.
	njr_test_redirect_enabled( [ 'id' => 7, 'url' => '/switched-on' ] );
	njr_test_enqueued(
		'enabling a redirect enqueues its source path, from its id alone',
		[ [ 'https://example.test/switched-on/', 10 ] ]
	);

	// The source has the same say whichever way the switch went.
	njr_test_redirect_disabled( [ 'url' => '/blog/(.*)', 'regex' => true ] );
	njr_test_enqueued( 'disabling a redirect with a regular expression source enqueues nothing', [] );

	njr_test_redirect_enabled( [ 'url' => '/blog/(.*)', 'regex' => true ] );
	njr_test_enqueued( 'enabling a redirect with a regular expression source enqueues nothing', [] );

	// An id nothing answers to. Redirection hands back false, and false is not
	// a redirect — reachable in ordinary use, since two operators bulk-deleting
	// and bulk-disabling the same selection in two tabs is enough.
	njr_test_reset();
	njr_test_integration()->on_redirect_enabled( 404 );
	njr_test_enqueued( 'enabling an id no redirect answers to enqueues nothing', [] );

	njr_test_reset();
	njr_test_integration()->on_redirect_disabled( 404 );
	njr_test_enqueued( 'disabling an id no redirect answers to enqueues nothing', [] );

	// Bulk operations
	// ====

	// Redirection's bulk routes fire these same per redirect actions in a loop,
	// so one click over hundreds of redirects reaches this integration hundreds
	// of times. That is absorbed rather than capped: the queue is durable and
	// cron drained, revalidate all routinely enqueues far more, and the count
	// is bounded by rules an operator actually created. Capping would silently
	// drop revalidations ADR 0004 guarantees no retry for, and collapsing above
	// a threshold would reintroduce the site wide stampede ADR 0006 rejected.
	//
	// So what is pinned here is the absence of both, and the absence of a third
	// thing: any memory of which paths this integration has already seen. A
	// path several redirects share is handed over once per redirect, on
	// purpose. Collapsing those duplicates is `RevalidateQueue::add_item()`'s
	// job, and whether it really does collapse them is a question about MySQL
	// rather than about this file: it is asserted against a real queue, in
	// `tests/integration/RedirectRevalidationTest.php`.
	njr_test_reset();

	$bulk        = njr_test_integration();
	$bulk_size   = 250;
	$shared_path = 'https://example.test/campaign/';

	foreach ( range( 1, $bulk_size ) as $i ) {
		$bulk->on_redirect_deleted( new NJR_Test_Redirect( [
			'id' => $i,

			// Every fifth redirect of the batch shares one campaign source
			// with the others, which is what a careless import leaves behind.
			'url' => 0 === $i % 5 ? '/campaign' : "/offer-$i",
		] ) );
	}

	njr_test_same(
		'a bulk delete enqueues once per redirect, under no cap and no threshold',
		$bulk_size,
		count( $GLOBALS['njr_test_enqueued'] )
	);

	njr_test_same(
		'a bulk delete over 250 redirects names the 201 distinct source paths they hold',
		201,
		count( array_unique( njr_test_permalinks() ) )
	);

	njr_test_same(
		'a source path 50 redirects share is handed over 50 times — deduplication is the queue\'s, not a set this integration keeps',
		50,
		count( array_keys( njr_test_permalinks(), $shared_path, true ) )
	);

	// A bulk disable is the same loop over ids rather than over redirects, and
	// is absorbed the same way.
	njr_test_reset();

	$bulk = njr_test_integration();

	foreach ( range( 1, 30 ) as $i ) {
		njr_test_store( [ 'id' => $i, 'url' => '/campaign', 'enabled' => false ] );
	}

	foreach ( range( 1, 30 ) as $i ) {
		$bulk->on_redirect_disabled( $i );
	}

	njr_test_same(
		'a bulk disable over 30 redirects sharing one source hands that path over 30 times, uncapped',
		30,
		count( $GLOBALS['njr_test_enqueued'] )
	);

	njr_test_same(
		'a bulk disable names nothing but the one source path its redirects share',
		[ $shared_path ],
		array_values( array_unique( njr_test_permalinks() ) )
	);

	printf( "\n%d failure(s)\n", $failures );
	exit( $failures === 0 ? 0 : 1 );
}
