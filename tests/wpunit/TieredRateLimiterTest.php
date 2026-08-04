<?php
/**
 * Tests for layered-window rate limiting.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use Codeception\Attribute\DataProvider;
use Codeception\Attribute\Examples;
use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\RateLimit\RateLimiter;
use WorkOS\RateLimit\TieredRateLimiter;
use WP_Error;

/**
 * Tier ordering and charging, verified against an in-memory limiter.
 *
 * No transients and no object cache: the point of splitting tiers out of
 * storage is that this logic can be checked on its own, including the
 * per-tier call counts a storage-backed test can only infer.
 */
class TieredRateLimiterTest extends WPTestCase {

	/**
	 * In-memory single-window limiter.
	 *
	 * @var RateLimiter
	 */
	private RateLimiter $fake;

	/**
	 * Limiter under test.
	 *
	 * @var TieredRateLimiter
	 */
	private TieredRateLimiter $limiter;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->fake = new class() implements RateLimiter {

			/**
			 * Counts keyed by "bucket|subject|window".
			 *
			 * @var array<string,int>
			 */
			public array $counts = [];

			/**
			 * Windows seen, in call order.
			 *
			 * @var array<int,int>
			 */
			public array $windows_seen = [];

			public function attempt( string $bucket, string $subject, int $limit, int $window ) {
				$this->windows_seen[] = $window;

				$key                  = $bucket . '|' . $subject . '|' . $window;
				$this->counts[ $key ] = ( $this->counts[ $key ] ?? 0 ) + 1;

				if ( $this->counts[ $key ] > $limit ) {
					return new WP_Error( 'workos_rate_limited', 'nope', [ 'status' => 429 ] );
				}

				return true;
			}

			public function refund( string $bucket, string $subject, int $window ): void {
				$key                  = $bucket . '|' . $subject . '|' . $window;
				$this->counts[ $key ] = max( 0, ( $this->counts[ $key ] ?? 0 ) - 1 );
			}

			public function reset( string $bucket, string $subject, int $window ): void {
				unset( $this->counts[ $bucket . '|' . $subject . '|' . $window ] );
			}

			public function client_ip(): string {
				return '203.0.113.7';
			}

			public function normalize_email( string $email ): string {
				return strtolower( trim( $email ) );
			}
		};

