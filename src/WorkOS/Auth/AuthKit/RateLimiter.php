<?php
/**
 * Rate limiter for public AuthKit REST endpoints.
 *
 * @package WorkOS\Auth\AuthKit
 */

namespace WorkOS\Auth\AuthKit;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Transient-backed fixed-window rate limiter.
 *
 * Public AuthKit routes (magic send, password auth, signup, reset, MFA) are
 * directly reachable by anonymous callers. WorkOS has its own back-end limits
 * (3–10/min depending on endpoint) but we want to reject floods *before*
 * spending a WorkOS call. This limiter sits in front of every mutation.
 *
 * Keys are composed of a *bucket* name (identifies the operation, e.g.
 * `magic_send_ip`, `password_email`) and a *subject* string (the IP address
 * or lowercased email). The subject is hashed before being embedded into the
 * transient name so personal data never sits in plaintext transients.
 *
 * Windows use a fixed-start-time scheme: the first `attempt()` in a window
 * sets `first_seen`; subsequent attempts within `window_seconds` increment.
 * Once the window elapses the bucket resets on the next attempt.
 */
class RateLimiter {

	/**
	 * Transient name prefix.
	 */
	private const TRANSIENT_PREFIX = 'workos_rl_';

	/**
	 * Cache group used when an external object cache is available.
	 */
	private const CACHE_GROUP = 'workos_rate_limit';

	/**
	 * Record an attempt. Returns WP_Error(429) when the limit is exceeded.
	 *
	 * Uses an atomic counter when a persistent object cache is available
	 * (Redis/Memcached back `wp_cache_incr` with a true CAS op). Falls back
	 * to the transient read-modify-write path when no object cache is
	 * configured — non-atomic by necessity, but safe for single-worker
	 * deployments where real concurrency is absent.
	 *
	 * @param string $bucket         Operation identifier (alnum + underscore; <=32 chars).
	 * @param string $subject        Caller identity (IP, email, or composite).
	 * @param int    $limit          Maximum attempts allowed in the window.
	 * @param int    $window_seconds Window length in seconds.
	 *
	 * @return true|WP_Error True when the attempt is allowed; WP_Error with status 429 otherwise.
	 */
	public function attempt( string $bucket, string $subject, int $limit, int $window_seconds ) {
		if ( $limit <= 0 || $window_seconds <= 0 ) {
			return true;
		}

		$now = time();

		if ( wp_using_ext_object_cache() ) {
			return $this->attempt_atomic( $bucket, $subject, $limit, $window_seconds, $now );
		}

		return $this->attempt_transient( $bucket, $subject, $limit, $window_seconds, $now );
	}

	/**
	 * Atomic attempt path backed by wp_cache_add + wp_cache_incr.
	 *
	 * `wp_cache_add` is CAS-safe when backed by a real object cache: the
	 * init of `first_seen` and `count` happens exactly once per window
	 * across concurrent callers. `wp_cache_incr` is an atomic in-place
	 * counter increment — no read-modify-write race. Two requests that
	 * both pass the init check get distinct `count` values, so the limit
	 * can never be exceeded under concurrency.
	 *
	 * @param string $bucket         Operation identifier.
	 * @param string $subject        Caller identity.
	 * @param int    $limit          Limit.
	 * @param int    $window_seconds Window length.
	 * @param int    $now            Unix timestamp for this attempt.
	 *
	 * @return true|WP_Error
	 */
	private function attempt_atomic( string $bucket, string $subject, int $limit, int $window_seconds, int $now ) {
		$cache_key = $this->cache_key( $bucket, $subject );
		$seen_key  = $cache_key . ':first_seen';
		$count_key = $cache_key . ':count';

		// CAS init: only the first caller in a fresh window writes these.
		wp_cache_add( $seen_key, $now, self::CACHE_GROUP, $window_seconds );
		wp_cache_add( $count_key, 0, self::CACHE_GROUP, $window_seconds );

		$first_seen = (int) wp_cache_get( $seen_key, self::CACHE_GROUP );
		if ( $first_seen <= 0 ) {
			// Bucket evicted between add + get (rare). Recover.
			wp_cache_set( $seen_key, $now, self::CACHE_GROUP, $window_seconds );
			wp_cache_set( $count_key, 0, self::CACHE_GROUP, $window_seconds );
			$first_seen = $now;
		}

		$count = wp_cache_incr( $count_key, 1, self::CACHE_GROUP );
		if ( false === $count ) {
			// Backend that doesn't support incr — degrade to the non-atomic path.
			return $this->attempt_transient( $bucket, $subject, $limit, $window_seconds, $now );
		}

		if ( $count > $limit ) {
			$retry_after = max( 1, ( $first_seen + $window_seconds ) - $now );
			return $this->limit_exceeded( $retry_after );
		}

		return true;
	}

