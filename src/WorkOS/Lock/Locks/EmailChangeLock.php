<?php
/**
 * Lock covering email change requests.
 *
 * @package WorkOS\Lock\Locks
 */

namespace WorkOS\Lock\Locks;

use WorkOS\Lock\BaseLock;

defined( 'ABSPATH' ) || exit;

/**
 * Bounds how often one account can initiate an email change.
 *
 * Every self-service initiation sends two messages — a confirmation link to
 * the new address, a cancel notice to the old — and the new address is
 * whatever the caller typed. The account doing the asking is the thing to
 * count: a signed-in user spraying addresses would otherwise use our sending
 * domain to spam strangers, one fresh target at a time.
 *
 * Keyed per account via {@see BaseLock::for_user()}, never per address, so
 * naming a different destination buys nothing. The capability-gated
 * admin-direct path sends no verification mail and bypasses this lock along
 * with the burst limits.
 */
class EmailChangeLock extends BaseLock {

	/**
	 * Machine name.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'email_change';
	}

	/**
	 * Human-readable label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Email change requests', 'integration-workos' );
	}

	/**
	 * Initiations allowed before a cooldown engages.
	 *
	 * Changing an address is rare; three unfinished attempts already suggest
	 * either a stuck user, who the operator can unlock, or abuse.
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
		return __( 'Too many email change requests. Please wait before trying again.', 'integration-workos' );
	}
}
