<?php
/**
 * Tests for UserSync::find_or_create_wp_user().
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Sync\UserSync;

/**
 * Find-or-create fallback path tests.
 */
class UserSyncFindOrCreateTest extends WPTestCase {

	/**
	 * Set up each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Enable plugin.
		update_option( 'workos_active_environment', 'production' );
		update_option( 'workos_production', [
			'api_key'        => 'sk_test_fake',
			'client_id'      => 'client_fake',
			'environment_id' => 'environment_test',
		] );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		// Intercept HTTP to prevent real API calls.
		add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );

		remove_all_actions( 'user_register' );
		remove_all_actions( 'profile_update' );
	}

	/**
	 * Tear down each test.
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10 );
		delete_option( 'workos_production' );
		delete_option( 'workos_active_environment' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$ref = new \ReflectionProperty( UserSync::class, 'syncing' );
		$ref->setAccessible( true );
		$ref->setValue( null, false );

		parent::tearDown();
	}

	/**
	 * Intercept HTTP requests.
	 *
	 * @param false|array $response Pre-filtered response.
	 * @param array       $args     Request arguments.
	 * @param string      $url      Request URL.
	 *
	 * @return array|false Fake response for WorkOS API calls.
	 */
	public function intercept_http( $response, array $args, string $url ) {
		if ( false === strpos( $url, 'api.workos.com' ) ) {
			return $response;
		}

		return [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'body'     => wp_json_encode( [ 'data' => [] ] ),
		];
	}

	/**
	 * Test returns error when WorkOS ID is missing.
	 */
	public function test_returns_error_when_workos_id_missing(): void {
		$result = UserSync::find_or_create_wp_user( [ 'email' => 'test@example.com' ] );

		$this->assertWPError( $result );
		$this->assertSame( 'workos_invalid_user', $result->get_error_code() );
	}

	/**
	 * Test returns error when email is missing.
	 */
	public function test_returns_error_when_email_missing(): void {
		$result = UserSync::find_or_create_wp_user( [ 'id' => 'user_123' ] );

		$this->assertWPError( $result );
		$this->assertSame( 'workos_invalid_user', $result->get_error_code() );
	}

	/**
	 * Test finds user by WorkOS ID meta (existing linked user).
	 */
	public function test_finds_user_by_workos_id_meta(): void {
		$user_id = self::factory()->user->create( [ 'user_email' => 'linked@example.com' ] );
		update_user_meta( $user_id, '_workos_user_id', 'user_linked_123' );

		$result = UserSync::find_or_create_wp_user(
			[
				'id'    => 'user_linked_123',
				'email' => 'linked@example.com',
			]
		);

		$this->assertInstanceOf( \WP_User::class, $result );
		$this->assertSame( $user_id, $result->ID );
	}

	/**
	 * Test finds by WorkOS ID even if email differs.
	 */
	public function test_finds_by_workos_id_even_if_email_differs(): void {
		$user_id = self::factory()->user->create( [ 'user_email' => 'old@example.com' ] );
		update_user_meta( $user_id, '_workos_user_id', 'user_email_change' );

		$result = UserSync::find_or_create_wp_user(
			[
				'id'    => 'user_email_change',
				'email' => 'new@example.com',
			]
		);

		$this->assertInstanceOf( \WP_User::class, $result );
		$this->assertSame( $user_id, $result->ID );
	}

	/**
	 * Test auto-links by email match.
	 */
	public function test_auto_links_by_email_match(): void {
		$user_id = self::factory()->user->create( [ 'user_email' => 'existing@example.com' ] );

		$result = UserSync::find_or_create_wp_user(
			[
				'id'    => 'user_new_workos_id',
				'email' => 'existing@example.com',
			]
		);

		$this->assertInstanceOf( \WP_User::class, $result );
		$this->assertSame( $user_id, $result->ID );
		$this->assertSame( 'user_new_workos_id', get_user_meta( $user_id, '_workos_user_id', true ) );
	}

	/**
	 * Test creates new user when no match.
	 */
	public function test_creates_new_user_when_no_match(): void {
		$result = UserSync::find_or_create_wp_user(
			[
				'id'         => 'user_brand_new',
				'email'      => 'brand_new@example.com',
				'first_name' => 'Brand',
				'last_name'  => 'New',
			]
		);

		$this->assertInstanceOf( \WP_User::class, $result );
		$this->assertSame( 'brand_new@example.com', $result->user_email );
		$this->assertSame( 'Brand', $result->first_name );
		$this->assertSame( 'New', $result->last_name );
		$this->assertSame( 'user_brand_new', get_user_meta( $result->ID, '_workos_user_id', true ) );
		$this->assertSame( '1', get_user_meta( $result->ID, '_workos_first_login', true ) );
	}

	/**
	 * Test created user gets default role.
	 */
	public function test_created_user_gets_default_role(): void {
		$result = UserSync::find_or_create_wp_user(
			[
				'id'    => 'user_role_check',
				'email' => 'rolecheck@example.com',
			]
		);

		$this->assertInstanceOf( \WP_User::class, $result );
		$default_role = get_option( 'default_role', 'subscriber' );
		$this->assertTrue( in_array( $default_role, $result->roles, true ) );
	}

	/**
	 * Test fires workos_user_created action for new users.
	 */
	public function test_fires_workos_user_created_action(): void {
		$fired = false;
		add_action( 'workos_user_created', function () use ( &$fired ) {
			$fired = true;
		} );

		UserSync::find_or_create_wp_user(
			[
				'id'    => 'user_action_test',
				'email' => 'action_test@example.com',
			]
		);

		$this->assertTrue( $fired, 'workos_user_created action should fire for new user creation.' );
	}

	/**
	 * Test does not fire workos_user_created for email match (auto-link).
	 */
	public function test_does_not_fire_for_email_match(): void {
		self::factory()->user->create( [ 'user_email' => 'nofire@example.com' ] );

		$fired = false;
		add_action( 'workos_user_created', function () use ( &$fired ) {
			$fired = true;
		} );

		UserSync::find_or_create_wp_user(
			[
				'id'    => 'user_nofire',
				'email' => 'nofire@example.com',
			]
		);

		$this->assertFalse( $fired, 'workos_user_created should NOT fire for email-match auto-links.' );
	}

	/**
	 * Test generates unique username on conflict.
	 */
	public function test_generates_unique_username_on_conflict(): void {
		// Create a user with the username that would conflict.
		self::factory()->user->create( [ 'user_login' => 'conflicting', 'user_email' => 'other@example.com' ] );

		$result = UserSync::find_or_create_wp_user(
			[
				'id'    => 'user_conflict_test',
				'email' => 'conflicting@example.com',
			]
		);

		$this->assertInstanceOf( \WP_User::class, $result );
		$this->assertNotSame( 'conflicting', $result->user_login );
		$this->assertStringStartsWith( 'conflicting', $result->user_login );
	}
}
