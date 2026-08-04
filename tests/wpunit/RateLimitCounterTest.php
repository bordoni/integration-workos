<?php
/**
 * Tests for the windowed counter behind every rate limit.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\RateLimit\RateLimiter;
use WorkOS\RateLimit\Reservation;
use WorkOS\RateLimit\TransientStore;
use WorkOS\Time\FrozenClock;
use WP_Error;

/**
 * Fixed-window counting, and the reservations built on it.
 *
 * @covers \WorkOS\RateLimit\RateLimiter
 * @covers \WorkOS\RateLimit\Reservation
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

	/**
	 * A longer window keeps counting after a shorter one has rolled over.
	 */
	public function test_a_long_window_survives_a_short_rollover(): void {
		$tiers = [ [ 100, 60 ], [ 4, HOUR_IN_SECONDS ] ];

		for ( $i = 0; $i < 4; $i++ ) {
			$this->assertInstanceOf( Reservation::class, $this->limiter->reserve( 'test_tiered', 'alice@example.com', $tiers ) );
		}

		$this->clock->travel( 60 );

		// The minute tier has room again; the hour tier is spent.
		$this->assertInstanceOf( WP_Error::class, $this->limiter->reserve( 'test_tiered', 'alice@example.com', $tiers ) );
	}

	/**
	 * Releasing an unspent reservation returns the slot.
	 */
	public function test_release_returns_the_slot(): void {
		$tiers = [ [ 3, 300 ] ];

		$slots = [];
		for ( $i = 0; $i < 3; $i++ ) {
			$slots[] = $this->limiter->reserve( 'test_release', 'alice@example.com', $tiers );
		}
		$this->assertInstanceOf( WP_Error::class, $this->limiter->reserve( 'test_release', 'alice@example.com', $tiers ) );

		$slots[0]->release();

		$this->assertInstanceOf( Reservation::class, $this->limiter->reserve( 'test_release', 'alice@example.com', $tiers ) );
	}

	/**
	 * Releasing twice would credit a window charged only once.
	 */
	public function test_release_is_idempotent(): void {
		$tiers = [ [ 3, 300 ] ];

		$slots = [];
		for ( $i = 0; $i < 3; $i++ ) {
			$slots[] = $this->limiter->reserve( 'test_idem', 'alice@example.com', $tiers );
		}

		$slots[0]->release();
		$slots[0]->release();

		$this->assertInstanceOf( Reservation::class, $this->limiter->reserve( 'test_idem', 'alice@example.com', $tiers ) );
		$this->assertInstanceOf( WP_Error::class, $this->limiter->reserve( 'test_idem', 'alice@example.com', $tiers ) );
	}

	/**
	 * A refused reservation leaves no charge behind.
	 *
	 * The short tier refuses first, so the longer tiers it already incremented
	 * have to be handed back or a rejected request still costs the caller.
	 */
	public function test_a_refused_reservation_is_not_charged(): void {
		$tiers = [ [ 2, 300 ], [ 10, HOUR_IN_SECONDS ] ];

		$slots = [];
		for ( $i = 0; $i < 2; $i++ ) {
			$slots[] = $this->limiter->reserve( 'test_refused', 'alice@example.com', $tiers );
		}

		$this->assertInstanceOf( WP_Error::class, $this->limiter->reserve( 'test_refused', 'alice@example.com', $tiers ) );
		$this->assertInstanceOf( WP_Error::class, $this->limiter->reserve( 'test_refused', 'alice@example.com', $tiers ) );

		// Freeing one real charge is enough. It would not be if the two
		// refusals had each left a charge on the hour tier.
		$slots[0]->release();

		$this->assertInstanceOf( Reservation::class, $this->limiter->reserve( 'test_refused', 'alice@example.com', $tiers ) );
	}

	/**
	 * A refund lands in the window it was charged to.
	 *
	 * Reserve, let the boundary pass while the work is in flight, then release.
	 * The credit belongs to the window that was charged, not to whichever is
	 * current, or the old one stays spent and the new one is gifted a slot.
	 */
	public function test_release_credits_the_window_it_charged(): void {
		$tiers = [ [ 2, 60 ] ];

		$slot = $this->limiter->reserve( 'test_straddle', 'alice@example.com', $tiers );
		$this->assertInstanceOf( Reservation::class, $slot );

		$this->clock->travel( 60 );

		// Fill the new window.
		$this->assertInstanceOf( Reservation::class, $this->limiter->reserve( 'test_straddle', 'alice@example.com', $tiers ) );
		$this->assertInstanceOf( Reservation::class, $this->limiter->reserve( 'test_straddle', 'alice@example.com', $tiers ) );
		$this->assertInstanceOf( WP_Error::class, $this->limiter->reserve( 'test_straddle', 'alice@example.com', $tiers ) );

		$slot->release();

		$this->assertInstanceOf( WP_Error::class, $this->limiter->reserve( 'test_straddle', 'alice@example.com', $tiers ) );
	}
}
