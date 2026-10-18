<?php
/**
 * Per-account limit on MFA code checks.
 *
 * @package WorkOS\Mfa
 */

namespace WorkOS\Mfa;

use WorkOS\RateLimit\TieredRateLimiter;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Caps wrong codes per account, across every surface that checks one.
 *
 * The sign-in step, confirming an enrollment, removing a factor and adding
 * a second one all go through here, keyed on the account rather than the
 * IP or the parked sign-in. Someone with the password can start as many
 * sign-ins as they like, so a cap per sign-in alone doesn't bound guessing.
 *
 * One check per account runs at a time. The rate limiter's transient
 * counters are a read-modify-write, so without the lock a burst of parallel
 * requests could all read the same count and overspend the cap.
 *
 * A check is charged before WorkOS is asked and refunded when the code was
 * right, so only wrong codes count.
 */
class CodeGuard {

	/**
	 * Rate-limit bucket.
	 */
	public const BUCKET = 'mfa_code_account';

	/**
	 * Wrong codes allowed per account: a few retries in a short window, and
	 * a daily ceiling that keeps guessing a 6-digit code out of reach.
	 */
	public const POLICY = [
		[ 5, 15 * MINUTE_IN_SECONDS ],
		[ 30, DAY_IN_SECONDS ],
	];

	/**
	 * Seconds after which a lock left by a crashed request is ignored.
	 */
	private const LOCK_TTL = 30;

	/**
	 * Rate limiter.
	 *
	 * @var TieredRateLimiter
	 */
	private TieredRateLimiter $rate_limiter;

	/**
	 * Constructor.
	 *
	 * @param TieredRateLimiter $rate_limiter Rate limiter.
	 */
	public function __construct( TieredRateLimiter $rate_limiter ) {
		$this->rate_limiter = $rate_limiter;
	}

	/**
	 * Subject for a WP user.
	 *
	 * @param int $user_id WP user ID.
	 *
	 * @return string
	 */
	public static function for_user( int $user_id ): string {
		return 'wp:' . $user_id;
	}

	/**
	 * Subject for a WorkOS user not matched to a WP user yet.
	 *
	 * @param string $workos_user_id WorkOS user ID.
	 *
	 * @return string
	 */
	public static function for_workos_user( string $workos_user_id ): string {
		return 'workos:' . $workos_user_id;
	}

	/**
	 * Start a code check: take the account's lock and charge one attempt.
	 *
	 * Every successful begin() must be followed by passed() or failed().
	 *
	 * @param string $subject Account subject (for_user() / for_workos_user()).
	 *
	 * @return true|WP_Error
	 */
	public function begin( string $subject ) {
		if ( ! $this->lock( $subject ) ) {
			return new WP_Error(
				'workos_mfa_busy',
				__( 'Another code is being checked for this account. Try again in a moment.', 'integration-workos' ),
				[ 'status' => 429 ]
			);
		}

		$charged = $this->rate_limiter->attempt( self::BUCKET, $subject, self::POLICY );
		if ( is_wp_error( $charged ) ) {
			$this->unlock( $subject );
			return new WP_Error(
				'workos_mfa_too_many_codes',
				__( 'Too many wrong codes for this account. Wait a while, then try again.', 'integration-workos' ),
				[ 'status' => 429 ]
			);
		}

		return true;
	}

	/**
	 * The code was right: refund the attempt and release the lock.
	 *
	 * @param string $subject Account subject.
	 *
	 * @return void
	 */
	public function passed( string $subject ): void {
		$this->rate_limiter->refund( self::BUCKET, $subject, self::POLICY );
		$this->unlock( $subject );
	}

	/**
	 * The code was wrong, or the check didn't finish: keep the attempt
	 * counted and release the lock.
	 *
	 * @param string $subject Account subject.
	 *
	 * @return void
	 */
	public function failed( string $subject ): void {
		$this->unlock( $subject );
	}

	/**
	 * Take the account's lock.
	 *
	 * With a persistent object cache, `wp_cache_add()` is atomic. Without
	 * one, an `INSERT IGNORE` on the options table's unique key is.
	 *
	 * @param string $subject Account subject.
	 *
	 * @return bool
	 */
	public function lock( string $subject ): bool {
		$key = $this->lock_key( $subject );

		if ( wp_using_ext_object_cache() ) {
			return wp_cache_add( $key, time(), 'workos_mfa', self::LOCK_TTL );
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name = %s AND option_value < %d',
				$wpdb->options,
				$key,
				time() - self::LOCK_TTL
			)
		);
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$wpdb->options,
				$key,
				(string) time()
			)
		);
		// phpcs:enable

		return 1 === (int) $inserted;
	}

	/**
	 * Release the account's lock.
	 *
	 * @param string $subject Account subject.
	 *
	 * @return void
	 */
	public function unlock( string $subject ): void {
		$key = $this->lock_key( $subject );

		if ( wp_using_ext_object_cache() ) {
			wp_cache_delete( $key, 'workos_mfa' );
			return;
		}

		global $wpdb;
		$wpdb->delete( $wpdb->options, [ 'option_name' => $key ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Lock key for a subject.
	 *
	 * @param string $subject Account subject.
	 *
	 * @return string
	 */
	private function lock_key( string $subject ): string {
		return '_workos_mfa_lock_' . md5( $subject );
	}
}
