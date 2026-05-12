<?php
/**
 * Tests for LoginCompleter recovery from WorkOS `organization_selection_required` errors.
 *
 * Covers the three branches added in fix(auth): recover from
 * organization_selection_required via pinned org —
 *
 *  - pinned org appears in WorkOS's candidate list → silently retry via
 *    the `organization-selection` grant;
 *  - pinned org is missing from the candidate list but a matching local WP
 *    user already exists → self-heal by creating the WorkOS membership,
 *    then retry;
 *  - no pinned org / no matching user → bail with a distinct WP_Error.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Auth\AuthKit\LoginCompleter;
use WorkOS\Auth\AuthKit\Profile;
use WP_Error;

/**
 * @covers \WorkOS\Auth\AuthKit\LoginCompleter
 */
class AuthKitLoginCompleterOrgSelectionTest extends WPTestCase {

	/**
	 * Completer under test.
	 *
	 * @var LoginCompleter
	 */
	private LoginCompleter $completer;

	/**
	 * Captured outbound HTTP requests, in order.
	 *
	 * @var array<int, array{url:string,method:string,body:string,headers:array}>
	 */
	private array $captured = [];

	/**
	 * Queue of responses to return from `pre_http_request`, in order.
	 *
	 * Each entry is a WP HTTP response array with `response.code` and `body`.
	 * The last queued entry is reused if more requests are made than queued.
	 *
	 * @var array<int, array>
	 */
	private array $responses = [];

