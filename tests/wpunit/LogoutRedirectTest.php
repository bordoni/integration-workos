<?php
/**
 * Tests for LogoutRedirect::resolve().
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Auth\LogoutRedirect;

/**
 * Logout redirect resolution tests.
 */
class LogoutRedirectTest extends WPTestCase {

	/**
	 * Set up each test.
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( 'workos_active_environment', 'production' );
		update_option( 'workos_production', [
			'api_key'        => 'sk_test_fake',
			'client_id'      => 'client_fake',
			'environment_id' => 'environment_test',
		] );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		remove_all_actions( 'user_register' );
	}

	/**
	 * Tear down each test.
	 */
	public function tearDown(): void {
		remove_all_filters( 'workos_logout_redirect_should_apply' );
		remove_all_filters( 'workos_logout_redirect_urls' );
		delete_option( 'workos_production' );
		delete_option( 'workos_active_environment' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		parent::tearDown();
	}

	/**
	 * Create a WorkOS-linked user.
	 *
	 * @param string $role WP role.
	 *
	 * @return \WP_User
	 */
	private function create_workos_user( string $role = 'subscriber' ): \WP_User {
		$user_id = self::factory()->user->create( [ 'role' => $role ] );
		update_user_meta( $user_id, '_workos_user_id', 'user_logout_' . wp_rand() );
		return get_user_by( 'id', $user_id );
	}

	/**
	 * Test returns role URL.
	 */
	public function test_returns_role_url(): void {
		$opts                          = get_option( 'workos_production' );
		$opts['logout_redirect_urls'] = [
			'subscriber' => 'https://example.com/goodbye',
		];
		update_option( 'workos_production', $opts );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$user   = $this->create_workos_user( 'subscriber' );
		$result = LogoutRedirect::resolve( wp_login_url(), $user );

		$this->assertSame( 'https://example.com/goodbye', $result );
	}

	/**
	 * Test falls back to default.
	 */
	public function test_falls_back_to_default(): void {
		$opts                          = get_option( 'workos_production' );
		$opts['logout_redirect_urls'] = [
			'__default__' => 'https://example.com/default-bye',
		];
		update_option( 'workos_production', $opts );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$user   = $this->create_workos_user( 'editor' );
		$result = LogoutRedirect::resolve( wp_login_url(), $user );

		$this->assertSame( 'https://example.com/default-bye', $result );
	}

	/**
	 * Test skips for non-WorkOS user via filter method.
	 */
	public function test_skips_for_non_workos_user(): void {
		$opts                          = get_option( 'workos_production' );
		$opts['logout_redirect_urls'] = [
			'subscriber' => 'https://example.com/nope',
		];
		update_option( 'workos_production', $opts );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$user    = get_user_by( 'id', $user_id );

		$logout_redirect = new LogoutRedirect();
		$result          = $logout_redirect->logout_redirect( wp_login_url(), '', $user );

		$this->assertSame( wp_login_url(), $result );
	}

	/**
	 * Test respects filter.
	 */
	public function test_respects_filter(): void {
		$opts                          = get_option( 'workos_production' );
		$opts['logout_redirect_urls'] = [
			'subscriber' => 'https://example.com/nope',
		];
		update_option( 'workos_production', $opts );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		add_filter( 'workos_logout_redirect_should_apply', '__return_false' );

		$user   = $this->create_workos_user( 'subscriber' );
		$result = LogoutRedirect::resolve( wp_login_url(), $user );

		$this->assertSame( wp_login_url(), $result );
	}

	/**
	 * Test converts relative URL.
	 */
	public function test_converts_relative_url(): void {
		$opts                          = get_option( 'workos_production' );
		$opts['logout_redirect_urls'] = [
			'subscriber' => '/see-you',
		];
		update_option( 'workos_production', $opts );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$user   = $this->create_workos_user( 'subscriber' );
		$result = LogoutRedirect::resolve( wp_login_url(), $user );

		$this->assertSame( home_url( '/see-you' ), $result );
	}

	/**
	 * Test returns original when no match.
	 */
	public function test_returns_original_when_no_match(): void {
		$opts                          = get_option( 'workos_production' );
		$opts['logout_redirect_urls'] = [];
		update_option( 'workos_production', $opts );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$user   = $this->create_workos_user( 'subscriber' );
		$result = LogoutRedirect::resolve( wp_login_url(), $user );

		$this->assertSame( wp_login_url(), $result );
	}
}
