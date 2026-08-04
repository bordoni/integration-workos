<?php
/**
 * Object-cache counter storage.
 *
 * @package WorkOS\RateLimit
 */

namespace WorkOS\RateLimit;

defined( 'ABSPATH' ) || exit;

/**
 * Counters held in the WordPress object cache.
 *
 * Fast, and genuinely atomic when a real backend is behind it: Redis
 * `INCRBY` and Memcached `incr` serialize per key, so concurrent callers
 * each get a distinct return value and the limit holds under load.
 *
 * Worth knowing: WordPress reports an object cache as present based on a
 * drop-in file existing, not on the cache working. A drop-in whose backend
 * is unreachable can degrade to per-request memory while still answering
 * every call, at which point every request starts from zero and no limit is
 * ever reached. {@see TransientStore} does not have that failure mode.
 */
final class ObjectCacheStore implements CounterStore {

	/**
	 * Cache group for every counter.
	 */
	private const GROUP = 'workos_rate_limit';

	/**
	 * Add one to a counter and return its new value.
	 *
	 * @param string $key Opaque counter key.
	 * @param int    $ttl Seconds until the counter should disappear.
	 *
	 * @return int The value after incrementing.
	 */
	public function increment( string $key, int $ttl ): int {
		// `add` writes only when absent, so the TTL is established once per
		// window and concurrent callers cannot reset each other to zero.
		wp_cache_add( $key, 0, self::GROUP, $ttl );

		$count = wp_cache_incr( $key, 1, self::GROUP );

		if ( false === $count ) {
			// Backend without a usable incr. Read-modify-write is racy, but a
			// counter that under-counts beats one that reports nothing.
			$count = $this->get( $key ) + 1;
			wp_cache_set( $key, $count, self::GROUP, $ttl );
		}

		return (int) $count;
	}

	/**
	 * Subtract one from a counter and return its new value.
	 *
	 * @param string $key Opaque counter key.
	 * @param int    $ttl Seconds until the counter should disappear.
	 *
	 * @return int The value after decrementing, never below zero.
	 */
	public function decrement( string $key, int $ttl ): int {
		$count = wp_cache_decr( $key, 1, self::GROUP );

		if ( false === $count ) {
			$count = max( 0, $this->get( $key ) - 1 );
			wp_cache_set( $key, $count, self::GROUP, $ttl );
		}

		return max( 0, (int) $count );
	}

	/**
	 * Read a counter without changing it.
	 *
	 * @param string $key Opaque counter key.
	 *
	 * @return int Current value, or 0 when absent or expired.
	 */
	public function get( string $key ): int {
		$value = wp_cache_get( $key, self::GROUP );

		return false === $value ? 0 : (int) $value;
	}

	/**
	 * Drop a counter.
	 *
	 * @param string $key Opaque counter key.
	 *
	 * @return void
	 */
	public function forget( string $key ): void {
		wp_cache_delete( $key, self::GROUP );
	}
}
