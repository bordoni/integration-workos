<?php
/**
 * Single-window rate limiter contract.
 *
 * @package WorkOS\Auth\AuthKit
 */

namespace WorkOS\RateLimit;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Counting attempts against one fixed window.
 *
 * Deliberately narrow: a bucket, a subject, a limit, a window. Stacking
 * several windows into a policy is {@see TieredRateLimiter}'s job, so
 * implementations here only have to get counting and storage right.
 *
 * Two implementations back this: {@see ObjectCacheRateLimiter} when the
 * site has a persistent object cache (counters are atomic, so the limit
 * holds under concurrency) and {@see DatabaseRateLimiter} otherwise. The
 * container picks one at boot.
 */
interface RateLimiter {

	/**
	 * Record an attempt in the current window.
	 *
	 * @param string $bucket  Operation identifier (alnum + underscore).
	 * @param string $subject Caller identity (IP, email, user ID).
	 * @param int    $limit   Attempts allowed in the window.
	 * @param int    $window  Window length in seconds.
	 *
	 * @return true|WP_Error True when allowed; WP_Error with status 429 otherwise.
	 */
	public function attempt( string $bucket, string $subject, int $limit, int $window );

	/**
	 * Hand back one attempt in the current window.
	 *
	 * Never drops below zero, and does nothing when the window has rolled
	 * since the attempt, so a refund can't credit a window that was never
	 * charged.
	 *
	 * @param string $bucket  Operation identifier.
	 * @param string $subject Caller identity.
	 * @param int    $window  Window length in seconds.
	 *
	 * @return void
	 */
	public function refund( string $bucket, string $subject, int $window ): void;

	/**
	 * Clear the current window's counter.
	 *
	 * @param string $bucket  Operation identifier.
	 * @param string $subject Caller identity.
	 * @param int    $window  Window length in seconds.
	 *
	 * @return void
	 */
	public function reset( string $bucket, string $subject, int $window ): void;
}
