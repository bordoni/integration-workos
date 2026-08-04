<?php
/**
 * Storage contract for rate-limit counters.
 *
 * @package WorkOS\RateLimit
 */

namespace WorkOS\RateLimit;

defined( 'ABSPATH' ) || exit;

/**
 * A keyed, expiring, monotonic counter.
 *
 * Implementations own storage and atomicity only. Windowing, limits and
 * verdicts live in {@see RateLimiter}, so a new backend never has to
 * re-derive that logic.
 *
 * Keys are opaque and already hashed by the caller; an implementation must
 * not assume it can parse one.
 */
interface CounterStore {

	/**
	 * Add one to a counter and return its new value.
	 *
	 * A key that does not exist starts at zero, so the first call returns 1.
	 * The TTL is set when the key is created and is not extended by later
	 * increments — the window is fixed from its first hit, not sliding.
	 *
	 * @param string $key Opaque counter key.
	 * @param int    $ttl Seconds until the counter should disappear.
	 *
	 * @return int The value after incrementing.
	 */
	public function increment( string $key, int $ttl ): int;

	/**
	 * Read a counter without changing it.
	 *
	 * @param string $key Opaque counter key.
	 *
	 * @return int Current value, or 0 when absent or expired.
	 */
	public function get( string $key ): int;

	/**
	 * Drop a counter.
	 *
	 * @param string $key Opaque counter key.
	 *
	 * @return void
	 */
	public function forget( string $key ): void;
}
