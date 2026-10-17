<?php
/**
 * Transient-backed rate limiter.
 *
 * @package WorkOS\Auth\AuthKit
 */

namespace WorkOS\RateLimit;

defined( 'ABSPATH' ) || exit;

/**
 * Counters in transients, for sites with no persistent object cache.
 *
 * Incrementing is a read-modify-write, so a genuinely concurrent burst can
 * overspend a window: several requests read the same count and all write
 * the same value back. There is no fix for that at this layer without a
 * lock, so the design leans on the longer tiers of a policy to bound the
 * damage, and on WorkOS's own back-end limits behind those.
 */
class DatabaseRateLimiter extends BaseRateLimiter implements CountsAttempts {

	/**
	 * {@inheritDoc}
	 *
	 * @param string $bucket  Operation identifier.
	 * @param string $subject Caller identity.
	 * @param int    $window  Window length in seconds.
	 *
	 * @return int
	 */
	public function count( string $bucket, string $subject, int $window ): int {
		if ( $window <= 0 ) {
			return 0;
		}

		return (int) get_transient( $this->key( $bucket, $subject, $window, time() ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key Storage key.
	 * @param int    $ttl Seconds until the counter's window rolls.
	 *
	 * @return int
	 */
	protected function increment( string $key, int $ttl ): int {
		$count = (int) get_transient( $key ) + 1;
		set_transient( $key, $count, $ttl );

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
		$count = get_transient( $key );
		if ( false === $count ) {
			return;
		}

		set_transient( $key, max( 0, (int) $count - 1 ), $ttl );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key Storage key.
	 *
	 * @return void
	 */
	protected function forget( string $key ): void {
		delete_transient( $key );
	}
}
