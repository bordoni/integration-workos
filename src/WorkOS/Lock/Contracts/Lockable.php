<?php
/**
 * Contract every lock implements.
 *
 * @package WorkOS\Lock\Contracts
 */

namespace WorkOS\Lock\Contracts;

use WorkOS\Lock\State;
use WorkOS\Lock\Subject;

defined( 'ABSPATH' ) || exit;

/**
 * A persisted, escalating attempt lock.
 *
 * The calling convention is three steps, and the order matters:
 *
 *     $lock    = workos()->lock( 'otp_send' );
 *     $subject = $lock->for_email( $email );
 *
 *     $gate = $lock->check( $subject );        // 1. read-only gate
 *     if ( is_wp_error( $gate ) ) {
 *         return $gate;
 *     }
 *
 *     $result = do_the_expensive_thing();      // 2. the call worth throttling
 *
 *     $lock->record( $subject );               // 3. count it
 *
 * `check()` never writes, so a barred caller costs one read and nothing else.
 * `record()` runs *after* the expensive call so the counter reflects work that
 * actually happened. For locks that count failures rather than attempts, call
 * `record()` on the failure branch and `release()` on success.
 *
 * Both `check()` and `record()` return `true` when the caller may proceed, or a
 * `WP_Error` with status 429 when it may not — the same error shape the
 * existing burst limiter returns, so REST callers need no special handling.
 */
interface Lockable {

	/**
	 * Machine name identifying this lock, e.g. `otp_send`.
	 *
	 * Used in storage keys, filter names and CLI arguments, so it must be
	 * lowercase alphanumeric with underscores and stable across releases.
	 *
	 * @return string
	 */
	public function slug(): string;

	/**
	 * Build a subject for a WordPress user.
	 *
	 * @param int $user_id WP user ID.
	 *
	 * @return Subject
	 */
	public function for_user( int $user_id ): Subject;

	/**
	 * Build a subject for an email address, resolving a local user when one owns it.
	 *
	 * @param string $email Raw email address.
	 *
	 * @return Subject
	 */
	public function for_email( string $email ): Subject;

	/**
	 * Build a subject for a client IP address.
	 *
	 * @param string $ip Client IP.
	 *
	 * @return Subject
	 */
	public function for_ip( string $ip ): Subject;

	/**
	 * Build a subject for an arbitrary key — an MFA factor id, a token hash.
	 *
	 * @param string $value Key value.
	 *
	 * @return Subject
	 */
	public function for_key( string $value ): Subject;

	/**
	 * Read-only gate. Never writes.
	 *
	 * @param Subject $subject Subject to test.
	 *
	 * @return true|\WP_Error True when allowed; WP_Error with status 429 when barred.
	 */
	public function check( Subject $subject );

	/**
	 * Count one attempt, engaging a cooldown once the threshold is reached.
	 *
	 * @param Subject $subject Subject to count against.
	 *
	 * @return true|\WP_Error True when still allowed; WP_Error with status 429 when this attempt engaged the lock.
	 */
	public function record( Subject $subject );

	/**
	 * Clear all state for a subject — a successful auth, or an operator unlock.
	 *
	 * @param Subject $subject Subject to clear.
	 *
	 * @return void
	 */
	public function release( Subject $subject ): void;

	/**
	 * Current state for a subject, with decay already applied.
	 *
	 * @param Subject $subject Subject to inspect.
	 *
	 * @return State
	 */
	public function status( Subject $subject ): State;
}
