<?php
/**
 * The base case for tests that observe the pending changes.
 *
 * @package NextJsRevalidate
 */

namespace NextJsRevalidate\Tests;

use NextJsRevalidate\PendingChanges;
use NextJsRevalidate\Settings;
use ReflectionProperty;
use WP_UnitTestCase;

/**
 * Isolation, fixtures and assertions for the **pending changes**.
 *
 * The pending changes live in the plugin's own `PendingChanges` object for as
 * long as the request does, and PHPUnit's whole run is one request: nothing
 * `WP_UnitTestCase`'s rollback undoes reaches them. This case therefore empties
 * them by hand on both sides of every test — after, too, so a test's leftovers
 * are not delivered to the fixture domain at `shutdown` when the run ends.
 *
 * Unlike the queue (see `QueueTestCase`), nothing here writes to the database
 * outside the test's transaction, so the posts a test creates and the settings
 * its fixture writes are rolled back as usual.
 *
 * See `docs/adr/0008-two-testing-idioms.md`, and
 * `docs/adr/0034-changes-are-delivered-when-the-request-ends.md` for the
 * pending changes themselves.
 */
abstract class PendingChangesTestCase extends WP_UnitTestCase {

	/**
	 * The revalidate domain a configured fixture site holds.
	 */
	const FIXTURE_DOMAIN = 'https://front-end.test';

	/**
	 * The secret a configured fixture site holds.
	 */
	const FIXTURE_SECRET = 'fixture-secret';

	public function set_up() {
		parent::set_up();

		$this->reset_pending_changes();
	}

	public function tear_down() {
		$this->reset_pending_changes();

		parent::tear_down();
	}

	// The subject
	// ====

	/**
	 * The pending changes, read through the plugin's own singleton.
	 *
	 * @return PendingChanges
	 */
	protected function pending_changes() {
		return \NextJsRevalidate::init()->pendingChanges;
	}

	/**
	 * Let go of every pending change, of every site, without delivering any.
	 *
	 * Reachable by a test as well as by the isolation above, because arranging
	 * a fixture can report: a post saved into place is a change of its own, and
	 * a test about what some *later* event reports has to be able to start from
	 * nothing rather than assert around it.
	 *
	 * @return void
	 */
	protected function reset_pending_changes() {
		$pending = new ReflectionProperty( PendingChanges::class, 'pending' );
		$pending->setAccessible( true );
		$pending->setValue( $this->pending_changes(), [] );
	}

	// Fixtures
	// ====

	/**
	 * Make the site being served a configured site.
	 *
	 * An unconfigured site refuses every change, so no event driven test can
	 * report anything without this.
	 *
	 * @return void
	 */
	protected function configure_site() {
		update_option( Settings::SETTINGS_DOMAIN_NAME, self::FIXTURE_DOMAIN );
		update_option( Settings::SETTINGS_SECRET_NAME, self::FIXTURE_SECRET );
	}

	/**
	 * The path a permalink of this site names — what a change calls its `uri`.
	 *
	 * A permalink pointing elsewhere is returned whole rather than trimmed into
	 * something that reads like a path, so a test sees it rather than has it
	 * hidden.
	 *
	 * @param string $permalink An absolute url.
	 * @return string
	 */
	protected function path_of( $permalink ) {
		$home = home_url();

		return strpos( $permalink, $home ) === 0
			? substr( $permalink, strlen( $home ) )
			: $permalink;
	}

	// Assertions
	// ====

	/**
	 * Assert the current site holds exactly these changes, in this order.
	 *
	 * @param array[] $changes The expected changes, as `Change` builds them.
	 * @param string  $message Optional.
	 *
	 * @return void
	 */
	protected function assertPendingChanges( array $changes, $message = '' ) {
		$this->assertSame( $changes, $this->pending_changes()->pending(), $message );
	}

	/**
	 * Assert the current site holds no change.
	 *
	 * @param string $message Optional.
	 * @return void
	 */
	protected function assertNoPendingChanges( $message = '' ) {
		$this->assertSame( [], $this->pending_changes()->pending(), $message );
	}
}
