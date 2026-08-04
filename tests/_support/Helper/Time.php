<?php
/**
 * Time control for tests.
 *
 * @package WorkOS\Tests
 */

namespace Helper;

use Codeception\TestInterface;
use WorkOS\App;
use WorkOS\Time\Clock;
use WorkOS\Time\FrozenClock;
use WorkOS\Time\SystemClock;

/**
 * Lets a test decide what time it is.
 *
 * Enabled per suite in the suite config. Swaps the container's {@see Clock}
 * for one that only moves when told, so behaviour that turns on a boundary
 * being crossed can be exercised without waiting for it.
 */
class Time extends \Codeception\Module {

	/**
	 * The clock installed by {@see self::freezeTime()}, if any.
	 *
	 * @var FrozenClock|null
	 */
	private ?FrozenClock $frozen = null;

	/**
	 * Hold the container's clock still.
	 *
	 * For behaviour that turns on a boundary being crossed — a rate-limit
	 * window rolling over, a reservation outliving the window it was taken
	 * in. Waiting for those in real time isn't practical, and choosing
	 * timestamps relative to `time()` can't move a boundary at all.
	 *
	 * Only affects things resolved from the container *after* this call, so
	 * freeze before building the subject under test.
	 *
	 * @param int|null $instant Unix timestamp to hold at. Defaults to now.
	 *
	 * @return FrozenClock Handle for travelling forward.
	 */
	public function freezeTime( ?int $instant = null ): FrozenClock {
		$this->frozen = new FrozenClock( $instant ?? time() );

		App::container()->singleton( Clock::class, $this->frozen );

		return $this->frozen;
	}

	/**
	 * Move the frozen clock forward.
	 *
	 * @param int $seconds Seconds to advance.
	 *
	 * @return void
	 */
	public function travelSeconds( int $seconds ): void {
		if ( ! $this->frozen instanceof FrozenClock ) {
			$this->fail( 'travelSeconds() needs freezeTime() first.' );
		}

		$this->frozen->travel( $seconds );
	}

	/**
	 * Put the real clock back.
	 *
	 * @return void
	 */
	public function unfreezeTime(): void {
		$this->frozen = null;

		App::container()->singleton( Clock::class, new SystemClock() );
	}

	/**
	 * Restore the real clock between tests so a freeze can't leak.
	 *
	 * @param TestInterface $test Test that just ran.
	 *
	 * @return void
	 */
	public function _after( TestInterface $test ): void {
		if ( $this->frozen instanceof FrozenClock ) {
			$this->unfreezeTime();
		}
	}
}
