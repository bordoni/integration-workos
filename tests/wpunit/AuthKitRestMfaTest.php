<?php
/**
 * Tests for the REST Auth MFA endpoints.
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
use WorkOS\REST\Auth\Mfa;

/**
 * REST dispatch coverage for /auth/mfa/*.
 */
class AuthKitRestMfaTest extends WPTestCase {

	/**
	 * Repository.
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
	 * Mocked response map.
	 *
	 * @var array<string, array>
	 */
	private array $responses = [];

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

		$this->repository->save(
			Profile::from_array(
				[
					'slug'    => 'mfa-on',
					'title'   => 'MFA On',
					'methods' => [ Profile::METHOD_PASSWORD ],
					'mfa'     => [
						'enforce' => Profile::MFA_ENFORCE_IF_REQUIRED,
						'factors' => [ Profile::FACTOR_TOTP, Profile::FACTOR_SMS ],
					],
				]
			)
		);

		$this->nonce = new Nonce();

		$mfa = new Mfa(
			$this->repository,
			$this->nonce,
			new Radar(),
			new RateLimiter(),
			new LoginCompleter( false )
		);
		add_action( 'rest_api_init', [ $mfa, 'register_routes' ] );

		$server = rest_get_server();
		do_action( 'rest_api_init', $server );

