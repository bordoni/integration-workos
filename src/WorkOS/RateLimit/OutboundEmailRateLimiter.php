<?php
/**
 * Per-recipient limiter for mail this plugin causes to be sent.
 *
 * @package WorkOS\RateLimit
 */

namespace WorkOS\RateLimit;

use WorkOS\Email\AddressCanonicalizer;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Limits security email per mailbox, across every path that can send it.
 *
 * Verification codes, magic codes and reset links are the plugin's security
 * email. {@see HttpRateLimit} limits who is *asking* for them; this limits
 * who *receives* them, which is the dimension that matters when the abuse
 * is aimed at somebody else's inbox. Per-endpoint requester limits cannot
 * bound that however tightly they are tuned: point sign-up, magic-code,
 * password reset and password login at one address and the real allowance
 * is their sum.
 *
 * Hence a single counter. Rotating endpoints buys nothing, because every
 * send counts against the same one for the same mailbox.
 *
 * Layered windows, because a single fixed window is burstable at its
 * boundary by construction. The short window keeps a mistyped address
 * usable; the hour and day windows are the actual ceiling.
 *
 * Slots are claimed before the send, not after. Some senders only learn
 * afterwards whether anything went out — WorkOS mails a verification code
 * as a side effect of authenticating an unverified account — so those
 * reserve up front and {@see self::release()} when the response shows no
 * mail was sent. That stops a mistyped password counting as a send, without
 * leaving a window for concurrent callers to all pass at once.
 */
class OutboundEmailRateLimiter {

	/**
	 * The one bucket every send path shares.
	 */
	private const BUCKET = 'outbound_email';

	/**
	 * Underlying counter.
	 *
	 * @var RateLimiter
	 */
	protected RateLimiter $limiter;

	/**
	 * Address canonicalizer.
	 *
	 * @var AddressCanonicalizer
	 */
	protected AddressCanonicalizer $addresses;

	/**
	 * Messages allowed per five minutes.
	 *
	 * @var int
	 */
	protected int $per_five_minutes;

	/**
	 * Messages allowed per hour.
	 *
	 * @var int
	 */
	protected int $per_hour;

	/**
	 * Messages allowed per day.
	 *
	 * @var int
	 */
	protected int $per_day;

	/**
	 * Constructor.
	 *
	 * @param RateLimiter          $limiter          Underlying counter.
	 * @param AddressCanonicalizer $addresses        Address canonicalizer.
	 * @param int                  $per_five_minutes Messages allowed per five minutes.
	 * @param int                  $per_hour         Messages allowed per hour.
	 * @param int                  $per_day          Messages allowed per day.
	 */
	public function __construct(
		RateLimiter $limiter,
		AddressCanonicalizer $addresses,
		int $per_five_minutes,
		int $per_hour,
		int $per_day
	) {
		$this->limiter          = $limiter;
		$this->addresses        = $addresses;
		$this->per_five_minutes = $per_five_minutes;
		$this->per_hour         = $per_hour;
		$this->per_day          = $per_day;
	}

	/**
	 * Windows this limit is enforced over.
	 *
	 * @return array<int, array{0:int,1:int}> List of [limit, window_seconds].
	 */
	public function tiers(): array {
		return [
			[ $this->per_five_minutes, 5 * MINUTE_IN_SECONDS ],
			[ $this->per_hour, HOUR_IN_SECONDS ],
			[ $this->per_day, DAY_IN_SECONDS ],
		];
	}

	/**
	 * Claim the right to send one message to an address.
	 *
	 * Call before sending. Senders that discover afterwards they sent
	 * nothing give the slot back with {@see self::release()}.
	 *
	 * @param string $email Destination address.
	 *
	 * @return Reservation|WP_Error WP_Error with status 429 when spent.
	 */
	public function reserve( string $email ) {
		return $this->limiter->reserve( self::BUCKET, $this->canonical( $email ), $this->tiers() );
	}

	/**
	 * Reduce an address to the mailbox its limits apply to.
	 *
	 * @param string $email Raw email string.
	 *
	 * @return string Empty when not an address.
	 */
	public function canonical( string $email ): string {
		return $this->addresses->canonical( $email );
	}
}
