<?php
/**
 * Keeps rate-limit tests off a window boundary.
 *
 * The limiters bucket attempts into fixed windows (`intdiv( time(), $window )`),
 * and every policy window is a multiple of a minute. A test that locks a
 * bucket and then reads it back fails if the minute rolls over in between,
 * so tests that lock call this first.
 *
 * @package WorkOS\Tests
 */

/**
 * Sleep out the rest of the minute when it's about to roll over.
 *
 * @param int $margin Seconds before the boundary that count as too close.
 */
function workos_tests_wait_for_fresh_window( int $margin = 3 ): void {
	$left = MINUTE_IN_SECONDS - ( time() % MINUTE_IN_SECONDS );

	if ( $left <= $margin ) {
		sleep( $left );
	}
}
