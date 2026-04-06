<?php
/**
 * Tests for Login::handle_logout().
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Auth\Login;

/**
 * Logout session cleanup and server-side revocation tests.
 */
class LoginLogoutTest extends WPTestCase {

	/**
	 * Login instance under test.
	 *
	 * @var Login
	 */
	private Login $login;

	/**
	 * Captured HTTP requests.
	 *
	 * @var array
	 */
	private array $captured_requests = [];

	/**
	 * Set up each test.
	 */
	public function setUp(): void {
		parent::setUp();

		\WorkOS\Config::set_active_environment( 'production' );
		update_option( 'workos_production', [
			'api_key'        => 'sk_test_fake',
			'client_id'      => 'client_fake',
			'environment_id' => 'environment_test',
		] );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );

		remove_all_actions( 'user_register' );

		// Remove constructor hooks so tests only exercise handle_logout directly.
		remove_all_actions( 'login_init' );
		remove_all_filters( 'authenticate' );
		remove_all_actions( 'wp_logout' );

		$this->login = new Login();
	}

	/**
	 * Tear down each test.
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10 );
		delete_option( 'workos_production' );
		\WorkOS\Config::set_active_environment( 'staging' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$this->captured_requests = [];

		parent::tearDown();
	}

	/**
	 * Intercept outbound HTTP requests.
	 *
	 * @param false|array $preempt  Response override.
	 * @param array       $args     Request args.
	 * @param string      $url      Request URL.
	 *
	 * @return array Fake response.
	 */
	public function intercept_http( $preempt, array $args, string $url ): array {
		$this->captured_requests[] = [
			'url'    => $url,
			'method' => $args['method'] ?? 'GET',
			'body'   => $args['body'] ?? '',
		];

		return [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'body'     => '{}',
		];
	}

	/**
	 * Build a fake JWT with a given payload.
	 *
	 * No real signature — extract_session_id only decodes the payload.
	 *
	 * @param array $payload JWT payload claims.
	 *
	 * @return string Fake JWT string.
	 */
	private function build_fake_jwt( array $payload ): string {
		$header = rtrim( strtr( base64_encode( '{"alg":"RS256","typ":"JWT"}' ), '+/', '-_' ), '=' );
		$body   = rtrim( strtr( base64_encode( wp_json_encode( $payload ) ), '+/', '-_' ), '=' );
		$sig    = 'fake_signature';

		return "{$header}.{$body}.{$sig}";
	}

	/**
	 * Create a user with a stored WorkOS access token.
	 *
	 * @param string $session_id Session ID to embed in the JWT.
	 *
	 * @return int WordPress user ID.
	 */
	private function create_user_with_token( string $session_id ): int {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$token   = $this->build_fake_jwt( [ 'sid' => $session_id, 'sub' => 'user_test' ] );

		update_user_meta( $user_id, '_workos_access_token', $token );
		update_user_meta( $user_id, '_workos_refresh_token', 'refresh_fake' );
		update_user_meta( $user_id, '_workos_session_id', $session_id );

		return $user_id;
	}

	/**
	 * Test tokens are deleted from usermeta on logout.
	 */
	public function test_clears_tokens_on_logout(): void {
		$user_id = $this->create_user_with_token( 'session_abc' );

		$this->login->handle_logout( $user_id );

		$this->assertEmpty( get_user_meta( $user_id, '_workos_access_token', true ) );
		$this->assertEmpty( get_user_meta( $user_id, '_workos_refresh_token', true ) );
		$this->assertEmpty( get_user_meta( $user_id, '_workos_session_id', true ) );
	}

	/**
	 * Test server-side session revocation is called with the correct session ID.
	 */
	public function test_revokes_session_server_side(): void {
		$user_id = $this->create_user_with_token( 'session_xyz' );

		$this->login->handle_logout( $user_id );

		$this->assertCount( 1, $this->captured_requests );
		$this->assertStringContainsString(
			'/user_management/sessions/session_xyz/revoke',
			$this->captured_requests[0]['url']
		);
		$this->assertSame( 'POST', $this->captured_requests[0]['method'] );
	}

	/**
	 * Test no API call is made when user has no access token.
	 */
	public function test_skips_revocation_when_no_access_token(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$this->login->handle_logout( $user_id );

		$this->assertCount( 0, $this->captured_requests );
	}

	/**
	 * Test no API call is made when JWT has no sid claim.
	 */
	public function test_skips_revocation_when_token_has_no_sid(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$token   = $this->build_fake_jwt( [ 'sub' => 'user_test' ] );
		update_user_meta( $user_id, '_workos_access_token', $token );

		$this->login->handle_logout( $user_id );

		$this->assertCount( 0, $this->captured_requests );
	}

	/**
	 * Test no logout_redirect filter is registered (redirect stays with WordPress).
	 */
	public function test_does_not_register_logout_redirect_filter(): void {
		$user_id = $this->create_user_with_token( 'session_abc' );

		$this->login->handle_logout( $user_id );

		$this->assertFalse( has_filter( 'logout_redirect', [ $this->login, 'wrap_logout_redirect' ] ) );
	}
}
