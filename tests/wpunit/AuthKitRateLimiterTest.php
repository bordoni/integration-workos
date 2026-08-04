<?php
/**
 * Tests for the AuthKit rate limiter.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use Codeception\Attribute\DataProvider;
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
	 * Which address a request is attributed to.
	 *
	 * @param string|null $remote_addr  REMOTE_ADDR, or null when absent.
	 * @param string|null $trusted      Header an operator has declared trusted.
	 * @param string|null $header_value Value that header carries.
	 * @param string      $expected     Address the limiter should count against.
	 */
	#[DataProvider( 'clientIpCases' )]
	public function test_client_ip_resolution( ?string $remote_addr, ?string $trusted, ?string $header_value, string $expected ): void {
		$previous = $_SERVER['REMOTE_ADDR'] ?? null;

		if ( null === $remote_addr ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $remote_addr;
		}

		if ( null !== $header_value ) {
			$_SERVER['HTTP_X_FORWARDED_FOR'] = $header_value;
		}

		$filter = static fn(): string => (string) $trusted;

		if ( null !== $trusted ) {
			add_filter( 'workos_trusted_proxy_header', $filter );
		}

		try {
			$this->assertSame( $expected, $this->limiter->client_ip() );
		} finally {
			if ( null !== $trusted ) {
				remove_filter( 'workos_trusted_proxy_header', $filter );
			}

			unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );

			if ( null === $previous ) {
				unset( $_SERVER['REMOTE_ADDR'] );
			} else {
				$_SERVER['REMOTE_ADDR'] = $previous;
			}
		}
	}

	// ------------------------------------------------------------ data providers

	/**
	 * Request conditions paired with the address they resolve to.
	 *
	 * Columns: REMOTE_ADDR, trusted header name, header value, expected.
	 *
	 * @return array<string, array{0:?string, 1:?string, 2:?string, 3:string}>
	 */
	public function clientIpCases(): array {
		return [
			'remote addr only' => [
				'203.0.113.4',
				null,
				null,
				'203.0.113.4',
			],
			'no remote addr at all' => [
				null,
				null,
				null,
				'0.0.0.0',
			],
			// The header is worthless until an operator vouches for it.
			'undeclared header is ignored' => [
				'203.0.113.4',
				null,
				'198.51.100.7',
				'203.0.113.4',
			],
			'declared header wins' => [
				'10.0.0.5',
				'HTTP_X_FORWARDED_FOR',
				'198.51.100.7',
				'198.51.100.7',
			],
			'declared header absent falls back' => [
				'203.0.113.4',
				'HTTP_X_FORWARDED_FOR',
				null,
				'203.0.113.4',
			],
			'declared header is garbage' => [
				'203.0.113.4',
				'HTTP_X_FORWARDED_FOR',
				'not-an-ip',
				'203.0.113.4',
			],
			// The client prepended its own value; the edge appended the truth.
			'spoofed prefix is not trusted' => [
				'10.0.0.5',
				'HTTP_X_FORWARDED_FOR',
				'6.6.6.1, 198.51.100.7',
				'198.51.100.7',
			],
			// An internal hop sits to the right of the real client.
			'private tail is skipped' => [
				'10.0.0.5',
				'HTTP_X_FORWARDED_FOR',
				'6.6.6.1, 198.51.100.7, 10.0.0.2',
				'198.51.100.7',
			],
			'multiple private hops are skipped' => [
				'10.0.0.5',
				'HTTP_X_FORWARDED_FOR',
				'6.6.6.1, 198.51.100.7, 10.0.0.2, 172.16.0.9',
				'198.51.100.7',
			],
			// Wholly internal deployment: nothing public to find.
			'all private falls back to the right-most' => [
				'10.0.0.5',
				'HTTP_X_FORWARDED_FOR',
				'10.0.0.1, 192.168.1.7',
				'192.168.1.7',
			],
			// Single-valued headers read the same either way.
			'single entry is unaffected' => [
				'10.0.0.5',
				'HTTP_X_FORWARDED_FOR',
				'198.51.100.7',
				'198.51.100.7',
			],
		];
	}
}
