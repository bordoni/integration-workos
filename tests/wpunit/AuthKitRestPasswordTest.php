<?php
/**
 * Tests for the REST Auth password + reset endpoints.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Auth\AuthKit\LoginCompleter;
use WorkOS\Auth\AuthKit\Nonce;
use WorkOS\Auth\AuthKit\Profile;
use WorkOS\Auth\AuthKit\ProfileRepository;
use WorkOS\Auth\AuthKit\Radar;
use WorkOS\Auth\AuthKit\RateLimiter;
use WorkOS\REST\Auth\Password;

/**
 * REST dispatch coverage for /auth/password/*.
 */
class AuthKitRestPasswordTest extends WPTestCase {

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
	 * Profile used in tests.
	 *
	 * @var Profile
	 */
	private Profile $profile;

	/**
	 * Captured HTTP calls.
	 *
	 * @var array
	 */
	private array $captured = [];

	/**
	 * Next WorkOS response body.
	 *
	 * @var array
	 */
	private array $next_workos = [
		'response' => [ 'code' => 200, 'message' => 'OK' ],
		'body'     => '{}',
	];

	/**
	 * Set up a standalone plugin environment + register routes.
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

		// Clean up residual profiles.
		foreach ( $this->repository->all() as $profile ) {
			wp_delete_post( $profile->get_id(), true );
		}

		$saved = $this->repository->save(
			Profile::from_array(
				[
					'slug'                => 'members',
					'title'               => 'Members',
					'methods'             => [ Profile::METHOD_PASSWORD, Profile::METHOD_MAGIC_CODE ],
					'password_reset_flow' => true,
				]
			)
		);
		$this->assertInstanceOf( Profile::class, $saved );
		$this->profile = $saved;

		$this->nonce = new Nonce();

		$password = new Password(
			$this->repository,
			$this->nonce,
			new Radar(),
			new RateLimiter(),
			new LoginCompleter( false )
		);

		// Hook into rest_api_init *before* dispatching so WP accepts the
		// route registration (WP complains otherwise via _doing_it_wrong).
		add_action( 'rest_api_init', [ $password, 'register_routes' ] );
		$server = rest_get_server();
		do_action( 'rest_api_init', $server );

		add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );

		// Ensure no residual user session.
		wp_set_current_user( 0 );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10 );
		$this->captured = [];

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
	 * HTTP interceptor.
	 */
	public function intercept_http( $preempt, array $args, string $url ): array {
		$this->captured[] = [
			'url'     => $url,
			'method'  => $args['method'] ?? 'GET',
			'body'    => $args['body'] ?? '',
			'headers' => $args['headers'] ?? [],
		];
		return $this->next_workos;
	}

