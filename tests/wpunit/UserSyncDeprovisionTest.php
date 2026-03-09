<?php
/**
 * Tests for UserSync::deprovision_user().
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Sync\UserSync;

/**
 * Deprovision strategy tests.
 */
class UserSyncDeprovisionTest extends WPTestCase {

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

		// Prevent HTTP calls.
		add_filter( 'pre_http_request', [ $this, 'block_http' ], 10, 3 );

		remove_all_actions( 'user_register' );
		remove_all_actions( 'profile_update' );
		remove_all_actions( 'set_user_role' );
	}

	/**
	 * Tear down each test.
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'block_http' ], 10 );
		delete_option( 'workos_production' );
		delete_option( 'workos_active_environment' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		parent::tearDown();
	}

	/**
	 * Block outgoing HTTP.
	 *
	 * @param false|array $response Pre-filtered response.
	 * @param array       $args     Request arguments.
	 * @param string      $url      Request URL.
	 *
	 * @return array|false
	 */
	public function block_http( $response, array $args, string $url ) {
		if ( false !== strpos( $url, 'api.workos.com' ) ) {
			return [
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'body'     => wp_json_encode( [] ),
			];
		}
		return $response;
	}

	/**
	 * Test deactivate sets meta flag (default action).
	 */
	public function test_deactivate_sets_meta_flag(): void {
		$opts                       = get_option( 'workos_production' );
		$opts['deprovision_action'] = 'deactivate';
		update_option( 'workos_production', $opts );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$user_id = self::factory()->user->create( [ 'role' => 'editor' ] );

		UserSync::deprovision_user( $user_id );

		$this->assertSame( '1', get_user_meta( $user_id, '_workos_deactivated', true ) );
		// Should still have editor role.
		$user = get_user_by( 'id', $user_id );
		$this->assertTrue( in_array( 'editor', $user->roles, true ) );
	}

	/**
	 * Test demote sets subscriber role.
	 */
	public function test_demote_sets_subscriber_role(): void {
		$opts                       = get_option( 'workos_production' );
		$opts['deprovision_action'] = 'demote';
		update_option( 'workos_production', $opts );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

		UserSync::deprovision_user( $user_id );

		$user = get_user_by( 'id', $user_id );
		$this->assertTrue( in_array( 'subscriber', $user->roles, true ) );
		$this->assertSame( '1', get_user_meta( $user_id, '_workos_deactivated', true ) );
	}

	/**
	 * Test delete removes user.
	 */
	public function test_delete_removes_user(): void {
		$opts                       = get_option( 'workos_production' );
		$opts['deprovision_action'] = 'delete';
		$opts['reassign_user']      = 0;
		update_option( 'workos_production', $opts );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$user_id = self::factory()->user->create();

		UserSync::deprovision_user( $user_id );

		$this->assertFalse( get_user_by( 'id', $user_id ) );
	}

	/**
	 * Test delete reassigns content when configured.
	 */
	public function test_delete_reassigns_content(): void {
		$opts                       = get_option( 'workos_production' );
		$opts['deprovision_action'] = 'delete';
		$opts['reassign_user']      = 0;
		update_option( 'workos_production', $opts );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$user_id  = self::factory()->user->create();
		$post_id  = self::factory()->post->create( [ 'post_author' => $user_id ] );

		// Now set reassign to admin.
		$opts['reassign_user'] = $admin_id;
		update_option( 'workos_production', $opts );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		UserSync::deprovision_user( $user_id );

		$post = get_post( $post_id );
		$this->assertEquals( $admin_id, $post->post_author );
	}

	/**
	 * Test deactivate is default for unknown action.
	 */
	public function test_deactivate_is_default_for_unknown_action(): void {
		$opts                       = get_option( 'workos_production' );
		$opts['deprovision_action'] = 'unknown_action';
		update_option( 'workos_production', $opts );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$user_id = self::factory()->user->create( [ 'role' => 'editor' ] );

		UserSync::deprovision_user( $user_id );

		$this->assertSame( '1', get_user_meta( $user_id, '_workos_deactivated', true ) );
		$user = get_user_by( 'id', $user_id );
		$this->assertTrue( in_array( 'editor', $user->roles, true ) );
	}

	/**
	 * Test deprovision via webhook handler calls handle_user_deleted.
	 */
	public function test_deprovision_via_webhook_handler(): void {
		$opts                       = get_option( 'workos_production' );
		$opts['deprovision_action'] = 'deactivate';
		update_option( 'workos_production', $opts );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, '_workos_user_id', 'user_to_deprovision' );

		$sync = new UserSync();
		$sync->handle_user_deleted( [
			'data' => [ 'id' => 'user_to_deprovision' ],
		] );

		$this->assertSame( '1', get_user_meta( $user_id, '_workos_deactivated', true ) );
	}
}
