<?php
/**
 * Read access to a rate limiter's counters.
 *
 * @package WorkOS\RateLimit
 */

namespace WorkOS\RateLimit;

defined( 'ABSPATH' ) || exit;

/**
 * A {@see RateLimiter} that can report a counter without charging it.
 *
 * Separate from the main contract so a third-party limiter that only
 * counts keeps working; lockout lookups just see zero for it. Both
 * shipped implementations provide it.
 */
interface CountsAttempts {

	/**
	 * Attempts recorded in the current window.
	 *
	 * @param string $bucket  Operation identifier.
	 * @param string $subject Caller identity.
	 * @param int    $window  Window length in seconds.
	 *
	 * @return int
	 */
	public function count( string $bucket, string $subject, int $window ): int;
}
