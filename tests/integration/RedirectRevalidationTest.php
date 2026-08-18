<?php
/**
 * A redirect changes, its source path revalidates — issue #9.
 *
 * The seam is the revalidation queue's contents: each test arranges a redirect,
 * fires the event Redirection fires, and asserts which paths the queue then
 * holds. Nothing here asserts that a class was constructed, that a hook was
 * added, or how the source path was derived — renaming the handler or
 * restructuring the normalisation should break none of it.
 *
 * The one behaviour this file cannot reach is what happens on a site *without*
 * Redirection: it is loaded here and a process cannot unload it. That is
 * asserted in `tests/redirect-integration-registration-test.php` instead.
 *
 * @package NextJsRevalidate
 */

namespace NextJsRevalidate\Tests;

use NextJsRevalidate\Logger;
use NextJsRevalidate\Settings;
use Red_Group;
use Red_Item;

class RedirectRevalidationTest extends QueueTestCase {

	/**
	 * The group the fixture redirects belong to. Redirection stores every
	 * redirect in one.
	 *
	 * @var int
	 */
	private $group_id;

	public function set_up() {
		parent::set_up();

		if ( ! class_exists( Red_Item::class ) ) {
			$this->markTestSkipped( 'The Redirection plugin is not installed in this environment.' );
		}

		// A source path is enqueued in the trailing slash form the site's
		// permalinks take, so what that form is has to be the test's decision
		// rather than whatever the environment was left holding.
		$this->set_permalink_structure( '/%postname%/' );

		$this->reset_redirects();
		$this->reset_log();

		$group = Red_Group::create( 'Redirect revalidation tests', 1 );
		$this->assertNotFalse( $group, 'Redirection has no tables on this site: see tests/integration/bootstrap.php.' );

		$this->group_id = $group->get_id();
	}

	/**
	 * Redirects outlive the rollback whenever the test enqueued anything — the
	 * queue's own `COMMIT` commits them too, exactly as `QueueTestCase`
	 * describes for posts — so they are cleared here, after it.
	 */
	public function tear_down() {
		parent::tear_down();

		$this->reset_redirects();
		$this->reset_log();
	}

	// Creating
	// ====

	/**
	 * The everyday case: a redirect an editor creates makes the front-end
	 * rebuild the path it redirects from.
	 */
	public function test_creating_a_redirect_revalidates_its_source_path() {
		$this->configure_site();

		$this->create_redirect( [ 'url' => '/about-us' ] );

		$this->assertQueueRevalidates( [ '/about-us/' ] );
		$this->assertQueueRevalidatesAtPriorities(
			[ '/about-us/' => 10 ],
			'A redirect revalidation holds no special place in the queue.'
		);
	}

	/**
	 * A disabled redirect resolves to nothing, so the answer the front-end
	 * already holds for its source is the right one. Redirection's monitor
	 * creates its redirects disabled, which makes this an everyday case rather
	 * than a hypothetical.
	 */
	public function test_a_redirect_created_disabled_revalidates_nothing() {
		$this->configure_site();

		$this->create_redirect( [ 'url' => '/a-trashed-page', 'status' => 'disabled' ] );

		$this->assertQueueIsEmpty();
	}

	/**
	 * A regular expression source matches an unbounded set of paths, so there
	 * is no single path to rebuild — and never a revalidate all.
	 */
	public function test_a_regular_expression_source_revalidates_nothing_and_is_logged() {
		$this->configure_site();
		$this->enable_logs();

		$this->create_redirect( [ 'url' => '/blog/(.*)', 'regex' => 1 ] );

		$this->assertQueueIsEmpty();
		$this->assertStringContainsString(
			'/blog/(.*)',
			$this->log(),
			'A skipped redirect is the log\'s to answer for: nothing else records it.'
		);
	}

	/**
	 * A conditional match type still has one literal source, and the answer for
	 * that path genuinely changed for some visitors.
	 */
	public function test_a_non_url_match_type_with_a_literal_source_is_revalidated() {
		$this->configure_site();

		$this->create_redirect( [
			'url'         => '/seen-by-one-browser',
			'match_type'  => 'agent',
			'action_data' => [ 'agent' => 'Firefox', 'url_from' => '/somewhere-else/' ],
		] );

		$this->assertQueueRevalidates( [ '/seen-by-one-browser/' ] );
	}

	// Deleting, disabling, enabling
	// ====

	public function test_deleting_a_redirect_revalidates_its_source_path() {
		$redirect = $this->arrange_redirect( [ 'url' => '/deleted' ] );

		$this->configure_site();
		$redirect->delete();

		$this->assertQueueRevalidates( [ '/deleted/' ] );
	}

	public function test_disabling_a_redirect_revalidates_its_source_path() {
		$redirect = $this->arrange_redirect( [ 'url' => '/switched-off' ] );

		$this->configure_site();
		$redirect->disable();

		$this->assertQueueRevalidates(
			[ '/switched-off/' ],
			'Disabling is an off switch, not a delayed one: the redirect reads as disabled by the time we hear about it.'
		);
	}

