<?php
/**
 * Tests for EntitlementGate.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Organization\EntitlementGate;

/**
 * Entitlement gate check tests.
 */
class EntitlementGateTest extends WPTestCase {

	/**
	 * Captured HTTP requests.
	 *
	 * @var array
	 */
	private array $http_requests = [];

	/**
	 * Canned API response.
	 *
	 * @var array|\WP_Error|null
	 */
	private $mock_response = null;

	/**
	 * Set up each test.
	 */
	public function setUp(): void {
		parent::setUp();

		\WorkOS\Config::set_active_environment( 'production' );
		update_option( 'workos_production', [
			'api_key'                    => 'sk_test_fake',
			'client_id'                  => 'client_fake',
			'environment_id'             => 'environment_test',
			'organization_id'            => 'org_gate_test',
			'entitlement_gate_enabled'   => false,
		] );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$this->http_requests = [];
		$this->mock_response = null;

		add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );

		remove_all_actions( 'user_register' );
	}

	/**
	 * Tear down each test.
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10 );
		remove_all_filters( 'workos_entitlement_gate_enabled' );
		remove_all_filters( 'workos_entitlement_check' );
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

		$this->http_requests[] = [ 'url' => $url, 'args' => $args ];

		if ( null !== $this->mock_response ) {
			if ( $this->mock_response instanceof \WP_Error ) {
				return $this->mock_response;
			}
			return $this->mock_response;
		}

		return [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'body'     => wp_json_encode( [ 'data' => [] ] ),
		];
	}

	/**
	 * Test is_enabled returns false by default.
	 */
	public function test_is_enabled_returns_false_by_default(): void {
		$this->assertFalse( EntitlementGate::is_enabled() );
	}

	/**
	 * Test is_enabled respects option.
	 */
	public function test_is_enabled_respects_option(): void {
		$opts                              = get_option( 'workos_production' );
		$opts['entitlement_gate_enabled'] = true;
		update_option( 'workos_production', $opts );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$this->assertTrue( EntitlementGate::is_enabled() );
	}

	/**
	 * Test is_enabled respects filter.
	 */
	public function test_is_enabled_respects_filter(): void {
		add_filter( 'workos_entitlement_gate_enabled', '__return_true' );

		$this->assertTrue( EntitlementGate::is_enabled() );
	}

	/**
	 * Test check skips when disabled.
	 */
	public function test_check_skips_when_disabled(): void {
		$user_id = self::factory()->user->create();

		// Should not throw or die.
		EntitlementGate::check( $user_id, [
			'user'            => [ 'id' => 'user_gate' ],
			'organization_id' => 'org_gate_test',
		] );

		$this->assertEmpty( $this->http_requests, 'No API call when gate is disabled.' );
	}

	/**
	 * Test check allows user with active membership.
	 */
	public function test_check_allows_user_with_active_membership(): void {
		add_filter( 'workos_entitlement_gate_enabled', '__return_true' );

		$this->mock_response = [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'body'     => wp_json_encode( [
				'data' => [ [ 'id' => 'mem_1', 'status' => 'active' ] ],
			] ),
		];

		$user_id = self::factory()->user->create();

		// Should not throw.
		EntitlementGate::check( $user_id, [
			'user'            => [ 'id' => 'user_allowed' ],
			'organization_id' => 'org_gate_test',
		] );

		$this->assertNotEmpty( $this->http_requests );
	}

	/**
	 * Test check denies user without active membership.
	 */
	public function test_check_denies_user_without_active_membership(): void {
		add_filter( 'workos_entitlement_gate_enabled', '__return_true' );

		// Enable activity log table for EventLogger.
		\WorkOS\Database\Schema::activate();

		$this->mock_response = [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'body'     => wp_json_encode( [
				'data' => [ [ 'id' => 'mem_1', 'status' => 'inactive' ] ],
			] ),
		];

		$user_id = self::factory()->user->create();

		$this->expectException( \WPDieException::class );

		EntitlementGate::check( $user_id, [
			'user'            => [ 'id' => 'user_denied' ],
			'organization_id' => 'org_gate_test',
		] );
	}

	/**
	 * Test check fails open on API error.
	 */
	public function test_check_fails_open_on_api_error(): void {
		add_filter( 'workos_entitlement_gate_enabled', '__return_true' );

		$this->mock_response = new \WP_Error( 'http_fail', 'Connection error' );

		$user_id = self::factory()->user->create();

		// Should not die — fails open.
		EntitlementGate::check( $user_id, [
			'user'            => [ 'id' => 'user_api_err' ],
			'organization_id' => 'org_gate_test',
		] );

		$this->assertTrue( true, 'Check should not die on API error.' );
	}

	/**
	 * Test check skips when user_id missing from workos data.
	 */
	public function test_check_skips_when_user_id_missing(): void {
		add_filter( 'workos_entitlement_gate_enabled', '__return_true' );

		$user_id = self::factory()->user->create();

		EntitlementGate::check( $user_id, [
			'user'            => [],
			'organization_id' => 'org_gate_test',
		] );

		$this->assertEmpty( $this->http_requests );
	}

	/**
	 * Test check respects workos_entitlement_check filter to allow.
	 */
	public function test_check_respects_filter_allow(): void {
		add_filter( 'workos_entitlement_gate_enabled', '__return_true' );
		add_filter( 'workos_entitlement_check', '__return_true' );

		$user_id = self::factory()->user->create();

		// Should not die.
		EntitlementGate::check( $user_id, [
			'user'            => [ 'id' => 'user_filter_allow' ],
			'organization_id' => 'org_gate_test',
		] );

		$this->assertEmpty( $this->http_requests, 'No API call when filter allows.' );
	}

	/**
	 * Test check respects workos_entitlement_check filter to deny.
	 */
	public function test_check_respects_filter_deny(): void {
		add_filter( 'workos_entitlement_gate_enabled', '__return_true' );
		add_filter( 'workos_entitlement_check', '__return_false' );

		// Enable activity log table for EventLogger.
		\WorkOS\Database\Schema::activate();

		$user_id = self::factory()->user->create();

		$this->expectException( \WPDieException::class );

		EntitlementGate::check( $user_id, [
			'user'            => [ 'id' => 'user_filter_deny' ],
			'organization_id' => 'org_gate_test',
		] );
	}
}
