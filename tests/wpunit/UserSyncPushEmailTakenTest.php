<?php
/**
 * Tests for pushing a WP user whose email already belongs to a WorkOS user.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Sync\UserSync;

/**
 * `push_user_to_workos()` and `sync_existing_user()` on `email_not_available`,
 * the pending match, `workos_user_push_failed`, and the
 * `workos_push_user_email_verified` filter.
 */
class UserSyncPushEmailTakenTest extends WPTestCase {

	/**
	 * UserSync instance under test.
	 *
	 * @var UserSync
	 */
	private UserSync $sync;

	/**
	 * Captured HTTP requests (method, URL, body).
	 *
	 * @var array
	 */
	private array $http_requests = [];

	/**
	 * Response to `POST /user_management/users`, or null for a 201.
	 *
	 * @var array|null
	 */
	private ?array $create_response = null;

	/**
	 * Users returned by `GET /user_management/users?email=…`.
	 *
	 * @var array
	 */
	private array $listed_users = [];

	/**
	 * Captured `workos_user_push_failed` calls.
	 *
	 * @var array
	 */
	private array $push_failed = [];

	/**
	 * Set up each test.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->http_requests   = [];
		$this->create_response = null;
		$this->listed_users    = [];
		$this->push_failed     = [];

		\WorkOS\Config::set_active_environment( 'production' );
		update_option(
			'workos_production',
			[
				'api_key'        => 'sk_test_fake',
				'client_id'      => 'client_fake',
				'environment_id' => 'environment_test',
			]
		);
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );
		add_action( 'workos_user_push_failed', [ $this, 'capture_push_failed' ], 10, 3 );

		$this->sync = new UserSync();

		// Factory users must not be pushed on their own; tests push explicitly.
		remove_all_actions( 'user_register' );
	}

	/**
	 * Tear down each test.
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10 );
		remove_action( 'workos_user_push_failed', [ $this, 'capture_push_failed' ], 10 );
		remove_all_filters( 'workos_push_user_email_verified' );
		remove_all_filters( 'workos_relink_protection_enabled' );
		delete_option( 'workos_production' );
		\WorkOS\Config::set_active_environment( 'staging' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$ref = new \ReflectionProperty( UserSync::class, 'syncing' );
		$ref->setAccessible( true );
		$ref->setValue( null, false );

		parent::tearDown();
	}

	/**
	 * Capture `workos_user_push_failed`.
	 *
	 * @param int         $user_id     WP user ID.
	 * @param \WP_Error   $error       Error from WorkOS.
	 * @param string|null $existing_id Existing WorkOS user ID, if found.
	 */
	public function capture_push_failed( $user_id, $error, $existing_id ): void {
		$this->push_failed[] = [ $user_id, $error, $existing_id ];
	}

	/**
	 * Mock the WorkOS API.
	 *
	 * @param false|array $response Pre-filtered response.
	 * @param array       $args     Request arguments.
	 * @param string      $url      Request URL.
	 *
	 * @return false|array
	 */
	public function intercept_http( $response, array $args, string $url ) {
		if ( false === strpos( $url, 'api.workos.com' ) ) {
			return $response;
		}

		$method = $args['method'] ?? 'GET';
		$body   = json_decode( $args['body'] ?? '{}', true );

		$this->http_requests[] = [
			'method' => $method,
			'url'    => $url,
			'body'   => $body,
		];

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		if ( 'POST' === $method && '/user_management/users' === $path ) {
			if ( null !== $this->create_response ) {
				return $this->create_response;
			}

			return $this->json(
				201,
				[
					'id'    => 'user_created',
					'email' => $body['email'] ?? '',
				]
			);
		}

		if ( 'GET' === $method && '/user_management/users' === $path ) {
			return $this->json(
				200,
				[
					'data'          => $this->listed_users,
					'list_metadata' => [],
				]
			);
		}

		return $this->json( 200, [ 'id' => basename( $path ) ] );
	}

	/**
	 * Build a fake JSON HTTP response.
	 *
	 * @param int   $code HTTP status.
	 * @param array $body Response body.
	 *
	 * @return array
	 */
	private function json( int $code, array $body ): array {
		return [
			'response' => [
				'code'    => $code,
				'message' => '',
			],
			'body'     => wp_json_encode( $body ),
		];
	}

