<?php
/**
 * The documented API functions, read through their return values and the
 * changes they report.
 *
 * These tests assert on what the plugin *told the caller*, because that is the
 * whole of the public contract: third-party code has no other way to find out
 * what happened, and for a long time the answer was the constant `true` (#50).
 *
 * What the contract is, and what it deliberately is not, is in
 * `docs/adr/0010-the-public-api-reports-acceptance-not-delivery.md`. In short:
 * these functions answer whether a change was *accepted* into the pending
 * changes, and no test here can assert on a delivery, because the delivery
 * happens once the request has ended.
 *
 * The v1 names are wrappers around these since 2.0.0 (ADR 0035), and warn
 * through `_deprecated_function()`; `tests/public-api-test.php` pins that they
 * delegate, and the wrapper test below that they still work against a real
 * WordPress.
 *
 * @package NextJsRevalidate
 */

namespace NextJsRevalidate\Tests;

use NextJsRevalidate\Change;
use NextJsRevalidate\Cron\ScheduledPurges;

class PublicApiTest extends PendingChangesTestCase {

	/**
	 * A date time far enough ahead that the scheduled purges cron will not have
	 * come due while the suite runs — a due scheduled purge would report
	 * itself, and the assertions on nothing held would stop meaning anything.
	 */
	const FIXTURE_DATETIME = '2099-01-01 09:00:00';

	/**
	 * Undo what a scheduled purge writes: an option, and a cron event in
	 * another. Both are rolled back with the test's transaction too; this is
	 * the belt to that pair of braces, since a leaked cron event would silently
	 * decide whether the next test schedules one.
	 */
	public function tear_down() {
		ScheduledPurges::delete_scheduled_purges();
		ScheduledPurges::unschedule_cron();

		parent::tear_down();
	}

	// nextjs_revalidate_path()
	// ====

	/**
	 * The accepted case: a configured site takes the change on, and says so.
	 */
	public function test_a_path_on_a_configured_site_is_accepted() {
		$this->configure_site();

		$this->assertTrue(
			\nextjs_revalidate_path( $this->permalink_of( '/hello-world/' ) ),
			'A configured site accepted the change and should have said so.'
		);

		$this->assertPendingChanges( [ Change::path( $this->uri_of( '/hello-world/' ) ) ] );
	}

	/**
	 * The refused case, and the reason this function's return value exists at
	 * all: an unconfigured site holds nothing, and must not report success.
	 */
	public function test_a_path_on_an_unconfigured_site_is_refused() {
		$this->assertFalse(
			\nextjs_revalidate_path( $this->permalink_of( '/hello-world/' ) ),
			'An unconfigured site refuses the change; reporting true would make a refusal indistinguishable from an acceptance.'
		);

		$this->assertNoPendingChanges( 'A refused change never joins the pending changes.' );
	}

	/**
	 * A URL and the path it names are one change: the path from the domain
	 * root, whichever form the caller had to hand.
	 */
	public function test_a_url_and_its_path_are_one_change() {
		$this->configure_site();

		$this->assertTrue( \nextjs_revalidate_path( $this->permalink_of( '/asked-for-twice/' ) ) );
		$this->assertTrue(
			\nextjs_revalidate_path( $this->uri_of( '/asked-for-twice/' ) ),
			'A path already held is accepted again, not rejected.'
		);

		$this->assertPendingChanges(
			[ Change::path( $this->uri_of( '/asked-for-twice/' ) ) ],
			'Identical changes merge, so the path is held once.'
		);
	}

	/**
	 * The site's own filter has the last word, and a change it drops was not
	 * accepted.
	 */
	public function test_a_path_the_change_filter_drops_is_not_accepted() {
		$this->configure_site();

		add_filter( 'nextjs_revalidate_change', '__return_false' );

		$this->assertFalse( \nextjs_revalidate_path( $this->permalink_of( '/dropped/' ) ) );
		$this->assertNoPendingChanges();
	}

	// nextjs_revalidate_schedule_path()
	// ====

	/**
	 * Registering a scheduled purge, and the second half of the sibling's
	 * contract: a URL already registered for that date time registers nothing,
	 * which is what the `false` means. The schedule itself stands either way.
	 */
	public function test_scheduling_the_same_path_twice_registers_it_once() {
		$permalink = $this->permalink_of( '/scheduled/' );

		$this->assertTrue(
			\nextjs_revalidate_schedule_path( self::FIXTURE_DATETIME, $permalink ),
			'The first call registers the scheduled purge.'
		);

		$this->assertFalse(
			\nextjs_revalidate_schedule_path( self::FIXTURE_DATETIME, $permalink ),
			'The second call adds nothing to the schedule, and false is what says so.'
		);

		$this->assertSame(
			[ $permalink ],
			$this->scheduled_urls(),
			'The schedule holds the URL once.'
		);
	}

