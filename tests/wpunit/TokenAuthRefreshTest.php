<?php
/**
 * Tests for TokenAuth lazy refresh on expired access tokens.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\REST\TokenAuth;

/**
 * Lazy refresh flow for expired WorkOS JWTs.
 */
class TokenAuthRefreshTest extends WPTestCase {

	/**
	 * TokenAuth under test.
	 *
	 * @var TokenAuth
	 */
	private TokenAuth $token_auth;

	/**
	 * Captured HTTP requests.
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

		// Simulate REST context so TokenAuth actually runs.
		if ( ! defined( 'REST_REQUEST' ) ) {
			define( 'REST_REQUEST', true );
		}

		$this->token_auth = new TokenAuth();
		add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10 );
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		delete_option( 'workos_production' );
		\WorkOS\Config::set_active_environment( 'staging' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();
		$this->captured = [];

		parent::tearDown();
	}

	/**
	 * HTTP interceptor — routes by URL.
	 */
	public function intercept_http( $preempt, array $args, string $url ) {
		$this->captured[] = [
			'url'    => $url,
			'method' => $args['method'] ?? 'GET',
			'body'   => $args['body'] ?? '',
		];

		// Refresh token exchange — return a new JWT.
		if ( str_contains( $url, '/user_management/authenticate' ) ) {
			return [
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'body'     => wp_json_encode(
					[
						'access_token'  => $this->build_jwt( [ 'sub' => 'user_workos_1', 'sid' => 'session_new', 'exp' => time() + 600 ] ),
						'refresh_token' => 'rt_rotated',
						'user'          => [ 'id' => 'user_workos_1' ],
					]
				),
			];
		}

		return [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'body'     => '{}',
		];
	}

	/**
	 * Build a fake (signature-free) JWT.
	 */
	private function build_jwt( array $payload ): string {
		$header = rtrim( strtr( base64_encode( '{"alg":"RS256","typ":"JWT","kid":"k1"}' ), '+/', '-_' ), '=' );
		$body   = rtrim( strtr( base64_encode( wp_json_encode( $payload ) ), '+/', '-_' ), '=' );
		return "{$header}.{$body}.fake";
	}

	/**
	 * Set up a WP user linked to a WorkOS sub.
	 */
	private function create_linked_user( string $workos_id, string $refresh_token = '' ): int {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, '_workos_user_id', $workos_id );
		if ( '' !== $refresh_token ) {
			update_user_meta( $user_id, '_workos_refresh_token', $refresh_token );
		}
		return $user_id;
	}

	/**
	 * When the JWT is expired and a refresh token is stored, TokenAuth
	 * refreshes the session and returns the WP user ID.
	 */
	public function test_expired_jwt_triggers_refresh_and_returns_user_id(): void {
		$wp_user_id = $this->create_linked_user( 'user_workos_1', 'rt_original' );

		$expired_jwt                = $this->build_jwt(
			[ 'sub' => 'user_workos_1', 'exp' => time() - 60 ]
		);
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $expired_jwt;

		$result = $this->token_auth->authenticate( false );

		$this->assertSame( $wp_user_id, $result );

		// A refresh call went out.
		$refresh_calls = array_filter(
			$this->captured,
			static fn( array $c ): bool => str_contains( $c['url'], '/user_management/authenticate' )
		);
		$this->assertNotEmpty( $refresh_calls, 'Expected a refresh call to WorkOS.' );

		// The new refresh token was persisted.
		$this->assertSame( 'rt_rotated', get_user_meta( $wp_user_id, '_workos_refresh_token', true ) );
	}

	/**
	 * Expired JWT with no stored refresh token does not authenticate.
	 */
	public function test_expired_jwt_without_refresh_token_fails(): void {
		$this->create_linked_user( 'user_workos_2' ); // No refresh token.

		$expired_jwt                = $this->build_jwt(
			[ 'sub' => 'user_workos_2', 'exp' => time() - 60 ]
		);
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $expired_jwt;

		$result = $this->token_auth->authenticate( false );

		$this->assertFalse( $result );

		// No refresh call was attempted.
		$refresh_calls = array_filter(
			$this->captured,
			static fn( array $c ): bool => str_contains( $c['url'], '/user_management/authenticate' )
		);
		$this->assertEmpty( $refresh_calls );
	}

	/**
	 * Expired JWT whose sub does not match any WP user does not authenticate.
	 */
	public function test_expired_jwt_unknown_sub_fails(): void {
		$expired_jwt                = $this->build_jwt(
			[ 'sub' => 'user_unknown', 'exp' => time() - 60 ]
		);
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $expired_jwt;

		$result = $this->token_auth->authenticate( false );

		$this->assertFalse( $result );
	}
}
