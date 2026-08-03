<?php
/**
 * Lock covering one-time-code and verification email dispatch.
 *
 * @package WorkOS\Lock\Locks
 */

namespace WorkOS\Lock\Locks;

use WorkOS\Lock\BaseLock;

defined( 'ABSPATH' ) || exit;

/**
 * Bounds how often a single address can be sent a one-time code.
 *
 * Every send costs a message, and the recipient does not choose to receive it —
 * the address is supplied by whoever made the request. Repeated sends to the
 * same address are therefore never useful past the first few: the code is
 * already in the inbox, and the rest is noise charged to the sending domain's
 * reputation.
 *
 * Deliberately tighter than the other locks, and the counters are per address
 * rather than per session so re-submitting from a fresh page does not reset
 * them.
 */
class OtpSendLock extends BaseLock {

	/**
	 * Machine name.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'otp_send';
	}

	/**
	 * Human-readable label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'One-time code delivery', 'integration-workos' );
	}

	/**
	 * Sends allowed before a cooldown engages.
	 *
	 * @return int
	 */
	protected function default_threshold(): int {
		return 5;
	}

	/**
	 * Message shown once the lock engages.
	 *
	 * @return string
	 */
	protected function message(): string {
		return __( 'We have sent several codes to this address already. Please check your inbox, including spam, and try again shortly.', 'integration-workos' );
	}
}
