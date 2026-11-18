<?php
/**
 * Layered-window rate limiting.
 *
 * @package WorkOS\Auth\AuthKit
 */

namespace WorkOS\RateLimit;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Stacks several windows into one decision.
 *
 * A limit is expressed as a *policy*: a list of `[ limit, window_seconds ]`
 * tiers, charged shortest window first. One tier behaves exactly like a
 * plain per-minute limit; three tiers give a burst allowance plus the
 * ceilings that actually bound abuse. A single window is burstable across
 * its boundary by construction (spend the maximum, wait for the rollover,
 * spend it again), which is why anything that sends email stacks longer
 * windows behind the short one.
 *
 * The wrapped {@see RateLimiter} counts one window at a time and knows
 * nothing about any of this.
 */
class TieredRateLimiter {

	/**
	 * Per-request policies. One 60-second tier: enough to stop a flood
	 * without giving a legitimate user a lockout that outlives their
	 * session.
	 */
	public const REQUEST_IP      = [ [ 10, MINUTE_IN_SECONDS ] ];
	public const REQUEST_SUBJECT = [ [ 5, MINUTE_IN_SECONDS ] ];

	/**
	 * Send policies, for buckets that cause an email. Layered so a
	 * mistyped address stays usable within the short window while the
	 * longer tiers cap what one inbox can be made to receive.
	 */
	public const SEND_RECIPIENT = [
		[ 3, 5 * MINUTE_IN_SECONDS ],
		[ 5, HOUR_IN_SECONDS ],
		[ 10, 8 * HOUR_IN_SECONDS ],
	];
	public const SEND_IP        = [
		[ 10, 5 * MINUTE_IN_SECONDS ],
		[ 30, HOUR_IN_SECONDS ],
		[ 100, 8 * HOUR_IN_SECONDS ],
	];

	/**
	 * Single-window limiter doing the counting.
	 *
	 * @var RateLimiter
	 */
	private RateLimiter $limiter;

	/**
	 * Constructor.
	 *
	 * @param RateLimiter $limiter Single-window limiter to layer over.
	 */
	public function __construct( RateLimiter $limiter ) {
		$this->limiter = $limiter;
	}

	/**
	 * Charge one attempt against every tier of a policy.
	 *
	 * Tiers are charged shortest window first and the first exhausted tier
	 * returns, so a burst stopped by the short tier never inflates the
	 * long ones. A refused attempt still counts against the tier that
	 * refused it, so hammering keeps that window pinned until it rolls.
	 *
	 * @param string                         $bucket  Operation identifier.
	 * @param string                         $subject Caller identity.
	 * @param array<int, array{0:int,1:int}> $policy  Tiers of [ limit, window_seconds ].
	 *
	 * @return true|WP_Error True when allowed; WP_Error with status 429 otherwise.
	 */
	public function attempt( string $bucket, string $subject, array $policy ) {
		foreach ( $this->tiers( $this->policy( $bucket, $policy ) ) as [ $limit, $window ] ) {
			$result = $this->limiter->attempt( $bucket, $subject, $limit, $window );

			if ( is_wp_error( $result ) ) {
				$this->limited( $bucket, $subject, $result );

				return $result;
			}
		}

		return true;
	}

	/**
	 * Hand back one attempt on every tier of a policy.
	 *
	 * For callers that must reserve a slot before an external call that
	 * may send mail, then refund when none did; counting only after the
	 * call would let concurrent requests slip through during the round
	 * trip.
	 *
	 * @param string                         $bucket  Operation identifier.
	 * @param string                         $subject Caller identity.
	 * @param array<int, array{0:int,1:int}> $policy  Policy used when reserving.
	 *
	 * @return void
	 */
	public function refund( string $bucket, string $subject, array $policy ): void {
		foreach ( $this->tiers( $this->policy( $bucket, $policy ) ) as [ , $window ] ) {
			$this->limiter->refund( $bucket, $subject, $window );
		}
	}