	public function test_enabling_a_redirect_revalidates_its_source_path() {
		$redirect = $this->arrange_redirect( [ 'url' => '/switched-on', 'status' => 'disabled' ] );

		$this->configure_site();
		$redirect->enable();

		$this->assertQueueRevalidates( [ '/switched-on/' ] );
	}

	// Updating
	// ====

	public function test_updating_a_redirect_revalidates_its_source_path() {
		$redirect = $this->arrange_redirect( [ 'url' => '/moved-target' ] );

		$this->configure_site();
		$redirect->update( $this->redirect_details( [
			'url'         => '/moved-target',
			'action_data' => [ 'url' => '/the-new-destination/' ],
		] ) );

		$this->assertQueueRevalidates( [ '/moved-target/' ] );
	}

	/**
	 * Changing a source leaves two paths stale: the one that should stop
	 * redirecting and the one that should start.
	 *
	 * The previous state is what knows the first of them, and it only ever
	 * reaches us through the action's first argument — which carries it on some
	 * versions of Redirection and the redirect's id on others. The action is
	 * fired here with the previous state, which is the case that has something
	 * to prove; the version that passes an id is the case above.
	 */
	public function test_an_update_carrying_the_previous_state_revalidates_the_old_source_too() {
		$redirect = $this->arrange_redirect( [ 'url' => '/the-old-source' ] );
		$previous = Red_Item::get_by_id( $redirect->get_id() );

		$redirect->update( $this->redirect_details( [ 'url' => '/the-new-source' ] ) );

		$this->configure_site();
		do_action( 'redirection_redirect_updated', $previous, Red_Item::get_by_id( $redirect->get_id() ) );

		$this->assertQueueRevalidates( [ '/the-old-source/', '/the-new-source/' ] );
	}

	// The source path
	// ====

	public function test_a_source_stored_with_a_query_string_revalidates_only_its_path() {
		$this->configure_site();

		$this->redirect_updated( $this->redirect_row( [ 'url' => '/a-page?ref=newsletter' ] ) );

		$this->assertQueueRevalidates( [ '/a-page/' ] );
	}

	public function test_a_source_stored_with_a_domain_revalidates_only_its_path() {
		$this->configure_site();

		$this->redirect_updated( $this->redirect_row( [ 'url' => 'https://an-old-domain.test/a-page/' ] ) );

		$this->assertQueueRevalidates( [ '/a-page/' ] );
	}

	public function test_a_source_that_names_no_path_revalidates_nothing() {
		$this->configure_site();

		$this->redirect_updated( $this->redirect_row( [ 'url' => '/' ] ) );
		$this->redirect_updated( $this->redirect_row( [ 'url' => '' ] ) );

		$this->assertQueueIsEmpty();
	}

	/**
	 * The site's own convention, whichever way it goes: the front-end keys on
	 * the exact path string, so a source has to arrive in the form the rest of
	 * the queue already holds.
	 */
	public function test_the_source_path_follows_a_site_whose_permalinks_carry_no_trailing_slash() {
		$this->set_permalink_structure( '/%postname%' );
		$this->configure_site();

		$this->create_redirect( [ 'url' => '/about-us/' ] );

		$this->assertQueueRevalidates( [ '/about-us' ] );
	}

	/**
	 * A source names a path from the *domain* root: Redirection matches its
	 * redirects against the request uri, and its monitor stores the path
	 * component of a post's permalink, so a site served from a subdirectory
	 * has that directory in every source it holds. The permalink the queue is
	 * handed names it once — a second helping would revalidate a path the
	 * front-end has nothing behind, and under ADR 0004 nothing retries it.
	 */
	public function test_a_site_served_from_a_subdirectory_names_that_directory_once() {
		$origin = home_url();

		// Served from a directory for the length of this test only. Filtered
		// rather than stored, because a test that enqueues anything commits the
		// transaction its options would otherwise roll back — see
		// `QueueTestCase::tear_down()` — while a filter is taken back down
		// whatever the test does.
		add_filter(
			'pre_option_home',
			function () use ( $origin ) {
				return $origin . '/blog';
			}
		);

		$this->configure_site();

		$this->create_redirect( [ 'url' => '/blog/about-us' ] );

		$this->assertQueueHolds( [ $origin . '/blog/about-us/' ] );
	}

	// Bulk operations
	// ====

	/**
	 * A bulk operation fires the per redirect actions in a loop. It is absorbed
	 * rather than capped — but the queue describes work to be done, so a path
	 * several redirects share costs one entry.
	 */
	public function test_a_bulk_operation_costs_one_revalidation_per_distinct_source_path() {
		$redirects = [];
		foreach ( range( 1, 12 ) as $i ) {
			$redirects[] = $this->arrange_redirect( [
				'url'        => 0 === $i % 2 ? '/campaign' : '/campaign-legacy',
				'match_type' => 0 === $i % 3 ? 'agent' : 'url',
			] );
		}

		$this->configure_site();
		foreach ( $redirects as $redirect ) {
			$redirect->delete();
		}

		$this->assertQueueRevalidates( [ '/campaign-legacy/', '/campaign/' ] );
	}

