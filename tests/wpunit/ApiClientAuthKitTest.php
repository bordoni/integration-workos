<?php
/**
 * Tests for Api\Client Custom AuthKit extensions.
 *
 * Verifies HTTP method, URL, JSON body, and Radar-header forwarding for each
 * new method. Real HTTP is intercepted via `pre_http_request`.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Api\Client;

/**
 * @covers \WorkOS\Api\Client
 */
class ApiClientAuthKitTest extends WPTestCase {

	/**
	 * Client under test.
	 *
	 * @var Client
	 */
	private Client $client;

	/**
	 * Captured outbound HTTP requests.
	 *
	 * @var array<int, array{url: string, method: string, body: string, headers: array}>
	 */
	private array $captured = [];

	/**
	 * Next response to return from the pre_http_request filter.
	 *
	 * @var array
	 */
	private array $next_response = [
		'response' => [ 'code' => 200, 'message' => 'OK' ],
		'body'     => '{}',
	];

	/**
	 * Set up — build a fresh client and attach the capture filter.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->client = new Client( 'sk_test_fake', 'client_test_fake' );
		add_filter( 'pre_http_request', [ $this, 'capture_http' ], 10, 3 );
	}

	/**
	 * Tear down — detach the capture filter, clear state.
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'capture_http' ], 10 );
		$this->captured      = [];
		$this->next_response = [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'body'     => '{}',
		];

		parent::tearDown();
	}

	/**
	 * Capture every wp_remote_* call and return our canned response.
	 *
	 * @param false|array $preempt Response override.
	 * @param array       $args    Request args.
	 * @param string      $url     Request URL.
	 *
	 * @return array
	 */
	public function capture_http( $preempt, array $args, string $url ): array {
		$this->captured[] = [
			'url'     => $url,
			'method'  => $args['method'] ?? 'GET',
			'body'    => $args['body'] ?? '',
			'headers' => $args['headers'] ?? [],
		];

		return $this->next_response;
	}

	/**
	 * Helper — pull the last captured request.
	 */
	private function last_request(): array {
		$this->assertNotEmpty( $this->captured, 'No HTTP request was captured.' );
		return $this->captured[ count( $this->captured ) - 1 ];
	}

	/**
	 * Helper — decode JSON body of the last request.
	 */
	private function last_body_json(): array {
		$request = $this->last_request();
		return json_decode( (string) $request['body'], true ) ?? [];
	}

	// -------------------------------------------------------------------------
	// authenticate_with_password + Radar header forwarding
	// -------------------------------------------------------------------------

	public function test_authenticate_with_password_posts_to_authenticate(): void {
		$this->client->authenticate_with_password( 'alice@example.com', 'hunter2' );

		$request = $this->last_request();
		$this->assertSame( 'POST', $request['method'] );
		$this->assertStringContainsString( '/user_management/authenticate', $request['url'] );

		$body = $this->last_body_json();
		$this->assertSame( 'alice@example.com', $body['email'] );
		$this->assertSame( 'hunter2', $body['password'] );
		$this->assertSame( 'password', $body['grant_type'] );
		$this->assertSame( 'client_test_fake', $body['client_id'] );
		$this->assertSame( 'sk_test_fake', $body['client_secret'] );
	}

	public function test_authenticate_with_password_forwards_radar_header(): void {
		$this->client->authenticate_with_password( 'a@example.com', 'x', 'radar_action_abc' );

		$headers = $this->last_request()['headers'];
		$this->assertSame( 'radar_action_abc', $headers['x-workos-radar-action-token'] );
	}

	public function test_authenticate_with_password_omits_radar_header_when_null(): void {
		$this->client->authenticate_with_password( 'a@example.com', 'x' );

		$headers = $this->last_request()['headers'];
		$this->assertArrayNotHasKey( 'x-workos-radar-action-token', $headers );
	}

	// -------------------------------------------------------------------------
	// Magic auth
	// -------------------------------------------------------------------------

	public function test_send_magic_auth_code_posts_to_send_endpoint(): void {
		$this->client->send_magic_auth_code( 'alice@example.com', 'radar_mc' );

		$request = $this->last_request();
		$this->assertSame( 'POST', $request['method'] );
		$this->assertStringContainsString( '/user_management/magic_auth/send', $request['url'] );
		$this->assertSame( 'alice@example.com', $this->last_body_json()['email'] );
		$this->assertSame( 'radar_mc', $request['headers']['x-workos-radar-action-token'] );
	}

