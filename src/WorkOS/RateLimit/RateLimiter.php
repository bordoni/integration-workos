<?php
/**
 * Fixed-window rate limiter.
 *
 * @package WorkOS\RateLimit
 */

namespace WorkOS\RateLimit;

use WorkOS\Time\Clock;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Counts events against a limit, over a window, for a subject.
 *
 * Public auth routes are reachable by anonymous callers. WorkOS applies its
 * own back-end limits, but we want to reject floods before spending a call.
 *
 * A counter is identified by a *bucket* naming the operation
 * (`password_email`, `signup_ip`) and a *subject* naming the thing being
 * limited (an IP, an address, a user ID). Subjects are hashed into the key,
 * so no personal data reaches the store.
 *
 * Windows are aligned to the clock rather than to first use: the key
 * carries `floor( now / window )`, so a window expires by arithmetic and a
 * counter that somehow outlives its TTL can only poison a slot that never
 * comes round again. Storage and atomicity belong to {@see CounterStore}.
 *
 * This class limits requesters over seconds. The durable attempt locks in
 * {@see \WorkOS\Lock} sit behind it and remember over hours.
 */
class RateLimiter {

	/**
	 * Key prefix, namespacing counters within a shared store.
	 */
	private const KEY_PREFIX = 'workos_rl_';

	/**
	 * Counter storage.
	 *
	 * @var CounterStore
	 */
	protected CounterStore $store;

	/**
	 * Time source.
	 *
	 * @var Clock
	 */
	protected Clock $clock;

	/**
	 * Constructor.
	 *
	 * @param CounterStore $store Counter storage.
	 * @param Clock        $clock Time source.
	 */
	public function __construct( CounterStore $store, Clock $clock ) {
		$this->store = $store;
		$this->clock = $clock;
	}

	/**
	 * Count an event and rule on it.
	 *
	 * @param string $bucket         Operation identifier.
	 * @param string $subject        Thing being limited (IP, email, user ID).
	 * @param int    $limit          Events allowed per window.
	 * @param int    $window_seconds Window length in seconds.
	 *
	 * @return true|WP_Error True when allowed; WP_Error with status 429 otherwise.
	 */
	public function attempt( string $bucket, string $subject, int $limit, int $window_seconds ) {
		if ( ! $this->countable( $subject, $limit, $window_seconds ) ) {
			return true;
		}

		$now  = $this->clock->now();
		$hits = $this->store->increment( $this->key( $bucket, $subject, $window_seconds, $now ), $this->ttl( $window_seconds, $now ) );

		return $this->verdict( $hits, $limit, $window_seconds, $now );
	}

	/**
	 * Clear the current window for a subject — tests and admin flushes.
	 *
	 * @param string                         $bucket  Operation identifier.
	 * @param string                         $subject Thing being limited.
	 * @param array<int, array{0:int,1:int}> $tiers   List of [limit, window_seconds].
	 *
	 * @return void
	 */
	public function reset( string $bucket, string $subject, array $tiers ): void {
		$now = $this->clock->now();

		foreach ( $tiers as $tier ) {
			$window = (int) $tier[1];

			if ( $window > 0 && '' !== $subject ) {
				$this->store->forget( $this->key( $bucket, $subject, $window, $now ) );
			}
		}
	}

	/**
	 * Is this tier worth counting?
	 *
	 * A zero or negative limit or window means "no limit", and an empty
	 * subject means we have nothing to attribute the event to.
	 *
	 * @param string $subject Thing being limited.
	 * @param int    $limit   Events allowed.
	 * @param int    $window  Window length.
	 *
	 * @return bool
	 */
	private function countable( string $subject, int $limit, int $window ): bool {
		return '' !== $subject && $limit > 0 && $window > 0;
	}

	/**
	 * Allow or deny, given a count.
	 *
	 * @param int $hits   Events counted in this window.
	 * @param int $limit  Events allowed.
	 * @param int $window Window length.
	 * @param int $now    Current timestamp.
	 *
	 * @return true|WP_Error
	 */
	private function verdict( int $hits, int $limit, int $window, int $now ) {
		if ( $hits <= $limit ) {
			return true;
		}

		return new WP_Error(
			'workos_rate_limited',
			__( 'Too many requests. Please try again shortly.', 'integration-workos' ),
			[
				'status'      => 429,
				'retry_after' => $this->ttl( $window, $now ),
			]
		);
	}

	/**
	 * Seconds left in the current window.
	 *
	 * @param int $window Window length.
	 * @param int $now    Current timestamp.
	 *
	 * @return int At least 1.
	 */
	private function ttl( int $window, int $now ): int {
		return max( 1, ( ( intdiv( $now, $window ) + 1 ) * $window ) - $now );
	}

	/**
	 * Build the store key for a bucket, subject and window.
	 *
	 * The window epoch is part of the key, which is what makes expiry
	 * arithmetic: a new window is simply a different key.
	 *
	 * @param string $bucket  Operation identifier.
	 * @param string $subject Thing being limited.
	 * @param int    $window  Window length.
	 * @param int    $now     Current timestamp.
	 *
	 * @return string
	 */
	private function key( string $bucket, string $subject, int $window, int $now ): string {
		$safe_bucket = preg_replace( '/[^a-z0-9_]/i', '', $bucket );
		$digest      = hash( 'sha256', $subject . '|' . $window . '|' . intdiv( $now, $window ) );

		return self::KEY_PREFIX . $safe_bucket . '_' . substr( $digest, 0, 40 );
	}
}
