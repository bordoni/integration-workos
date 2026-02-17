<?php
/**
 * Tests for UserSync::push_user_to_workos().
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Sync\UserSync;

/**
 * Outbound user creation sync tests.
 */
class UserSyncPushTest extends WPTestCase {

	/**
	 * UserSync instance under test.
	 *
	 * @var UserSync
	 */
	private UserSync $sync;

	/**
	 * Captured HTTP requests (URL + body).
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
		update_option( 'workos_active_environment', 'production' );
		update_option( 'workos_production', [
			'api_key'        => 'sk_test_fake',
			'client_id'      => 'client_fake',
			'environment_id' => 'environment_test',
		] );

		// Reset Options singletons so they re-read from DB.
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		// Intercept outgoing HTTP requests to the WorkOS API.
		add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );

		// Create a fresh instance for calling methods directly.
		$this->sync = new UserSync();

		// Remove user_register hooks so factory()->user->create() doesn't
		// trigger automatic pushes — we call push_user_to_workos() explicitly.
		remove_all_actions( 'user_register' );
	}

	/**
	 * Tear down each test.
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10 );
		delete_option( 'workos_production' );
		delete_option( 'workos_active_environment' );

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
	 * @return array Fake HTTP response.
	 */
	public function intercept_http( $response, array $args, string $url ) {
		if ( false === strpos( $url, 'api.workos.com' ) ) {
			return $response;
		}

		$this->http_requests[] = [
			'url'  => $url,
			'args' => $args,
			'body' => json_decode( $args['body'] ?? '{}', true ),
		];

		if ( null !== $this->mock_response ) {
			return $this->mock_response;
		}

		// Default: successful creation response.
		return [
			'response' => [
				'code'    => 201,
				'message' => 'Created',
			],
			'body'     => wp_json_encode(
				[
					'id'         => 'user_workos_' . wp_rand(),
					'email'      => $this->http_requests[ count( $this->http_requests ) - 1 ]['body']['email'] ?? '',
					'first_name' => $this->http_requests[ count( $this->http_requests ) - 1 ]['body']['first_name'] ?? '',
					'last_name'  => $this->http_requests[ count( $this->http_requests ) - 1 ]['body']['last_name'] ?? '',
				]
			),
		];
	}

	// -------------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------------

	/**
	 * Test successful push creates user in WorkOS and stores meta.
	 */
	public function test_push_creates_workos_user_and_links(): void {
		$user_id = self::factory()->user->create(
			[
				'user_email' => 'alice@example.com',
				'first_name' => 'Alice',
				'last_name'  => 'Smith',
			]
		);

		$userdata = [
			'user_email' => 'alice@example.com',
			'first_name' => 'Alice',
			'last_name'  => 'Smith',
		];

		$this->sync->push_user_to_workos( $user_id, $userdata );

		// Verify an HTTP request was made to the create user endpoint.
		$this->assertCount( 1, $this->http_requests );
		$this->assertStringContainsString( '/user_management/users', $this->http_requests[0]['url'] );

		// Verify the payload.
		$body = $this->http_requests[0]['body'];
		$this->assertSame( 'alice@example.com', $body['email'] );
		$this->assertSame( 'Alice', $body['first_name'] );
		$this->assertSame( 'Smith', $body['last_name'] );
		$this->assertTrue( $body['email_verified'] );

		// Verify WorkOS user ID was saved as meta.
		$workos_id = get_user_meta( $user_id, '_workos_user_id', true );
		$this->assertNotEmpty( $workos_id );
		$this->assertStringStartsWith( 'user_workos_', $workos_id );
	}

	/**
	 * Test push skips when syncing flag is set (webhook-originated user).
	 */
	public function test_push_skips_when_syncing(): void {
		$ref = new \ReflectionProperty( UserSync::class, 'syncing' );
		$ref->setAccessible( true );
		$ref->setValue( null, true );

		$user_id = self::factory()->user->create( [ 'user_email' => 'bob@example.com' ] );

		$this->sync->push_user_to_workos( $user_id, [ 'user_email' => 'bob@example.com' ] );

		$this->assertEmpty( $this->http_requests, 'No API call should be made when $syncing is true.' );
	}

	/**
	 * Test push skips when plugin is not enabled (no API key).
	 */
	public function test_push_skips_when_not_enabled(): void {
		update_option( 'workos_production', [ 'client_id' => 'client_fake' ] );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$user_id = self::factory()->user->create( [ 'user_email' => 'carol@example.com' ] );

		$this->sync->push_user_to_workos( $user_id, [ 'user_email' => 'carol@example.com' ] );

		$this->assertEmpty( $this->http_requests, 'No API call should be made when plugin is disabled.' );
	}

	/**
	 * Test push skips when email is empty.
	 */
	public function test_push_skips_when_email_empty(): void {
		$user_id = self::factory()->user->create( [ 'user_email' => 'dummy@example.com' ] );

		$this->sync->push_user_to_workos( $user_id, [ 'user_email' => '' ] );

		$this->assertEmpty( $this->http_requests, 'No API call should be made when email is empty.' );
	}

	/**
	 * Test push handles API error without breaking.
	 */
	public function test_push_handles_api_error_gracefully(): void {
		$this->mock_response = [
			'response' => [
				'code'    => 422,
				'message' => 'Unprocessable Entity',
			],
			'body'     => wp_json_encode(
				[
					'message' => 'A user with this email already exists.',
					'code'    => 'user_exists',
				]
			),
		];

		$user_id = self::factory()->user->create( [ 'user_email' => 'dupe@example.com' ] );

		// Should not throw or fatally error.
		$this->sync->push_user_to_workos( $user_id, [ 'user_email' => 'dupe@example.com' ] );

		// API was called but no meta should be stored.
		$this->assertCount( 1, $this->http_requests );
		$this->assertEmpty( get_user_meta( $user_id, '_workos_user_id', true ) );
	}

	/**
	 * Test push omits optional fields when not provided.
	 */
	public function test_push_omits_missing_name_fields(): void {
		$user_id = self::factory()->user->create( [ 'user_email' => 'minimal@example.com' ] );

		$this->sync->push_user_to_workos( $user_id, [ 'user_email' => 'minimal@example.com' ] );

		$body = $this->http_requests[0]['body'];
		$this->assertSame( 'minimal@example.com', $body['email'] );
		$this->assertTrue( $body['email_verified'] );
		$this->assertArrayNotHasKey( 'first_name', $body );
		$this->assertArrayNotHasKey( 'last_name', $body );
	}

	/**
	 * Test handle_user_created sets syncing flag so push_user_to_workos is skipped.
	 */
	public function test_handle_user_created_prevents_echo_back(): void {
		$event = [
			'data' => [
				'id'         => 'user_workos_from_webhook',
				'email'      => 'webhook@example.com',
				'first_name' => 'Web',
				'last_name'  => 'Hook',
			],
		];

		$this->sync->handle_user_created( $event );

		// The webhook handler should have created a WP user without calling the API.
		$wp_user = get_user_by( 'email', 'webhook@example.com' );
		$this->assertInstanceOf( \WP_User::class, $wp_user );

		// Syncing flag should be reset after the handler completes.
		$this->assertFalse( UserSync::is_syncing() );

		// No outbound API call should have been made by push_user_to_workos.
		$create_calls = array_filter(
			$this->http_requests,
			static function ( $req ) {
				return 'POST' === ( $req['args']['method'] ?? 'POST' )
					&& false !== strpos( $req['url'], '/user_management/users' );
			}
		);
		$this->assertEmpty( $create_calls, 'Webhook-created users must not trigger an outbound API call.' );
	}
}
