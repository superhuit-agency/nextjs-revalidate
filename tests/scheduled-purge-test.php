<?php
/**
 * What a scheduled purge reports when it comes due —
 * `ScheduledPurges::run_cron_hook()`.
 *
 * A **scheduled purge** is a path registered to be revalidated at a future
 * time. When the cron request finds it due, each of its URLs becomes a **path**
 * change in that request's pending changes, and the entry is dropped — whatever
 * became of the change, because a refusal is final rather than something to
 * keep the entry for. Pinned here:
 *
 *  - a due URL is reported as a path change, reduced to its path from the
 *    domain root, whether it was registered as a URL or as a path;
 *  - an entry not yet due is left in the option, untouched;
 *  - an unconfigured site refuses the change, and the entry goes all the same;
 *  - a URL that names no path is dropped with a log line rather than reported.
 *
 * Until v2 a due entry was enqueued at `QUEUE_PRIORITY`, 5 (#63). There is no
 * queue left to order, and the constant went with it (ADR 0034).
 *
 * Walking the entries needs no pending changes of the real kind, no options
 * and no database, so this is a standalone script rather than a PHPUnit test —
 * see `docs/adr/0008-two-testing-idioms.md`. That the change a due entry
 * produces is really in the pending changes of a real site is
 * `tests/integration/PublicApiTest.php`'s.
 *
 * Run with `npm run test:php`, or `php tests/scheduled-purge-test.php`.
 */

namespace NextJsRevalidate {

	/**
	 * The plugin's logger, reduced to the lines it was asked to write.
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

	if ( 'cli' !== PHP_SAPI ) die( 'This file must be run from the command line.' );

	// The plugin files bail when this is not defined.
	define( 'ABSPATH', __DIR__ . '/' );

	/**
	 * The scheduled purges the fixture site holds, keyed by date time.
	 * @var array
	 */
	$GLOBALS['njr_test_entries'] = [];

	/**
	 * The entries the last run wrote back, or null when it wrote none.
	 * @var array|null
	 */
	$GLOBALS['njr_test_written_entries'] = null;

	/**
	 * Everything the plugin logged during the last run.
	 * @var string[]
	 */
	$GLOBALS['njr_test_log'] = [];

	// WordPress stubs
	// ====

