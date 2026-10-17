<?php
/**
 * Sign-in lockout lookup and clearing.
 *
 * @package WorkOS\RateLimit
 */

namespace WorkOS\RateLimit;

use WorkOS\ActivityLog\EventLogger;
use WorkOS\Email\AddressCanonicalizer;
use WorkOS\REST\Auth\BaseEndpoint;
use WorkOS\REST\Auth\Signup;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Support's view of the email-keyed rate limits.
 *
 * Answers "is this address locked out, by what, and for how long?" and
 * clears it, for the WP-CLI command and the WorkOS Users screen. Only the
 * buckets keyed on an email address are covered: per-IP buckets aren't
 * tied to a customer, and the user-ID buckets guard admin and account
 * actions rather than sign-in.
 *
 * Each bucket is keyed the way its endpoint keys it, so a lookup for the
 * address a customer typed finds the same counters their requests hit.
 */
class Lockouts {

	/**
	 * Keyed on the address as typed, lowercased and trimmed.
	 */
	public const KEY_EXACT = 'exact';

	/**
	 * Keyed on the canonical mailbox ({@see AddressCanonicalizer::canonical()}).
	 */
	public const KEY_MAILBOX = 'mailbox';

	/**
	 * Tiered limiter shared with the endpoints.
	 *
	 * @var TieredRateLimiter
	 */
	private TieredRateLimiter $limiter;

	/**
	 * Mailbox canonicalizer, the same one the endpoints use.
	 *
	 * @var AddressCanonicalizer
	 */
	private AddressCanonicalizer $canonicalizer;

	/**
	 * Constructor.
	 *
	 * @param TieredRateLimiter    $limiter       Tiered limiter.
	 * @param AddressCanonicalizer $canonicalizer Mailbox canonicalizer.
	 */
	public function __construct( TieredRateLimiter $limiter, AddressCanonicalizer $canonicalizer ) {
		$this->limiter       = $limiter;
		$this->canonicalizer = $canonicalizer;
	}

	/**
	 * Every email-keyed bucket, how it keys the address, and its default.
	 *
	 * The names must match the endpoints'. Shared ones come from their
	 * constants; the rest are literals in the endpoint next to the reason
	 * for their keying (see docs/rate-limiting.md, "Which mailbox").
	 *
	 * @return array<string, array{key:string, policy:array<int, array{0:int,1:int}>}>
	 */
	public function buckets(): array {
		return [
			'password_email'                            => [
				'key'    => self::KEY_EXACT,
				'policy' => TieredRateLimiter::REQUEST_SUBJECT,
			],
			'magic_verify_email'                        => [
				'key'    => self::KEY_EXACT,
				'policy' => TieredRateLimiter::REQUEST_SUBJECT,
			],
			'magic_send_email'                          => [
				'key'    => self::KEY_MAILBOX,
				'policy' => TieredRateLimiter::REQUEST_SUBJECT,
			],
			'pw_reset_email'                            => [
				'key'    => self::KEY_MAILBOX,
				'policy' => TieredRateLimiter::REQUEST_SUBJECT,
			],
			'signup_email'                              => [
				'key'    => self::KEY_MAILBOX,
				'policy' => Signup::RATE_LIMIT_CREATE_EMAIL,
			],
			BaseEndpoint::BUCKET_OTP_SEND_RECIPIENT     => [
				'key'    => self::KEY_MAILBOX,
				'policy' => TieredRateLimiter::SEND_RECIPIENT,
			],
			BaseEndpoint::BUCKET_PASSWORD_OTP_RECIPIENT => [
				'key'    => self::KEY_MAILBOX,
				'policy' => TieredRateLimiter::SEND_RECIPIENT,
			],
		];
	}

	/**
	 * Where an address stands on every email-keyed bucket.
	 *
	 * @param string $email Address as the customer would type it.
	 *
	 * @return array<int, array{bucket:string, key:string, subject_hash:string, locked:bool, retry_after:int, tiers:array<int, array{limit:int, window:int, count:int, resets_in:int}>}>|WP_Error
	 */
	public function check( string $email ) {
		$subjects = $this->subjects( $email );
		if ( is_wp_error( $subjects ) ) {
			return $subjects;
		}

		$rows = [];
		foreach ( $this->buckets() as $bucket => $spec ) {
			$subject = $subjects[ $spec['key'] ];
			$tiers   = $this->limiter->status( $bucket, $subject, $spec['policy'] );

			// A request is refused while any tier is full, so the lock
			// lasts until the last full tier rolls.
			$retry_after = 0;
			foreach ( $tiers as $tier ) {
				if ( $tier['count'] >= $tier['limit'] ) {
					$retry_after = max( $retry_after, $tier['resets_in'] );
				}
			}

			$rows[] = [
				'bucket'       => $bucket,
				'key'          => $spec['key'],
				'subject_hash' => TieredRateLimiter::hash_subject( $subject ),
				'locked'       => $retry_after > 0,
				'retry_after'  => $retry_after,
				'tiers'        => $tiers,
			];
		}

		return $rows;
	}

	/**
	 * Clear one bucket, or every email-keyed bucket, for an address.
	 *
	 * Every tier's current window is cleared, and one activity-log entry
	 * records who did it and which buckets, with hashed subjects only.
	 *
	 * @param string $email  Address as the customer would type it.
	 * @param string $bucket One bucket name, or '' for all of them.
	 * @param string $source Where the clear came from: `cli` or `admin`.
	 *
	 * @return array<int, string>|WP_Error Names of the cleared buckets.
	 */
	public function clear( string $email, string $bucket, string $source ) {
		$subjects = $this->subjects( $email );
		if ( is_wp_error( $subjects ) ) {
			return $subjects;
		}

		$buckets = $this->buckets();
		if ( '' !== $bucket ) {
			if ( ! isset( $buckets[ $bucket ] ) ) {
				return new WP_Error(
					'workos_lockout_unknown_bucket',
					sprintf(
						/* translators: 1: bucket name given, 2: comma-separated list of valid bucket names. */
						__( 'Unknown bucket "%1$s". Use one of: %2$s.', 'integration-workos' ),
						$bucket,
						implode( ', ', array_keys( $buckets ) )
					),
					[ 'status' => 400 ]
				);
			}

			$buckets = [ $bucket => $buckets[ $bucket ] ];
		}

		$hashes = [];
		foreach ( $buckets as $name => $spec ) {
			$subject = $subjects[ $spec['key'] ];
			$this->limiter->reset( $name, $subject, $spec['policy'] );
			$hashes[ $name ] = TieredRateLimiter::hash_subject( $subject );
		}

		EventLogger::log(
			'rate_limit_cleared',
			[
				'user_id'  => get_current_user_id(),
				'metadata' => [
					'source'  => $source,
					'buckets' => $hashes,
				],
			]
		);

		return array_keys( $hashes );
	}

	/**
	 * The two subjects an address can be keyed by.
	 *
	 * @param string $email Address as typed.
	 *
	 * @return array{exact:string, mailbox:string}|WP_Error
	 */
	private function subjects( string $email ) {
		$exact = $this->canonicalizer->normalize( $email );
		if ( '' === $exact ) {
			return new WP_Error(
				'workos_lockout_invalid_email',
				__( 'Enter a valid email address.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		return [
			self::KEY_EXACT   => $exact,
			self::KEY_MAILBOX => $this->canonicalizer->canonical( $exact ),
		];
	}
}
