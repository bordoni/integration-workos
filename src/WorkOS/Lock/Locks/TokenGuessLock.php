<?php
/**
 * Lock covering repeated failed token and code redemption.
 *
 * @package WorkOS\Lock\Locks
 */

namespace WorkOS\Lock\Locks;

use WorkOS\Lock\BaseLock;

defined( 'ABSPATH' ) || exit;

/**
 * Bounds repeated failed redemption of a code, token or invitation.
 *
 * The routes this covers all accept a secret as their only credential — a
 * six-digit code, a reset token, an invitation token — so the number of tries
 * allowed *is* the security margin. A six-digit code has a million
 * combinations, which sounds ample until it is divided by an unlimited retry
 * budget.
 *
 * Counts failures only, and clears on success, so someone who mistypes a code
 * twice and then gets it right leaves no trace.
 *
 * Subjects here are usually a hash of the presented token or the account it
 * belongs to rather than an address, which is why {@see \WorkOS\Lock\Subject}
 * carries a generic key type.
 */
class TokenGuessLock extends BaseLock {

	/**
	 * Machine name.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'token_guess';
	}

	/**
	 * Human-readable label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Code and token redemption', 'integration-workos' );
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
		return __( 'Too many incorrect attempts. Please wait before trying again, or request a new code.', 'integration-workos' );
	}
}