	/**
	 * Non-atomic fallback for sites without a persistent object cache.
	 *
	 * Under true concurrency a single over-limit attempt can slip past
	 * (two requests both read count=N-1 and both write N). WorkOS's own
	 * back-end rate limits (3-10/min on the user-facing endpoints we
	 * proxy) back-stop this; the plugin-level limiter is best-effort on
	 * non-object-cache installs.
	 *
	 * @param string $bucket         Operation identifier.
	 * @param string $subject        Caller identity.
	 * @param int    $limit          Limit.
	 * @param int    $window_seconds Window length.
	 * @param int    $now            Unix timestamp for this attempt.
	 *
	 * @return true|WP_Error
	 */
	private function attempt_transient( string $bucket, string $subject, int $limit, int $window_seconds, int $now ) {
		$key = $this->transient_key( $bucket, $subject );

		$state = get_transient( $key );
		if ( ! is_array( $state ) || ! isset( $state['first_seen'], $state['count'] ) ) {
			$state = [
				'first_seen' => $now,
				'count'      => 0,
			];
		}

		// Reset if the window has elapsed.
		if ( ( $now - (int) $state['first_seen'] ) >= $window_seconds ) {
			$state = [
				'first_seen' => $now,
				'count'      => 0,
			];
		}

		++$state['count'];

		// Persist before the limit check so a concurrent caller also sees the increment.
		// Transient TTL matches the remaining window so stale buckets drop on their own.
		$ttl = max( 1, $window_seconds - ( $now - (int) $state['first_seen'] ) );
		set_transient( $key, $state, $ttl );

		if ( $state['count'] > $limit ) {
			$retry_after = max( 1, ( (int) $state['first_seen'] + $window_seconds ) - $now );
			return $this->limit_exceeded( $retry_after );
		}

		return true;
	}

	/**
	 * Build the 429 WP_Error returned when a bucket is exhausted.
	 *
	 * @param int $retry_after Seconds the caller should wait.
	 *
	 * @return WP_Error
	 */
	private function limit_exceeded( int $retry_after ): WP_Error {
		return new WP_Error(
			'workos_rate_limited',
			__( 'Too many requests. Please try again shortly.', 'integration-workos' ),
			[
				'status'      => 429,
				'retry_after' => $retry_after,
			]
		);
	}

	/**
	 * Reset a bucket — intended for tests and administrative flushes.
	 *
	 * @param string $bucket  Operation identifier.
	 * @param string $subject Caller identity.
	 *
	 * @return void
	 */
	public function reset( string $bucket, string $subject ): void {
		delete_transient( $this->transient_key( $bucket, $subject ) );

		if ( wp_using_ext_object_cache() ) {
			$cache_key = $this->cache_key( $bucket, $subject );
			wp_cache_delete( $cache_key . ':first_seen', self::CACHE_GROUP );
			wp_cache_delete( $cache_key . ':count', self::CACHE_GROUP );
		}
	}

	/**
	 * Build the object-cache key for a bucket + subject pair.
	 *
	 * Uses the same hashing approach as the transient path so personal
	 * data never sits in cache keys in plaintext.
	 *
	 * @param string $bucket  Operation identifier.
	 * @param string $subject Subject (IP, email, etc.).
	 *
	 * @return string
	 */
	private function cache_key( string $bucket, string $subject ): string {
		$safe_bucket = preg_replace( '/[^a-z0-9_]/i', '', $bucket );
		$hash        = substr( hash( 'sha256', $subject ), 0, 32 );
		return $safe_bucket . '_' . $hash;
	}

	/**
	 * Best-effort client IP extraction.
	 *
	 * Respects the standard WP proxy hint hierarchy without blindly trusting
	 * X-Forwarded-For (a caller can spoof the header otherwise). When no
	 * trusted IP can be determined, returns '0.0.0.0' so the caller still has
	 * *something* stable to rate-limit against.
	 *
	 * @return string IPv4 or IPv6 address string.
	 */
	public function client_ip(): string {
		// REMOTE_ADDR is set by the webserver and cannot be spoofed by callers.
		$remote = isset( $_SERVER['REMOTE_ADDR'] )
			? filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ), FILTER_VALIDATE_IP )
			: false;

		return is_string( $remote ) && '' !== $remote ? $remote : '0.0.0.0';
	}

	/**
	 * Normalize an email for consistent per-email bucketing.
	 *
	 * @param string $email Raw email string.
	 *
	 * @return string Lowercased, trimmed email; empty string if not an email.
	 */
	public function normalize_email( string $email ): string {
		$email = strtolower( trim( $email ) );
		return is_email( $email ) ? $email : '';
	}

	/**
	 * Build the transient key for a bucket + subject pair.
	 *
	 * @param string $bucket  Operation identifier.
	 * @param string $subject Subject (IP, email, etc.).
	 *
	 * @return string
	 */
	private function transient_key( string $bucket, string $subject ): string {
		$safe_bucket = preg_replace( '/[^a-z0-9_]/i', '', $bucket );
		$hash        = substr( hash( 'sha256', $subject ), 0, 32 );
		return self::TRANSIENT_PREFIX . $safe_bucket . '_' . $hash;
	}
}
