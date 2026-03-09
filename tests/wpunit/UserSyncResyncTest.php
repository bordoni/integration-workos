<?php
/**
 * Tests for UserSync::resync_from_workos().
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Sync\UserSync;

/**
 * Inbound re-sync (WorkOS → WP) tests.
 */
class UserSyncResyncTest extends WPTestCase {

	/**
	 * Captured HTTP requests (URL + args).
	 *
	 * @var array
	 */
	private array $http_requests = [];

	/**
	 * Canned API response to return from the mock.
	 *
	 * @var array|null
	 */
	private ?array $mock_response = null;

	/**
	 * Set up each test.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->http_requests = [];
		$this->mock_response = null;

		// Make is_enabled() return true (requires api_key + client_id + environment_id).
		\WorkOS\Config::set_active_environment( 'production' );
		update_option( 'workos_production', [
			'api_key'        => 'sk_test_fake',
			'client_id'      => 'client_fake',
			'environment_id' => 'environment_test',
		] );

		// Reset Options singletons so they re-read from DB.
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		// Intercept outgoing HTTP requests to the WorkOS API.
		add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );

		// Remove hooks so factory user creation doesn't trigger pushes.
		remove_all_actions( 'user_register' );
		remove_all_actions( 'profile_update' );
	}

	/**
	 * Tear down each test.
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10 );
		delete_option( 'workos_production' );
		\WorkOS\Config::set_active_environment( 'staging' );

		// Reset Options singletons so they re-read from DB.
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		// Reset the static syncing flag via reflection.
		$ref = new \ReflectionProperty( UserSync::class, 'syncing' );
		$ref->setAccessible( true );
		$ref->setValue( null, false );

		parent::tearDown();
	}

	/**
	 * Mock HTTP transport — captures requests and returns a canned response.
	 *
	 * @param false|array $response Pre-filtered response.
	 * @param array       $args     Request arguments.
	 * @param string      $url      Request URL.
	 *
	 * @return array|false Fake HTTP response or passthrough.
	 */
	public function intercept_http( $response, array $args, string $url ) {
		if ( false === strpos( $url, 'api.workos.com' ) ) {
			return $response;
		}

		$this->http_requests[] = [
			'url'  => $url,
			'args' => $args,
		];

		if ( null !== $this->mock_response ) {
			return $this->mock_response;
		}

		// Default: successful GET response for a user.
		return [
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'body'     => wp_json_encode(
				[
					'id'         => 'user_workos_resync',
					'email'      => 'updated@example.com',
					'first_name' => 'Updated',
					'last_name'  => 'Name',
				]
			),
		];
	}

	/**
	 * Helper — create a WP user and link it to a WorkOS ID.
	 *
	 * @param array  $user_args WP user args.
	 * @param string $workos_id WorkOS user ID.
	 *
	 * @return int WP user ID.
	 */
	private function create_linked_user( array $user_args = [], string $workos_id = 'user_workos_resync' ): int {
		$defaults = [
			'user_email' => 'original@example.com',
			'first_name' => 'Original',
			'last_name'  => 'User',
		];

		$user_id = self::factory()->user->create( array_merge( $defaults, $user_args ) );
		update_user_meta( $user_id, '_workos_user_id', $workos_id );

		return $user_id;
	}

	// -------------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------------

	/**
	 * Test successful re-sync updates WP user profile from WorkOS.
	 */
	public function test_resync_updates_wp_user_from_workos(): void {
		$user_id = $this->create_linked_user();

		$result = UserSync::resync_from_workos( $user_id );

		// Should return the WorkOS user data array.
		$this->assertIsArray( $result );
		$this->assertSame( 'user_workos_resync', $result['id'] );

		// Verify an HTTP GET was made to the correct endpoint.
		$this->assertCount( 1, $this->http_requests );
		$this->assertStringContainsString( '/user_management/users/user_workos_resync', $this->http_requests[0]['url'] );

		// Verify WP profile was updated.
		$user = get_user_by( 'id', $user_id );
		$this->assertSame( 'updated@example.com', $user->user_email );
		$this->assertSame( 'Updated', $user->first_name );
		$this->assertSame( 'Name', $user->last_name );

		// Verify sync meta was updated.
		$this->assertNotEmpty( get_user_meta( $user_id, '_workos_last_synced_at', true ) );
		$this->assertNotEmpty( get_user_meta( $user_id, '_workos_profile_hash', true ) );
	}

	/**
	 * Test re-sync returns error when user is not linked.
	 */
	public function test_resync_returns_error_for_unlinked_user(): void {
		$user_id = self::factory()->user->create( [ 'user_email' => 'nolink@example.com' ] );

		$result = UserSync::resync_from_workos( $user_id );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'workos_not_linked', $result->get_error_code() );
		$this->assertEmpty( $this->http_requests, 'No API call should be made for unlinked users.' );
	}

	/**
	 * Test re-sync returns error when syncing flag is already set.
	 */
	public function test_resync_returns_error_when_syncing(): void {
		$ref = new \ReflectionProperty( UserSync::class, 'syncing' );
		$ref->setAccessible( true );
		$ref->setValue( null, true );

		$user_id = $this->create_linked_user();

		$result = UserSync::resync_from_workos( $user_id );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'workos_sync_in_progress', $result->get_error_code() );
		$this->assertEmpty( $this->http_requests );
	}

	/**
	 * Test re-sync returns error when plugin is not enabled.
	 */
	public function test_resync_returns_error_when_not_enabled(): void {
		// Remove the API key to disable the plugin.
		update_option( 'workos_production', [ 'client_id' => 'client_fake' ] );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$user_id = $this->create_linked_user();

		$result = UserSync::resync_from_workos( $user_id );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'workos_not_configured', $result->get_error_code() );
		$this->assertEmpty( $this->http_requests );
	}

	/**
	 * Test re-sync returns error when API call fails.
	 */
	public function test_resync_returns_error_on_api_failure(): void {
		$this->mock_response = [
			'response' => [
				'code'    => 404,
				'message' => 'Not Found',
			],
			'body'     => wp_json_encode(
				[
					'message' => 'User not found.',
					'code'    => 'user_not_found',
				]
			),
		];

		$user_id = $this->create_linked_user();

		$result = UserSync::resync_from_workos( $user_id );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'workos_api_error', $result->get_error_code() );

		// API was still called.
		$this->assertCount( 1, $this->http_requests );
	}

	/**
	 * Test re-sync resets the syncing flag after completion.
	 */
	public function test_resync_resets_syncing_flag(): void {
		$user_id = $this->create_linked_user();

		UserSync::resync_from_workos( $user_id );

		$this->assertFalse( UserSync::is_syncing(), 'Syncing flag should be false after re-sync completes.' );
	}
}
