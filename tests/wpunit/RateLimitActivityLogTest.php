<?php
/**
 * Tests for the activity-log entry written when a rate limit triggers.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\ActivityLog\EventLogger;
use WorkOS\Database\Schema;
use WorkOS\RateLimit\DatabaseRateLimiter;
use WorkOS\RateLimit\TieredRateLimiter;
use WP_Error;

/**
 * `workos_rate_limited` → one `rate_limited` row, with no raw subject in it.
 */
class RateLimitActivityLogTest extends WPTestCase {

	/**
	 * REMOTE_ADDR before the test.
	 *
	 * @var string|null
	 */
	private ?string $remote_addr;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();

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

		$this->remote_addr      = $_SERVER['REMOTE_ADDR'] ?? null;
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		if ( null === $this->remote_addr ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $this->remote_addr;
		}

		delete_option( 'workos_production' );
		\WorkOS\Config::set_active_environment( 'staging' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		parent::tearDown();
	}

	/**
	 * Rows of one event type.
	 *
	 * @param string $event_type Event type.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rows( string $event_type ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}workos_activity_log WHERE event_type = %s", $event_type ),
			ARRAY_A
		);
	}

	/**
	 * A limited attempt logs exactly once, with the bucket and the hashed
	 * subject, and neither the address nor the caller's IP anywhere in
	 * the row.
	 */
	public function test_limited_attempt_logs_once_without_raw_subject(): void {
		$limiter = new TieredRateLimiter( new DatabaseRateLimiter() );
		$policy  = [ [ 1, MINUTE_IN_SECONDS ] ];

		$this->assertTrue( $limiter->attempt( 'test_log_email', 'locked@example.com', $policy ) );
		$this->assertSame( [], $this->rows( 'rate_limited' ), 'An allowed attempt logs nothing.' );

		$this->assertInstanceOf( WP_Error::class, $limiter->attempt( 'test_log_email', 'locked@example.com', $policy ) );

		$rows = $this->rows( 'rate_limited' );
		$this->assertCount( 1, $rows );

		$row = $rows[0];
		$this->assertSame( '0', (string) $row['user_id'] );
		$this->assertSame( '', $row['user_email'] );
		$this->assertSame( '', $row['ip_address'] );

		$meta = json_decode( (string) $row['metadata'], true );
		$this->assertSame( 'test_log_email', $meta['bucket'] );
		$this->assertSame( TieredRateLimiter::hash_subject( 'locked@example.com' ), $meta['subject_hash'] );
		$this->assertGreaterThan( 0, $meta['retry_after'] );

		$flat = (string) wp_json_encode( $row );
		$this->assertStringNotContainsString( 'locked@example.com', $flat );
		$this->assertStringNotContainsString( '203.0.113.9', $flat );
	}

	/**
	 * A per-IP bucket hashes the IP the same way, so the log never holds
	 * the address it was limiting.
	 */
	public function test_ip_bucket_logs_hash_not_ip(): void {
		$limiter = new TieredRateLimiter( new DatabaseRateLimiter() );
		$policy  = [ [ 1, MINUTE_IN_SECONDS ] ];

		$limiter->attempt( 'test_log_ip', '203.0.113.9', $policy );
		$limiter->attempt( 'test_log_ip', '203.0.113.9', $policy );

		$rows = $this->rows( 'rate_limited' );
		$this->assertCount( 1, $rows );
		$this->assertStringNotContainsString( '203.0.113.9', (string) wp_json_encode( $rows[0] ) );
	}

	/**
	 * With the activity log off, the action still fires and nothing is
	 * written.
	 */
	public function test_nothing_logged_when_activity_log_disabled(): void {
		$opts                        = get_option( 'workos_production' );
		$opts['enable_activity_log'] = false;
		update_option( 'workos_production', $opts );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$fired = did_action( 'workos_rate_limited' );

		$limiter = new TieredRateLimiter( new DatabaseRateLimiter() );
		$limiter->attempt( 'test_log_off', 's', [ [ 1, MINUTE_IN_SECONDS ] ] );
		$limiter->attempt( 'test_log_off', 's', [ [ 1, MINUTE_IN_SECONDS ] ] );

		$this->assertSame( $fired + 1, did_action( 'workos_rate_limited' ) );
		$this->assertSame( [], $this->rows( 'rate_limited' ) );
	}
}
