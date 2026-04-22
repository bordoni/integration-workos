<?php
/**
 * Tests for the REST Auth signup, invitation, and oauth endpoints.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Auth\AuthKit\LoginCompleter;
use WorkOS\Auth\AuthKit\Nonce;
use WorkOS\Auth\AuthKit\Profile;
use WP_REST_Request;
use WP_REST_Response;
use WorkOS\Auth\AuthKit\ProfileRepository;
use WorkOS\Auth\AuthKit\Radar;
use WorkOS\Auth\AuthKit\RateLimiter;
use WorkOS\REST\Auth\Invitation;
use WorkOS\REST\Auth\OAuth;
use WorkOS\REST\Auth\Signup;

/**
 * REST dispatch coverage for /auth/{signup,invitation,oauth}/*.
 */
class AuthKitRestSignupInvitationOAuthTest extends WPTestCase {

	/**
	 * Profile repository.
	 *
	 * @var ProfileRepository
	 */
	private ProfileRepository $repository;

	/**
	 * Nonce helper.
	 *
	 * @var Nonce
	 */
	private Nonce $nonce;

	/**
	 * Captured HTTP.
	 *
	 * @var array
	 */
	private array $captured = [];

	/**
	 * Next mocked HTTP response. Keyed by URL substring for route-based mocking.
	 *
	 * @var array<string, array>
	 */
	private array $responses = [];

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();

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

		$this->repository = new ProfileRepository();
		$this->repository->register_post_type();
		foreach ( $this->repository->all() as $profile ) {
			wp_delete_post( $profile->get_id(), true );
		}

		// A profile with signup + invite + oauth-google enabled.
		$this->repository->save(
			Profile::from_array(
				[
					'slug'        => 'open',
					'title'       => 'Open',
					'methods'     => [ Profile::METHOD_PASSWORD, Profile::METHOD_OAUTH_GOOGLE ],
					'signup'      => [ 'enabled' => true, 'require_invite' => false ],
					'invite_flow' => true,
				]
			)
		);

		// A profile that requires invite-only signup.
		$this->repository->save(
			Profile::from_array(
				[
					'slug'            => 'invite-only',
					'title'           => 'Invite Only',
					'methods'         => [ Profile::METHOD_PASSWORD ],
					'organization_id' => 'org_01ABC',
					'signup'          => [ 'enabled' => true, 'require_invite' => true ],
					'invite_flow'     => true,
				]
			)
		);

		$this->nonce = new Nonce();

		$radar        = new Radar();
		$rate_limiter = new RateLimiter();
		$completer    = new LoginCompleter( false );

		$signup     = new Signup( $this->repository, $this->nonce, $radar, $rate_limiter, $completer );
		$invitation = new Invitation( $this->repository, $this->nonce, $radar, $rate_limiter, $completer );
		$oauth      = new OAuth( $this->repository, $this->nonce, $radar, $rate_limiter, $completer );

		add_action( 'rest_api_init', [ $signup, 'register_routes' ] );
		add_action( 'rest_api_init', [ $invitation, 'register_routes' ] );
		add_action( 'rest_api_init', [ $oauth, 'register_routes' ] );

		$server = rest_get_server();
		do_action( 'rest_api_init', $server );

