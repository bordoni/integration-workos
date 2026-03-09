<?php
/**
 * Tests for OnboardingAjax::sync_single_user() (via reflection).
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Admin\OnboardingAjax;
use WorkOS\Database\Schema;

/**
 * Onboarding sync tests.
 */
class OnboardingSyncTest extends WPTestCase {

	/**
	 * Captured HTTP requests.
	 *
	 * @var array
	 */
	private array $http_requests = [];

	/**
	 * HTTP response queue.
	 *
	 * @var array
	 */
	private array $response_queue = [];

	/**
	 * Set up each test.
	 */
	public function setUp(): void {
		parent::setUp();

		\WorkOS\Config::set_active_environment( 'production' );
		update_option( 'workos_production', [
			'api_key'         => 'sk_test_fake',
			'client_id'       => 'client_fake',
			'environment_id'  => 'environment_test',
			'organization_id' => 'org_onboarding_test',
		] );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$this->http_requests  = [];
		$this->response_queue = [];

		add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );

		Schema::activate();

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
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		parent::tearDown();
	}

	/**
	 * Intercept HTTP requests.
	 *
	 * @param false|array $response Pre-filtered response.
	 * @param array       $args     Request arguments.
	 * @param string      $url      Request URL.
	 *
	 * @return array|false
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

		if ( ! empty( $this->response_queue ) ) {
			return array_shift( $this->response_queue );
		}

		return [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'body'     => wp_json_encode( [ 'data' => [] ] ),
		];
	}

	/**
	 * Call the private sync_single_user method via reflection.
	 *
	 * @param int $user_id WP user ID.
	 *
	 * @return array|\WP_Error
	 */
	private function call_sync_single_user( int $user_id ) {
		$ajax  = new OnboardingAjax();
		$ref   = new \ReflectionMethod( OnboardingAjax::class, 'sync_single_user' );
		$ref->setAccessible( true );

		return $ref->invoke( $ajax, $user_id );
	}

	/**
	 * Test sync links existing WorkOS user by email.
	 */
	public function test_sync_links_existing_workos_user_by_email(): void {
		$user_id = self::factory()->user->create( [ 'user_email' => 'existing_workos@example.com' ] );

		// Queue: list_users returns existing user.
		$this->response_queue[] = [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'body'     => wp_json_encode( [
				'data' => [
					[ 'id' => 'user_existing_workos' ],
				],
			] ),
		];

		// Queue: create_organization_membership.
		$this->response_queue[] = [
			'response' => [ 'code' => 201, 'message' => 'Created' ],
			'body'     => wp_json_encode( [ 'id' => 'om_new' ] ),
		];

		$result = $this->call_sync_single_user( $user_id );

		$this->assertIsArray( $result );
		$this->assertSame( 'user_existing_workos', $result['workos_user_id'] );
		$this->assertSame( 'linked', $result['action'] );
		$this->assertSame( 'user_existing_workos', get_user_meta( $user_id, '_workos_user_id', true ) );
	}

	/**
	 * Test sync creates WorkOS user when not found.
	 */
	public function test_sync_creates_workos_user_when_not_found(): void {
		$user_id = self::factory()->user->create( [
			'user_email' => 'newuser@example.com',
			'first_name' => 'New',
			'last_name'  => 'User',
		] );

		// Queue: list_users returns empty.
		$this->response_queue[] = [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'body'     => wp_json_encode( [ 'data' => [] ] ),
		];

		// Queue: create_user response.
		$this->response_queue[] = [
			'response' => [ 'code' => 201, 'message' => 'Created' ],
			'body'     => wp_json_encode( [ 'id' => 'user_created_new' ] ),
		];

		// Queue: create_organization_membership.
		$this->response_queue[] = [
			'response' => [ 'code' => 201, 'message' => 'Created' ],
			'body'     => wp_json_encode( [ 'id' => 'om_new' ] ),
		];

		$result = $this->call_sync_single_user( $user_id );

		$this->assertIsArray( $result );
		$this->assertSame( 'user_created_new', $result['workos_user_id'] );
		$this->assertSame( 'created', $result['action'] );
	}

	/**
	 * Test sync returns error for already linked user.
	 */
	public function test_sync_returns_error_for_already_linked(): void {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, '_workos_user_id', 'user_already_linked' );

		$result = $this->call_sync_single_user( $user_id );

		$this->assertWPError( $result );
		$this->assertSame( 'already_linked', $result->get_error_code() );
	}

	/**
	 * Test sync returns error for invalid user.
	 */
	public function test_sync_returns_error_for_invalid_user(): void {
		$result = $this->call_sync_single_user( 999999 );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_user', $result->get_error_code() );
	}

	/**
	 * Test sync creates org membership.
	 */
	public function test_sync_creates_org_membership(): void {
		$user_id = self::factory()->user->create( [ 'user_email' => 'orgmem@example.com' ] );

		// Queue: list_users returns existing user.
		$this->response_queue[] = [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'body'     => wp_json_encode( [
				'data' => [ [ 'id' => 'user_orgmem' ] ],
			] ),
		];

		// Queue: create_organization_membership.
		$this->response_queue[] = [
			'response' => [ 'code' => 201, 'message' => 'Created' ],
			'body'     => wp_json_encode( [ 'id' => 'om_created' ] ),
		];

		$this->call_sync_single_user( $user_id );

		// Verify an org membership creation API call was made.
		$membership_calls = array_filter( $this->http_requests, function ( $req ) {
			return false !== strpos( $req['url'], 'organization_memberships' );
		} );

		$this->assertNotEmpty( $membership_calls, 'Should have called create_organization_membership.' );
	}

	/**
	 * Test sync returns error on API failure.
	 */
	public function test_sync_returns_error_on_api_failure(): void {
		$user_id = self::factory()->user->create( [ 'user_email' => 'apifail@example.com' ] );

		// Queue: list_users fails.
		$this->response_queue[] = [
			'response' => [ 'code' => 500, 'message' => 'Internal Server Error' ],
			'body'     => wp_json_encode( [ 'message' => 'Server error' ] ),
		];

		$result = $this->call_sync_single_user( $user_id );

		$this->assertWPError( $result );
	}
}