		add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );

		wp_set_current_user( 0 );
	}

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

	private function dispatch_with_nonce( string $method, string $route, array $body, string $profile_slug ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', $this->nonce->mint( $profile_slug ) );
		$request->set_body( wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $request );
	}

	// --------------------------- /auth/mfa/challenge ---------------------------

	public function test_challenge_requires_factor_id(): void {
		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/mfa/challenge',
			[ 'profile' => 'mfa-on' ],
			'mfa-on'
		);

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_challenge_returns_challenge_id_on_success(): void {
		$this->queue_response(
			'/user_management/auth_factors/',
			[ 'id' => 'challenge_abc', 'expires_at' => '2030-01-01T00:00:00Z' ]
		);

		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/mfa/challenge',
			[ 'profile' => 'mfa-on', 'factor_id' => 'factor_123' ],
			'mfa-on'
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'challenge_abc', $response->get_data()['challenge_id'] );
	}

	// --------------------------- /auth/mfa/verify ---------------------------

	public function test_verify_requires_all_inputs(): void {
		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/mfa/verify',
			[ 'profile' => 'mfa-on', 'code' => '111111' ],
			'mfa-on'
		);

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_verify_completes_login_on_success(): void {
		$this->queue_response(
			'/user_management/authenticate',
			[
				'access_token'  => 'eyJhbGciOi.eyJzdWIiOiJ1c2VyX21mYSIsImV4cCI6OTk5OTk5OTk5OX0.sig',
				'refresh_token' => 'rt_mfa',
				'user'          => [ 'id' => 'user_mfa', 'email' => 'mfa@example.com' ],
			]
		);

		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/mfa/verify',
			[
				'profile'                       => 'mfa-on',
				'pending_authentication_token'  => 'pat_1',
				'authentication_challenge_id'   => 'chal_1',
				'code'                          => '123456',
			],
			'mfa-on'
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'mfa@example.com', $response->get_data()['user']['email'] );

		// Confirm we used the TOTP grant type.
		$auth_calls = array_filter(
			$this->captured,
			static fn( array $c ) => str_contains( $c['url'], '/user_management/authenticate' )
		);
		$this->assertNotEmpty( $auth_calls );
		$body = json_decode( (string) array_values( $auth_calls )[0]['body'], true );
		$this->assertSame( 'urn:workos:oauth:grant-type:mfa-totp', $body['grant_type'] );
	}

	// --------------------------- /auth/mfa/factors ---------------------------

	public function test_list_factors_rejects_unauthenticated(): void {
		$response = rest_get_server()->dispatch(
			new \WP_REST_Request( 'GET', '/workos/v1/auth/mfa/factors' )
		);

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_list_factors_returns_user_factors(): void {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, '_workos_user_id', 'user_mfa_1' );
		wp_set_current_user( $user_id );

		$this->queue_response(
			'/user_management/users/user_mfa_1/auth_factors',
			[
				'data' => [
					[ 'id' => 'factor_1', 'type' => 'totp', 'created_at' => 'x' ],
					[ 'id' => 'factor_2', 'type' => 'sms', 'created_at' => 'y' ],
				],
			]
		);

		$response = rest_get_server()->dispatch(
			new \WP_REST_Request( 'GET', '/workos/v1/auth/mfa/factors' )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 2, $response->get_data()['factors'] );
	}

	// --------------------------- /auth/mfa/totp/enroll ---------------------------

	public function test_totp_enroll_requires_logged_in_user(): void {
		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/mfa/totp/enroll',
			[ 'profile' => 'mfa-on' ],
			'mfa-on'
		);

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_totp_enroll_returns_qr_and_secret(): void {
		$user_id = self::factory()->user->create( [ 'user_email' => 'alice@example.com' ] );
		update_user_meta( $user_id, '_workos_user_id', 'user_mfa_2' );
		wp_set_current_user( $user_id );

		$this->queue_response(
			'/user_management/users/user_mfa_2/auth_factors',
			[
				'id'   => 'factor_totp',
				'totp' => [
					'qr_code' => 'data:image/png;base64,AAA',
					'secret'  => 'SECRETBASE32',
					'uri'     => 'otpauth://totp/Acme:alice@example.com?secret=SECRETBASE32',
				],
			],
			201
		);

		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/mfa/totp/enroll',
			[ 'profile' => 'mfa-on' ],
			'mfa-on'
		);

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'factor_totp', $data['factor_id'] );
		$this->assertSame( 'SECRETBASE32', $data['secret'] );
		$this->assertStringStartsWith( 'otpauth://', $data['otpauth_uri'] );
	}

	// --------------------------- /auth/mfa/sms/enroll ---------------------------

	public function test_sms_enroll_requires_phone_number(): void {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, '_workos_user_id', 'user_sms' );
		wp_set_current_user( $user_id );

		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/mfa/sms/enroll',
			[ 'profile' => 'mfa-on' ],
			'mfa-on'
		);

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_sms_enroll_persists_factor(): void {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, '_workos_user_id', 'user_sms' );
		wp_set_current_user( $user_id );

		$this->queue_response(
			'/user_management/users/user_sms/auth_factors',
			[ 'id' => 'factor_sms', 'type' => 'sms' ],
			201
		);

		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/mfa/sms/enroll',
			[ 'profile' => 'mfa-on', 'phone_number' => '+15551234567' ],
			'mfa-on'
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'factor_sms', $response->get_data()['factor_id'] );
	}

	// --------------------------- /auth/mfa/factor/delete ---------------------------

	public function test_delete_factor_removes_owned_factor(): void {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, '_workos_user_id', 'user_del' );
		wp_set_current_user( $user_id );

		// list_auth_factors confirms ownership.
		$this->queue_response(
			'/user_management/users/user_del/auth_factors',
			[
				'data' => [
					[ 'id' => 'factor_x', 'type' => 'totp' ],
				],
			]
		);
		$this->queue_response( '/user_management/auth_factors/factor_x', [] );

		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/mfa/factor/delete',
			[ 'profile' => 'mfa-on', 'factor_id' => 'factor_x' ],
			'mfa-on'
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['deleted'] );

		$delete_calls = array_filter(
			$this->captured,
			static fn( array $c ) => $c['method'] === 'DELETE' && str_contains( $c['url'], 'factor_x' )
		);
		$this->assertNotEmpty( $delete_calls );
	}

	public function test_delete_factor_rejects_other_users_factor(): void {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, '_workos_user_id', 'user_attacker' );
		wp_set_current_user( $user_id );

		// Attacker's own factor list — does NOT include factor_victim.
		$this->queue_response(
			'/user_management/users/user_attacker/auth_factors',
			[
				'data' => [
					[ 'id' => 'factor_attacker_own', 'type' => 'totp' ],
				],
			]
		);

		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/mfa/factor/delete',
			[ 'profile' => 'mfa-on', 'factor_id' => 'factor_victim' ],
			'mfa-on'
		);

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'workos_authkit_factor_not_found', $response->get_data()['code'] );

		// Critically: no DELETE went to WorkOS for the victim's factor.
		$delete_calls = array_filter(
			$this->captured,
			static fn( array $c ) => $c['method'] === 'DELETE' && str_contains( $c['url'], 'factor_victim' )
		);
		$this->assertEmpty( $delete_calls );
	}

	public function test_delete_factor_rejects_user_without_workos_link(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/mfa/factor/delete',
			[ 'profile' => 'mfa-on', 'factor_id' => 'factor_x' ],
			'mfa-on'
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'workos_authkit_no_workos_user', $response->get_data()['code'] );
	}
}
