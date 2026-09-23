<?php
/**
 * What a scheduled purge is enqueued at when it comes due —
 * `ScheduledPurges::run_cron_hook()`.
 *
 * The bug this guards (#63): the call site passed `true` where `add_item()`
 * takes `$priority`, and `true` lands in an `int(10)` column as `1`. The queue
 * drains `priority ASC, id ASC`, so every scheduled purge jumped ahead of every
 * ordinary revalidation waiting at the default 10 — an ordering nobody
 * configured, produced by a leftover boolean rather than a decision.
 *
 * A scheduled purge *is* more urgent than an ordinary save, so the fix says so
 * with an integer: `ScheduledPurges::QUEUE_PRIORITY`, elevated above the
 * default and still behind anything a caller deemed more urgent. These cases
 * pin the number, its type, and its place between `0` and the default — the
 * three things a second leftover boolean would break.
 *
 * Walking the entries needs no queue, no options and no database, so this is a
 * standalone script rather than a PHPUnit test — see
 * `docs/adr/0008-two-testing-idioms.md`. That an entry at that priority really
 * drains ahead of one at the default is the integration suite's business:
 * `tests/integration/QueuePriorityTest.php`.
 *
 * Run with `npm run test:php`, or `php tests/scheduled-purge-priority-test.php`.
 */

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

// WordPress stubs
// ====

function add_action( $name, $callback, $priority = 10, $accepted_args = 1 ) {}
function add_filter( $name, $callback, $priority = 10, $accepted_args = 1 ) {}
function __( $text, $domain = null ) { return $text; }

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

/**
 * A queue that accepts everything and remembers what it was asked.
 *
 * Standing in for `RevalidateQueue` rather than being one: the subject is the
 * arguments the cron hands it, and the real queue would need a database to be
 * asked anything at all.
 */
class NextJsRevalidate_Test_Queue {

	/**
	 * What each call was asked for — `[ permalink, priority ]`, in order.
	 * @var array
	 */
	public $asked = [];

	public function add_item( $permalink, $priority = 10 ) {
		$this->asked[] = [ $permalink, $priority ];

		return 1;
	}
}

class NextJsRevalidate {
	public $queue;

	private static $instance;

	public static function init() {
		if ( ! isset( self::$instance ) ) self::$instance = new static();
		return self::$instance;
	}

	private function __construct() {
		$this->queue = new NextJsRevalidate_Test_Queue();
	}
}

// The subject
// ====

require_once __DIR__ . '/../include/Interfaces/Hookable.php';
require_once __DIR__ . '/../include/Abstracts/Base.php';
require_once __DIR__ . '/../include/Traits/SendbackUrl.php';
require_once __DIR__ . '/../include/RevalidateQueue.php';
require_once __DIR__ . '/../include/Cron/ScheduledPurges.php';

use NextJsRevalidate\Cron\ScheduledPurges;
use NextJsRevalidate\RevalidateQueue;

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
 * @param array $entries The scheduled purges the site holds.
 * @return NextJsRevalidate_Test_Queue The queue, holding what it was asked.
 */
function njr_test_run( array $entries ) {
	$GLOBALS['njr_test_entries']         = $entries;
	$GLOBALS['njr_test_written_entries'] = null;

	$queue = NextJsRevalidate::init()->queue;
	$queue->asked = [];

	$purges = new ScheduledPurges();
	$purges->run_cron_hook();

	return $queue;
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

// The cases
// ====

// The number itself, and the two comparisons that make it mean anything. A
// priority is only "elevated" relative to the default, and only "not the most
// urgent there is" relative to 0.
njr_test_assert( 5 === ScheduledPurges::QUEUE_PRIORITY, 'a scheduled purge is enqueued at priority 5' );
njr_test_assert( is_int( ScheduledPurges::QUEUE_PRIORITY ), 'the priority is an integer, not a flag' );
njr_test_assert(
	ScheduledPurges::QUEUE_PRIORITY < RevalidateQueue::DEFAULT_PRIORITY,
	'it drains ahead of an ordinary save, which waits at the default'
);
njr_test_assert(
	ScheduledPurges::QUEUE_PRIORITY > 0,
	'it stays behind anything a caller explicitly deemed more urgent'
);

// The call site itself: what the cron hands the queue when an entry comes due.
$queue = njr_test_run( [ njr_test_datetime( -1 ) => [ 'https://example.test/hello-world/' ] ] );

njr_test_assert( 1 === count( $queue->asked ), 'a due entry enqueues its url' );
njr_test_assert(
	[ 'https://example.test/hello-world/', ScheduledPurges::QUEUE_PRIORITY ] === $queue->asked[0],
	'it is enqueued at the scheduled purge priority'
);

// The bug in the shape it had: `true` is not 5, and read as a priority it is
// the `1` that put every scheduled purge in front of the whole queue.
$asked_priority = $queue->asked[0][1];
njr_test_assert( true !== $asked_priority, 'the priority is not a boolean' );
njr_test_assert( 1 !== $asked_priority, 'the priority is not the 1 a coerced boolean produced' );

// A due entry is spent by the run; nothing is left to enqueue it a second time.
njr_test_assert( [] === $GLOBALS['njr_test_written_entries'], 'a due entry is dropped from the option' );

// Every url of one entry comes due together, and at the same priority.
$queue = njr_test_run( [ njr_test_datetime( -1 ) => [ 'https://example.test/a/', 'https://example.test/b/' ] ] );

njr_test_assert(
	[
		[ 'https://example.test/a/', ScheduledPurges::QUEUE_PRIORITY ],
		[ 'https://example.test/b/', ScheduledPurges::QUEUE_PRIORITY ],
	] === $queue->asked,
	'every url of a due entry is enqueued, each at that priority'
);

// An entry whose date time has not passed is left alone — the run enqueues
// nothing and writes it back untouched.
$future  = njr_test_datetime( 60 );
$queue   = njr_test_run( [ $future => [ 'https://example.test/later/' ] ] );

njr_test_assert( [] === $queue->asked, 'an entry not yet due enqueues nothing' );
njr_test_assert(
	[ $future => [ 'https://example.test/later/' ] ] === $GLOBALS['njr_test_written_entries'],
	'an entry not yet due stays in the option'
);

// Both at once: the run is a partition of the entries, not a whole-option
// decision.
$past   = njr_test_datetime( -1 );
$future = njr_test_datetime( 60 );
$queue  = njr_test_run( [
	$past   => [ 'https://example.test/now/' ],
	$future => [ 'https://example.test/later/' ],
] );

njr_test_assert(
	[ [ 'https://example.test/now/', ScheduledPurges::QUEUE_PRIORITY ] ] === $queue->asked,
	'only the due entry is enqueued'
);
njr_test_assert(
	[ $future => [ 'https://example.test/later/' ] ] === $GLOBALS['njr_test_written_entries'],
	'only the entry not yet due survives the run'
);

// The verdict
// ====

if ( $failures > 0 ) {
	printf( "\n%d failing expectation(s).\n", $failures );
	exit( 1 );
}

print( "\nAll good.\n" );