	function add_action( $name, $callback, $priority = 10, $accepted_args = 1 ) {}
	function add_filter( $name, $callback, $priority = 10, $accepted_args = 1 ) {}
	function __( $text, $domain = null ) { return $text; }

	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}

	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}

	function get_option( $name, $default = false ) {
		return NextJsRevalidate\Cron\ScheduledPurges::OPTION_NAME === $name
			? $GLOBALS['njr_test_entries']
			: $default;
	}

	function update_option( $name, $value, $autoload = null ) {
		if ( NextJsRevalidate\Cron\ScheduledPurges::OPTION_NAME !== $name ) return false;

		$GLOBALS['njr_test_entries']         = $value;
		$GLOBALS['njr_test_written_entries'] = $value;

		return true;
	}

	// The run schedules its next cron once it is done with the entries; what it
	// schedules is not what this file is about.
	function wp_next_scheduled( $hook, $args = [] ) { return false; }
	function wp_schedule_single_event( $timestamp, $hook, $args = [] ) { return true; }

	class WP_Error {
		private $code;
		private $message;

		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}

	/**
	 * Pending changes that remember what they were handed, and answer the way
	 * the real ones do for a configured or an unconfigured site.
	 *
	 * Standing in for `PendingChanges` rather than being one: the subject is
	 * what the cron hands over, and what becomes of a change once handed over
	 * is `tests/pending-changes-test.php`'s.
	 */
	class NextJsRevalidate_Test_PendingChanges {

		/**
		 * Every change handed over, in order.
		 * @var array[]
		 */
		public $reported = [];

		/**
		 * Whether the fixture site is configured.
		 * @var bool
		 */
		public $configured = true;

		public function report( array $change ) {
			$this->reported[] = $change;

			return $this->configured
				? true
				: new WP_Error( 'not_configured', 'Next.js revalidate is not configured for this site.' );
		}
	}

	class NextJsRevalidate {
		public $pendingChanges;

		private static $instance;

		public static function init() {
			if ( ! isset( self::$instance ) ) self::$instance = new static();
			return self::$instance;
		}

		private function __construct() {
			$this->pendingChanges = new NextJsRevalidate_Test_PendingChanges();
		}
	}

	// The subject
	// ====

	require_once __DIR__ . '/../include/Interfaces/Hookable.php';
	require_once __DIR__ . '/../include/Abstracts/Base.php';
	require_once __DIR__ . '/../include/Change.php';
	require_once __DIR__ . '/../include/Cron/ScheduledPurges.php';

	// The harness
	// ====

	$failures = 0;

	function njr_test_assert( $condition, $description ) {
		global $failures;

		if ( $condition ) {
			printf( "ok   — %s\n", $description );
			return;
		}

		$failures++;
		printf( "FAIL — %s\n", $description );
	}

	/**
	 * Run the cron hook against a fixture site holding the given entries.
	 *
	 * @param array $entries    The scheduled purges the site holds.
	 * @param bool  $configured Optional. Whether the site is configured. Default true.
	 *
	 * @return array[] The changes the run handed over, in order.
	 */
	function njr_test_run( array $entries, $configured = true ) {
		$GLOBALS['njr_test_entries']         = $entries;
		$GLOBALS['njr_test_written_entries'] = null;
		$GLOBALS['njr_test_log']             = [];

		$pending = NextJsRevalidate::init()->pendingChanges;
		$pending->reported   = [];
		$pending->configured = $configured;

		( new NextJsRevalidate\Cron\ScheduledPurges() )->run_cron_hook();

		return $pending->reported;
	}

	/**
	 * A date time the given number of minutes from now, spelled as the option
	 * holds them.
	 *
	 * @param int $minutes Negative for a purge already due.
	 * @return string
	 */
	function njr_test_datetime( $minutes ) {
		$dt = new DateTime( 'now', new DateTimeZone( 'Europe/Zurich' ) );
		$dt->modify( sprintf( '%+d minutes', $minutes ) );

		return $dt->format( 'c' );
	}

	/**
	 * The path change a `uri` is reported as.
	 *
	 * @param string $uri
	 * @return array
	 */
	function njr_test_path( $uri ) {
		return [ 'subject' => 'path', 'uri' => $uri ];
	}

	// The cases
	// ====

	// The everyday case: a permalink registered for a time that has passed is
	// reported as a path change, its `uri` the path from the domain root.
	$reported = njr_test_run( [ njr_test_datetime( -1 ) => [ 'https://example.test/hello-world/' ] ] );

	njr_test_assert( [ njr_test_path( '/hello-world/' ) ] === $reported, 'a due url is reported as a path change, reduced to its path' );
	njr_test_assert( [] === $GLOBALS['njr_test_written_entries'], 'a due entry is dropped from the option' );

	// A caller may register a path as readily as a URL, and a URL may carry a
	// query string; all of them name one `uri`.
	$reported = njr_test_run( [
		njr_test_datetime( -2 ) => [ '/a-path/', 'https://example.test/with-a-query/?utm=x' ],
	] );

	njr_test_assert(
		[ njr_test_path( '/a-path/' ), njr_test_path( '/with-a-query/' ) ] === $reported,
		'every url of a due entry is reported, a path as itself and a url as its path'
	);

	// An entry whose date time has not passed is left alone — the run reports
	// nothing and writes it back untouched.
	$future   = njr_test_datetime( 60 );
	$reported = njr_test_run( [ $future => [ 'https://example.test/later/' ] ] );

	njr_test_assert( [] === $reported, 'an entry not yet due reports nothing' );
	njr_test_assert(
		[ $future => [ 'https://example.test/later/' ] ] === $GLOBALS['njr_test_written_entries'],
		'an entry not yet due stays in the option'
	);

	// Both at once: the run is a partition of the entries, not a whole-option
	// decision.
	$past     = njr_test_datetime( -1 );
	$future   = njr_test_datetime( 60 );
	$reported = njr_test_run( [
		$past   => [ 'https://example.test/now/' ],
		$future => [ 'https://example.test/later/' ],
	] );

	njr_test_assert( [ njr_test_path( '/now/' ) ] === $reported, 'only the due entry is reported' );
	njr_test_assert(
		[ $future => [ 'https://example.test/later/' ] ] === $GLOBALS['njr_test_written_entries'],
		'only the entry not yet due survives the run'
	);

	// An unconfigured site refuses the change when it is reported — and the
	// entry goes anyway, as it always has: a refusal is the end of it, and
	// keeping the entry would report it again on every run until somebody
	// configured the site, long after its date meant anything.
	$reported = njr_test_run( [ njr_test_datetime( -1 ) => [ 'https://example.test/refused/' ] ], false );

	njr_test_assert( [ njr_test_path( '/refused/' ) ] === $reported, 'on an unconfigured site the due change is still handed over, to be refused there' );
	njr_test_assert( [] === $GLOBALS['njr_test_written_entries'], 'and the refused entry is dropped all the same' );

	// A URL that names no path cannot be a path change. It is dropped, with a
	// line saying so, rather than reported as something nobody registered.
	$reported = njr_test_run( [ njr_test_datetime( -1 ) => [ '', 'https://example.test/kept/' ] ] );

	njr_test_assert( [ njr_test_path( '/kept/' ) ] === $reported, 'a url naming no path is not reported, and the url beside it still is' );
	njr_test_assert( 1 === count( $GLOBALS['njr_test_log'] ) && false !== strpos( $GLOBALS['njr_test_log'][0], 'names no path' ), 'the dropped url is logged' );
	njr_test_assert( [] === $GLOBALS['njr_test_written_entries'], 'its entry is dropped too' );

	// The priority is gone with the queue it ordered.
	njr_test_assert( ! defined( 'NextJsRevalidate\Cron\ScheduledPurges::QUEUE_PRIORITY' ), 'a scheduled purge has no queue priority left' );

	// The verdict
	// ====

	if ( $failures > 0 ) {
		printf( "\n%d failing expectation(s).\n", $failures );
		exit( 1 );
	}

	print( "\nAll good.\n" );
}
