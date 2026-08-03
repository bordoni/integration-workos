<?php
/**
 * Lock covering password-reset email dispatch.
 *
 * @package WorkOS\Lock\Locks
 */

namespace WorkOS\Lock\Locks;

use WorkOS\Lock\BaseLock;

defined( 'ABSPATH' ) || exit;

/**
 * Bounds how often a reset link can be sent to one account.
 *
 * Like {@see OtpSendLock}, each request sends mail to an address the requester
 * names rather than owns, so the useful number of repeats is small. Applies to
 * the self-service reset flow and the operator-triggered one alike — an
 * account should not receive twenty reset emails because the button was
 * clicked twenty times.
 */
class PasswordResetLock extends BaseLock {

	/**
	 * Machine name.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'password_reset';
	}

	/**
	 * Human-readable label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Password reset delivery', 'integration-workos' );
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
		return __( 'A reset link has already been sent several times. Please check your inbox and try again shortly.', 'integration-workos' );
	}
}
