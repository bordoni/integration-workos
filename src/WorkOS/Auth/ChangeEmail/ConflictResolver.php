<?php
/**
 * Conflict checks + policy enforcement for the change-email flow.
 *
 * @package WorkOS\Auth\ChangeEmail
 */

namespace WorkOS\Auth\ChangeEmail;

use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether a requested change to `$new_email` is allowed.
 *
 * The hard case is collision: another local WP user already owns that
 * address. Three policies are supported:
 *
 *  - `block`         — hard reject. The user-facing message is intentionally
 *                      vague (no enumeration) but the activity log records
 *                      the collision.
 *  - `allow_orphan`  — accept only when the conflicting user looks
 *                      abandoned (no WorkOS link, no posts, no comments,
 *                      no recent login). Audit-logged as a takeover.
 *  - `merge_request` — defer to the future merge flow (Issue 2). Until
 *                      that ships this behaves like `block` plus a
 *                      separate hook fire so a future plugin can observe
 *                      the request.
 *
 * `same_user_no_op` is short-circuited: changing to your own current
 * address is treated as a benign no-op (no error, but also no work).
 */
class ConflictResolver {

	public const POLICY_BLOCK         = 'block';
	public const POLICY_ALLOW_ORPHAN  = 'allow_orphan';
	public const POLICY_MERGE_REQUEST = 'merge_request';

	private const DEFAULT_ORPHAN_MAX_INACTIVE_DAYS = 90;

	/**
	 * Check whether `$new_email` can be assigned to `$target`.
	 *
	 * @param string  $new_email Lowercased new address.
	 * @param WP_User $target    User whose email is being changed.
	 *
	 * @return null|WP_Error null when allowed; WP_Error on a hard block.
	 */
	public function check( string $new_email, WP_User $target ) {
		$existing = get_user_by( 'email', $new_email );
		if ( ! $existing instanceof WP_User ) {
			return null;
		}

		if ( (int) $existing->ID === (int) $target->ID ) {
			// Changing to the address you already have. No conflict; the
			// REST handler treats this as a no-op and returns success
			// without writing a pending record.
			return null;
		}

		$policy = $this->resolve_policy();

		/**
		 * Fired before the policy is enforced so other features (notably
		 * the future merge flow in Issue 2) can observe the collision.
		 *
		 * @param int    $target_user_id      User whose email is changing.
		 * @param string $new_email           Requested new address.
		 * @param int    $conflicting_user_id Existing WP user that owns the address.
		 * @param string $policy              Resolved policy slug.
		 */
		do_action(
			'workos_change_email_conflict_detected',
			(int) $target->ID,
			$new_email,
			(int) $existing->ID,
			$policy
		);

		switch ( $policy ) {
			case self::POLICY_ALLOW_ORPHAN:
				if ( $this->is_orphan( $existing ) ) {
					return null;
				}
				return $this->reject( $new_email );

			case self::POLICY_MERGE_REQUEST:
				/**
				 * Fired when the conflict policy is `merge_request`. The
				 * future merge feature (Issue 2) subscribes here. Until
				 * that lands the request still rejects, mirroring `block`.
				 *
				 * @param int    $target_user_id      User whose email is changing.
				 * @param string $new_email           Requested new address.
				 * @param int    $conflicting_user_id Existing WP user that owns the address.
				 */
				do_action(
					'workos_change_email_merge_requested',
					(int) $target->ID,
					$new_email,
					(int) $existing->ID
				);
				return $this->reject( $new_email );

			case self::POLICY_BLOCK:
			default:
				return $this->reject( $new_email );
		}
	}

	/**
	 * Resolve the active conflict policy, honoring the request-time
	 * filter so a security plugin can force `block` regardless of stored
	 * settings.
	 *
	 * @return string
	 */
	public function resolve_policy(): string {
		$option = (string) workos()->option( 'change_email_conflict_policy', self::POLICY_BLOCK );

		/**
		 * Filter the change-email conflict policy at request time.
		 *
		 * @param string $policy One of block | allow_orphan | merge_request.
		 */
		$policy = (string) apply_filters( 'workos_change_email_conflict_policy', $option );

		$valid = [ self::POLICY_BLOCK, self::POLICY_ALLOW_ORPHAN, self::POLICY_MERGE_REQUEST ];
		return in_array( $policy, $valid, true ) ? $policy : self::POLICY_BLOCK;
	}

	/**
	 * Detect whether a WP user looks abandoned and can be reclaimed.
	 *
	 * Conservative on purpose: any signal of activity (posts, comments,
	 * a WorkOS link, a recent login record) disqualifies the user. The
	 * inactivity threshold defaults to 90 days; tune via the
	 * `workos_change_email_orphan_max_inactive_days` filter.
	 *
	 * @param WP_User $user Candidate user to check.
	 *
	 * @return bool
	 */
	public function is_orphan( WP_User $user ): bool {
		// Linked to a WorkOS profile? Not orphaned.
		if ( '' !== (string) get_user_meta( $user->ID, '_workos_user_id', true ) ) {
			return false;
		}

		// Authored content disqualifies — we don't want to silently
		// rebind an address whose user is producing.
		$post_count = count_user_posts( $user->ID, 'any', true );
		if ( $post_count > 0 ) {
			return false;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$comment_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->comments} WHERE user_id = %d",
				$user->ID
			)
		);
		if ( $comment_count > 0 ) {
			return false;
		}

		$days = (int) apply_filters(
			'workos_change_email_orphan_max_inactive_days',
			self::DEFAULT_ORPHAN_MAX_INACTIVE_DAYS
		);
		$days = max( 1, $days );

		// WorkOS UserSync records last login as user meta; if it exists
		// and is recent the user is not orphaned.
		$last_login = (int) get_user_meta( $user->ID, '_workos_last_login_at', true );
		if ( $last_login > 0 && ( time() - $last_login ) < ( $days * DAY_IN_SECONDS ) ) {
			return false;
		}

		// Fall back to the user_registered date as a coarse activity
		// proxy — accounts registered within the window are not yet
		// candidates for takeover.
		$registered = strtotime( (string) $user->user_registered . ' UTC' );
		if ( $registered && ( time() - $registered ) < ( $days * DAY_IN_SECONDS ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Build the consistent "conflict" error.
	 *
	 * The message is intentionally vague so that an attacker can't
	 * enumerate which addresses are taken.
	 *
	 * @param string $new_email Address that would have been written.
	 *
	 * @return WP_Error
	 */
	private function reject( string $new_email ): WP_Error {
		return new WP_Error(
			'workos_change_email_conflict',
			__(
				'That email cannot be used for this account.',
				'integration-workos'
			),
			[
				'status'    => 409,
				'new_email' => $new_email,
			]
		);
	}
}
