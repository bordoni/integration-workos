<?php
/**
 * Checks linked identity and membership versions, then records verified refresh completion.
 *
 * @package WorkOS\Sync\Membership
 */

namespace WorkOS\Sync\Membership;

use WorkOS\Sync\RoleMapper;
use WorkOS\Webhook\EventGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Checks linked identity and membership versions, then records verified refresh completion.
 */
final class MembershipState {

	/**
	 * Reject responses made obsolete while remote requests were in flight.
	 *
	 * @param int                $user_id    WordPress user ID.
	 * @param MembershipSnapshot $membership Validated remote membership.
	 *
	 * @return true|\WP_Error
	 */
	public function check_current( int $user_id, MembershipSnapshot $membership ) {
		// The API requests may outlast a webhook or an administrator's identity change.
		wp_cache_delete( $user_id, 'user_meta' );

		if ( get_user_meta( $user_id, '_workos_user_id', true ) !== $membership->workos_user_id() ) {
			return new \WP_Error( 'workos_identity_changed', __( 'The linked WorkOS identity changed during the request. Retry the refresh.', 'integration-workos' ) );
		}

		$applied = get_user_meta( $user_id, RoleMapper::META_MEMBERSHIP_UPDATED_AT, true );
		$applied = is_array( $applied ) ? $applied : [];
		$last    = $applied[ $membership->id() ] ?? '';

		// Equal timestamps must still repair drift; only a strictly older read is rejected.
		if ( EventGuard::is_stale( $membership->updated_at(), $last ) && ! EventGuard::is_stale( $last, $membership->updated_at() ) ) {
			return new \WP_Error( 'workos_stale_membership', __( 'A newer membership update has already been processed. Retry the refresh.', 'integration-workos' ) );
		}

		return true;
	}

	/**
	 * Record a successfully applied version and verify persistence.
	 *
	 * @param int                $user_id    WordPress user ID.
	 * @param MembershipSnapshot $membership Applied remote membership.
	 *
	 * @return true|\WP_Error
	 */
	public function record_applied( int $user_id, MembershipSnapshot $membership ) {
		$applied = get_user_meta( $user_id, RoleMapper::META_MEMBERSHIP_UPDATED_AT, true );
		$applied = is_array( $applied ) ? $applied : [];

		$applied[ $membership->id() ] = $membership->updated_at();
		update_user_meta( $user_id, RoleMapper::META_MEMBERSHIP_UPDATED_AT, $applied );

		$persisted = get_user_meta( $user_id, RoleMapper::META_MEMBERSHIP_UPDATED_AT, true );

		if ( ! is_array( $persisted ) || ( $persisted[ $membership->id() ] ?? '' ) !== $membership->updated_at() ) {
			return new \WP_Error( 'workos_membership_timestamp_save_failed', __( 'The role was refreshed, but its membership timestamp could not be saved. Retry the refresh to protect it from older webhook events.', 'integration-workos' ) );
		}

		return true;
	}
}
