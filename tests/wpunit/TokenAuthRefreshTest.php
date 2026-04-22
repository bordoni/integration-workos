<?php
/**
 * Tests for TokenAuth rejecting invalid/expired JWTs.
 *
 * The plugin intentionally does NOT perform "lazy refresh" on expired
 * Bearer tokens — an attacker with a forged unsigned JWT whose `sub`
 * pointed at any active user could otherwise trigger a server-side
 * refresh-token exchange for that user and impersonate them. Clients
 * that need to rotate tokens must use POST /auth/session/refresh, which
 * authenticates via the WP cookie.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\REST\TokenAuth;

/**
 * Regression tests that TokenAuth never trusts an unverified JWT.
 */
class TokenAuthRefreshTest extends WPTestCase {

	/**
	 * TokenAuth under test.
	 *
	 * @var TokenAuth
	 */
	private TokenAuth $token_auth;

	/**
	 * Captured HTTP requests (to assert *no* outbound calls were made).
	 *
	 * @var array
	 */
	private array $captured = [];

	/**
	 * Set up — configure plugin, install HTTP interceptor.
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

		if ( ! defined( 'REST_REQUEST' ) ) {
			define( 'REST_REQUEST', true );
		}

		$this->token_auth = new TokenAuth();
		add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );
	}

	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10 );
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		delete_option( 'workos_production' );
		\WorkOS\Config::set_active_environment( 'staging' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();
		$this->captured = [];

		parent::tearDown();
	}

	public function intercept_http( $preempt, array $args, string $url ) {
		$this->captured[] = [
			'url'    => $url,
			'method' => $args['method'] ?? 'GET',
			'body'   => $args['body'] ?? '',
		];
		return [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'body'     => '{}',
		];
	}

	private function build_jwt( array $payload ): string {
		$header = rtrim( strtr( base64_encode( '{"alg":"RS256","typ":"JWT","kid":"k1"}' ), '+/', '-_' ), '=' );
		$body   = rtrim( strtr( base64_encode( wp_json_encode( $payload ) ), '+/', '-_' ), '=' );
		return "{$header}.{$body}.fake";
	}

	private function create_linked_user( string $workos_id, string $refresh_token = '' ): int {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, '_workos_user_id', $workos_id );
		if ( '' !== $refresh_token ) {
			update_user_meta( $user_id, '_workos_refresh_token', $refresh_token );
		}
		return $user_id;
	}

	/**
	 * An unsigned JWT claiming a valid `sub` + expired `exp` is rejected
	 * outright — *no* refresh-token exchange happens, even if the target
	 * user has a stored refresh token. This is the key regression for the
	 * signature-verification-bypass vulnerability.
	 */
	public function test_expired_unsigned_jwt_with_stored_refresh_is_rejected(): void {
		$this->create_linked_user( 'user_workos_1', 'rt_original' );

		$expired_jwt                   = $this->build_jwt(
			[ 'sub' => 'user_workos_1', 'exp' => time() - 60 ]
		);
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $expired_jwt;

		$result = $this->token_auth->authenticate( false );

		$this->assertFalse( $result );

		// Critically: no refresh call went out. The server never exchanged
		// the victim's stored refresh token against an unverified claim.
		$refresh_calls = array_filter(
			$this->captured,
			static fn( array $c ): bool => str_contains( $c['url'], '/user_management/authenticate' )
		);
		$this->assertEmpty( $refresh_calls );
	}

	/**
	 * Expired JWT with no stored refresh token — still rejected.
	 */
	public function test_expired_jwt_without_refresh_token_is_rejected(): void {
		$this->create_linked_user( 'user_workos_2' );

		$expired_jwt                   = $this->build_jwt(
			[ 'sub' => 'user_workos_2', 'exp' => time() - 60 ]
		);
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $expired_jwt;

		$result = $this->token_auth->authenticate( false );

		$this->assertFalse( $result );
	}

	/**
	 * JWT whose `sub` does not match any WP user — rejected.
	 */
	public function test_jwt_unknown_sub_is_rejected(): void {
		$expired_jwt                   = $this->build_jwt(
			[ 'sub' => 'user_unknown', 'exp' => time() - 60 ]
		);
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $expired_jwt;

		$result = $this->token_auth->authenticate( false );

		$this->assertFalse( $result );
	}

	/**
	 * Bearer header absent — returns existing $user_id untouched.
	 */
	public function test_no_bearer_header_passes_through(): void {
		$result = $this->token_auth->authenticate( false );
		$this->assertFalse( $result );

		$result = $this->token_auth->authenticate( 7 );
		$this->assertSame( 7, $result );
	}
}
