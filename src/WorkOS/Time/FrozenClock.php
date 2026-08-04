<?php
/**
 * A clock that only moves when told to.
 *
 * @package WorkOS\Time
 */

namespace WorkOS\Time;

defined( 'ABSPATH' ) || exit;

/**
 * A clock held at a fixed instant.
 *
 * For tests of behaviour that turns on the passage of time — a rate-limit
 * window rolling over, a reservation outliving the window it was taken in.
 * Waiting for those in real time isn't practical, and picking timestamps
 * relative to `time()` can't move the boundary at all.
 */
final class FrozenClock implements Clock {

	/**
	 * The instant this clock reports.
	 *
	 * @var int
	 */
	private int $instant;

	/**
	 * Constructor.
	 *
	 * @param int $instant Unix timestamp to hold at.
	 */
	public function __construct( int $instant ) {
		$this->instant = $instant;
	}

	/**
	 * The current Unix timestamp.
	 *
	 * @return int
	 */
	public function now(): int {
		return $this->instant;
	}

	/**
	 * Move forward.
	 *
	 * @param int $seconds Seconds to advance.
	 *
	 * @return void
	 */
	public function travel( int $seconds ): void {
		$this->instant += $seconds;
	}

	/**
	 * Jump to a specific instant.
	 *
	 * @param int $instant Unix timestamp.
	 *
	 * @return void
	 */
	public function set( int $instant ): void {
		$this->instant = $instant;
	}
}
