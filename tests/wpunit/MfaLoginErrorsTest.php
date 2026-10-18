<?php
/**
 * Tests for WorkOS MFA errors on every sign-in path.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Auth\AuthKit\LoginCompleter;
use WorkOS\Auth\AuthKit\MfaStep;
use WorkOS\Auth\AuthKit\Profile;
use WP_Error;

/**
 * WorkOS reports `mfa_challenge` and `mfa_enrollment` as 4xx errors. These
 * tests feed the error shape the API client really produces, and check
 * that no path signs the user in without the second factor.
 */
class MfaLoginErrorsTest extends WPTestCase {

	/**
	 * Mocked WorkOS response for the next HTTP call.
	 *
	 * @var array|null
	 */
	private ?array $next_workos = null;

	/**
	 * WP user with a known WordPress password.
	 *
	 * @var int
	 */
	private int $user_id = 0;

	public function setUp(): void {
		parent::setUp();

		\WorkOS\Config::set_active_environment( 'production' );
		update_option(
			'workos_production',
			[
				'api_key'                 => 'sk_test_fake',
				'client_id'               => 'client_fake',
				'environment_id'          => 'environment_test',
				'login_mode'              => 'headless',
				'allow_password_fallback' => true,
			]
		);
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );

		$this->user_id = (int) wp_create_user( 'mfa_user', 'wp_password_123', 'mfa-user@example.com' );
	}

	public function tearDown(): void {
		remove_all_filters( 'workos_mfa_mode' );
		remove_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10 );
		$this->next_workos = null;

		if ( $this->user_id ) {
			wp_delete_user( $this->user_id );
		}

		wp_set_current_user( 0 );
		delete_option( 'workos_production' );
		\WorkOS\Config::set_active_environment( 'staging' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		parent::tearDown();
	}

	public function intercept_http( $preempt, array $args, string $url ) {
		if ( null === $this->next_workos || ! str_contains( $url, 'api.workos.com' ) ) {
			return $preempt;
		}

		return $this->next_workos;
	}

	// ------------------------------- MfaStep -------------------------------

	public function test_code_reads_challenge_and_enrollment(): void {
		$this->assertSame( MfaStep::CHALLENGE, MfaStep::code( $this->api_error( $this->challenge_body() ) ) );
		$this->assertSame( MfaStep::ENROLLMENT, MfaStep::code( $this->api_error( $this->enrollment_body() ) ) );
	}

	public function test_code_ignores_other_errors_and_successes(): void {
		$this->assertSame( '', MfaStep::code( $this->api_error( [ 'code' => 'invalid_credentials' ] ) ) );
		$this->assertSame( '', MfaStep::code( new WP_Error( 'http_request_failed', 'Timeout' ) ) );
		$this->assertSame( '', MfaStep::code( [ 'user' => [ 'id' => 'user_1' ] ] ) );
	}

	// ---------------------------- LoginCompleter ----------------------------

	public function test_completer_turns_challenge_error_into_mfa_step(): void {
		$result = ( new LoginCompleter() )->complete( $this->api_error( $this->challenge_body() ), $this->profile() );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['mfa_required'] );
		// WorkOS's pending token stays on the server; the browser gets an mfa_token.
		$this->assertArrayNotHasKey( 'pending_authentication_token', $result );
		$this->assertNotEmpty( $result['mfa_token'] );
		$this->assertSame( [ [ 'id' => 'auth_factor_1', 'type' => 'totp' ] ], $result['factors'] );
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_completer_applies_factor_allowlist_to_challenge_error(): void {
		$body                           = $this->challenge_body();
		$body['authentication_factors'] = [ [ 'id' => 'auth_factor_sms', 'type' => 'sms' ] ];

		$result = ( new LoginCompleter() )->complete( $this->api_error( $body ), $this->profile() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'workos_authkit_factor_not_allowed', $result->get_error_code() );
	}

	public function test_completer_parks_enrollment_error_without_leaking_token(): void {
		$result = ( new LoginCompleter() )->complete( $this->api_error( $this->enrollment_body() ), $this->profile() );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['mfa_required'] );
		$this->assertTrue( $result['enroll'] );
		$this->assertNotEmpty( $result['mfa_token'] );
		$this->assertArrayNotHasKey( 'pending_authentication_token', $result );
		$this->assertStringNotContainsString( 'pat_enroll', wp_json_encode( $result ) );
		$this->assertSame( 0, get_current_user_id() );

		$parked = \WorkOS\Mfa\PendingLogin::get( $result['mfa_token'], 'members' );
		$this->assertSame( 'pat_enroll', $parked['pending_authentication_token'] );
		$this->assertSame( 'user_mfa', $parked['workos_user_id'] );
		$this->assertNull( \WorkOS\Mfa\PendingLogin::get( $result['mfa_token'], 'another-profile' ) );
	}

	public function test_completer_refuses_enrollment_error_without_user(): void {
		$body = $this->enrollment_body();
		unset( $body['user'] );

		$result = ( new LoginCompleter() )->complete( $this->api_error( $body ), $this->profile() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'workos_authkit_mfa_enrollment_required', $result->get_error_code() );
		$this->assertStringNotContainsString( 'pat_enroll', wp_json_encode( $result->get_error_data() ) );
	}

	public function test_completer_accepts_satisfied_success_on_always_profile(): void {
		add_filter( 'workos_mfa_mode', static fn() => 'workos' );
		$profile = $this->profile( Profile::MFA_ENFORCE_ALWAYS );
		$success = [
			'access_token'  => 'eyJ.eyJzdWIiOiJ1c2VyX3NhdCJ9.sig',
			'refresh_token' => 'rt_sat',
			'user'          => [ 'id' => 'user_sat', 'email' => 'satisfied@example.com' ],
		];

		$refused = ( new LoginCompleter() )->complete( $success, $profile );
		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'workos_authkit_mfa_required', $refused->get_error_code() );

		$result = ( new LoginCompleter() )->complete( $success, $profile, '', true, true );
		$this->assertIsArray( $result );
		$this->assertSame( 'satisfied@example.com', $result['user']['email'] );
	}

	// ------------------------- Headless wp-login.php -------------------------

	/**
	 * The WorkOS password was right but MFA is pending. WP's own password
	 * check at priority 20 must not sign the user in with the same password.
	 */
	public function test_headless_mfa_challenge_blocks_wp_password_fallback(): void {
		$this->next_workos = $this->http( 403, $this->challenge_body() );

		$result = wp_authenticate( 'mfa-user@example.com', 'wp_password_123' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'workos_mfa_step_unsupported', $result->get_error_code() );
	}

	public function test_headless_mfa_enrollment_blocks_wp_password_fallback(): void {
		$this->next_workos = $this->http( 403, $this->enrollment_body() );

		$result = wp_authenticate( 'mfa_user', 'wp_password_123' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'workos_mfa_step_unsupported', $result->get_error_code() );
	}

	/**
	 * Wrong WorkOS password still falls back to WordPress, as before.
	 */
	public function test_headless_invalid_credentials_still_falls_back(): void {
		$this->next_workos = $this->http(
			400,
			[
				'code'    => 'invalid_credentials',
				'message' => 'Invalid credentials.',
			]
		);

		$result = wp_authenticate( 'mfa-user@example.com', 'wp_password_123' );

		$this->assertInstanceOf( \WP_User::class, $result );
		$this->assertSame( $this->user_id, $result->ID );
	}

	/**
	 * A refusal is used once: the next `authenticate` run in the same
	 * request starts clean.
	 */
	public function test_headless_refusal_does_not_leak_into_next_attempt(): void {
		$this->next_workos = $this->http( 403, $this->challenge_body() );
		$this->assertInstanceOf( WP_Error::class, wp_authenticate( 'mfa-user@example.com', 'wp_password_123' ) );

		$this->next_workos = $this->http( 400, [ 'code' => 'invalid_credentials' ] );
		$this->assertInstanceOf( \WP_User::class, wp_authenticate( 'mfa-user@example.com', 'wp_password_123' ) );
	}

	// -------------------------------- Helpers --------------------------------

	private function profile( string $enforce = Profile::MFA_ENFORCE_IF_REQUIRED ): Profile {
		return Profile::from_array(
			[
				'slug'  => 'members',
				'title' => 'Members',
				'mfa'   => [
					'enforce' => $enforce,
					'factors' => [ Profile::FACTOR_TOTP ],
				],
			]
		);
	}

	private function challenge_body(): array {
		return [
			'code'                         => 'mfa_challenge',
			'message'                      => 'The user must complete an MFA challenge to finish authenticating.',
			'pending_authentication_token' => 'pat_mfa',
			'authentication_factors'       => [ [ 'id' => 'auth_factor_1', 'type' => 'totp' ] ],
			'user'                         => [ 'id' => 'user_mfa', 'email' => 'mfa-user@example.com' ],
		];
	}

	private function enrollment_body(): array {
		return [
			'code'                         => 'mfa_enrollment',
			'message'                      => 'The user must enroll in MFA to finish authenticating.',
			'pending_authentication_token' => 'pat_enroll',
			'user'                         => [ 'id' => 'user_mfa', 'email' => 'mfa-user@example.com' ],
		];
	}

	/**
	 * The WP_Error `Api\Client::parse_response()` builds for a 4xx.
	 */
	private function api_error( array $body, int $status = 403 ): WP_Error {
		return new WP_Error(
			'workos_api_error',
			(string) ( $body['message'] ?? 'Error' ),
			[
				'status' => $status,
				'body'   => $body,
			]
		);
	}

	private function http( int $code, array $body ): array {
		return [
			'response' => [ 'code' => $code, 'message' => 'Error' ],
			'body'     => wp_json_encode( $body ),
		];
	}
}
