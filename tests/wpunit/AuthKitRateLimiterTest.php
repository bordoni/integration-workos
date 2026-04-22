<?php
/**
 * Tests for the AuthKit rate limiter.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Auth\AuthKit\RateLimiter;
use WP_Error;

/**
 * Fixed-window limiter semantics.
 */
class AuthKitRateLimiterTest extends WPTestCase {

	/**
	 * Limiter under test.
	 *
	 * @var RateLimiter
	 */
	private RateLimiter $limiter;

	/**
	 * Set up each test.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->limiter = new RateLimiter();
		$this->limiter->reset( 'test_bucket', '192.168.0.1' );
		$this->limiter->reset( 'test_bucket', 'alice@example.com' );
	}

	/**
	 * Attempts within the limit succeed.
	 */
	public function test_attempts_within_limit_succeed(): void {
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertTrue( $this->limiter->attempt( 'test_bucket', '192.168.0.1', 5, 60 ) );
		}
	}

	/**
	 * The (limit+1)th attempt returns a 429 WP_Error with Retry-After.
	 */
	public function test_attempt_beyond_limit_returns_429(): void {
		for ( $i = 0; $i < 3; $i++ ) {
			$this->limiter->attempt( 'test_bucket', '192.168.0.1', 3, 60 );
		}

		$result = $this->limiter->attempt( 'test_bucket', '192.168.0.1', 3, 60 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'workos_rate_limited', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertSame( 429, $data['status'] );
		$this->assertIsInt( $data['retry_after'] );
		$this->assertGreaterThan( 0, $data['retry_after'] );
	}

	/**
	 * Different subjects use separate buckets.
	 */
	public function test_buckets_are_subject_isolated(): void {
		for ( $i = 0; $i < 3; $i++ ) {
			$this->limiter->attempt( 'test_bucket', '192.168.0.1', 3, 60 );
		}

		// Another IP gets its own bucket.
		$this->assertTrue( $this->limiter->attempt( 'test_bucket', '10.0.0.1', 3, 60 ) );
	}

	/**
	 * Zero limit / zero window is treated as "no limit".
	 */
	public function test_zero_limit_bypasses_check(): void {
		for ( $i = 0; $i < 100; $i++ ) {
			$this->assertTrue( $this->limiter->attempt( 'test_bucket', '192.168.0.1', 0, 60 ) );
		}
	}

	/**
	 * Reset clears the bucket.
	 */
	public function test_reset_clears_bucket(): void {
		for ( $i = 0; $i < 3; $i++ ) {
			$this->limiter->attempt( 'test_bucket', '192.168.0.1', 3, 60 );
		}
		$this->assertInstanceOf( WP_Error::class, $this->limiter->attempt( 'test_bucket', '192.168.0.1', 3, 60 ) );

		$this->limiter->reset( 'test_bucket', '192.168.0.1' );

		$this->assertTrue( $this->limiter->attempt( 'test_bucket', '192.168.0.1', 3, 60 ) );
	}

	/**
	 * Email normalization lowercases and trims; non-emails return empty.
	 */
	public function test_normalize_email(): void {
		$this->assertSame( 'alice@example.com', $this->limiter->normalize_email( '  Alice@Example.COM ' ) );
		$this->assertSame( '', $this->limiter->normalize_email( 'not-an-email' ) );
		$this->assertSame( '', $this->limiter->normalize_email( '' ) );
	}

	/**
	 * client_ip falls back to 0.0.0.0 when REMOTE_ADDR is unset.
	 */
	public function test_client_ip_returns_fallback_when_unset(): void {
		$previous = $_SERVER['REMOTE_ADDR'] ?? null;
		unset( $_SERVER['REMOTE_ADDR'] );

		$this->assertSame( '0.0.0.0', $this->limiter->client_ip() );

		if ( null !== $previous ) {
			$_SERVER['REMOTE_ADDR'] = $previous;
		}
	}

	/**
	 * client_ip uses REMOTE_ADDR when set to a valid IP.
	 */
	public function test_client_ip_uses_remote_addr(): void {
		$previous              = $_SERVER['REMOTE_ADDR'] ?? null;
		$_SERVER['REMOTE_ADDR'] = '203.0.113.4';

		$this->assertSame( '203.0.113.4', $this->limiter->client_ip() );

		if ( null === $previous ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $previous;
		}
	}
}
