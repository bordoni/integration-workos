<?php
/**
 * Object-cache-backed rate limiter.
 *
 * @package WorkOS\Auth\AuthKit
 */

namespace WorkOS\RateLimit;

defined( 'ABSPATH' ) || exit;

/**
 * Counters in the object cache.
 *
 * The reason to prefer this one: on a real backend (Redis, Memcached)
 * `wp_cache_incr` is an atomic in-place increment, so two concurrent
 * requests get distinct counts and the limit cannot be overspent. That is
 * exactly the hole a read-modify-write leaves open under a burst.
 */
class ObjectCacheRateLimiter extends BaseRateLimiter {

	/**
	 * Cache group for every counter.
	 */
	private const CACHE_GROUP = 'workos_rate_limit';

	/**
	 * {@inheritDoc}
	 *
	 * `wp_cache_add` is the CAS-safe init: only the first caller in a
	 * window creates the counter, so concurrent callers can't both reset
	 * it to zero.
	 *
	 * @param string $key Storage key.
	 * @param int    $ttl Seconds until the counter's window rolls.
	 *
	 * @return int
	 */
	protected function increment( string $key, int $ttl ): int {
		wp_cache_add( $key, 0, self::CACHE_GROUP, $ttl );

		$count = wp_cache_incr( $key, 1, self::CACHE_GROUP );
		if ( false !== $count ) {
			return (int) $count;
		}

		// Drop-in without incr support: correct, just not atomic.
		$count = (int) wp_cache_get( $key, self::CACHE_GROUP ) + 1;
		wp_cache_set( $key, $count, self::CACHE_GROUP, $ttl );

		return $count;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key Storage key.
	 * @param int    $ttl Seconds until the counter's window rolls.
	 *
	 * @return void
	 */
	protected function decrement( string $key, int $ttl ): void {
		if ( false === wp_cache_get( $key, self::CACHE_GROUP ) ) {
			return;
		}

		$count = wp_cache_decr( $key, 1, self::CACHE_GROUP );

		// Redis decrements below zero where Memcached floors at it; clamp
		// so a stray refund can't buy extra attempts later in the window.
		if ( false === $count || (int) $count < 0 ) {
			wp_cache_set( $key, 0, self::CACHE_GROUP, $ttl );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key Storage key.
	 *
	 * @return void
	 */
	protected function forget( string $key ): void {
		wp_cache_delete( $key, self::CACHE_GROUP );
	}
}