	/**
	 * WorkOS's answer when the email belongs to another user.
	 *
	 * @return array
	 */
	private function email_not_available(): array {
		return $this->json(
			400,
			[
				'code'    => 'user_creation_error',
				'message' => 'Could not create user.',
				'errors'  => [
					[
						'code'    => 'email_not_available',
						'message' => 'This email is not available.',
					],
				],
			]
		);
	}

	/**
	 * Requests matching a method and path.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   URL path.
	 *
	 * @return array
	 */
	private function requests( string $method, string $path ): array {
		return array_values(
			array_filter(
				$this->http_requests,
				static function ( $req ) use ( $method, $path ) {
					return $method === $req['method'] && wp_parse_url( $req['url'], PHP_URL_PATH ) === $path;
				}
			)
		);
	}

	// -------------------------------------------------------------------------
	// push_user_to_workos()
	// -------------------------------------------------------------------------

	/**
	 * Email taken: the existing WorkOS user is stored as a pending match, not linked.
	 */
	public function test_push_email_taken_stores_pending_match(): void {
		$this->create_response = $this->email_not_available();
		$this->listed_users    = [
			[
				'id'    => 'user_existing',
				'email' => 'guest@example.com',
			],
		];

		$user_id = self::factory()->user->create( [ 'user_email' => 'guest@example.com' ] );
		$before  = time();

		$this->sync->push_user_to_workos( $user_id, [ 'user_email' => 'guest@example.com' ] );

		$lookups = $this->requests( 'GET', '/user_management/users' );
		$this->assertCount( 1, $lookups );
		$this->assertStringContainsString( 'email=guest%40example.com', $lookups[0]['url'] );

		$this->assertSame( '', get_user_meta( $user_id, '_workos_user_id', true ), 'An email match must not link.' );

		$pending = get_user_meta( $user_id, UserSync::META_PENDING_MATCH, true );
		$this->assertIsArray( $pending );
		$this->assertSame( 'user_existing', $pending['workos_user_id'] );
		$this->assertSame( 'user_register', $pending['source'] );
		$this->assertGreaterThanOrEqual( $before, $pending['time'] );
	}

