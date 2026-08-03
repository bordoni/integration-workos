<?php
/**
 * Lock covering MFA challenge dispatch.
 *
 * @package WorkOS\Lock\Locks
 */

namespace WorkOS\Lock\Locks;

use WorkOS\Lock\BaseLock;

defined( 'ABSPATH' ) || exit;

/**
 * Bounds how often a challenge can be issued against one authentication factor.
 *
 * SMS factors turn each challenge into a billed message, which makes this the
 * most directly expensive action the plugin exposes. The challenge route also
 * has to stay reachable without a session — it runs mid-login, before one
 * exists — so the factor id is the only stable thing to count against.
 *
 * Counting per factor rather than per session is the point: a caller can
 * discard sessions freely, but the factor is fixed to the account being
 * challenged.
 */
class MfaChallengeLock extends BaseLock {

	/**
	 * Machine name.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'mfa_challenge';
	}

	/**
	 * Human-readable label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Multi-factor challenge', 'integration-workos' );
	}

	/**
	 * Challenges allowed before a cooldown engages.
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
		return __( 'Too many verification codes requested. Please wait before requesting another.', 'integration-workos' );
	}
}