		add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );

		wp_set_current_user( 0 );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10 );
		$this->captured  = [];
		$this->responses = [];

		foreach ( $this->repository->all() as $profile ) {
			wp_delete_post( $profile->get_id(), true );
		}

		wp_set_current_user( 0 );
		delete_option( 'workos_production' );
		\WorkOS\Config::set_active_environment( 'staging' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		parent::tearDown();
	}

	/**
	 * Intercept HTTP and route-match via URL substrings.
	 */
	public function intercept_http( $preempt, array $args, string $url ): array {
		$this->captured[] = [
			'url'     => $url,
			'method'  => $args['method'] ?? 'GET',
			'body'    => $args['body'] ?? '',
			'headers' => $args['headers'] ?? [],
		];

		foreach ( $this->responses as $needle => $resp ) {
			if ( str_contains( $url, $needle ) ) {
				return $resp;
			}
		}

		return [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'body'     => '{}',
		];
	}

	private function queue_response( string $url_needle, array $body, int $code = 200 ): void {
		$this->responses[ $url_needle ] = [
			'response' => [ 'code' => $code, 'message' => 'OK' ],
			'body'     => wp_json_encode( $body ),
		];
	}

	private function dispatch_with_nonce( string $method, string $route, array $body, string $profile_slug ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', $this->nonce->mint( $profile_slug ) );
		$request->set_body( wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $request );
	}

	// --------------------------- Signup ---------------------------

	public function test_signup_creates_user_and_triggers_verification(): void {
		$this->queue_response(
			'/user_management/users',
			[
				'id'             => 'user_new',
				'email'          => 'new@example.com',
				'email_verified' => false,
			]
		);

		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/signup/create',
			[ 'profile' => 'open', 'email' => 'new@example.com', 'password' => 'hunter2' ],
			'open'
		);

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'user_new', $data['user']['id'] );
		$this->assertTrue( $data['verification_needed'] );

		// A verification send call was made.
		$verify_sends = array_filter(
			$this->captured,
			static fn( array $c ) => str_contains( $c['url'], '/email_verification/send' )
		);
		$this->assertNotEmpty( $verify_sends );
	}

	public function test_signup_rejects_when_profile_requires_invite(): void {
		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/signup/create',
			[ 'profile' => 'invite-only', 'email' => 'new@example.com', 'password' => 'hunter2' ],
			'invite-only'
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'workos_authkit_invitation_required', $response->get_data()['code'] );
	}

	public function test_signup_verify_requires_code(): void {
		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/signup/verify',
			[ 'profile' => 'open', 'user_id' => 'user_1' ],
			'open'
		);

		$this->assertSame( 400, $response->get_status() );
	}

	// --------------------------- Invitation ---------------------------

	public function test_invitation_lookup_returns_context(): void {
		$this->queue_response(
			'/user_management/invitations/by_token/',
			[
				'id'              => 'inv_1',
				'email'           => 'invitee@example.com',
				'organization_id' => 'org_01ABC',
				'state'           => 'pending',
				'expires_at'      => '2030-01-01T00:00:00Z',
			]
		);

		$response = rest_get_server()->dispatch(
			new WP_REST_Request( 'GET', '/workos/v1/auth/invitation/ABC123TOKEN' )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'invitee@example.com', $response->get_data()['email'] );
		$this->assertSame( 'org_01ABC', $response->get_data()['organization_id'] );
	}

	public function test_invitation_accept_requires_token_and_password(): void {
		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/invitation/accept',
			[
				'profile'          => 'invite-only',
				'invitation_token' => '',
				'password'         => '',
			],
			'invite-only'
		);

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_invitation_accept_posts_invitation_grant(): void {
		$this->queue_response(
			'/user_management/authenticate',
			[
				'access_token'  => 'eyJhbGciOi.eyJzdWIiOiJ1c2VyX2ludiIsImV4cCI6OTk5OTk5OTk5OX0.sig',
				'refresh_token' => 'rt_inv',
				'user'          => [
					'id'             => 'user_inv',
					'email'          => 'invitee@example.com',
					'email_verified' => true,
				],
			]
		);

		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/invitation/accept',
			[
				'profile'          => 'invite-only',
				'invitation_token' => 'ABC123',
				'password'         => 'strongpass',
			],
			'invite-only'
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'invitee@example.com', $response->get_data()['user']['email'] );

		// The critical security guarantee: exactly ONE authenticate call
		// went out, using the invitation_token grant type, with NO
		// caller-supplied email in the body. WorkOS matches the invitation
		// to its bound email server-side — an attacker cannot substitute
		// an arbitrary recipient.
		//
		// (A separate /user_management/users POST may appear as a side
		//  effect of UserSync's push-to-WorkOS sync for a brand-new WP
		//  user, which uses the authoritative WorkOS user data returned by
		//  the authenticate call — not attacker input. That path is not
		//  the takeover vector this test guards.)
		$auth_calls = array_filter(
			$this->captured,
			static fn( array $c ) => str_contains( $c['url'], '/user_management/authenticate' )
		);
		$this->assertCount( 1, $auth_calls );

		$body = json_decode( (string) array_values( $auth_calls )[0]['body'], true );
		$this->assertSame( 'urn:workos:oauth:grant-type:invitation_token', $body['grant_type'] );
		$this->assertSame( 'ABC123', $body['invitation_token'] );
		$this->assertSame( 'strongpass', $body['password'] );
		$this->assertArrayNotHasKey( 'email', $body, 'Caller-supplied email must never be forwarded.' );
		$this->assertArrayNotHasKey( 'email_verified', $body, 'Caller cannot force email_verified.' );
	}

	public function test_invitation_accept_surfaces_workos_rejection(): void {
		$this->queue_response(
			'/user_management/authenticate',
			[ 'message' => 'Invitation is no longer valid.' ],
			400
		);

		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/invitation/accept',
			[
				'profile'          => 'invite-only',
				'invitation_token' => 'consumed_token',
				'password'         => 'pass',
			],
			'invite-only'
		);

		$this->assertSame( 400, $response->get_status() );
	}

	// --------------------------- OAuth ---------------------------

	public function test_oauth_authorize_url_builds_google_url(): void {
		$request = new WP_REST_Request( 'GET', '/workos/v1/auth/oauth/authorize-url' );
		$request->set_param( 'profile', 'open' );
		$request->set_param( 'provider', Profile::METHOD_OAUTH_GOOGLE );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertStringContainsString( 'provider=GoogleOAuth', $data['authorize_url'] );
		$this->assertStringContainsString( 'redirect_uri=', $data['authorize_url'] );
		$this->assertStringContainsString( 'state=', $data['authorize_url'] );
	}

	public function test_oauth_authorize_url_rejects_disabled_provider(): void {
		$request = new WP_REST_Request( 'GET', '/workos/v1/auth/oauth/authorize-url' );
		$request->set_param( 'profile', 'open' );
		$request->set_param( 'provider', Profile::METHOD_OAUTH_MICROSOFT );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'workos_authkit_method_disabled', $response->get_data()['code'] );
	}

	public function test_oauth_authorize_url_rejects_unknown_provider(): void {
		$request = new WP_REST_Request( 'GET', '/workos/v1/auth/oauth/authorize-url' );
		$request->set_param( 'profile', 'open' );
		$request->set_param( 'provider', 'oauth_nope' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'workos_authkit_unknown_provider', $response->get_data()['code'] );
	}

	public function test_oauth_authorize_url_includes_organization_id_when_pinned(): void {
		// Add oauth-google to the invite-only profile which has an org pinned.
		$invite_only                = $this->repository->find_by_slug( 'invite-only' );
		$this->assertInstanceOf( Profile::class, $invite_only );
		$data            = $invite_only->to_array();
		$data['methods'] = [ Profile::METHOD_OAUTH_GOOGLE ];
		$this->repository->save( Profile::from_array( $data ) );

		$request = new WP_REST_Request( 'GET', '/workos/v1/auth/oauth/authorize-url' );
		$request->set_param( 'profile', 'invite-only' );
		$request->set_param( 'provider', Profile::METHOD_OAUTH_GOOGLE );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'organization_id=org_01ABC', $response->get_data()['authorize_url'] );
	}
}