	/**
	 * Email taken: `workos_user_push_failed` gets the user, the error and the existing WorkOS ID.
	 */
	public function test_push_email_taken_fires_action_with_existing_id(): void {
		$this->create_response = $this->email_not_available();
		$this->listed_users    = [
			[
				'id'    => 'user_existing',
				'email' => 'guest@example.com',
			],
		];

		$user_id = self::factory()->user->create( [ 'user_email' => 'guest@example.com' ] );

		$this->sync->push_user_to_workos( $user_id, [ 'user_email' => 'guest@example.com' ] );

		$this->assertCount( 1, $this->push_failed );
		[ $failed_user, $error, $existing_id ] = $this->push_failed[0];
		$this->assertSame( $user_id, $failed_user );
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 400, $error->get_error_data()['status'] );
		$this->assertSame( 'user_existing', $existing_id );
	}

	/**
	 * WorkOS may put `email_not_available` at the top level of the error body.
	 */
	public function test_push_email_taken_top_level_code(): void {
		$this->create_response = $this->json(
			422,
			[
				'code'    => 'email_not_available',
				'message' => 'This email is not available.',
			]
		);
		$this->listed_users    = [
			[
				'id'    => 'user_existing',
				'email' => 'guest@example.com',
			],
		];

		$user_id = self::factory()->user->create( [ 'user_email' => 'guest@example.com' ] );

		$this->sync->push_user_to_workos( $user_id, [ 'user_email' => 'guest@example.com' ] );

		$pending = get_user_meta( $user_id, UserSync::META_PENDING_MATCH, true );
		$this->assertSame( 'user_existing', $pending['workos_user_id'] ?? null );
		$this->assertSame( 'user_existing', $this->push_failed[0][2] );
	}

	/**
	 * Any other failure: no lookup, no pending match, the action fires with null.
	 */
	public function test_push_other_failure_fires_action_without_lookup(): void {
		$this->create_response = $this->json( 500, [ 'message' => 'Internal error' ] );

		$user_id = self::factory()->user->create( [ 'user_email' => 'boom@example.com' ] );

		$this->sync->push_user_to_workos( $user_id, [ 'user_email' => 'boom@example.com' ] );

		$this->assertEmpty( $this->requests( 'GET', '/user_management/users' ) );
		$this->assertSame( '', get_user_meta( $user_id, UserSync::META_PENDING_MATCH, true ) );
		$this->assertCount( 1, $this->push_failed );
		$this->assertSame( $user_id, $this->push_failed[0][0] );
		$this->assertNull( $this->push_failed[0][2] );
	}

	/**
	 * Email taken but the lookup finds nobody: no pending match, the action fires with null.
	 */
	public function test_push_email_taken_lookup_empty(): void {
		$this->create_response = $this->email_not_available();

		$user_id = self::factory()->user->create( [ 'user_email' => 'ghost@example.com' ] );

		$this->sync->push_user_to_workos( $user_id, [ 'user_email' => 'ghost@example.com' ] );

		$this->assertSame( '', get_user_meta( $user_id, UserSync::META_PENDING_MATCH, true ) );
		$this->assertCount( 1, $this->push_failed );
		$this->assertNull( $this->push_failed[0][2] );
	}

	/**
	 * Email taken by a WorkOS user another WP user is linked to: reported, but no pending match.
	 */
	public function test_push_email_taken_by_linked_workos_user_skips_pending_match(): void {
		$holder = self::factory()->user->create( [ 'user_email' => 'holder@example.com' ] );
		update_user_meta( $holder, '_workos_user_id', 'user_existing' );

		$this->create_response = $this->email_not_available();
		$this->listed_users    = [
			[
				'id'    => 'user_existing',
				'email' => 'guest@example.com',
			],
		];

		$user_id = self::factory()->user->create( [ 'user_email' => 'guest@example.com' ] );

		$this->sync->push_user_to_workos( $user_id, [ 'user_email' => 'guest@example.com' ] );

		$this->assertSame( '', get_user_meta( $user_id, UserSync::META_PENDING_MATCH, true ) );
		$this->assertSame( '', get_user_meta( $user_id, '_workos_user_id', true ) );
		$this->assertSame( 'user_existing', $this->push_failed[0][2] );
	}

	/**
	 * A successful push doesn't fire the failure action or leave a marker.
	 */
	public function test_push_success_fires_no_failure(): void {
		$user_id = self::factory()->user->create( [ 'user_email' => 'fresh@example.com' ] );

		$this->sync->push_user_to_workos( $user_id, [ 'user_email' => 'fresh@example.com' ] );

		$this->assertSame( 'user_created', get_user_meta( $user_id, '_workos_user_id', true ) );
		$this->assertSame( '', get_user_meta( $user_id, UserSync::META_PENDING_MATCH, true ) );
		$this->assertEmpty( $this->push_failed );
	}

	// -------------------------------------------------------------------------
	// sync_existing_user()
	// -------------------------------------------------------------------------

	/**
	 * Manual sync, email taken: still an error, plus the pending match and the action.
	 */
	public function test_sync_existing_email_taken_stores_pending_match(): void {
		$this->create_response = $this->email_not_available();
		$this->listed_users    = [
			[
				'id'    => 'user_existing',
				'email' => 'legacy@example.com',
			],
		];

		$user_id = self::factory()->user->create( [ 'user_email' => 'legacy@example.com' ] );

		$result = UserSync::sync_existing_user( $user_id );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( '', get_user_meta( $user_id, '_workos_user_id', true ) );

		$pending = get_user_meta( $user_id, UserSync::META_PENDING_MATCH, true );
		$this->assertSame( 'user_existing', $pending['workos_user_id'] ?? null );
		$this->assertSame( 'manual_sync', $pending['source'] ?? null );

		$this->assertCount( 1, $this->push_failed );
		$this->assertSame( $user_id, $this->push_failed[0][0] );
		$this->assertSame( $result, $this->push_failed[0][1] );
		$this->assertSame( 'user_existing', $this->push_failed[0][2] );
		$this->assertFalse( UserSync::is_syncing() );
	}

	// -------------------------------------------------------------------------
	// Clearing on link
	// -------------------------------------------------------------------------

	/**
	 * Signing in with the existing WorkOS user links the account and clears the marker.
	 */
	public function test_sign_in_link_clears_pending_match(): void {
		add_filter( 'workos_relink_protection_enabled', '__return_true' );

		$this->create_response = $this->email_not_available();
		$this->listed_users    = [
			[
				'id'    => 'user_existing',
				'email' => 'guest@example.com',
			],
		];

		$user_id = self::factory()->user->create( [ 'user_email' => 'guest@example.com' ] );
		$this->sync->push_user_to_workos( $user_id, [ 'user_email' => 'guest@example.com' ] );
		$this->assertNotEmpty( get_user_meta( $user_id, UserSync::META_PENDING_MATCH, true ) );

		$linked = [];
		$spy    = static function ( $wp_user_id, $workos_id ) use ( &$linked ) {
			$linked[] = [ $wp_user_id, $workos_id ];
		};
		add_action( 'workos_user_linked', $spy, 10, 2 );

		$user = UserSync::find_or_create_wp_user(
			[
				'id'    => 'user_existing',
				'email' => 'guest@example.com',
			],
			'login'
		);

		remove_action( 'workos_user_linked', $spy, 10 );

		$this->assertInstanceOf( \WP_User::class, $user );
		$this->assertSame( $user_id, $user->ID );
		$this->assertSame( 'user_existing', get_user_meta( $user_id, '_workos_user_id', true ) );
		$this->assertSame( '', get_user_meta( $user_id, UserSync::META_PENDING_MATCH, true ) );
		$this->assertSame( [ [ $user_id, 'user_existing' ] ], $linked );
	}

	// -------------------------------------------------------------------------
	// workos_push_user_email_verified
	// -------------------------------------------------------------------------

	/**
	 * The filter defaults to true and receives the user ID and userdata.
	 */
	public function test_email_verified_filter_default_and_args(): void {
		$seen = [];
		add_filter(
			'workos_push_user_email_verified',
			static function ( $verified, $user_id, $userdata ) use ( &$seen ) {
				$seen[] = [ $verified, $user_id, $userdata ];
				return $verified;
			},
			10,
			3
		);

		$user_id  = self::factory()->user->create( [ 'user_email' => 'filter@example.com' ] );
		$userdata = [
			'user_email' => 'filter@example.com',
			'first_name' => 'Fil',
		];

		$this->sync->push_user_to_workos( $user_id, $userdata );

		$this->assertSame( [ [ true, $user_id, $userdata ] ], $seen );
		$this->assertTrue( $this->requests( 'POST', '/user_management/users' )[0]['body']['email_verified'] );
	}

	/**
	 * Returning false sends `email_verified => false` on push.
	 */
	public function test_email_verified_filter_false_on_push(): void {
		add_filter( 'workos_push_user_email_verified', '__return_false' );

		$user_id = self::factory()->user->create( [ 'user_email' => 'unverified@example.com' ] );

		$this->sync->push_user_to_workos( $user_id, [ 'user_email' => 'unverified@example.com' ] );

		$this->assertFalse( $this->requests( 'POST', '/user_management/users' )[0]['body']['email_verified'] );
	}

	/**
	 * Manual sync runs the same filter, with userdata built from the WP user.
	 */
	public function test_email_verified_filter_on_sync_existing(): void {
		$seen = [];
		add_filter(
			'workos_push_user_email_verified',
			static function ( $verified, $user_id, $userdata ) use ( &$seen ) {
				$seen[] = [ $user_id, $userdata ];
				return false;
			},
			10,
			3
		);

		$user_id = self::factory()->user->create(
			[
				'user_email' => 'legacy2@example.com',
				'first_name' => 'Leg',
				'last_name'  => 'Acy',
			]
		);

		UserSync::sync_existing_user( $user_id );

		$this->assertSame( $user_id, $seen[0][0] );
		$this->assertSame( 'legacy2@example.com', $seen[0][1]['user_email'] );
		$this->assertSame( 'Leg', $seen[0][1]['first_name'] );
		$this->assertSame( 'Acy', $seen[0][1]['last_name'] );
		$this->assertFalse( $this->requests( 'POST', '/user_management/users' )[0]['body']['email_verified'] );
	}
}
