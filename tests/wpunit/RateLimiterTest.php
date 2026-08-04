<?php
/**
 * Contract tests for the single-window rate limiters.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use Codeception\Attribute\DataProvider;
use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\RateLimit\DatabaseRateLimiter;
use WorkOS\RateLimit\ObjectCacheRateLimiter;
use WorkOS\RateLimit\RateLimiter;
use WP_Error;

/**
 * Every case runs against both implementations.
 *
 * The two storage backends have to behave identically or the limit a site
 * gets depends on whether an object cache happens to be installed. Running
 * one body against both is also the only way CI exercises the object-cache
 * path at all: production only selects it when a persistent cache exists.
 */
class RateLimiterTest extends WPTestCase {

	/**
	 * Build a limiter.
	 *
	 * No bucket clearing needed: the suite flushes the object cache and
	 * opens a transaction before each test, then rolls back, so counters
	 * cannot survive in either store.
	 *
	 * @param string $class Limiter implementation.
	 *
	 * @return RateLimiter
	 */
	private function limiter( string $class ): RateLimiter {
		/** @var RateLimiter $limiter */
		return new $class();
	}

	/**
	 * Attempts within the limit succeed.
	 *
	 * @param string $class Limiter implementation.
	 */
	#[DataProvider( 'limiter_provider' )]
	public function test_attempts_within_limit_succeed( string $class ): void {
		$limiter = $this->limiter( $class );

		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertTrue( $limiter->attempt( 'test_bucket', '192.168.0.1', 5, 60 ) );
		}
	}

	/**
	 * The (limit+1)th attempt returns a 429 carrying Retry-After.
	 *
	 * @param string $class Limiter implementation.
	 */
	#[DataProvider( 'limiter_provider' )]
	public function test_attempt_beyond_limit_returns_429( string $class ): void {
		$limiter = $this->limiter( $class );

		for ( $i = 0; $i < 3; $i++ ) {
			$limiter->attempt( 'test_bucket', '192.168.0.1', 3, 60 );
		}

		$result = $limiter->attempt( 'test_bucket', '192.168.0.1', 3, 60 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'workos_rate_limited', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertSame( 429, $data['status'] );
		$this->assertIsInt( $data['retry_after'] );
		$this->assertGreaterThan( 0, $data['retry_after'] );
		$this->assertLessThanOrEqual( 60, $data['retry_after'] );
	}

	/**
	 * A refund hands one slot back within the window.
	 *
	 * @param string $class Limiter implementation.
	 */
	#[DataProvider( 'limiter_provider' )]
	public function test_refund_returns_a_slot( string $class ): void {
		$limiter = $this->limiter( $class );

		for ( $i = 0; $i < 3; $i++ ) {
			$limiter->attempt( 'test_bucket', '192.168.0.1', 3, 60 );
		}

		$limiter->refund( 'test_bucket', '192.168.0.1', 60 );

		$this->assertTrue( $limiter->attempt( 'test_bucket', '192.168.0.1', 3, 60 ) );
		$this->assertInstanceOf( WP_Error::class, $limiter->attempt( 'test_bucket', '192.168.0.1', 3, 60 ) );
	}

	/**
	 * Refunding an uncharged bucket can't buy extra attempts later.
	 *
	 * @param string $class Limiter implementation.
	 */
	#[DataProvider( 'limiter_provider' )]
	public function test_refund_never_drops_below_zero( string $class ): void {
		$limiter = $this->limiter( $class );

		$limiter->refund( 'test_bucket', '192.168.0.1', 60 );
		$limiter->refund( 'test_bucket', '192.168.0.1', 60 );

		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertTrue( $limiter->attempt( 'test_bucket', '192.168.0.1', 3, 60 ) );
		}
		$this->assertInstanceOf( WP_Error::class, $limiter->attempt( 'test_bucket', '192.168.0.1', 3, 60 ) );
	}

	/**
	 * Different subjects count separately.
	 *
	 * @param string $class Limiter implementation.
	 */
	#[DataProvider( 'limiter_provider' )]
	public function test_buckets_are_subject_isolated( string $class ): void {
		$limiter = $this->limiter( $class );

		for ( $i = 0; $i < 3; $i++ ) {
			$limiter->attempt( 'test_bucket', '192.168.0.1', 3, 60 );
		}

		$this->assertTrue( $limiter->attempt( 'test_bucket', '10.0.0.1', 3, 60 ) );
	}

	/**
	 * Reset clears the current window.
	 *
	 * @param string $class Limiter implementation.
	 */
	#[DataProvider( 'limiter_provider' )]
	public function test_reset_clears_bucket( string $class ): void {
		$limiter = $this->limiter( $class );

		for ( $i = 0; $i < 3; $i++ ) {
			$limiter->attempt( 'test_bucket', '192.168.0.1', 3, 60 );
		}
		$this->assertInstanceOf( WP_Error::class, $limiter->attempt( 'test_bucket', '192.168.0.1', 3, 60 ) );

		$limiter->reset( 'test_bucket', '192.168.0.1', 60 );

		$this->assertTrue( $limiter->attempt( 'test_bucket', '192.168.0.1', 3, 60 ) );
	}

	/**
	 * A non-positive limit or window means "no limit" rather than a
	 * lockout, so a misconfigured policy fails open on that tier alone.
	 *
	 * @param string $class  Limiter implementation.
	 * @param int    $limit  Limit under test.
	 * @param int    $window Window under test.
	 */
	#[DataProvider( 'non_positive_provider' )]
	public function test_non_positive_limit_or_window_bypasses_check( string $class, int $limit, int $window ): void {
		$limiter = $this->limiter( $class );

		for ( $i = 0; $i < 50; $i++ ) {
			$this->assertTrue( $limiter->attempt( 'test_bucket', '192.168.0.1', $limit, $window ) );
		}
	}

	// -------------------------------------------------------------------------
	// Providers
	// -------------------------------------------------------------------------

	/**
	 * Both storage implementations of the limiter contract.
	 *
	 * @return array<string, array{0:string}>
	 */
	public function limiter_provider(): array {
		return [
			'transients'   => [ DatabaseRateLimiter::class ],
			'object cache' => [ ObjectCacheRateLimiter::class ],
		];
	}

	/**
	 * Each implementation crossed with every non-positive limit/window.
	 *
	 * @return array<string, array{0:string,1:int,2:int}>
	 */
	public function non_positive_provider(): array {
		$cases = [
			'zero limit'      => [ 0, 60 ],
			'negative limit'  => [ -5, 60 ],
			'zero window'     => [ 3, 0 ],
			'negative window' => [ 3, -60 ],
		];

		$data = [];
		foreach ( $this->limiter_provider() as $storage => [ $class ] ) {
			foreach ( $cases as $case => [ $limit, $window ] ) {
				$data[ "{$storage}, {$case}" ] = [ $class, $limit, $window ];
			}
		}

		return $data;
	}
}