	// The site has the last word
	// ====

	public function test_the_filter_can_decline_a_redirect_revalidation() {
		$this->configure_site();

		add_filter(
			'nextjs_revalidate_should_revalidate_redirect',
			function ( $should_revalidate, $path ) {
				return '/handled-by-the-front-end/' === $path ? false : $should_revalidate;
			},
			10,
			2
		);

		$this->create_redirect( [ 'url' => '/handled-by-the-front-end' ] );
		$this->create_redirect( [ 'url' => '/an-ordinary-redirect' ] );

		$this->assertQueueRevalidates( [ '/an-ordinary-redirect/' ] );
	}

	// Fixtures
	// ====

	/**
	 * The details Redirection needs to store a redirect, with anything the test
	 * cares about on top.
	 *
	 * @param array $details What this redirect is about.
	 * @return array
	 */
	private function redirect_details( array $details = [] ) {
		return array_merge(
			[
				'url'         => '/a-source-path',
				'match_type'  => 'url',
				'action_type' => 'url',
				'action_data' => [ 'url' => '/a-destination/' ],
				'group_id'    => $this->group_id,
			],
			$details
		);
	}

	/**
	 * Create a redirect through Redirection's own API, which fires its own
	 * create action on the way.
	 *
	 * @param array $details What this redirect is about.
	 * @return Red_Item
	 */
	private function create_redirect( array $details = [] ) {
		$redirect = Red_Item::create( $this->redirect_details( $details ) );

		$this->assertNotWPError( $redirect, 'The fixture redirect could not be created.' );

		return $redirect;
	}

	/**
	 * A redirect that exists before the test's event, and that enqueued nothing
	 * on its way in.
	 *
	 * An unconfigured site refuses every revalidation, which is what leaves the
	 * queue empty for the event the test is actually about — creating the
	 * fixture *after* configuring the site would enqueue its source too, and
	 * the queue holds one entry per path either way, so the assertion could not
	 * tell the two apart.
	 *
	 * @param array $details What this redirect is about.
	 * @return Red_Item
	 */
	private function arrange_redirect( array $details = [] ) {
		$this->unconfigure_site();

		return $this->create_redirect( $details );
	}

	/**
	 * A redirect as Redirection would have read it back from its table, without
	 * the sanitising its API does on the way in.
	 *
	 * How a source is *stored* is the point of the tests that use this: a row
	 * can hold a query string or a domain, whether it was written by an import,
	 * by an older version of Redirection, or by hand.
	 *
	 * @param array $values The stored values.
	 * @return Red_Item
	 */
	private function redirect_row( array $values = [] ) {
		return new Red_Item( array_merge(
			[
				'id'          => 1,
				'url'         => '/a-source-path',
				'regex'       => 0,
				'status'      => 'enabled',
				'match_type'  => 'url',
				'action_type' => 'url',
				'action_code' => 301,
				'group_id'    => $this->group_id,
			],
			$values
		) );
	}

	/**
	 * Fire the action Redirection fires when a redirect is created or updated,
	 * as its create call site does: the redirect's id, then the redirect.
	 *
	 * @param Red_Item $redirect The redirect as it now is.
	 * @return void
	 */
	private function redirect_updated( $redirect ) {
		do_action( 'redirection_redirect_updated', $redirect->get_id(), $redirect );
	}

	/**
	 * Empty Redirection's redirect table.
	 *
	 * Written as a query rather than through Redirection's API on purpose:
	 * deleting a redirect through the API fires the action this file is about,
	 * so a teardown that used it would enqueue the very paths the next test
	 * asserts on. The groups are left where they are — they are not what a test
	 * arranges, and the tables outlive the class.
	 *
	 * @return void
	 */
	private function reset_redirects() {
		global $wpdb;

		if ( ! class_exists( Red_Item::class ) ) return;

		$wpdb->query( "TRUNCATE TABLE `{$wpdb->prefix}redirection_items`" );
	}

	// The log
	// ====

	/**
	 * Switch the plugin's logging on, which is the only way a skipped redirect
	 * leaves any record at all.
	 *
	 * @return void
	 */
	private function enable_logs() {
		update_option( Settings::SETTINGS_DEBUG, [ 'enable-logs' => 'on' ] );
	}

	/**
	 * Everything the plugin has logged on this site.
	 *
	 * @return string
	 */
	private function log() {
		$log_file = $this->log_file();

		return file_exists( $log_file ) ? (string) file_get_contents( $log_file ) : '';
	}

	/**
	 * The log file is on disk rather than in the database, so no rollback
	 * reaches it: it is removed on both sides of a test by hand.
	 *
	 * @return void
	 */
	private function reset_log() {
		$log_file = $this->log_file();

		if ( file_exists( $log_file ) ) unlink( $log_file );

		delete_option( Settings::SETTINGS_DEBUG );
	}

	/**
	 * @return string
	 */
	private function log_file() {
		$uploads = wp_upload_dir();

		return trailingslashit( $uploads['basedir'] ) . Logger::FILENAME;
	}
}
