<?php
/**
 * Lock covering account creation.
 *
 * @package WorkOS\Lock\Locks
 */

namespace WorkOS\Lock\Locks;

use WorkOS\Lock\BaseLock;

defined( 'ABSPATH' ) || exit;

/**
 * Bounds how many accounts one caller can create.
 *
 * Each signup writes a durable user record upstream and sends a verification
 * email, and nothing cleans up records that are never verified. Repeated
 * signups therefore leave a permanent residue in the directory as well as
 * costing mail.
 *
 * The per-address threshold is the tightest of any lock — creating the same
 * account three times is already unusual — while the per-IP allowance stays
 * generous enough for a shared office or campus network, where many genuine
 * people legitimately sign up from one address.
 */
class SignupLock extends BaseLock {

	/**
	 * Machine name.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'signup';
	}

	/**
	 * Human-readable label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Account creation', 'integration-workos' );
	}

	/**
	 * Signups allowed before a cooldown engages.
	 *
	 * @return int
	 */
	protected function default_threshold(): int {
		return 3;
	}

	/**
	 * Message shown once the lock engages.
	 *
	 * @return string
	 */
	protected function message(): string {
		return __( 'Too many sign-up attempts. Please wait before trying again.', 'integration-workos' );
	}
}
