<?php
/**
 * Tests for the sign-in lockout REST endpoints.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

require_once dirname( __DIR__ ) . '/_support/RateLimitWindow.php';

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Admin\Users\LockoutsRestApi;
use WorkOS\ActivityLog\EventLogger;
use WorkOS\Database\Schema;
use WorkOS\RateLimit\TieredRateLimiter;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Covers POST /workos/v1/admin/lockouts/{check,clear}.
 *
 * @covers \WorkOS\Admin\Users\LockoutsRestApi
 */
class LockoutsRestApiTest extends WPTestCase {

	/**
	 * Address used throughout.
	 */
	private const EMAIL = 'support-case@example.com';

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();

		\workos_tests_wait_for_fresh_window();

		\WorkOS\Config::set_active_environment( 'production' );
		update_option(
			'workos_production',
			[
				'api_key'             => 'sk_test_fake',
				'client_id'           => 'client_fake',
				'environment_id'      => 'environment_test',
				'enable_activity_log' => true,
			]
		);
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		Schema::activate();
		EventLogger::clear();
		remove_all_actions( 'user_register' );

		// No outbound HTTP from fixtures.
		add_filter(
			'pre_http_request',
			static fn() => [
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'body'     => '{}',
			]
		);

		do_action( 'rest_api_init', rest_get_server() );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'workos_production' );
		delete_option( 'workos_active_environment' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		parent::tearDown();
	}

	/**
	 * Dispatch a POST.
	 *
	 * @param string               $action `check` or `clear`.
	 * @param array<string, mixed> $body   Body params.
	 */
	private function post( string $action, array $body ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/' . LockoutsRestApi::NAMESPACE . LockoutsRestApi::BASE . '/' . $action );
		$request->set_body_params( $body );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Lock the exact-address password bucket the way the endpoint does.
	 */
	private function lock_password(): void {
		$limiter = \WorkOS\App::container()->get( TieredRateLimiter::class );
		for ( $i = 0; $i < 6; $i++ ) {
			$limiter->attempt( 'password_email', self::EMAIL, TieredRateLimiter::REQUEST_SUBJECT );
		}
	}

	/**
	 * Both routes refuse anyone without `edit_users`, and change nothing.
	 *
	 * @param string $role Role of the caller, or '' for signed out.
	 */
	#[\Codeception\Attribute\Examples( '' )]
	#[\Codeception\Attribute\Examples( 'subscriber' )]
	#[\Codeception\Attribute\Examples( 'editor' )]
	public function test_callers_without_edit_users_are_refused( string $role ): void {
		$this->lock_password();
		if ( '' !== $role ) {
			wp_set_current_user( self::factory()->user->create( [ 'role' => $role ] ) );
		}

		$check = $this->post( 'check', [ 'email' => self::EMAIL ] );
		$clear = $this->post( 'clear', [ 'email' => self::EMAIL ] );

		$expected = '' === $role ? 401 : 403;
		$this->assertSame( $expected, $check->get_status() );
		$this->assertSame( $expected, $clear->get_status() );

		$limiter = \WorkOS\App::container()->get( TieredRateLimiter::class );
		$this->assertInstanceOf( \WP_Error::class, $limiter->attempt( 'password_email', self::EMAIL, TieredRateLimiter::REQUEST_SUBJECT ) );
	}

	/**
	 * Check returns every bucket, with the locked one flagged.
	 */
	public function test_check_returns_buckets(): void {
		$this->lock_password();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$response = $this->post( 'check', [ 'email' => self::EMAIL ] );

		$this->assertSame( 200, $response->get_status() );
		$buckets = array_column( $response->get_data()['buckets'], null, 'bucket' );
		$this->assertCount( 7, $buckets );
		$this->assertTrue( $buckets['password_email']['locked'] );
		$this->assertFalse( $buckets['magic_send_email']['locked'] );
		$this->assertSame( 1, $response->get_data()['locked'] );
	}

	/**
	 * Clear lets the next request through and logs who did it.
	 */
	public function test_clear_unlocks_and_logs(): void {
		$this->lock_password();
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$response = $this->post(
			'clear',
			[
				'email'  => self::EMAIL,
				'bucket' => 'password_email',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'password_email' ], $response->get_data()['cleared'] );

		$limiter = \WorkOS\App::container()->get( TieredRateLimiter::class );
		$this->assertTrue( $limiter->attempt( 'password_email', self::EMAIL, TieredRateLimiter::REQUEST_SUBJECT ) );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}workos_activity_log WHERE event_type = 'rate_limit_cleared'", ARRAY_A );
		$this->assertCount( 1, $rows );
		$this->assertSame( (string) $admin, (string) $rows[0]['user_id'] );
		$this->assertSame( 'admin', json_decode( (string) $rows[0]['metadata'], true )['source'] );
	}

	/**
	 * Bad input is a 400, not a silent no-op.
	 */
	public function test_bad_input_is_400(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->assertSame( 400, $this->post( 'check', [ 'email' => 'nope' ] )->get_status() );
		$this->assertSame(
			400,
			$this->post(
				'clear',
				[
					'email'  => self::EMAIL,
					'bucket' => 'not_a_bucket',
				]
			)->get_status()
		);
	}
}
