<?php
/**
 * Per-requester limits for the public auth endpoints.
 *
 * @package WorkOS\RateLimit
 */

namespace WorkOS\RateLimit;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * How much auth work we will do for one caller.
 *
 * Every public auth route proxies to WorkOS, so an unthrottled caller costs
 * us API quota and costs the account owner a brute-force attempt. Two
 * dimensions bound that: the address the request came from, and whatever
 * the request is acting *on* — an email, an account, an MFA factor.
 *
 * This limits *requesters*. Limiting who receives security email is a
 * separate concern, {@see OutboundEmailRateLimiter}.
 *
 * One instance, one default posture. Operations that differ — sign-up is
 * tighter because it creates accounts, `oauth_url` looser because a login
 * screen asks for several — pass their own numbers at the call site, where
 * the constants and the reasoning live next to the endpoint they describe.
 */
class HttpRateLimit {

	/**
	 * Requests allowed per window from one address.
	 */
	public const PER_IP = 10;

	/**
	 * Requests allowed per window against one subject.
	 */
	public const PER_SUBJECT = 5;

	/**
	 * Window length in seconds.
	 */
	public const WINDOW = 60;

	/**
	 * Underlying counter.
	 *
	 * @var RateLimiter
	 */
	protected RateLimiter $limiter;

	/**
	 * Requests allowed per window from one address.
	 *
	 * @var int
	 */
	protected int $per_ip;

	/**
	 * Requests allowed per window against one subject.
	 *
	 * @var int
	 */
	protected int $per_subject;

	/**
	 * Window length in seconds.
	 *
	 * @var int
	 */
	protected int $window;

	/**
	 * Constructor.
	 *
	 * @param RateLimiter $limiter     Underlying counter.
	 * @param int         $per_ip      Requests allowed per window from one address.
	 * @param int         $per_subject Requests allowed per window against one subject.
	 * @param int         $window      Window length in seconds.
	 */
	public function __construct(
		RateLimiter $limiter,
		int $per_ip = self::PER_IP,
		int $per_subject = self::PER_SUBJECT,
		int $window = self::WINDOW
	) {
		$this->limiter     = $limiter;
		$this->per_ip      = $per_ip;
		$this->per_subject = $per_subject;
		$this->window      = $window;
	}

	/**
	 * Apply both dimensions to one request.
	 *
	 * Buckets are derived from the operation name so a caller can't mistype
	 * one into a silently separate counter. Pass an empty subject for routes
	 * that don't act on a specific one.
	 *
	 * @param string   $operation   Operation name, e.g. `password`, `magic_send`.
	 * @param string   $ip          Caller address.
	 * @param string   $subject     Thing being acted on; empty to skip.
	 * @param int|null $per_ip      Replacement for the default per-IP limit.
	 * @param int|null $per_subject Replacement for the default per-subject limit.
	 *
	 * @return true|WP_Error First dimension to refuse wins.
	 */
	public function for_request( string $operation, string $ip, string $subject = '', ?int $per_ip = null, ?int $per_subject = null ) {
		$verdict = $this->for_ip( $operation . '_ip', $ip, $per_ip );
		if ( is_wp_error( $verdict ) ) {
			return $verdict;
		}

		if ( '' === $subject ) {
			return true;
		}

		return $this->for_subject( $operation . '_subject', $subject, $per_subject );
	}

	/**
	 * Count a request against its origin address.
	 *
	 * @param string   $bucket Counter name.
	 * @param string   $ip     Caller address.
	 * @param int|null $limit  Replacement for the instance's per-IP limit.
	 *
	 * @return true|WP_Error
	 */
	public function for_ip( string $bucket, string $ip, ?int $limit = null ) {
		return $this->limiter->attempt( $bucket, $ip, $limit ?? $this->per_ip, $this->window );
	}

	/**
	 * Count a request against the thing it acts on.
	 *
	 * @param string   $bucket  Counter name.
	 * @param string   $subject Email, account ID, factor ID.
	 * @param int|null $limit   Replacement for the instance's per-subject limit.
	 *
	 * @return true|WP_Error
	 */
	public function for_subject( string $bucket, string $subject, ?int $limit = null ) {
		return $this->limiter->attempt( $bucket, $subject, $limit ?? $this->per_subject, $this->window );
	}
}
