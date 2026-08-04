<?php
/**
 * Shared window logic for rate limiters.
 *
 * @package WorkOS\Auth\AuthKit
 */

namespace WorkOS\RateLimit;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Everything about counting a window that isn't storage.
 *
 * Subclasses supply three counter primitives and inherit the window
 * arithmetic, key derivation, and 429 shape.
 *
 * Counters are keyed by bucket, subject, window length, and window number
 * (`floor( now / window )`). Making the window part of the key is what lets
 * a limiter forget about expiry: a new window is simply a different key, so
 * the storage TTL does the cleanup and `retry_after` is arithmetic, with no
 * stored start time to maintain. Subjects are hashed into the key so
 * personal data never sits in plaintext.
 */
abstract class BaseRateLimiter implements RateLimiter {

	/**
	 * Storage key prefix.
	 */
	protected const KEY_PREFIX = 'workos_rl_';

	/**
	 * Add one to a counter and return the new value.
	 *
	 * @param string $key Storage key.
	 * @param int    $ttl Seconds until the counter's window rolls.
	 *
	 * @return int Count after incrementing.
	 */
	abstract protected function increment( string $key, int $ttl ): int;

	/**
	 * Subtract one from an existing counter, never below zero.
	 *
	 * Missing counters are left alone: their window rolled, and
	 * re-creating one would charge a window nothing was spent in.
	 *
	 * @param string $key Storage key.
	 * @param int    $ttl Seconds until the counter's window rolls.
	 *
	 * @return void
	 */
	abstract protected function decrement( string $key, int $ttl ): void;

	/**
	 * Drop a counter entirely.
	 *
	 * @param string $key Storage key.
	 *
	 * @return void
	 */
	abstract protected function forget( string $key ): void;

	/**
	 * {@inheritDoc}
	 *
	 * A non-positive limit or window means "no limit", which keeps a
	 * misconfigured value from silently disabling nothing at all.
	 *
	 * @param string $bucket  Operation identifier.
	 * @param string $subject Caller identity.
	 * @param int    $limit   Attempts allowed in the window.
	 * @param int    $window  Window length in seconds.
	 *
	 * @return true|WP_Error
	 */
	public function attempt( string $bucket, string $subject, int $limit, int $window ) {
		if ( $limit <= 0 || $window <= 0 ) {
			return true;
		}

		$now   = time();
		$ttl   = $this->seconds_left( $window, $now );
		$count = $this->increment( $this->key( $bucket, $subject, $window, $now ), $ttl );

		if ( $count > $limit ) {
			return new WP_Error(
				'workos_rate_limited',
				__( 'Too many requests. Please try again shortly.', 'integration-workos' ),
				[
					'status'      => 429,
					'retry_after' => $ttl,
				]
			);
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $bucket  Operation identifier.
	 * @param string $subject Caller identity.
	 * @param int    $window  Window length in seconds.
	 *
	 * @return void
	 */
	public function refund( string $bucket, string $subject, int $window ): void {
		if ( $window <= 0 ) {
			return;
		}

		$now = time();

		$this->decrement(
			$this->key( $bucket, $subject, $window, $now ),
			$this->seconds_left( $window, $now )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $bucket  Operation identifier.
	 * @param string $subject Caller identity.
	 * @param int    $window  Window length in seconds.
	 *
	 * @return void
	 */
	public function reset( string $bucket, string $subject, int $window ): void {
		if ( $window <= 0 ) {
			return;
		}

		$this->forget( $this->key( $bucket, $subject, $window, time() ) );
	}

	/**
	 * Seconds until the current window rolls.
	 *
	 * @param int $window Window length in seconds.
	 * @param int $now    Unix timestamp.
	 *
	 * @return int
	 */
	protected function seconds_left( int $window, int $now ): int {
		return max( 1, ( intdiv( $now, $window ) + 1 ) * $window - $now );
	}

	/**
	 * Build the storage key for one bucket/subject/window/window-number.
	 *
	 * @param string $bucket  Operation identifier.
	 * @param string $subject Subject (IP, email, user ID).
	 * @param int    $window  Window length in seconds.
	 * @param int    $now     Unix timestamp.
	 *
	 * @return string
	 */
	protected function key( string $bucket, string $subject, int $window, int $now ): string {
		$safe_bucket = preg_replace( '/[^a-z0-9_]/i', '', $bucket );
		$hash        = substr( hash( 'sha256', $subject ), 0, 32 );

		return self::KEY_PREFIX . $safe_bucket . '_' . $window . '_' . intdiv( $now, $window ) . '_' . $hash;
	}
}