	public function setUp(): void {
		parent::setUp();

		// Configure the production env so workos()->api() builds a Client
		// with real-looking credentials. Reset the lazy Options singleton
		// so it re-reads the option we just set.
		\WorkOS\Config::set_active_environment( 'production' );
		update_option(
			'workos_production',
			[
				'api_key'        => 'sk_test_fake',
				'client_id'      => 'client_fake',
				'environment_id' => 'environment_test',
			]
		);
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$this->completer = new LoginCompleter();
		$this->captured  = [];
		$this->responses = [];

		add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );
	}

	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10 );
		$this->captured  = [];
		$this->responses = [];

		wp_set_current_user( 0 );
		delete_option( 'workos_production' );
		\WorkOS\Config::set_active_environment( 'staging' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		parent::tearDown();
	}

	/**
	 * Intercept any outbound HTTP and return queued responses in order.
	 */
	public function intercept_http( $preempt, array $args, string $url ): array {
		$this->captured[] = [
			'url'     => $url,
			'method'  => $args['method'] ?? 'GET',
			'body'    => $args['body'] ?? '',
			'headers' => $args['headers'] ?? [],
		];

		if ( ! empty( $this->responses ) ) {
			return count( $this->responses ) > 1 ? array_shift( $this->responses ) : $this->responses[0];
		}

		return [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'body'     => '{}',
		];
	}

	private function queue_response( int $status, array $body ): void {
		$this->responses[] = [
			'response' => [ 'code' => $status, 'message' => 'OK' ],
			'body'     => wp_json_encode( $body ),
		];
	}

	private function org_selection_error( array $body = [] ): WP_Error {
		$payload = array_merge(
			[
				'code'                         => 'organization_selection_required',
				'message'                      => 'The user must choose an organization to finish their authentication.',
				'pending_authentication_token' => 'pat_org_sel',
				'email'                        => 'legacy@example.com',
				'organizations'                => [],
			],
			$body
		);

		return new WP_Error(
			'workos_api_error',
			(string) $payload['message'],
			[
				'status' => 422,
				'body'   => $payload,
			]
		);
	}

	private function profile_with_org( string $org_id ): Profile {
		return Profile::from_array(
			[
				'slug'            => 'staff',
				'title'           => 'Staff',
				'organization_id' => $org_id,
			]
		);
	}

	/**
	 * Captured requests filtered to those hitting `/user_management/authenticate`.
	 *
	 * @return array<int, array>
	 */
	private function authenticate_requests(): array {
		return array_values(
			array_filter(
				$this->captured,
				static fn ( array $c ) => str_contains( $c['url'], '/user_management/authenticate' )
			)
		);
	}

	/**
	 * Captured requests filtered to membership creation.
	 *
	 * @return array<int, array>
	 */
	private function membership_requests(): array {
		return array_values(
			array_filter(
				$this->captured,
				static fn ( array $c ) => str_contains( $c['url'], '/user_management/organization_memberships' ) && 'POST' === $c['method']
			)
		);
	}

	// -------------------------------------------------------------------------
	// Branch: pinned org IS in the candidate list — silent retry
	// -------------------------------------------------------------------------

	public function test_resolves_silently_when_pinned_org_in_candidate_list(): void {
		$profile = $this->profile_with_org( 'org_pinned' );

		// Follow-up authenticate succeeds.
		$this->queue_response(
			200,
			[
				'user' => [
					'id'    => 'user_legacy',
					'email' => 'legacy@example.com',
				],
				'access_token'  => 'eyJ.eyJzdWIiOiJ1c2VyX2xlZ2FjeSJ9.sig',
				'refresh_token' => 'rt_legacy',
			]
		);

		$error = $this->org_selection_error(
			[
				'organizations' => [
					[ 'id' => 'org_other',  'name' => 'Other' ],
					[ 'id' => 'org_pinned', 'name' => 'Pinned' ],
				],
			]
		);

		$result = $this->completer->complete( $error, $profile );

		$this->assertIsArray( $result, 'Expected success result, got WP_Error: ' . ( is_wp_error( $result ) ? $result->get_error_code() : '' ) );
		$this->assertSame( 'legacy@example.com', $result['user']['email'] );

		$auth_requests = $this->authenticate_requests();
		$this->assertCount( 1, $auth_requests, 'Exactly one follow-up authenticate call should fire.' );

		$body = json_decode( (string) $auth_requests[0]['body'], true );
		$this->assertSame( 'urn:workos:oauth:grant-type:organization-selection', $body['grant_type'] );
		$this->assertSame( 'pat_org_sel', $body['pending_authentication_token'] );
		$this->assertSame( 'org_pinned', $body['organization_id'] );

		// No membership creation should fire on this path.
		$this->assertSame( [], $this->membership_requests() );
	}

	// -------------------------------------------------------------------------
	// Branch: pinned org missing, WP user exists — self-heal via membership
	// -------------------------------------------------------------------------

	public function test_self_heals_existing_user_when_pinned_org_missing(): void {
		$existing_user_id = wp_insert_user(
			[
				'user_login' => 'legacy_user',
				'user_email' => 'legacy@example.com',
				'user_pass'  => wp_generate_password(),
				'role'       => 'subscriber',
			]
		);
		$this->assertIsInt( $existing_user_id );

		$profile = $this->profile_with_org( 'org_pinned' );

		// Queue: 1) membership creation, 2) authenticate follow-up.
		$this->queue_response(
			201,
			[
				'id'              => 'om_1',
				'user_id'         => 'user_legacy',
				'organization_id' => 'org_pinned',
			]
		);
		$this->queue_response(
			200,
			[
				'user' => [
					'id'    => 'user_legacy',
					'email' => 'legacy@example.com',
				],
				'access_token'  => 'at_legacy',
				'refresh_token' => 'rt_legacy',
			]
		);

		$error = $this->org_selection_error(
			[
				'user_id'       => 'user_legacy',
				'organizations' => [
					[ 'id' => 'org_other', 'name' => 'Other' ],
				],
			]
		);

		$result = $this->completer->complete( $error, $profile );

		$this->assertIsArray( $result, 'Expected success result, got WP_Error: ' . ( is_wp_error( $result ) ? $result->get_error_code() : '' ) );
		$this->assertSame( 'legacy@example.com', $result['user']['email'] );

		$memberships = $this->membership_requests();
		$this->assertCount( 1, $memberships, 'Membership creation should fire exactly once.' );

		$mb_body = json_decode( (string) $memberships[0]['body'], true );
		$this->assertSame( 'user_legacy', $mb_body['user_id'] );
		$this->assertSame( 'org_pinned', $mb_body['organization_id'] );

		$auth_requests = $this->authenticate_requests();
		$this->assertCount( 1, $auth_requests, 'Exactly one follow-up authenticate call should fire after membership creation.' );
	}

	/**
	 * If WorkOS reports the membership already exists (race / replay), we
	 * still proceed to retry the authenticate call.
	 */
	public function test_self_heal_treats_already_exists_as_success(): void {
		$existing_user_id = wp_insert_user(
			[
				'user_login' => 'legacy_user2',
				'user_email' => 'legacy@example.com',
				'user_pass'  => wp_generate_password(),
				'role'       => 'subscriber',
			]
		);
		$this->assertIsInt( $existing_user_id );

		$profile = $this->profile_with_org( 'org_pinned' );

		$this->queue_response(
			409,
			[
				'code'    => 'entity_already_exists',
				'message' => 'A membership already exists.',
			]
		);
		$this->queue_response(
			200,
			[
				'user' => [
					'id'    => 'user_legacy',
					'email' => 'legacy@example.com',
				],
				'access_token'  => 'at_legacy',
				'refresh_token' => 'rt_legacy',
			]
		);

		$error = $this->org_selection_error(
			[
				'user_id'       => 'user_legacy',
				'organizations' => [
					[ 'id' => 'org_other', 'name' => 'Other' ],
				],
			]
		);

		$result = $this->completer->complete( $error, $profile );

		$this->assertIsArray( $result );
		$this->assertSame( 'legacy@example.com', $result['user']['email'] );
	}

	/**
	 * If a local WP user exists but WorkOS didn't include the authenticated
	 * `user_id` in the error body, we refuse rather than guess via an
	 * email lookup that can collide on shared addresses.
	 */
	public function test_refuses_self_heal_when_body_has_no_user_id(): void {
		$existing_user_id = wp_insert_user(
			[
				'user_login' => 'legacy_user_no_uid',
				'user_email' => 'legacy@example.com',
				'user_pass'  => wp_generate_password(),
				'role'       => 'subscriber',
			]
		);
		$this->assertIsInt( $existing_user_id );

		$profile = $this->profile_with_org( 'org_pinned' );

		$error = $this->org_selection_error(
			[
				// no user_id
				'organizations' => [
					[ 'id' => 'org_other', 'name' => 'Other' ],
				],
			]
		);

		$result = $this->completer->complete( $error, $profile );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'workos_authkit_pinned_org_mismatch', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );

		// No membership creation — we refuse before any write.
		$this->assertSame( [], $this->membership_requests() );
		$this->assertSame( [], $this->authenticate_requests() );
	}

	// -------------------------------------------------------------------------
	// Branch: pinned org missing, no matching WP user — refuse to self-heal
	// -------------------------------------------------------------------------

	public function test_refuses_self_heal_when_no_local_user_exists(): void {
		$profile = $this->profile_with_org( 'org_pinned' );

		$error = $this->org_selection_error(
			[
				'email'         => 'stranger@example.com',
				'organizations' => [
					[ 'id' => 'org_other', 'name' => 'Other' ],
				],
			]
		);

		$result = $this->completer->complete( $error, $profile );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'workos_authkit_pinned_org_mismatch', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );

		// No outbound HTTP should happen on this path — we bail before any retry.
		$this->assertSame( [], $this->authenticate_requests() );
		$this->assertSame( [], $this->membership_requests() );
	}

	// -------------------------------------------------------------------------
	// Branch: no org pinned anywhere — distinct error, no picker
	// -------------------------------------------------------------------------

	public function test_returns_no_pinned_org_error_when_nothing_is_pinned(): void {
		$profile = Profile::from_array(
			[
				'slug'  => 'open',
				'title' => 'Open',
			]
		);

		$error  = $this->org_selection_error();
		$result = $this->completer->complete( $error, $profile );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'workos_authkit_no_pinned_org', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( [], $this->authenticate_requests() );
	}

	// -------------------------------------------------------------------------
	// Honor-profile-redirect flag (legacy callback contract)
	// -------------------------------------------------------------------------

	/**
	 * Profile has a post_login_redirect, but the caller (e.g. legacy
	 * /workos/callback) opts out by passing $honor_profile_redirect = false.
	 * The state-supplied redirect_to must win in that case.
	 */
	public function test_honor_profile_redirect_false_keeps_client_redirect(): void {
		$profile = Profile::from_array(
			[
				'slug'                => 'default',
				'title'               => 'Default',
				'post_login_redirect' => '/profile-target/',
			]
		);

		$result = $this->completer->complete(
			[
				'user' => [
					'id'    => 'user_redirect',
					'email' => 'redirect@example.com',
				],
				'access_token'  => 'at_r',
				'refresh_token' => 'rt_r',
			],
			$profile,
			'/state-target/',
			false
		);

		$this->assertIsArray( $result );
		$this->assertStringContainsString( 'state-target', $result['redirect_to'] );
		$this->assertStringNotContainsString( 'profile-target', $result['redirect_to'] );
	}

	/**
	 * Default behavior (and AuthKit-REST callers): profile redirect still
	 * wins.
	 */
	public function test_honor_profile_redirect_default_lets_profile_win(): void {
		$profile = Profile::from_array(
			[
				'slug'                => 'staff',
				'title'               => 'Staff',
				'post_login_redirect' => '/profile-target/',
			]
		);

		$result = $this->completer->complete(
			[
				'user' => [
					'id'    => 'user_redirect2',
					'email' => 'redirect2@example.com',
				],
				'access_token'  => 'at_r',
				'refresh_token' => 'rt_r',
			],
			$profile,
			'/state-target/'
		);

		$this->assertIsArray( $result );
		$this->assertStringContainsString( 'profile-target', $result['redirect_to'] );
	}

	// -------------------------------------------------------------------------
	// Branch: unrelated WorkOS errors bubble through unchanged
	// -------------------------------------------------------------------------

	public function test_unrelated_workos_error_is_returned_unchanged(): void {
		$profile = $this->profile_with_org( 'org_pinned' );

		$error = new WP_Error(
			'workos_api_error',
			'Invalid credentials',
			[
				'status' => 401,
				'body'   => [ 'code' => 'invalid_credentials' ],
			]
		);

		$result = $this->completer->complete( $error, $profile );

		$this->assertSame( $error, $result, 'Non-org-selection errors should pass through untouched.' );
		$this->assertSame( [], $this->authenticate_requests() );
	}
}