	public function test_authenticate_with_magic_auth_posts_with_code(): void {
		$this->client->authenticate_with_magic_auth( 'alice@example.com', '123456', null, 'radar_ma' );

		$body = $this->last_body_json();
		$this->assertSame( 'alice@example.com', $body['email'] );
		$this->assertSame( '123456', $body['code'] );
		$this->assertSame( 'urn:workos:oauth:grant-type:magic-auth:code', $body['grant_type'] );
		$this->assertArrayNotHasKey( 'pending_authentication_token', $body );
		$this->assertSame( 'radar_ma', $this->last_request()['headers']['x-workos-radar-action-token'] );
	}

	public function test_authenticate_with_magic_auth_includes_pending_token_when_provided(): void {
		$this->client->authenticate_with_magic_auth( 'a@example.com', '654321', 'pat_abc' );

		$body = $this->last_body_json();
		$this->assertSame( 'pat_abc', $body['pending_authentication_token'] );
	}

	// -------------------------------------------------------------------------
	// TOTP
	// -------------------------------------------------------------------------

	public function test_authenticate_with_totp_sends_required_fields(): void {
		$this->client->authenticate_with_totp( 'pat_1', 'challenge_1', '098765', 'radar_totp' );

		$body = $this->last_body_json();
		$this->assertSame( 'urn:workos:oauth:grant-type:mfa-totp', $body['grant_type'] );
		$this->assertSame( 'pat_1', $body['pending_authentication_token'] );
		$this->assertSame( 'challenge_1', $body['authentication_challenge_id'] );
		$this->assertSame( '098765', $body['code'] );
		$this->assertSame( 'radar_totp', $this->last_request()['headers']['x-workos-radar-action-token'] );
	}

	// -------------------------------------------------------------------------
	// Refresh
	// -------------------------------------------------------------------------

	public function test_authenticate_with_refresh_token_uses_refresh_grant(): void {
		$this->client->authenticate_with_refresh_token( 'rt_abc' );

		$body = $this->last_body_json();
		$this->assertSame( 'refresh_token', $body['grant_type'] );
		$this->assertSame( 'rt_abc', $body['refresh_token'] );
	}

	public function test_refresh_session_is_alias_of_refresh_token_auth(): void {
		$this->client->refresh_session( 'rt_xyz' );

		$this->assertSame( 'rt_xyz', $this->last_body_json()['refresh_token'] );
	}

	// -------------------------------------------------------------------------
	// Password reset
	// -------------------------------------------------------------------------

	public function test_send_password_reset_posts_email_and_url(): void {
		$this->client->send_password_reset( 'a@example.com', 'https://site.test/reset', 'radar_pr' );

		$request = $this->last_request();
		$this->assertStringContainsString( '/user_management/password_reset/send', $request['url'] );

		$body = $this->last_body_json();
		$this->assertSame( 'a@example.com', $body['email'] );
		$this->assertSame( 'https://site.test/reset', $body['password_reset_url'] );
		$this->assertSame( 'radar_pr', $request['headers']['x-workos-radar-action-token'] );
	}

	public function test_reset_password_posts_token_and_new_password(): void {
		$this->client->reset_password( 'tok_xyz', 'newpass123', 'radar_rp' );

		$body = $this->last_body_json();
		$this->assertSame( 'tok_xyz', $body['token'] );
		$this->assertSame( 'newpass123', $body['new_password'] );
		$this->assertStringContainsString( '/user_management/password_reset/confirm', $this->last_request()['url'] );
	}

	// -------------------------------------------------------------------------
	// Email verification
	// -------------------------------------------------------------------------

	public function test_send_verification_email_targets_user(): void {
		$this->client->send_verification_email( 'user_01' );

		$request = $this->last_request();
		$this->assertSame( 'POST', $request['method'] );
		$this->assertStringContainsString( '/user_management/users/user_01/email_verification/send', $request['url'] );
	}

