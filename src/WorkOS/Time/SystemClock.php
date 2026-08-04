<?php
/**
 * Wall-clock time.
 *
 * @package WorkOS\Time
 */

namespace WorkOS\Time;

defined( 'ABSPATH' ) || exit;

/**
 * The real clock. Bound everywhere outside tests.
 */
final class SystemClock implements Clock {

	/**
	 * The current Unix timestamp.
	 *
	 * @return int
	 */
	public function now(): int {
		return time();
	}
}