	/**
	 * Helper to dispatch with nonce header.
	 */
	private function dispatch_with_nonce( string $method, string $route, array $body = [] ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', $this->nonce->mint( $this->profile->get_slug() ) );
		$request->set_body( wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Missing profile returns 400.
	 */
	public function test_authenticate_requires_profile(): void {
		$request = new \WP_REST_Request( 'POST', '/workos/v1/auth/password/authenticate' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [ 'email' => 'a@b.c', 'password' => 'x' ] ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'workos_authkit_missing_profile', $response->get_data()['code'] );
	}

	/**
	 * Unknown profile returns 404.
	 */
	public function test_authenticate_unknown_profile_returns_404(): void {
		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/password/authenticate',
			[ 'profile' => 'nope', 'email' => 'a@b.c', 'password' => 'x' ]
		);

		// Nonce is profile-scoped, so this also fails nonce verification — but
		// profile resolution runs first and returns 404.
		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * Missing nonce returns 403.
	 */
	public function test_authenticate_rejects_missing_nonce(): void {
		$request = new \WP_REST_Request( 'POST', '/workos/v1/auth/password/authenticate' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				[
					'profile'  => 'members',
					'email'    => 'a@b.c',
					'password' => 'x',
				]
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'workos_authkit_invalid_nonce', $response->get_data()['code'] );
	}

	/**
	 * Method disabled on profile returns 400.
	 */
	public function test_authenticate_respects_method_toggle(): void {
		// Save a profile that only allows magic codes.
		$magic_only = $this->repository->save(
			Profile::from_array(
				[
					'slug'    => 'magic-only',
					'title'   => 'Magic Only',
					'methods' => [ Profile::METHOD_MAGIC_CODE ],
				]
			)
		);
		$this->assertInstanceOf( Profile::class, $magic_only );

		$request = new \WP_REST_Request( 'POST', '/workos/v1/auth/password/authenticate' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', $this->nonce->mint( 'magic-only' ) );
		$request->set_body(
			wp_json_encode(
				[
					'profile'  => 'magic-only',
					'email'    => 'a@b.c',
					'password' => 'x',
				]
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'workos_authkit_method_disabled', $response->get_data()['code'] );
	}

	/**
	 * Happy-path authenticate: calls WorkOS and completes login.
	 */
	public function test_authenticate_success_calls_workos_and_returns_redirect(): void {
		$this->next_workos['body'] = wp_json_encode(
			[
				'access_token'  => 'eyJhbGciOi.eyJzdWIiOiJ1c2VyX3dva28iLCJleHAiOjk5OTk5OTk5OTl9.sig',
				'refresh_token' => 'rt_abc',
				'user'          => [
					'id'             => 'user_woko',
					'email'          => 'alice@example.com',
					'first_name'     => 'Alice',
					'last_name'      => 'Doe',
					'email_verified' => true,
				],
			]
		);

		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/password/authenticate',
			[
				'profile'     => 'members',
				'email'       => 'alice@example.com',
				'password'    => 'hunter2',
				'redirect_to' => admin_url(),
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'user', $data );
		$this->assertSame( 'alice@example.com', $data['user']['email'] );
		$this->assertNotEmpty( $data['redirect_to'] );

		$authenticate_calls = array_filter(
			$this->captured,
			static fn( array $c ) => str_contains( $c['url'], '/user_management/authenticate' )
		);
		$this->assertNotEmpty( $authenticate_calls );
	}

	/**
	 * MFA-required response is surfaced to the client.
	 */
	public function test_authenticate_mfa_required_is_surfaced(): void {
		$this->next_workos['body'] = wp_json_encode(
			[
				'pending_authentication_token' => 'pat_123',
				'authentication_factor'        => [ 'id' => 'factor_1', 'type' => 'totp' ],
			]
		);

		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/password/authenticate',
			[
				'profile'  => 'members',
				'email'    => 'alice@example.com',
				'password' => 'hunter2',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['mfa_required'] );
		$this->assertSame( 'pat_123', $data['pending_authentication_token'] );
		$this->assertCount( 1, $data['factors'] );
	}

	/**
	 * Radar header is forwarded to WorkOS.
	 */
	public function test_authenticate_forwards_radar_header(): void {
		$request = new \WP_REST_Request( 'POST', '/workos/v1/auth/password/authenticate' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', $this->nonce->mint( 'members' ) );
		$request->set_header( 'X-WorkOS-Radar-Action-Token', 'radar_tok' );
		$request->set_body(
			wp_json_encode(
				[
					'profile'  => 'members',
					'email'    => 'alice@example.com',
					'password' => 'hunter2',
				]
			)
		);

		$this->next_workos['body'] = wp_json_encode(
			[
				'access_token'  => 'eyJhbGciOi.eyJzdWIiOiJ1c2VyX3cxIiwiZXhwIjo5OTk5OTk5OTk5fQ.sig',
				'refresh_token' => 'rt_xyz',
				'user'          => [
					'id'             => 'user_w1',
					'email'          => 'alice@example.com',
					'email_verified' => true,
				],
			]
		);

		rest_get_server()->dispatch( $request );

		$authenticate_call = null;
		foreach ( $this->captured as $c ) {
			if ( str_contains( $c['url'], '/user_management/authenticate' ) ) {
				$authenticate_call = $c;
				break;
			}
		}

		$this->assertNotNull( $authenticate_call );
		$this->assertSame( 'radar_tok', $authenticate_call['headers']['x-workos-radar-action-token'] );
	}

	/**
	 * reset_start returns 200 even for unknown email (enumeration-safe).
	 */
	public function test_reset_start_returns_200_regardless_of_email_existence(): void {
		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/password/reset/start',
			[
				'profile' => 'members',
				'email'   => 'ghost@nowhere.test',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['ok'] );

		// A send-password-reset request still went out to WorkOS.
		$reset_calls = array_filter(
			$this->captured,
			static fn( array $c ) => str_contains( $c['url'], '/user_management/password_reset/send' )
		);
		$this->assertNotEmpty( $reset_calls );
	}

	/**
	 * reset_confirm requires a token + new_password.
	 */
	public function test_reset_confirm_requires_token_and_password(): void {
		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/password/reset/confirm',
			[
				'profile' => 'members',
				'token'   => '',
			]
		);

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Rate limiter: 11th attempt from the same IP returns 429.
	 */
	public function test_authenticate_rate_limits_by_ip(): void {
		for ( $i = 0; $i < 10; $i++ ) {
			$this->dispatch_with_nonce(
				'POST',
				'/workos/v1/auth/password/authenticate',
				[
					'profile'  => 'members',
					'email'    => 'alice@example.com',
					'password' => 'hunter2',
				]
			);
		}

		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/password/authenticate',
			[
				'profile'  => 'members',
				'email'    => 'alice@example.com',
				'password' => 'hunter2',
			]
		);

		$this->assertSame( 429, $response->get_status() );
	}
}
