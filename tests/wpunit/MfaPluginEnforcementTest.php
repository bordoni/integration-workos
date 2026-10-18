<?php
/**
 * End-to-end tests for MFA the plugin enforces at sign-in.
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
use WorkOS\Auth\AuthKit\Renderer;
use WorkOS\Email\AddressCanonicalizer;
use WorkOS\Http\ClientIp;
use WorkOS\Mfa\PendingLogin;
use WorkOS\RateLimit\DatabaseRateLimiter;
use WorkOS\RateLimit\TieredRateLimiter;
use WorkOS\REST\Auth\Mfa;
use WorkOS\REST\Auth\Password;
use WorkOS\Sync\UserSync;
use WorkOS\User;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Password sign-in → MFA step → challenge → verify, through the real REST
 * routes, with WorkOS mocked at the HTTP layer.
 */
class MfaPluginEnforcementTest extends WPTestCase {

	/**
	 * Profiles.
	 *
	 * @var ProfileRepository
	 */
	private ProfileRepository $repository;

	/**
	 * Nonce minter.
	 *
	 * @var Nonce
	 */
	private Nonce $nonce;

	/**
	 * Linked WP user.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Captured HTTP calls.
	 *
	 * @var array
	 */
	private array $captured = [];

	/**
	 * Mocked responses keyed by "METHOD url-needle".
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
				'api_key'                 => 'sk_test_fake',
				'client_id'               => 'client_fake',
				'environment_id'          => 'environment_test',
				'allow_password_fallback' => true,
			]
		);
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$this->repository = new ProfileRepository();
		$this->repository->register_post_type();
		foreach ( $this->repository->all() as $profile ) {
			wp_delete_post( $profile->get_id(), true );
		}
		$this->save_profile( 'members', Profile::MFA_ENFORCE_IF_REQUIRED );
		$this->save_profile( 'no-mfa', Profile::MFA_ENFORCE_NEVER );

		$this->nonce = new Nonce();
		$deps        = [
			$this->repository,
			$this->nonce,
			new Radar(),
			new TieredRateLimiter( new DatabaseRateLimiter() ),
			new ClientIp(),
			new AddressCanonicalizer(),
			new LoginCompleter(),
		];
		$password    = new Password( ...$deps );
		$mfa         = new Mfa( ...$deps );
		add_action( 'rest_api_init', [ $password, 'register_routes' ] );
		add_action( 'rest_api_init', [ $mfa, 'register_routes' ] );
		do_action( 'rest_api_init', rest_get_server() );

		add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );

		$this->user_id = (int) wp_create_user( 'mfa_member', 'wp_password_123', 'member@example.com' );
		UserSync::link_user( $this->user_id, [ 'id' => 'user_mfa', 'email' => 'member@example.com' ], 'test' );

		$this->captured = [];
		wp_set_current_user( 0 );
	}

	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10 );
		remove_all_filters( 'workos_mfa_mode' );
		remove_all_filters( 'workos_mfa_allow_password_fallback' );
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

	public function intercept_http( $preempt, array $args, string $url ) {
		if ( ! str_contains( $url, 'api.workos.com' ) ) {
			return $preempt;
		}

		$method           = $args['method'] ?? 'GET';
		$this->captured[] = [
			'method' => $method,
			'url'    => $url,
			'body'   => $args['body'] ?? '',
		];

		foreach ( $this->responses as $key => $resp ) {
			[ $want_method, $needle ] = explode( ' ', $key, 2 );
			if ( $want_method === $method && str_contains( $url, $needle ) ) {
				return $resp;
			}
		}

		return [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'body'     => '{}',
		];
	}

	// ------------------------------- Challenge -------------------------------

	public function test_enrolled_user_gets_mfa_step_instead_of_a_session(): void {
		$this->enroll_user();

		$step = $this->sign_in();

		$this->assertSame( 200, $step->get_status() );
		$data = $step->get_data();
		$this->assertTrue( $data['mfa_required'] );
		$this->assertFalse( $data['enroll'] );
		$this->assertSame( [ [ 'id' => 'auth_factor_1', 'type' => 'totp' ] ], $data['factors'] );
		$this->assertArrayNotHasKey( 'access_token', $data );
		$this->assertSame( 0, get_current_user_id() );
		$this->assertSame( '', (string) get_user_meta( $this->user_id, User::META_ACCESS_TOKEN, true ) );
	}

	public function test_challenge_and_verify_finish_the_sign_in(): void {
		$this->enroll_user();
		$token = $this->sign_in()->get_data()['mfa_token'];

		$this->queue( 'POST /auth/factors/auth_factor_1/challenge', [ 'id' => 'auth_challenge_1' ] );
		$challenge = $this->dispatch( '/workos/v1/auth/mfa/challenge', [ 'mfa_token' => $token, 'factor_id' => 'auth_factor_1' ] );
		$this->assertSame( 'auth_challenge_1', $challenge->get_data()['challenge_id'] );

		$this->queue( 'POST /auth/challenges/auth_challenge_1/verify', [ 'valid' => true ] );
		$done = $this->dispatch(
			'/workos/v1/auth/mfa/verify',
			[
				'mfa_token'                   => $token,
				'authentication_challenge_id' => 'auth_challenge_1',
				'code'                        => '123456',
			]
		);

		$this->assertSame( 200, $done->get_status() );
		$this->assertSame( 'member@example.com', $done->get_data()['user']['email'] );
		$this->assertSame( $this->user_id, get_current_user_id() );
		$this->assertNotSame( '', (string) get_user_meta( $this->user_id, User::META_ACCESS_TOKEN, true ) );
		$this->assertNull( PendingLogin::get( $token, 'members' ), 'The parked sign-in is used once.' );
	}

	public function test_challenge_refuses_a_factor_the_sign_in_does_not_own(): void {
		$this->enroll_user();
		$token = $this->sign_in()->get_data()['mfa_token'];

		$response = $this->dispatch( '/workos/v1/auth/mfa/challenge', [ 'mfa_token' => $token, 'factor_id' => 'auth_factor_someone_else' ] );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_verify_refuses_a_challenge_it_did_not_issue(): void {
		$this->enroll_user();
		$token = $this->sign_in()->get_data()['mfa_token'];

		$response = $this->dispatch(
			'/workos/v1/auth/mfa/verify',
			[
				'mfa_token'                   => $token,
				'authentication_challenge_id' => 'auth_challenge_forged',
				'code'                        => '123456',
			]
		);

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_wrong_codes_are_capped(): void {
		$this->enroll_user();
		$token = $this->sign_in()->get_data()['mfa_token'];
		$this->queue( 'POST /auth/factors/auth_factor_1/challenge', [ 'id' => 'auth_challenge_1' ] );
		$this->dispatch( '/workos/v1/auth/mfa/challenge', [ 'mfa_token' => $token, 'factor_id' => 'auth_factor_1' ] );
		$this->queue( 'POST /auth/challenges/auth_challenge_1/verify', [ 'valid' => false ] );

		$codes = [];
		for ( $i = 0; $i < PendingLogin::MAX_ATTEMPTS; $i++ ) {
			$codes[] = $this->dispatch(
				'/workos/v1/auth/mfa/verify',
				[
					'mfa_token'                   => $token,
					'authentication_challenge_id' => 'auth_challenge_1',
					'code'                        => '000000',
				]
			)->get_data()['code'];
		}

		$this->assertSame( 'workos_mfa_invalid_code', $codes[0] );
		$this->assertSame( 'workos_authkit_mfa_attempts_exceeded', end( $codes ) );
		$this->assertNull( PendingLogin::get( $token, 'members' ) );
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_token_is_bound_to_its_profile(): void {
		$this->enroll_user();
		$token = $this->sign_in()->get_data()['mfa_token'];

		$response = $this->dispatch( '/workos/v1/auth/mfa/challenge', [ 'mfa_token' => $token, 'factor_id' => 'auth_factor_1' ], 'no-mfa' );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'workos_authkit_mfa_expired', $response->get_data()['code'] );
	}

	// ------------------------------- Enrollment -------------------------------

	public function test_required_user_enrolls_during_sign_in(): void {
		update_user_meta( $this->user_id, User::META_MFA_REQUIRED, '1' );

		$step = $this->sign_in()->get_data();
		$this->assertTrue( $step['enroll'] );
		$this->assertSame( [], $step['factors'] );

		$this->queue( 'POST /user_management/users/user_mfa/auth_factors', $this->enroll_response(), 201 );
		$enroll = $this->dispatch( '/workos/v1/auth/mfa/enroll', [ 'mfa_token' => $step['mfa_token'] ] );
		$this->assertSame( 201, $enroll->get_status() );
		$this->assertSame( 'SECRET', $enroll->get_data()['secret'] );

		$this->queue( 'POST /auth/challenges/auth_challenge_enroll/verify', [ 'valid' => true ] );
		$this->queue( 'GET /user_management/users/user_mfa/auth_factors', [ 'data' => [ [ 'id' => 'auth_factor_new', 'type' => 'totp' ] ] ] );
		$done = $this->dispatch(
			'/workos/v1/auth/mfa/verify',
			[
				'mfa_token'                   => $step['mfa_token'],
				'authentication_challenge_id' => 'auth_challenge_enroll',
				'code'                        => '123456',
			]
		);

		$this->assertSame( 200, $done->get_status() );
		$this->assertSame( $this->user_id, get_current_user_id() );
		$this->assertSame( '1', get_user_meta( $this->user_id, User::META_MFA_ENABLED, true ) );
	}

	public function test_enroll_route_refuses_a_challenge_step(): void {
		$this->enroll_user();
		$token = $this->sign_in()->get_data()['mfa_token'];

		$response = $this->dispatch( '/workos/v1/auth/mfa/enroll', [ 'mfa_token' => $token ] );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_workos_enrollment_error_enrolls_and_completes_with_workos(): void {
		add_filter( 'workos_mfa_mode', static fn() => 'workos' );
		$this->queue(
			'POST /user_management/authenticate',
			[
				'code'                         => 'mfa_enrollment',
				'message'                      => 'The user must enroll in MFA to finish authenticating.',
				'pending_authentication_token' => 'pat_enroll',
				'user'                         => [ 'id' => 'user_mfa', 'email' => 'member@example.com' ],
			],
			403
		);

		$step = $this->sign_in()->get_data();
		$this->assertTrue( $step['enroll'] );

		$this->queue( 'POST /user_management/users/user_mfa/auth_factors', $this->enroll_response(), 201 );
		$this->dispatch( '/workos/v1/auth/mfa/enroll', [ 'mfa_token' => $step['mfa_token'] ] );

		$this->queue( 'POST /user_management/authenticate', $this->success() );
		$done = $this->dispatch(
			'/workos/v1/auth/mfa/verify',
			[
				'mfa_token'                   => $step['mfa_token'],
				'authentication_challenge_id' => 'auth_challenge_enroll',
				'code'                        => '123456',
			]
		);

		$this->assertSame( 200, $done->get_status() );
		$this->assertSame( $this->user_id, get_current_user_id() );
		$this->assertSame( '1', get_user_meta( $this->user_id, User::META_MFA_ENABLED, true ) );

		$grant = array_values(
			array_filter(
				$this->captured,
				static fn( $c ) => str_contains( $c['url'], '/user_management/authenticate' ) && str_contains( (string) $c['body'], 'mfa-totp' )
			)
		);
		$this->assertCount( 1, $grant );
		$body = json_decode( (string) $grant[0]['body'], true );
		$this->assertSame( 'pat_enroll', $body['pending_authentication_token'] );
		$this->assertSame( 'auth_challenge_enroll', $body['authentication_challenge_id'] );
	}

	// ------------------------------- Blocking -------------------------------

	public function test_profile_without_mfa_blocks_a_user_who_has_it(): void {
		$this->enroll_user();

		$response = $this->sign_in( 'no-mfa' );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'workos_mfa_blocked', $response->get_data()['code'] );
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_user_without_mfa_signs_in_as_before(): void {
		$response = $this->sign_in();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $this->user_id, get_current_user_id() );
	}

	// ------------------------------ Password guard ------------------------------

	public function test_wordpress_password_cannot_skip_mfa(): void {
		update_user_meta( $this->user_id, User::META_MFA_ENABLED, '1' );

		$result = wp_authenticate( 'mfa_member', 'wp_password_123' );

		$this->assertWPError( $result );
		$this->assertSame( 'workos_mfa_step_unsupported', $result->get_error_code() );
	}

	public function test_wordpress_password_still_works_without_mfa_or_when_allowed(): void {
		$this->assertInstanceOf( \WP_User::class, wp_authenticate( 'mfa_member', 'wp_password_123' ) );

		update_user_meta( $this->user_id, User::META_MFA_ENABLED, '1' );
		add_filter( 'workos_mfa_allow_password_fallback', '__return_true' );

		$this->assertInstanceOf( \WP_User::class, wp_authenticate( 'mfa_member', 'wp_password_123' ) );
	}

	// ------------------------------- Resume -------------------------------

	public function test_renderer_hands_a_parked_step_to_the_shell(): void {
		$profile = $this->repository->find_by_slug( 'members' );
		$mfa     = [
			'mfa_required' => true,
			'mfa_token'    => 'tok',
			'enroll'       => true,
			'factors'      => [],
		];

		$html = ( new Renderer() )->render_mount( $profile, [ 'mfa' => $mfa ] );

		$this->assertStringContainsString( 'data-mfa="' . esc_attr( wp_json_encode( $mfa ) ) . '"', $html );
	}

	// -------------------------------- Fixtures --------------------------------

	private function enroll_user(): void {
		update_user_meta( $this->user_id, User::META_MFA_ENABLED, '1' );
		$this->queue( 'GET /user_management/users/user_mfa/auth_factors', [ 'data' => [ [ 'id' => 'auth_factor_1', 'type' => 'totp' ] ] ] );
	}

	private function sign_in( string $profile = 'members' ): WP_REST_Response {
		if ( ! isset( $this->responses['POST /user_management/authenticate'] ) ) {
			$this->queue( 'POST /user_management/authenticate', $this->success() );
		}

		return $this->dispatch(
			'/workos/v1/auth/password/authenticate',
			[
				'email'    => 'member@example.com',
				'password' => 'CorrectPassword!1',
			],
			$profile
		);
	}

	private function success(): array {
		return [
			'access_token'  => 'eyJhbGciOi.eyJzdWIiOiJ1c2VyX21mYSIsInNpZCI6InNlc3NfMSJ9.sig',
			'refresh_token' => 'rt_mfa',
			'user'          => [ 'id' => 'user_mfa', 'email' => 'member@example.com' ],
		];
	}

	private function enroll_response(): array {
		return [
			'authentication_factor'    => [
				'id'   => 'auth_factor_new',
				'type' => 'totp',
				'totp' => [
					'qr_code' => 'data:image/png;base64,AAA',
					'secret'  => 'SECRET',
					'uri'     => 'otpauth://totp/Site:member@example.com?secret=SECRET',
				],
			],
			'authentication_challenge' => [ 'id' => 'auth_challenge_enroll' ],
		];
	}

	private function save_profile( string $slug, string $enforce ): void {
		$this->repository->save(
			Profile::from_array(
				[
					'slug'    => $slug,
					'title'   => $slug,
					'methods' => [ Profile::METHOD_PASSWORD ],
					'mfa'     => [
						'enforce' => $enforce,
						'factors' => [ Profile::FACTOR_TOTP ],
					],
				]
			)
		);
	}

	private function queue( string $key, array $body, int $code = 200 ): void {
		$this->responses[ $key ] = [
			'response' => [ 'code' => $code, 'message' => 'OK' ],
			'body'     => wp_json_encode( $body ),
		];
	}

	private function dispatch( string $route, array $body, string $profile = 'members' ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WorkOS-Nonce', $this->nonce->mint( $profile ) );
		$request->set_body( wp_json_encode( array_merge( [ 'profile' => $profile ], $body ) ) );

		return rest_get_server()->dispatch( $request );
	}
}
