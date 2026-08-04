<?php
/**
 * Tests for the AuthKit rate limiter.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use Codeception\Attribute\DataProvider;
use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\App;
use WorkOS\Email\AddressCanonicalizer;
use WorkOS\Http\ClientIp;
use WorkOS\RateLimit\OutboundEmailRateLimiter;
use WorkOS\RateLimit\RateLimiter;
use WorkOS\RateLimit\Reservation;
use WorkOS\RateLimit\TransientStore;
use WorkOS\Time\Clock;
use WorkOS\Time\FrozenClock;
use WorkOS\Time\SystemClock;
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
	 * Per-recipient mail limiter under test.
	 *
	 * @var OutboundEmailRateLimiter
	 */
	private OutboundEmailRateLimiter $outbound;

	/**
	 * Set up each test.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->limiter  = new RateLimiter( new TransientStore(), new SystemClock() );
		$this->outbound = new OutboundEmailRateLimiter( $this->limiter, new AddressCanonicalizer(), 3, 10, 20 );
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

		$this->limiter->reset( 'test_bucket', '192.168.0.1', [ [ 3, 60 ] ] );

		$this->assertTrue( $this->limiter->attempt( 'test_bucket', '192.168.0.1', 3, 60 ) );
	}

	/**
	 * Aliases of one mailbox share a single limit.
	 */
	public function test_outbound_limit_is_shared_across_aliases(): void {
		// Short tier is 3 per 5 minutes, spent here across three spellings.
		$this->assertInstanceOf( Reservation::class, $this->outbound->reserve( 'victim@gmail.com' ) );
		$this->assertInstanceOf( Reservation::class, $this->outbound->reserve( 'vic.tim+1@gmail.com' ) );
		$this->assertInstanceOf( Reservation::class, $this->outbound->reserve( 'v.i.c.t.i.m+2@googlemail.com' ) );

		$this->assertInstanceOf( WP_Error::class, $this->outbound->reserve( 'victim+3@gmail.com' ) );
	}

	/**
	 * A refused reservation doesn't leave the partially-taken slots behind.
	 *
	 * The short window refuses first; the hour and day windows it already
	 * incremented have to be given back or a rejected request would still
	 * count against the limit.
	 */
	public function test_refused_reservation_is_not_charged(): void {
		$slots = [];
		for ( $i = 0; $i < 3; $i++ ) {
			$slots[] = $this->outbound->reserve( 'alice@example.com' );
		}

		// Two refusals inside the same short window.
		$this->assertInstanceOf( WP_Error::class, $this->outbound->reserve( 'alice@example.com' ) );
		$this->assertInstanceOf( WP_Error::class, $this->outbound->reserve( 'alice@example.com' ) );

		// Freeing one real send is enough to let the next through. It wouldn't
		// be if the two refusals had each left a charge behind.
		$slots[0]->release();

		$this->assertInstanceOf( Reservation::class, $this->outbound->reserve( 'alice@example.com' ) );
	}

	/**
	 * Releasing an unspent reservation frees the slot again.
	 */
	public function test_release_returns_the_slot(): void {
		$slots = [];
		for ( $i = 0; $i < 3; $i++ ) {
			$slots[] = $this->outbound->reserve( 'alice@example.com' );
		}
		$this->assertInstanceOf( WP_Error::class, $this->outbound->reserve( 'alice@example.com' ) );

		$slots[0]->release();

		$this->assertInstanceOf( Reservation::class, $this->outbound->reserve( 'alice@example.com' ) );
	}

	/**
	 * Releasing twice would credit a window that was only charged once.
	 */
	public function test_release_is_idempotent(): void {
		$slots = [];
		for ( $i = 0; $i < 3; $i++ ) {
			$slots[] = $this->outbound->reserve( 'alice@example.com' );
		}

		$slots[0]->release();
		$slots[0]->release();

		// One slot back, not two.
		$this->assertInstanceOf( Reservation::class, $this->outbound->reserve( 'alice@example.com' ) );
		$this->assertInstanceOf( WP_Error::class, $this->outbound->reserve( 'alice@example.com' ) );
	}

	/**
	 * A refund lands in the window it was charged to, not the current one.
	 *
	 * The reservation carries the instant it was taken, so a boundary passing
	 * while the work is in flight cannot send the credit to the next window
	 * and leave the original charged.
	 */
	public function test_release_targets_the_window_it_was_charged_to(): void {
		$tiers = [ [ 2, 60 ] ];

		$slot = $this->limiter->reserve( 'test_straddle', 'alice@example.com', $tiers );
		$this->assertInstanceOf( Reservation::class, $slot );

		// The window the slot belongs to is fixed at reservation time.
		$this->assertSame( intdiv( $slot->instant(), 60 ), intdiv( time(), 60 ) );

		$slot->release();

		// Two more fit, so the charge really came back off this window.
		$this->assertInstanceOf( Reservation::class, $this->limiter->reserve( 'test_straddle', 'alice@example.com', $tiers ) );
		$this->assertInstanceOf( Reservation::class, $this->limiter->reserve( 'test_straddle', 'alice@example.com', $tiers ) );
	}

	/**
	 * Addresses that reach one mailbox reduce to one string.
	 *
	 * @param string $raw      Address as typed.
	 * @param string $expected Mailbox it belongs to.
	 */
	#[DataProvider( 'canonicalAddresses' )]
	public function test_canonical_addressing( string $raw, string $expected ): void {
		$this->assertSame( $expected, $this->outbound->canonical( $raw ) );
	}

	/**
	 * The Time module reaches code resolved from the container.
	 *
	 * The direct-construction tests below prove the limiter's own behaviour.
	 * This proves the module's freeze actually lands, so an endpoint test
	 * that never touches a Clock itself can still move one.
	 */
	public function test_time_module_freezes_the_container_clock(): void {
		/** @var \Helper\Time $helper */
		$helper = $this->getModule( '\\Helper\\Time' );

		$clock = $helper->freezeTime( 1_800_000_030 );

		$resolved = App::container()->get( Clock::class );
		$this->assertSame( 1_800_000_030, $resolved->now() );

		$helper->travelSeconds( 90 );
		$this->assertSame( 1_800_000_120, $resolved->now() );
		$this->assertSame( $clock, $resolved );

		$helper->unfreezeTime();
		$this->assertNotSame( $clock, App::container()->get( Clock::class ) );
	}

	/**
	 * A short window really does reset once its boundary passes.
	 *
	 * The window is `floor( now / length )`, so this is only observable with
	 * a clock we can move: relative timestamps can't shift the boundary.
	 */
	public function test_short_window_resets_and_long_window_persists(): void {
		// Start mid-window so the jump below lands cleanly in the next one.
		$clock   = new FrozenClock( 1_800_000_030 );
		$limiter = new RateLimiter( new TransientStore(), $clock );
		$tiers   = [ [ 2, 60 ], [ 3, HOUR_IN_SECONDS ] ];

		$this->assertInstanceOf( Reservation::class, $limiter->reserve( 'test_roll', 'alice@example.com', $tiers ) );
		$this->assertInstanceOf( Reservation::class, $limiter->reserve( 'test_roll', 'alice@example.com', $tiers ) );
		$this->assertInstanceOf( WP_Error::class, $limiter->reserve( 'test_roll', 'alice@example.com', $tiers ) );

		$clock->travel( 60 );

		// Fresh minute: the third request now fits.
		$this->assertInstanceOf( Reservation::class, $limiter->reserve( 'test_roll', 'alice@example.com', $tiers ) );

		// But the hour window carried all three across, and it allows three.
		$this->assertInstanceOf( WP_Error::class, $limiter->reserve( 'test_roll', 'alice@example.com', $tiers ) );
	}

	/**
	 * A refund crossing a window boundary credits the window it was charged.
	 *
	 * Reserve in one window, let the boundary pass while the work is in
	 * flight, then release. The credit has to land on the window that was
	 * charged — not on whichever one happens to be current, which would both
	 * leave the original spent and gift a slot to the new one.
	 */
	public function test_release_after_boundary_does_not_credit_the_new_window(): void {
		$clock   = new FrozenClock( 1_800_000_030 );
		$limiter = new RateLimiter( new TransientStore(), $clock );
		$tiers   = [ [ 2, 60 ] ];

		// Charged against the first window.
		$slot = $limiter->reserve( 'test_straddle', 'alice@example.com', $tiers );
		$this->assertInstanceOf( Reservation::class, $slot );

		// Boundary passes while the request is still in flight.
		$clock->travel( 60 );

		// Fill the new window to its limit.
		$this->assertInstanceOf( Reservation::class, $limiter->reserve( 'test_straddle', 'alice@example.com', $tiers ) );
		$this->assertInstanceOf( Reservation::class, $limiter->reserve( 'test_straddle', 'alice@example.com', $tiers ) );
		$this->assertInstanceOf( WP_Error::class, $limiter->reserve( 'test_straddle', 'alice@example.com', $tiers ) );

		// The refund belongs to the old window, so the new one stays full.
		$slot->release();

		$this->assertInstanceOf( WP_Error::class, $limiter->reserve( 'test_straddle', 'alice@example.com', $tiers ) );
	}

	/**
	 * A long window still bites after a short one has rolled over.
	 *
	 * Fixed windows reset wholesale, so a single window is burstable by
	 * waiting out its boundary. The longer tiers are what bound the total.
	 */
	public function test_long_tier_survives_short_window_rollover(): void {
		$tiers = [ [ 100, 60 ], [ 4, HOUR_IN_SECONDS ] ];

		for ( $i = 0; $i < 4; $i++ ) {
			$this->assertInstanceOf( Reservation::class, $this->limiter->reserve( 'test_tiered', 'alice@example.com', $tiers ) );
		}

		// The minute tier has plenty of headroom; the hour tier is spent.
		$result = $this->limiter->reserve( 'test_tiered', 'alice@example.com', $tiers );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 429, $result->get_error_data()['status'] );
	}

	/**
	 * Which address a request is attributed to.
	 *
	 * @param string|null $remote_addr REMOTE_ADDR, or null when absent.
	 * @param string|null $header_name Header an operator has declared trusted.
	 * @param string|null $header_value Value that header carries.
	 * @param string      $expected    Address the limiter should count against.
	 */
	#[DataProvider( 'clientIpCases' )]
	public function test_client_ip_resolution( ?string $remote_addr, ?string $header_name, ?string $header_value, string $expected ): void {
		$previous = $_SERVER['REMOTE_ADDR'] ?? null;

		if ( null === $remote_addr ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $remote_addr;
		}

		if ( null !== $header_value ) {
			$_SERVER['HTTP_CF_CONNECTING_IP'] = $header_value;
		}

		if ( null !== $header_name ) {
			putenv( 'WORKOS_CLIENT_IP_HEADER=' . $header_name );
		}

		$this->assertSame( $expected, ( new ClientIp() )->get() );

		putenv( 'WORKOS_CLIENT_IP_HEADER' );
		unset( $_SERVER['HTTP_CF_CONNECTING_IP'] );

		if ( null === $previous ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $previous;
		}
	}

	// -------------------------------------------------------------------------
	// Data providers
	// -------------------------------------------------------------------------

	/**
	 * Addresses paired with the mailbox they reduce to.
	 *
	 * @return array<string, array{0:string, 1:string}>
	 */
	public function canonicalAddresses(): array {
		return [
			'gmail drops dots and tags' => [
				'a.l.i.c.e+spam@gmail.com',
				'alice@gmail.com',
			],
			'googlemail folds to gmail' => [
				'Alice@googlemail.com',
				'alice@gmail.com',
			],
			'tags stripped everywhere' => [
				'alice+tag@example.com',
				'alice@example.com',
			],
			'dots kept off alias domains' => [
				'a.lice@example.com',
				'a.lice@example.com',
			],
			'uppercase folded' => [
				'  Alice@Example.COM ',
				'alice@example.com',
			],
			'tag-only local part kept' => [
				'+tag@example.com',
				'+tag@example.com',
			],
			'not an address' => [
				'not-an-email',
				'',
			],
			'empty' => [
				'',
				'',
			],
		];
	}

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
			'no remote addr' => [
				null,
				null,
				null,
				'0.0.0.0',
			],
			'undeclared header ignored' => [
				'203.0.113.4',
				null,
				'198.51.100.7',
				'203.0.113.4',
			],
			'declared header wins' => [
				'203.0.113.4',
				'CF-Connecting-IP',
				'198.51.100.7',
				'198.51.100.7',
			],
			'declared header absent' => [
				'203.0.113.4',
				'CF-Connecting-IP',
				null,
				'203.0.113.4',
			],
			'declared header is garbage' => [
				'203.0.113.4',
				'CF-Connecting-IP',
				'not-an-ip',
				'203.0.113.4',
			],
			'private hop skipped, client kept' => [
				'203.0.113.4',
				'CF-Connecting-IP',
				'198.51.100.7, 10.0.0.1',
				'198.51.100.7',
			],
			'client-prepended entry ignored' => [
				'203.0.113.4',
				'CF-Connecting-IP',
				'6.6.6.6, 198.51.100.7',
				'198.51.100.7',
			],
			'all-private list uses right-most' => [
				'203.0.113.4',
				'CF-Connecting-IP',
				'10.0.0.2, 10.0.0.1',
				'10.0.0.1',
			],
			'garbage entries skipped' => [
				'203.0.113.4',
				'CF-Connecting-IP',
				'198.51.100.7, not-an-ip',
				'198.51.100.7',
			],
		];
	}
}
