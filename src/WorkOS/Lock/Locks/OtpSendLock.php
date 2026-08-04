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
 * Bounds how often a single recipient can be sent a one-time code.
 *
 * Two caller postures share this lock, and it guards something different in
 * each. An anonymous caller names any address they like (magic send, signup),
 * so the cap keeps this site's sending domain from being used to spam an
 * inbox whose owner never asked us to contact them. A caller who already
 * holds a correct password (the verification refusal on password sign-in,
 * the fallback confirmation code) can only reach the account's own inbox —
 * there the concern is not spam but that every dispatch is another live code
 * to phish, and the password being presented may itself be compromised.
 *
 * Subjects come from {@see BaseLock::for_email()}, so the count survives
 * a fresh page, a different send endpoint, and the address being re-spelled —
 * `alice+2@` does not restart it. The authenticated change-email flow keeps
 * its own per-account lock, {@see EmailChangeLock}.
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
