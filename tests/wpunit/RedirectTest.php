<?php
/**
 * Tests for Redirect::resolve().
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Auth\Redirect;

/**
 * Login redirect resolution tests.
 */
class RedirectTest extends WPTestCase {

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

		remove_all_actions( 'user_register' );
	}

	/**
	 * Tear down each test.
	 */
	public function tearDown(): void {
		remove_all_filters( 'workos_redirect_should_apply' );
		remove_all_filters( 'workos_redirect_is_explicit' );
		remove_all_filters( 'workos_redirect_urls' );
		delete_option( 'workos_production' );
		\WorkOS\Config::set_active_environment( 'staging' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		parent::tearDown();
	}

	/**
	 * Create a WP_User with a specific role and WorkOS link.
	 *
	 * @param string $role WP role.
	 *
	 * @return \WP_User
	 */
	private function create_workos_user( string $role = 'subscriber' ): \WP_User {
		$user_id = self::factory()->user->create( [ 'role' => $role ] );
		update_user_meta( $user_id, '_workos_user_id', 'user_redirect_' . wp_rand() );
		return get_user_by( 'id', $user_id );
	}

	/**
	 * Test returns role_url for matching role.
	 */
	public function test_returns_role_url_for_matching_role(): void {
		$opts                  = get_option( 'workos_production' );
		$opts['redirect_urls'] = [
			'subscriber' => [ 'url' => 'https://example.com/dashboard', 'first_login_only' => false ],
		];
		update_option( 'workos_production', $opts );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$user   = $this->create_workos_user( 'subscriber' );
		$result = Redirect::resolve( admin_url(), $user );

		$this->assertSame( 'https://example.com/dashboard', $result );
	}

	/**
	 * Test falls back to __default__ entry.
	 */
	public function test_falls_back_to_default_entry(): void {
		$opts                  = get_option( 'workos_production' );
		$opts['redirect_urls'] = [
			'__default__' => [ 'url' => 'https://example.com/welcome', 'first_login_only' => false ],
		];
		update_option( 'workos_production', $opts );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$user   = $this->create_workos_user( 'editor' );
		$result = Redirect::resolve( admin_url(), $user );

		$this->assertSame( 'https://example.com/welcome', $result );
	}

	/**
	 * Test respects first_login_only flag.
	 */
	public function test_respects_first_login_only_flag(): void {
		$opts                  = get_option( 'workos_production' );
		$opts['redirect_urls'] = [
			'subscriber' => [ 'url' => 'https://example.com/first', 'first_login_only' => true ],
		];
		update_option( 'workos_production', $opts );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		// Not first login → should not redirect.
		$user   = $this->create_workos_user( 'subscriber' );
		$result = Redirect::resolve( admin_url(), $user );

		$this->assertNotSame( 'https://example.com/first', $result );
	}

	/**
	 * Test first_login_only works on first login.
	 */
	public function test_first_login_only_works_on_first_login(): void {
		$opts                  = get_option( 'workos_production' );
		$opts['redirect_urls'] = [
			'subscriber' => [ 'url' => 'https://example.com/first', 'first_login_only' => true ],
		];
		update_option( 'workos_production', $opts );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$user = $this->create_workos_user( 'subscriber' );
		update_user_meta( $user->ID, '_workos_first_login', '1' );

		$result = Redirect::resolve( admin_url(), $user );

		$this->assertSame( 'https://example.com/first', $result );
	}

	/**
	 * Test clears first_login flag after redirect.
	 */
	public function test_clears_first_login_flag(): void {
		$opts                  = get_option( 'workos_production' );
		$opts['redirect_urls'] = [
			'subscriber' => [ 'url' => 'https://example.com/first', 'first_login_only' => true ],
		];
		update_option( 'workos_production', $opts );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$user = $this->create_workos_user( 'subscriber' );
		update_user_meta( $user->ID, '_workos_first_login', '1' );

		Redirect::resolve( admin_url(), $user );

		$this->assertFalse( Redirect::is_first_login( $user->ID ) );
	}

	/**
	 * Test skips when filter disabled.
	 */
	public function test_skips_when_filter_disabled(): void {
		$opts                  = get_option( 'workos_production' );
		$opts['redirect_urls'] = [
			'subscriber' => [ 'url' => 'https://example.com/nope', 'first_login_only' => false ],
		];
		update_option( 'workos_production', $opts );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		add_filter( 'workos_redirect_should_apply', '__return_false' );

		$user   = $this->create_workos_user( 'subscriber' );
		$result = Redirect::resolve( admin_url(), $user );

		$this->assertSame( admin_url(), $result );
	}

	/**
	 * Test skips for explicit custom redirect_to.
	 */
	public function test_skips_for_explicit_redirect_to(): void {
		$opts                  = get_option( 'workos_production' );
		$opts['redirect_urls'] = [
			'subscriber' => [ 'url' => 'https://example.com/mapped', 'first_login_only' => false ],
		];
		update_option( 'workos_production', $opts );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$user   = $this->create_workos_user( 'subscriber' );
		$result = Redirect::resolve( 'https://example.com/custom-page', $user );

		$this->assertSame( 'https://example.com/custom-page', $result );
	}

	/**
	 * Test converts relative to absolute URL.
	 */
	public function test_converts_relative_to_absolute_url(): void {
		$opts                  = get_option( 'workos_production' );
		$opts['redirect_urls'] = [
			'subscriber' => [ 'url' => '/my-dashboard', 'first_login_only' => false ],
		];
		update_option( 'workos_production', $opts );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$user   = $this->create_workos_user( 'subscriber' );
		$result = Redirect::resolve( admin_url(), $user );

		$this->assertSame( home_url( '/my-dashboard' ), $result );
	}

	/**
	 * Test returns admin_url when no match.
	 */
	public function test_returns_admin_url_when_no_match(): void {
		$opts                  = get_option( 'workos_production' );
		$opts['redirect_urls'] = [];
		update_option( 'workos_production', $opts );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$user   = $this->create_workos_user( 'subscriber' );
		$result = Redirect::resolve( '', $user );

		$this->assertSame( admin_url(), $result );
	}

	/**
	 * Test normalizes legacy string entries.
	 */
	public function test_normalizes_legacy_string_entries(): void {
		$opts                  = get_option( 'workos_production' );
		$opts['redirect_urls'] = [
			'subscriber' => 'https://example.com/legacy',
		];
		update_option( 'workos_production', $opts );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$user   = $this->create_workos_user( 'subscriber' );
		$result = Redirect::resolve( admin_url(), $user );

		$this->assertSame( 'https://example.com/legacy', $result );
	}
}
