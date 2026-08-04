<?php
/**
 * Time source.
 *
 * @package WorkOS\Time
 */

namespace WorkOS\Time;

defined( 'ABSPATH' ) || exit;

/**
 * Where "now" comes from.
 *
 * Exists so time-dependent behaviour can be exercised without waiting for
 * it. Anything that decides based on the clock takes one of these rather
 * than calling `time()`, which makes the decision reproducible.
 */
interface Clock {

	/**
	 * The current Unix timestamp.
	 *
	 * @return int
	 */
	public function now(): int;
}
