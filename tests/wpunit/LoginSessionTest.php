<?php
/**
 * Tests for Login::session_expiration() and maybe_disable_password_fallback().
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Auth\Login;

/**
 * Session and password fallback tests.
 */
class LoginSessionTest extends WPTestCase {

	/**
	 * Login instance.
	 *
	 * @var Login
	 */
	private Login $login;

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

		$this->login = new Login();

		remove_all_actions( 'user_register' );
	}

	/**
	 * Tear down each test.
	 */
	public function tearDown(): void {
		delete_option( 'workos_production' );
		\WorkOS\Config::set_active_environment( 'staging' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		parent::tearDown();
	}

	/**
	 * Test session_expiration returns DAY_IN_SECONDS for WorkOS user (remember=false).
	 */
	public function test_session_expiration_day_for_workos_user(): void {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, '_workos_user_id', 'user_session_test' );

		$result = $this->login->session_expiration( 2 * DAY_IN_SECONDS, $user_id, false );

		$this->assertSame( DAY_IN_SECONDS, $result );
	}

	/**
	 * Test returns 14 * DAY_IN_SECONDS for remember=true.
	 */
	public function test_session_expiration_14_days_for_remember(): void {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, '_workos_user_id', 'user_session_test' );

		$result = $this->login->session_expiration( 2 * DAY_IN_SECONDS, $user_id, true );

		$this->assertSame( 14 * DAY_IN_SECONDS, $result );
	}

	/**
	 * Test passthrough for non-WorkOS user.
	 */
	public function test_session_expiration_passthrough_for_non_workos_user(): void {
		$user_id = self::factory()->user->create();

		$original = 2 * DAY_IN_SECONDS;
		$result   = $this->login->session_expiration( $original, $user_id, false );

		$this->assertSame( $original, $result );
	}

	/**
	 * Test disable_password_fallback removes authenticators when not allowed.
	 */
	public function test_disable_password_fallback_removes_authenticators(): void {
		$opts                           = get_option( 'workos_production' );
		$opts['allow_password_fallback'] = false;
		update_option( 'workos_production', $opts );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		// Re-add the default authenticators first.
		add_filter( 'authenticate', 'wp_authenticate_username_password', 20, 3 );
		add_filter( 'authenticate', 'wp_authenticate_email_password', 20, 3 );

		$this->login->maybe_disable_password_fallback();

		$this->assertFalse( has_filter( 'authenticate', 'wp_authenticate_username_password' ) );
		$this->assertFalse( has_filter( 'authenticate', 'wp_authenticate_email_password' ) );
	}

	/**
	 * Test keeps authenticators when fallback is allowed.
	 */
	public function test_keeps_authenticators_when_fallback_allowed(): void {
		$opts                           = get_option( 'workos_production' );
		$opts['allow_password_fallback'] = true;
		update_option( 'workos_production', $opts );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		add_filter( 'authenticate', 'wp_authenticate_username_password', 20, 3 );

		$this->login->maybe_disable_password_fallback();

		$this->assertSame( 20, has_filter( 'authenticate', 'wp_authenticate_username_password' ) );
	}

	/**
	 * Test skips when plugin not enabled.
	 */
	public function test_disable_password_fallback_skips_when_not_enabled(): void {
		delete_option( 'workos_production' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		add_filter( 'authenticate', 'wp_authenticate_username_password', 20, 3 );

		$this->login->maybe_disable_password_fallback();

		$this->assertSame( 20, has_filter( 'authenticate', 'wp_authenticate_username_password' ) );
	}
}
