<?php
/**
 * Where the secret goes, as WordPress's own transport is handed it.
 *
 * @package NextJsRevalidate
 */

namespace NextJsRevalidate\Tests;

use NextJsRevalidate\Change;
use NextJsRevalidate\Settings;

/**
 * The headers of a real delivery and a real probe, caught at
 * `pre_http_request` — the last thing `wp_remote_post()` does before handing
 * its arguments to cURL or fsockopen.
 *
 * A front-end behind basic auth has its credentials in the revalidate domain,
 * and a request carries one `Authorization` header. So a domain with
 * credentials sends them there as `Basic`, and the secret in
 * `X-Nextjs-Revalidate-Secret`; a domain without them sends
 * `Authorization: Bearer`, as 2.0 did. Each hop of a followed redirect is
 * worked out from its own URL.
 *
 * See `docs/adr/0042-a-domain-with-credentials-moves-the-secret-to-its-own-header.md`.
 * The header choice and the decoding of the credentials, case by case, are
 * `tests/front-end-redirect-test.php`'s.
 */
class FrontEndRequestTest extends PendingChangesTestCase {

	/**
	 * A revalidate domain with credentials, percent-encoded as they have to be
	 * to hold an `@`.
	 */
	const STAGING_DOMAIN = 'https://runbook:p%40ss@staging.test';

	/**
	 * Every request caught, as `[ url, headers ]`.
	 *
	 * @var array[]
	 */
	private $sent = [];

	/**
	 * What each URL answers. A URL not named here answers 204.
	 *
	 * @var array<string, array>
	 */
	private $answers = [];

	public function set_up() {
		parent::set_up();

		$this->sent    = [];
		$this->answers = [];

		add_filter( 'pre_http_request', [ $this, 'catch_request' ], 10, 3 );
	}

	/**
	 * Record a request and answer it, rather than let it leave.
	 *
	 * @param false|array $preempt
	 * @param array       $args
	 * @param string      $url
	 * @return array
	 */
	public function catch_request( $preempt, $args, $url ) {
		$this->sent[] = [ $url, $args['headers'] ?? [] ];

		return $this->answers[ $url ] ?? [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 204, 'message' => 'No Content' ], 'cookies' => [] ];
	}

	// Without credentials
	// ====

	public function test_a_domain_without_credentials_sends_the_secret_as_a_bearer_token() {
		$this->configure_site();

		$this->deliver_one_change();

		$this->assertCount( 1, $this->sent );
		$this->assertSame( 'Bearer ' . self::FIXTURE_SECRET, $this->sent[0][1]['Authorization'] ?? null );
		$this->assertArrayNotHasKey( 'X-Nextjs-Revalidate-Secret', $this->sent[0][1], 'The secret is sent once, in Authorization.' );
	}

	// With credentials
	// ====

	public function test_a_domain_with_credentials_sends_them_as_basic_auth_and_the_secret_in_its_own_header() {
		$this->configure_staging_site();

		$this->deliver_one_change();

		$this->assertCount( 1, $this->sent );
		$this->assertSame( self::STAGING_DOMAIN . '/api/revalidate', $this->sent[0][0] );
		$this->assertSame( 'Basic ' . base64_encode( 'runbook:p@ss' ), $this->sent[0][1]['Authorization'] ?? null, 'The credentials are sent decoded, as basic auth.' );
		$this->assertSame( self::FIXTURE_SECRET, $this->sent[0][1]['X-Nextjs-Revalidate-Secret'] ?? null, 'The secret is sent bare, in its own header.' );
	}

	public function test_a_probe_of_a_domain_with_credentials_sends_what_a_delivery_sends() {
		$this->configure_staging_site();

		$result = \NextJsRevalidate::init()->probe->send( '/hello-world/' );

		$this->assertSame( 'success', $result['status'] );
		$this->assertCount( 1, $this->sent );
		$this->assertSame( 'Basic ' . base64_encode( 'runbook:p@ss' ), $this->sent[0][1]['Authorization'] ?? null );
		$this->assertSame( self::FIXTURE_SECRET, $this->sent[0][1]['X-Nextjs-Revalidate-Secret'] ?? null );
	}

	/**
	 * The redirect a proxy rewriting `Location` answers with: an absolute URL
	 * on the same origin, without the credentials (#184). They are carried over
	 * to the next hop's URL, and that hop sends them.
	 */
	public function test_a_same_origin_redirect_keeps_basic_auth_on_the_next_hop() {
		$this->configure_staging_site();

		foreach ( [ 307, 308 ] as $status ) {
			$this->sent    = [];
			$this->answers = [ self::STAGING_DOMAIN . '/api/revalidate' => $this->redirect( $status, 'https://staging.test/api/revalidate/' ) ];

			$this->deliver_one_change();

			$this->assertSame(
				[ self::STAGING_DOMAIN . '/api/revalidate', self::STAGING_DOMAIN . '/api/revalidate/' ],
				array_column( $this->sent, 0 ),
				"A $status is followed with the credentials."
			);
			$this->assertSame( 'Basic ' . base64_encode( 'runbook:p@ss' ), $this->sent[1][1]['Authorization'] ?? null, "A $status keeps basic auth on the next hop." );
			$this->assertSame( self::FIXTURE_SECRET, $this->sent[1][1]['X-Nextjs-Revalidate-Secret'] ?? null );
		}
	}

	public function test_a_redirect_naming_its_own_credentials_is_sent_with_them() {
		$this->configure_staging_site();

		$this->answers = [ self::STAGING_DOMAIN . '/api/revalidate' => $this->redirect( 308, 'https://other:word@staging.test/api/revalidate/' ) ];

		$this->deliver_one_change();

		$this->assertCount( 2, $this->sent );
		$this->assertSame( 'Basic ' . base64_encode( 'other:word' ), $this->sent[1][1]['Authorization'] ?? null );
	}

	public function test_a_user_with_no_password_is_sent_as_user_and_a_colon() {
		update_option( Settings::SETTINGS_DOMAIN_NAME, 'https://runbook@staging.test' );
		update_option( Settings::SETTINGS_SECRET_NAME, self::FIXTURE_SECRET );

		$this->deliver_one_change();

		$this->assertSame( 'Basic ' . base64_encode( 'runbook:' ), $this->sent[0][1]['Authorization'] ?? null );
	}

	// Fixtures
	// ====

	/**
	 * A site whose front-end is behind basic auth.
	 *
	 * @return void
	 */
	private function configure_staging_site() {
		update_option( Settings::SETTINGS_DOMAIN_NAME, self::STAGING_DOMAIN );
		update_option( Settings::SETTINGS_SECRET_NAME, self::FIXTURE_SECRET );
	}

	/**
	 * Report one change and deliver it now, as the end of the request would.
	 *
	 * @return void
	 */
	private function deliver_one_change() {
		$this->pending_changes()->report( Change::templates() );
		$this->pending_changes()->deliver();
	}

	/**
	 * A redirect, as `pre_http_request` answers one.
	 *
	 * @param int    $status
	 * @param string $location
	 * @return array
	 */
	private function redirect( $status, $location ) {
		return [ 'headers' => [ 'location' => $location ], 'body' => '', 'response' => [ 'code' => $status, 'message' => '' ], 'cookies' => [] ];
	}
}