		$this->limiter = new TieredRateLimiter( $this->fake );
	}

	/**
	 * Every tier is charged while the request is allowed.
	 */
	public function test_attempt_charges_all_tiers(): void {
		$this->assertTrue( $this->limiter->attempt( 'b', 's', [ [ 5, 60 ], [ 10, 3600 ] ] ) );

		$this->assertSame( 1, $this->fake->counts['b|s|60'] );
		$this->assertSame( 1, $this->fake->counts['b|s|3600'] );
	}

	/**
	 * Tiers are charged shortest window first regardless of declaration
	 * order, and a burst refused by the short tier never reaches the long
	 * one.
	 */
	public function test_short_tier_refusal_does_not_charge_long_tier(): void {
		$policy = [ [ 20, 3600 ], [ 2, 60 ] ];

		$this->assertTrue( $this->limiter->attempt( 'b', 's', $policy ) );
		$this->assertTrue( $this->limiter->attempt( 'b', 's', $policy ) );
		$this->assertInstanceOf( WP_Error::class, $this->limiter->attempt( 'b', 's', $policy ) );
		$this->assertInstanceOf( WP_Error::class, $this->limiter->attempt( 'b', 's', $policy ) );

		// Shortest first: the 60s window is always the tier consulted first.
		$this->assertSame( 60, $this->fake->windows_seen[0] );

		// The hour tier only saw the two allowed attempts.
		$this->assertSame( 2, $this->fake->counts['b|s|3600'] );
		$this->assertSame( 4, $this->fake->counts['b|s|60'] );
	}

	/**
	 * A long tier keeps refusing after the short tier's window rolls,
	 * which is the whole point of layering: a single window can be
	 * re-spent every time it resets, a stacked one can't.
	 */
	public function test_long_tier_still_refuses_after_short_window_rolls(): void {
		$policy = [ [ 3, 60 ], [ 4, 3600 ] ];

		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertTrue( $this->limiter->attempt( 'b', 's', $policy ) );
		}

		// Roll the short window only.
		$this->fake->reset( 'b', 's', 60 );

		$this->assertTrue( $this->limiter->attempt( 'b', 's', $policy ) );
		$this->assertInstanceOf( WP_Error::class, $this->limiter->attempt( 'b', 's', $policy ) );
	}

	/**
	 * Refunds hand back a slot on every tier.
	 */
	public function test_refund_returns_every_tier(): void {
		$policy = [ [ 5, 60 ], [ 10, 3600 ] ];

		$this->limiter->attempt( 'b', 's', $policy );
		$this->limiter->refund( 'b', 's', $policy );

		$this->assertSame( 0, $this->fake->counts['b|s|60'] );
		$this->assertSame( 0, $this->fake->counts['b|s|3600'] );
	}

	/**
	 * Reset clears every tier.
	 */
	public function test_reset_clears_every_tier(): void {
		$policy = [ [ 5, 60 ], [ 10, 3600 ] ];

		$this->limiter->attempt( 'b', 's', $policy );
		$this->limiter->reset( 'b', 's', $policy );

		$this->assertSame( [], $this->fake->counts );
	}

	/**
	 * An unusable tier is dropped rather than disabling the whole policy,
	 * so a misconfigured value costs one tier and not the limit itself.
	 *
	 * @param int $limit  Limit of the unusable tier.
	 * @param int $window Window of the unusable tier.
	 */
	#[Examples( 0, 60 )]
	#[Examples( -5, 60 )]
	#[Examples( 3, 0 )]
	#[Examples( 3, -60 )]
	public function test_unusable_tiers_are_dropped( int $limit, int $window ): void {
		$policy = [ [ $limit, $window ], [ 1, 3600 ] ];

		$this->assertTrue( $this->limiter->attempt( 'b', 's', $policy ) );

		// Only the usable tier was consulted.
		$this->assertSame( [ 3600 ], $this->fake->windows_seen );

		// And it still applies.
		$this->assertInstanceOf( WP_Error::class, $this->limiter->attempt( 'b', 's', $policy ) );
	}

	/**
	 * A policy with nothing usable in it allows everything, rather than
	 * failing closed and locking a route out entirely.
	 */
	public function test_policy_with_no_usable_tiers_allows_everything(): void {
		for ( $i = 0; $i < 20; $i++ ) {
			$this->assertTrue( $this->limiter->attempt( 'b', 's', [ [ 0, 0 ] ] ) );
		}

		$this->assertSame( [], $this->fake->windows_seen );
	}

	/**
	 * Request helpers pass straight through to the wrapped limiter.
	 */
	public function test_helpers_delegate(): void {
		$this->assertSame( '203.0.113.7', $this->limiter->client_ip() );
		$this->assertSame( 'alice@example.com', $this->limiter->normalize_email( '  Alice@Example.COM ' ) );
	}

	/**
	 * The shipped policies, so a retune can't silently invert the tiers.
	 *
	 * @param array<int, array{0:int,1:int}> $policy Policy under test.
	 */
	#[DataProvider( 'shipped_policy_provider' )]
	public function test_shipped_policies_are_ordered_and_usable( array $policy ): void {
		$this->assertNotEmpty( $policy );

		$windows = array_column( $policy, 1 );
		$limits  = array_column( $policy, 0 );

		$sorted = $windows;
		sort( $sorted );
		$this->assertSame( $sorted, $windows, 'Declare tiers shortest window first.' );

		foreach ( $limits as $limit ) {
			$this->assertGreaterThan( 0, $limit );
		}

		// A longer window must allow at least as much as a shorter one, or
		// the shorter tier could never be reached.
		$previous = 0;
		foreach ( $limits as $limit ) {
			$this->assertGreaterThanOrEqual( $previous, $limit );
			$previous = $limit;
		}
	}

	// -------------------------------------------------------------------------
	// Providers
	// -------------------------------------------------------------------------

	/**
	 * Every policy constant the plugin ships.
	 *
	 * @return array<string, array{0:array}>
	 */
	public function shipped_policy_provider(): array {
		return [
			'request per IP'      => [ TieredRateLimiter::REQUEST_IP ],
			'request per subject' => [ TieredRateLimiter::REQUEST_SUBJECT ],
			'send per recipient'  => [ TieredRateLimiter::SEND_RECIPIENT ],
			'send per IP'         => [ TieredRateLimiter::SEND_IP ],
		];
	}
}
