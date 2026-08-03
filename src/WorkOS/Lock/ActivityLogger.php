<?php
/**
 * Bridges lock lifecycle events into the activity log.
 *
 * @package WorkOS\Lock
 */

namespace WorkOS\Lock;

use WorkOS\ActivityLog\EventLogger;

defined( 'ABSPATH' ) || exit;

/**
 * Records lock engagements and releases in the activity log.
 *
 * Kept out of {@see BaseLock} so the engine has no opinion about logging and
 * stays testable without a database table behind it. A site that wants
 * different reporting can unhook this and listen to the same two actions.
 *
 * Subject values are recorded as a kind plus a truncated hash, never the raw
 * address — the log answers "which lock engaged, and roughly how often" without
 * turning into a second copy of everyone's email.
 */
class ActivityLogger {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'workos_lock_engaged', [ $this, 'on_engaged' ], 10, 3 );
		add_action( 'workos_lock_released', [ $this, 'on_released' ], 10, 3 );
	}

	/**
	 * Remove hooks.
	 *
	 * @return void
	 */
	public function unregister(): void {
		remove_action( 'workos_lock_engaged', [ $this, 'on_engaged' ], 10 );
		remove_action( 'workos_lock_released', [ $this, 'on_released' ], 10 );
	}

	/**
	 * Record a lock engaging.
	 *
	 * @param string  $slug    Lock slug.
	 * @param Subject $subject Barred subject.
	 * @param State   $state   State after engaging.
	 *
	 * @return void
	 */
	public function on_engaged( string $slug, Subject $subject, State $state ): void {
		EventLogger::log(
			'lock.engaged',
			[
				'user_id'  => $subject->user_id(),
				'metadata' => [
					'lock'         => $slug,
					'subject_type' => $subject->type(),
					'subject_hash' => $this->fingerprint( $subject ),
					'lockouts'     => $state->breaches(),
					'locked_until' => $state->locked_until(),
				],
			]
		);
	}

	/**
	 * Record a lock being cleared.
	 *
	 * @param string  $slug    Lock slug.
	 * @param Subject $subject Cleared subject.
	 * @param State   $state   State immediately before clearing.
	 *
	 * @return void
	 */
	public function on_released( string $slug, Subject $subject, State $state ): void {
		// Only worth a row when something was actually holding. Successful
		// sign-ins clear locks routinely, and logging every one of those would
		// bury the entries that matter.
		if ( 0 === $state->breaches() && ! $state->is_locked( time() ) ) {
			return;
		}

		EventLogger::log(
			'lock.released',
			[
				'user_id'  => $subject->user_id(),
				'metadata' => [
					'lock'         => $slug,
					'subject_type' => $subject->type(),
					'subject_hash' => $this->fingerprint( $subject ),
					'lockouts'     => $state->breaches(),
				],
			]
		);
	}

	/**
	 * Short, non-reversible handle for a subject.
	 *
	 * Enough to correlate rows for the same subject; not enough to recover the
	 * address behind it.
	 *
	 * @param Subject $subject Subject.
	 *
	 * @return string
	 */
	private function fingerprint( Subject $subject ): string {
		return substr( hash( 'sha256', $subject->identity() ), 0, 12 );
	}
}
