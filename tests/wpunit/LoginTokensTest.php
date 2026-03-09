<?php
/**
 * Tests for Login::store_tokens() and JWT session ID extraction.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Auth\Login;

/**
 * Token storage and JWT session extraction tests.
 */
class LoginTokensTest extends WPTestCase {

	/**
	 * Set up each test.
	 */
	public function setUp(): void {
		parent::setUp();
		remove_all_actions( 'user_register' );
	}

	/**
	 * Build a fake JWT with a given payload.
	 *
	 * @param array $payload JWT payload claims.
	 *
	 * @return string Fake JWT string.
	 */
	private function build_fake_jwt( array $payload ): string {
		$header  = base64_encode( wp_json_encode( [ 'typ' => 'JWT', 'alg' => 'RS256' ] ) );
		$body    = base64_encode( wp_json_encode( $payload ) );
		return "{$header}.{$body}.fakesignature";
	}

	/**
	 * Test stores access token.
	 */
	public function test_stores_access_token(): void {
		$user_id = self::factory()->user->create();

		Login::store_tokens( $user_id, [
			'access_token' => 'at_test_token',
		] );

		$this->assertSame( 'at_test_token', get_user_meta( $user_id, '_workos_access_token', true ) );
	}

	/**
	 * Test stores refresh token.
	 */
	public function test_stores_refresh_token(): void {
		$user_id = self::factory()->user->create();

		Login::store_tokens( $user_id, [
			'refresh_token' => 'rt_test_token',
		] );

		$this->assertSame( 'rt_test_token', get_user_meta( $user_id, '_workos_refresh_token', true ) );
	}

	/**
	 * Test stores organization ID.
	 */
	public function test_stores_organization_id(): void {
		$user_id = self::factory()->user->create();

		Login::store_tokens( $user_id, [
			'organization_id' => 'org_test_123',
		] );

		$this->assertSame( 'org_test_123', get_user_meta( $user_id, '_workos_org_id', true ) );
	}

	/**
	 * Test extracts session ID from JWT and stores it.
	 */
	public function test_extracts_session_id_from_jwt(): void {
		$user_id = self::factory()->user->create();

		$token = $this->build_fake_jwt( [ 'sid' => 'session_abc123' ] );

		Login::store_tokens( $user_id, [
			'access_token' => $token,
		] );

		$this->assertSame( 'session_abc123', get_user_meta( $user_id, '_workos_session_id', true ) );
	}

	/**
	 * Test skips session ID for invalid JWT.
	 */
	public function test_skips_session_id_for_invalid_jwt(): void {
		$user_id = self::factory()->user->create();

		Login::store_tokens( $user_id, [
			'access_token' => 'not-a-jwt',
		] );

		$this->assertEmpty( get_user_meta( $user_id, '_workos_session_id', true ) );
	}

	/**
	 * Test skips access token when empty.
	 */
	public function test_skips_access_token_when_empty(): void {
		$user_id = self::factory()->user->create();

		Login::store_tokens( $user_id, [ 'access_token' => '' ] );

		$this->assertEmpty( get_user_meta( $user_id, '_workos_access_token', true ) );
	}

	/**
	 * Test skips refresh token when empty.
	 */
	public function test_skips_refresh_token_when_empty(): void {
		$user_id = self::factory()->user->create();

		Login::store_tokens( $user_id, [ 'refresh_token' => '' ] );

		$this->assertEmpty( get_user_meta( $user_id, '_workos_refresh_token', true ) );
	}

	/**
	 * Test skips organization ID when empty.
	 */
	public function test_skips_org_id_when_empty(): void {
		$user_id = self::factory()->user->create();

		Login::store_tokens( $user_id, [ 'organization_id' => '' ] );

		$this->assertEmpty( get_user_meta( $user_id, '_workos_org_id', true ) );
	}
}
