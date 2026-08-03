<?php
/**
 * Lock covering repeated failed password authentication.
 *
 * @package WorkOS\Lock\Locks
 */

namespace WorkOS\Lock\Locks;

use WorkOS\Lock\BaseLock;

defined( 'ABSPATH' ) || exit;

/**
 * Bounds repeated failed password attempts against one account.
 *
 * Counts failures, not attempts — a caller who signs in correctly every time is
 * never throttled no matter how often they do it, so `release()` runs on every
 * success. That keeps the lock invisible to ordinary use while still putting a
 * hard ceiling on guessing.
 *
 * The threshold is looser than the code-send lock because a person genuinely
 * fumbling their own password is common, and locking them out for hours over it
 * is its own kind of failure.
 */
class PasswordAuthLock extends BaseLock {

	/**
	 * Machine name.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'password_auth';
	}

	/**
	 * Human-readable label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Password sign-in', 'integration-workos' );
	}

	/**
	 * Failures allowed before a cooldown engages.
	 *
	 * @return int
	 */
	protected function default_threshold(): int {
		return 10;
	}

	/**
	 * Message shown once the lock engages.
	 *
	 * @return string
	 */
	protected function message(): string {
		return __( 'Too many failed sign-in attempts. Please wait before trying again, or reset your password.', 'integration-workos' );
	}
}
