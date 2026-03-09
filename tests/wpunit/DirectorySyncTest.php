<?php
/**
 * Tests for DirectorySync handlers.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Sync\DirectorySync;
use WorkOS\Sync\UserSync;

/**
 * Directory sync (SCIM) webhook handler tests.
 */
class DirectorySyncTest extends WPTestCase {

	/**
	 * DirectorySync instance.
	 *
	 * @var DirectorySync
	 */
	private DirectorySync $dsync;

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

		// Intercept HTTP to prevent real API calls.
		add_filter( 'pre_http_request', [ $this, 'block_http' ], 10, 3 );

		$this->dsync = new DirectorySync();

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
		\WorkOS\Config::set_active_environment( 'staging' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$ref = new \ReflectionProperty( UserSync::class, 'syncing' );
		$ref->setAccessible( true );
		$ref->setValue( null, false );

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
				'body'     => wp_json_encode( [ 'data' => [] ] ),
			];
		}
		return $response;
	}

	/**
	 * Test normalize extracts primary email.
	 */
	public function test_normalize_extracts_primary_email(): void {
		$this->dsync->handle_user_created( [
			'data' => [
				'id'     => 'dsync_user_primary',
				'emails' => [
					[ 'value' => 'secondary@example.com', 'primary' => false ],
					[ 'value' => 'primary@example.com', 'primary' => true ],
				],
			],
		] );

		$user = get_user_by( 'email', 'primary@example.com' );
		$this->assertInstanceOf( \WP_User::class, $user );
	}

	/**
	 * Test normalize falls back to first email.
	 */
	public function test_normalize_falls_back_to_first_email(): void {
		$this->dsync->handle_user_created( [
			'data' => [
				'id'     => 'dsync_user_first',
				'emails' => [
					[ 'value' => 'first@example.com' ],
					[ 'value' => 'second@example.com' ],
				],
			],
		] );

		$user = get_user_by( 'email', 'first@example.com' );
		$this->assertInstanceOf( \WP_User::class, $user );
	}

	/**
	 * Test normalize falls back to email field.
	 */
	public function test_normalize_falls_back_to_email_field(): void {
		$this->dsync->handle_user_created( [
			'data' => [
				'id'    => 'dsync_user_direct',
				'email' => 'direct@example.com',
			],
		] );

		$user = get_user_by( 'email', 'direct@example.com' );
		$this->assertInstanceOf( \WP_User::class, $user );
	}

	/**
	 * Test normalize returns null when no email.
	 */
	public function test_normalize_returns_null_when_no_email(): void {
		$before_count = count_users()['total_users'];

		$this->dsync->handle_user_created( [
			'data' => [
				'id' => 'dsync_user_no_email',
			],
		] );

		$after_count = count_users()['total_users'];
		$this->assertSame( $before_count, $after_count, 'No user should be created without email.' );
	}

	/**
	 * Test handle_user_created provisions WP user.
	 */
	public function test_handle_user_created_provisions_wp_user(): void {
		$this->dsync->handle_user_created( [
			'data' => [
				'id'         => 'dsync_provision_user',
				'email'      => 'provisioned@example.com',
				'first_name' => 'Directory',
				'last_name'  => 'User',
			],
		] );

		$user = get_user_by( 'email', 'provisioned@example.com' );
		$this->assertInstanceOf( \WP_User::class, $user );
		$this->assertSame( 'dsync_provision_user', get_user_meta( $user->ID, '_workos_user_id', true ) );
	}

	/**
	 * Test handle_user_deleted deprovisions.
	 */
	public function test_handle_user_deleted_deprovisions(): void {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, '_workos_user_id', 'dsync_delete_user' );

		$opts                       = get_option( 'workos_production' );
		$opts['deprovision_action'] = 'deactivate';
		update_option( 'workos_production', $opts );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$this->dsync->handle_user_deleted( [
			'data' => [ 'id' => 'dsync_delete_user' ],
		] );

		$this->assertSame( '1', get_user_meta( $user_id, '_workos_deactivated', true ) );
	}

	/**
	 * Test handle_group_user_added sets role.
	 */
	public function test_handle_group_user_added_sets_role(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		update_user_meta( $user_id, '_workos_user_id', 'dsync_group_user' );

		$this->dsync->handle_group_user_added( [
			'data' => [
				'user'  => [ 'id' => 'dsync_group_user' ],
				'group' => [ 'name' => 'Admin' ],
			],
		] );

		$user = get_user_by( 'id', $user_id );
		$this->assertTrue( in_array( 'administrator', $user->roles, true ) );
	}

	/**
	 * Test handle_group_user_removed resets to default.
	 */
	public function test_handle_group_user_removed_resets_to_default(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		update_user_meta( $user_id, '_workos_user_id', 'dsync_group_remove' );

		$this->dsync->handle_group_user_removed( [
			'data' => [
				'user'  => [ 'id' => 'dsync_group_remove' ],
				'group' => [ 'name' => 'Admin' ],
			],
		] );

		$user = get_user_by( 'id', $user_id );
		$this->assertTrue( in_array( 'subscriber', $user->roles, true ) );
	}
}