	/**
	 * Clear the current window of every tier.
	 *
	 * @param string                         $bucket  Operation identifier.
	 * @param string                         $subject Caller identity.
	 * @param array<int, array{0:int,1:int}> $policy  Policy whose tiers should be cleared.
	 *
	 * @return void
	 */
	public function reset( string $bucket, string $subject, array $policy ): void {
		foreach ( $this->tiers( $this->policy( $bucket, $policy ) ) as [ , $window ] ) {
			$this->limiter->reset( $bucket, $subject, $window );
		}
	}

	/**
	 * The policy a bucket actually runs with, after the filter.
	 *
	 * Applied on attempt, refund and reset alike, so all three touch the
	 * same windows. A filter that returns something other than an array is
	 * ignored; an empty array disables the bucket.
	 *
	 * @param string                         $bucket Operation identifier.
	 * @param array<int, array{0:int,1:int}> $policy Default policy from the caller.
	 *
	 * @return array<int, array{0:int,1:int}>
	 */
	public function policy( string $bucket, array $policy ): array {
		/**
		 * Filters the tiers one rate-limit bucket runs with.
		 *
		 * Each tier is `[ limit, window_seconds ]`. Return the default to
		 * leave the bucket alone, higher limits to loosen it, and an empty
		 * array to turn it off. See docs/rate-limiting.md for the bucket
		 * names and their defaults.
		 *
		 * @since 1.0.11
		 *
		 * @param array<int, array{0:int,1:int}> $policy Default tiers.
		 * @param string                         $bucket Bucket name, e.g. `password_email`.
		 */
		$filtered = apply_filters( 'workos_rate_limit_policy', $policy, $bucket );

		return is_array( $filtered ) ? $filtered : $policy;
	}

	/**
	 * Keyed hash of a subject, for logs and hooks.
	 *
	 * Subjects are emails and IPs. A plain SHA-256 of either can be
	 * reversed by guessing, so this is an HMAC under the site's auth salt:
	 * stable on one site, so entries for the same subject line up, and
	 * useless for confirming a guess without the salt.
	 *
	 * @param string $subject Caller identity.
	 *
	 * @return string 16 hex characters.
	 */
	public static function hash_subject( string $subject ): string {
		return substr( hash_hmac( 'sha256', $subject, wp_salt( 'auth' ) ), 0, 16 );
	}

	/**
	 * Announce a refused attempt.
	 *
	 * @param string   $bucket  Operation identifier.
	 * @param string   $subject Caller identity. Hashed before it leaves here.
	 * @param WP_Error $error   The 429 the tier returned.
	 *
	 * @return void
	 */
	private function limited( string $bucket, string $subject, WP_Error $error ): void {
		$data        = $error->get_error_data();
		$retry_after = is_array( $data ) ? (int) ( $data['retry_after'] ?? 0 ) : 0;

		/**
		 * Fires when a request is refused by a rate limit.
		 *
		 * Once per refused attempt: the first exhausted tier stops the
		 * check. The subject is never passed raw.
		 *
		 * @since 1.0.11
		 *
		 * @param string $bucket       Bucket that refused, e.g. `otp_send_recipient`.
		 * @param string $subject_hash Keyed hash of the email, IP or ID; see TieredRateLimiter::hash_subject().
		 * @param int    $retry_after  Seconds until the refusing window rolls.
		 */
		do_action( 'workos_rate_limited', $bucket, self::hash_subject( $subject ), $retry_after );
	}

	/**
	 * Order a policy shortest window first.
	 *
	 * Tiers with a non-positive limit or window are dropped here rather
	 * than passed down, so a bad value disables that tier alone.
	 *
	 * @param array<int, array{0:int,1:int}> $policy Raw policy.
	 *
	 * @return array<int, array{0:int,1:int}>
	 */
	private function tiers( array $policy ): array {
		$tiers = [];
		foreach ( $policy as $tier ) {
			$limit  = (int) ( $tier[0] ?? 0 );
			$window = (int) ( $tier[1] ?? 0 );

			if ( $limit > 0 && $window > 0 ) {
				$tiers[] = [ $limit, $window ];
			}
		}

		usort( $tiers, static fn( array $a, array $b ): int => $a[1] <=> $b[1] );

		return $tiers;
	}
}