	/**
	 * The half of the contract that only exists because the return value stopped
	 * being a constant: a write that does not happen is not a registration.
	 *
	 * The failure is forced through `pre_update_option`, which is where
	 * `update_option()` decides it has nothing to do — the filter hands back the
	 * stored value, so the write is skipped and `false` comes back exactly as a
	 * failed one would. There is no way to make the real write fail from a test
	 * that is not also a way to break the database for the rest of the suite.
	 *
	 * Without this test the function could return a constant `true` again and
	 * every other test here would still pass: the duplicate case returns before
	 * the write, so it never reads the write's answer.
	 */
	public function test_a_scheduled_path_whose_write_fails_is_not_registered() {
		$refuse_the_write = function( $value, $old_value ) {
			return $old_value;
		};

		add_filter( 'pre_update_option_' . ScheduledPurges::OPTION_NAME, $refuse_the_write, 10, 2 );

		$registered = \nextjs_revalidate_schedule_path(
			self::FIXTURE_DATETIME,
			$this->permalink_of( '/the-write-fails/' )
		);

		remove_filter( 'pre_update_option_' . ScheduledPurges::OPTION_NAME, $refuse_the_write, 10 );

		$this->assertFalse(
			$registered,
			'The write did not happen, so nothing was registered, and true would be the constant this contract exists to remove.'
		);

		$this->assertSame(
			[],
			$this->scheduled_urls(),
			'A failed write leaves no scheduled purge behind.'
		);
	}

	/**
	 * A scheduled purge is not a reported change.
	 *
	 * Nothing is reported until the date time passes — which is why this
	 * function's true is a promise to report later, and why a site configured
	 * now can still refuse the change when it comes due.
	 */
	public function test_scheduling_a_path_reports_nothing_yet() {
		$this->configure_site();

		\nextjs_revalidate_schedule_path( self::FIXTURE_DATETIME, $this->permalink_of( '/due-in-2099/' ) );

		$this->assertNoPendingChanges( 'A scheduled purge is reported at its due time, not when it is registered.' );
	}

	/**
	 * A scheduled purge whose time has passed is reported as a path change by
	 * the cron request that finds it due, and its entry is dropped.
	 */
	public function test_a_due_scheduled_path_is_reported_as_a_path_change() {
		$this->configure_site();

		\nextjs_revalidate_schedule_path( '2000-01-01 09:00:00', $this->permalink_of( '/was-due/' ) );

		\NextJsRevalidate::init()->cronScheduledPurges->run_cron_hook();

		$this->assertPendingChanges( [ Change::path( $this->uri_of( '/was-due/' ) ) ] );
		$this->assertSame( [], $this->scheduled_urls(), 'A due entry is spent by the run.' );
	}

	/**
	 * An unconfigured site refuses the change a due scheduled purge produces,
	 * and the entry is dropped all the same, as it always has been: a refusal is
	 * the end of it.
	 */
	public function test_a_due_scheduled_path_on_an_unconfigured_site_is_refused_and_dropped() {
		\nextjs_revalidate_schedule_path( '2000-01-01 09:00:00', $this->permalink_of( '/was-due/' ) );

		\NextJsRevalidate::init()->cronScheduledPurges->run_cron_hook();

		$this->assertNoPendingChanges( 'The change was refused, so nothing is held.' );
		$this->assertSame( [], $this->scheduled_urls(), 'The refused entry is dropped rather than kept for a later run.' );
	}

	/**
	 * Isolation of what a scheduled purge writes, second half — this test is
	 * paired with the ones above it and only means anything run after them:
	 * a leak appears on the *next* test, and is invisible in a source review.
	 */
	public function test_no_scheduled_path_survives_into_the_next_test() {
		$this->assertSame(
			[],
			$this->scheduled_urls(),
			'The previous test\'s scheduled purge survived into this one: tear_down is not undoing it.'
		);

		$this->assertFalse(
			wp_next_scheduled( ScheduledPurges::CRON_HOOK_NAME ),
			'The previous test\'s scheduled purges cron survived into this one.'
		);
	}

	// The v1 names
	// ====

	/**
	 * The deprecated wrappers still do what they did, through the v2 functions,
	 * and say they are deprecated. `$priority` is accepted and changes nothing.
	 */
	public function test_the_v1_names_still_work_and_are_deprecated() {
		$this->configure_site();

		$this->setExpectedDeprecated( 'nextjs_revalidate_purge_url' );
		$this->setExpectedDeprecated( 'nextjs_revalidate_schedule_purge_url' );

		$this->assertTrue( \nextjs_revalidate_purge_url( $this->permalink_of( '/v1-caller/' ), 1 ) );
		$this->assertTrue( \nextjs_revalidate_schedule_purge_url( self::FIXTURE_DATETIME, $this->permalink_of( '/v1-scheduled/' ) ) );

		$this->assertPendingChanges( [ Change::path( $this->uri_of( '/v1-caller/' ) ) ] );
		$this->assertSame( [ $this->permalink_of( '/v1-scheduled/' ) ], $this->scheduled_urls() );
	}

	// Reading the schedule
	// ====

	/**
	 * Every URL registered for a future purge, in schedule order.
	 *
	 * The option is a date time => URLs map; a test here cares which URLs are
	 * registered rather than how they are keyed.
	 *
	 * @return string[]
	 */
	private function scheduled_urls() {
		$urls = [];

		foreach ( get_option( ScheduledPurges::OPTION_NAME, [] ) as $entries ) {
			foreach ( $entries as $url ) {
				$urls[] = $url;
			}
		}

		return $urls;
	}

	/**
	 * The `uri` a path of this site is reported with: its path from the domain
	 * root, which on a test site served from a directory includes it.
	 *
	 * @param string $path A path of this site, as `/hello-world/`.
	 * @return string
	 */
	private function uri_of( $path ) {
		return (string) Change::uri_of( $this->permalink_of( $path ) );
	}
}
