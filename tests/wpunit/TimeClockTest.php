<?php
/**
 * Tests for the injected time source.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\App;
use WorkOS\Time\Clock;
use WorkOS\Time\FrozenClock;

/**
 * Container wiring and the test module's freeze.
 *
 * @covers \WorkOS\Time\Controller
 * @covers \WorkOS\Time\FrozenClock
 */
class TimeClockTest extends WPTestCase {

	/**
	 * Every consumer shares one clock.
	 *
	 * Binding the concrete class separately would cache it under a second key,
	 * so a consumer type-hinting one would not see a swap applied to the other.
	 */
	public function test_the_clock_is_a_single_shared_instance(): void {
		$this->assertSame(
			App::container()->get( Clock::class ),
			App::container()->get( Clock::class )
		);
	}

	/**
	 * The bound clock tracks real time.
	 */
	public function test_the_bound_clock_reports_wall_time(): void {
		$this->assertEqualsWithDelta( time(), App::container()->get( Clock::class )->now(), 2 );
	}

	/**
	 * Freezing reaches anything resolved from the container afterwards.
	 */
	public function test_the_time_module_freezes_the_container_clock(): void {
		/** @var \Helper\Time $helper */
		$helper = $this->getModule( '\\Helper\\Time' );

		$clock = $helper->freezeTime( 1_800_000_030 );

		$resolved = App::container()->get( Clock::class );
		$this->assertSame( 1_800_000_030, $resolved->now() );

		$helper->travelSeconds( 90 );
		$this->assertSame( 1_800_000_120, $resolved->now() );
		$this->assertSame( $clock, $resolved );

		$helper->unfreezeTime();
		$this->assertNotInstanceOf( FrozenClock::class, App::container()->get( Clock::class ) );
	}
}