	public function test_verify_email_sends_code(): void {
		$this->client->verify_email( 'user_01', '424242', 'radar_ve' );

		$request = $this->last_request();
		$this->assertStringContainsString( '/user_management/users/user_01/email_verification/confirm', $request['url'] );
		$this->assertSame( '424242', $this->last_body_json()['code'] );
		$this->assertSame( 'radar_ve', $request['headers']['x-workos-radar-action-token'] );
	}

	// -------------------------------------------------------------------------
	// Invitation
	// -------------------------------------------------------------------------

	public function test_get_invitation_by_token_issues_get(): void {
		$this->client->get_invitation_by_token( 'inv_tok_123' );

		$request = $this->last_request();
		$this->assertSame( 'GET', $request['method'] );
		$this->assertStringContainsString( '/user_management/invitations/by_token/inv_tok_123', $request['url'] );
	}

	// -------------------------------------------------------------------------
	// MFA factors
	// -------------------------------------------------------------------------

	public function test_list_auth_factors_issues_get(): void {
		$this->client->list_auth_factors( 'user_77' );

		$request = $this->last_request();
		$this->assertSame( 'GET', $request['method'] );
		$this->assertStringContainsString( '/user_management/users/user_77/auth_factors', $request['url'] );
	}

	public function test_enroll_totp_factor_posts_totp_payload(): void {
		$this->client->enroll_totp_factor( 'user_77', 'Acme Inc', 'alice@acme.test' );

		$request = $this->last_request();
		$this->assertSame( 'POST', $request['method'] );
		$this->assertStringContainsString( '/user_management/users/user_77/auth_factors', $request['url'] );

		$body = $this->last_body_json();
		$this->assertSame( 'totp', $body['type'] );
		$this->assertSame( 'Acme Inc', $body['totp_issuer'] );
		$this->assertSame( 'alice@acme.test', $body['totp_user'] );
	}

	public function test_enroll_sms_factor_posts_sms_payload(): void {
		$this->client->enroll_sms_factor( 'user_77', '+15551234567' );

		$body = $this->last_body_json();
		$this->assertSame( 'sms', $body['type'] );
		$this->assertSame( '+15551234567', $body['phone_number'] );
	}

	public function test_delete_auth_factor_issues_delete(): void {
		$this->client->delete_auth_factor( 'factor_abc' );

		$request = $this->last_request();
		$this->assertSame( 'DELETE', $request['method'] );
		$this->assertStringContainsString( '/user_management/auth_factors/factor_abc', $request['url'] );
	}

	public function test_challenge_auth_factor_posts_empty_when_no_template(): void {
		$this->client->challenge_auth_factor( 'factor_abc' );

		$request = $this->last_request();
		$this->assertSame( 'POST', $request['method'] );
		$this->assertStringContainsString( '/user_management/auth_factors/factor_abc/challenge', $request['url'] );
		$this->assertSame( [], $this->last_body_json() );
	}

	public function test_challenge_auth_factor_includes_sms_template(): void {
		$this->client->challenge_auth_factor( 'factor_abc', 'Your code is {{code}}' );

		$this->assertSame( 'Your code is {{code}}', $this->last_body_json()['sms_template'] );
	}

	public function test_verify_auth_challenge_posts_code(): void {
		$this->client->verify_auth_challenge( 'challenge_abc', '111222', 'radar_vc' );

		$request = $this->last_request();
		$this->assertStringContainsString( '/user_management/auth_challenges/challenge_abc/verify', $request['url'] );
		$this->assertSame( '111222', $this->last_body_json()['code'] );
		$this->assertSame( 'radar_vc', $request['headers']['x-workos-radar-action-token'] );
	}

	// -------------------------------------------------------------------------
	// Radar forwarding on create_user
	// -------------------------------------------------------------------------

	public function test_create_user_forwards_radar_header_when_provided(): void {
		$this->client->create_user(
			[
				'email'    => 'new@example.com',
				'password' => 'pass',
			],
			'radar_signup'
		);

		$headers = $this->last_request()['headers'];
		$this->assertSame( 'radar_signup', $headers['x-workos-radar-action-token'] );
	}

	// -------------------------------------------------------------------------
	// Authorization bearer header on every request
	// -------------------------------------------------------------------------

	public function test_all_requests_include_bearer_api_key(): void {
		$this->client->send_magic_auth_code( 'a@example.com' );

		$this->assertSame( 'Bearer sk_test_fake', $this->last_request()['headers']['Authorization'] );
	}
}
