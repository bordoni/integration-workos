<?php
/**
 * Tests for PasswordReset::block_reset_for_workos_users().
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Auth\PasswordReset;

/**
 * Password reset blocking tests.
 */
class PasswordResetTest extends WPTestCase {

	/**
	 * PasswordReset instance.
	 *
	 * @var PasswordReset
	 */
	private PasswordReset $reset;

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

		$this->reset = new PasswordReset();

		remove_all_actions( 'user_register' );
	}

	/**
	 * Tear down each test.
	 */
	public function tearDown(): void {
		delete_option( 'workos_production' );
		delete_option( 'workos_active_environment' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		parent::tearDown();
	}

	/**
	 * Test blocks reset for linked user.
	 */
	public function test_blocks_reset_for_linked_user(): void {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, '_workos_user_id', 'user_linked_pw_reset' );

		$result = $this->reset->block_reset_for_workos_users( true, $user_id );

		$this->assertWPError( $result );
		$this->assertSame( 'workos_no_password_reset', $result->get_error_code() );
	}

	/**
	 * Test allows reset for unlinked user.
	 */
	public function test_allows_reset_for_unlinked_user(): void {
		$user_id = self::factory()->user->create();

		$result = $this->reset->block_reset_for_workos_users( true, $user_id );

		$this->assertTrue( $result );
	}

	/**
	 * Test allows reset when plugin disabled.
	 */
	public function test_allows_reset_when_plugin_disabled(): void {
		delete_option( 'workos_production' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, '_workos_user_id', 'user_linked' );

		$result = $this->reset->block_reset_for_workos_users( true, $user_id );

		$this->assertTrue( $result );
	}

	/**
	 * Test error message mentions SSO.
	 */
	public function test_error_message_mentions_sso(): void {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, '_workos_user_id', 'user_sso_msg' );

		$result = $this->reset->block_reset_for_workos_users( true, $user_id );

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'SSO', $result->get_error_message() );
	}
}
