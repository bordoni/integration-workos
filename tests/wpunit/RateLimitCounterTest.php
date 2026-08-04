<?php
/**
 * Tests for the windowed counter behind every rate limit.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\RateLimit\RateLimiter;
use WorkOS\RateLimit\TransientStore;
use WorkOS\Time\FrozenClock;
use WP_Error;

/**
 * Fixed-window counting, and the reservations built on it.
 *
 * @covers \WorkOS\RateLimit\RateLimiter
 * @covers \WorkOS\RateLimit\TransientStore
 */
class RateLimitCounterTest extends WPTestCase {

	/**
	 * Clock the limiter reads.
	 *
	 * @var FrozenClock
	 */
	private FrozenClock $clock;

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

		// Mid-window, so a 60s jump lands cleanly in the next one.
		$this->clock   = new FrozenClock( 1_800_000_030 );
		$this->limiter = new RateLimiter( new TransientStore(), $this->clock );
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
	 * The attempt past the limit returns a 429 carrying a retry hint.
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
		$this->assertGreaterThan( 0, $data['retry_after'] );
	}

	/**
	 * Different subjects count separately.
	 */
	public function test_buckets_are_subject_isolated(): void {
		for ( $i = 0; $i < 3; $i++ ) {
			$this->limiter->attempt( 'test_bucket', '192.168.0.1', 3, 60 );
		}

		$this->assertTrue( $this->limiter->attempt( 'test_bucket', '10.0.0.1', 3, 60 ) );
	}

	/**
	 * A zero limit means no limit.
	 */
	public function test_zero_limit_bypasses_check(): void {
		for ( $i = 0; $i < 100; $i++ ) {
			$this->assertTrue( $this->limiter->attempt( 'test_bucket', '192.168.0.1', 0, 60 ) );
		}
	}

	/**
	 * An empty subject is nothing to attribute an attempt to.
	 */
	public function test_empty_subject_is_not_counted(): void {
		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertTrue( $this->limiter->attempt( 'test_bucket', '', 3, 60 ) );
		}
	}

	/**
	 * Reset clears the current window.
	 */
	public function test_reset_clears_the_window(): void {
		for ( $i = 0; $i < 3; $i++ ) {
			$this->limiter->attempt( 'test_bucket', '192.168.0.1', 3, 60 );
		}
		$this->assertInstanceOf( WP_Error::class, $this->limiter->attempt( 'test_bucket', '192.168.0.1', 3, 60 ) );

		$this->limiter->reset( 'test_bucket', '192.168.0.1', [ [ 3, 60 ] ] );

		$this->assertTrue( $this->limiter->attempt( 'test_bucket', '192.168.0.1', 3, 60 ) );
	}

	/**
	 * Crossing a window boundary starts a fresh allowance.
	 *
	 * The window is `floor( now / length )`, so this is only observable with a
	 * clock that can be moved. Timestamps relative to `time()` shift the data,
	 * not the boundary.
	 */
	public function test_a_new_window_starts_a_fresh_allowance(): void {
		for ( $i = 0; $i < 3; $i++ ) {
			$this->limiter->attempt( 'test_roll', '192.168.0.1', 3, 60 );
		}
		$this->assertInstanceOf( WP_Error::class, $this->limiter->attempt( 'test_roll', '192.168.0.1', 3, 60 ) );

		$this->clock->travel( 60 );

		$this->assertTrue( $this->limiter->attempt( 'test_roll', '192.168.0.1', 3, 60 ) );
	}
}
