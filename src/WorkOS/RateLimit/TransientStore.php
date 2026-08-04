<?php
/**
 * Transient counter storage.
 *
 * @package WorkOS\RateLimit
 */

namespace WorkOS\RateLimit;

defined( 'ABSPATH' ) || exit;

/**
 * Counters held in transients.
 *
 * The default. Works on any install with no schema of its own, and expiry
 * is handled by WordPress.
 *
 * Not atomic: two concurrent requests can read the same value and both
 * write the same increment, so a burst can slip one or two past a limit.
 * The durable attempt locks behind the limiter are what bound that — they
 * count in the database, where a race can shave a request past a window
 * but repetition still accumulates.
 */
final class TransientStore implements CounterStore {

	/**
	 * Add one to a counter and return its new value.
	 *
	 * @param string $key Opaque counter key.
	 * @param int    $ttl Seconds until the counter should disappear.
	 *
	 * @return int The value after incrementing.
	 */
	public function increment( string $key, int $ttl ): int {
		$hits = $this->get( $key ) + 1;

		set_transient( $key, $hits, $ttl );

		return $hits;
	}

	/**
	 * Read a counter without changing it.
	 *
	 * @param string $key Opaque counter key.
	 *
	 * @return int Current value, or 0 when absent or expired.
	 */
	public function get( string $key ): int {
		$hits = get_transient( $key );

		return false === $hits ? 0 : (int) $hits;
	}

	/**
	 * Drop a counter.
	 *
	 * @param string $key Opaque counter key.
	 *
	 * @return void
	 */
	public function forget( string $key ): void {
		delete_transient( $key );
	}
}
