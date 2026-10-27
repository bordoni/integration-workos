<?php
/**
 * Applies validated memberships to WordPress and verifies the stored membership and role.
 *
 * @package WorkOS\Sync\Membership
 */

namespace WorkOS\Sync\Membership;

use WorkOS\Organization\Manager;
use WorkOS\Sync\RoleMapper;

defined( 'ABSPATH' ) || exit;

/**
 * Applies validated memberships to WordPress and verifies the stored membership and role.
 */
final class MembershipWriter {

	/**
	 * Shared role mapper suppressing outbound sync.
	 *
	 * @var RoleMapper
	 */
	private RoleMapper $role_mapper;

	/**
	 * Receive the shared role mapper.
	 *
	 * @param RoleMapper $role_mapper Shared role mapper.
	 */
	public function __construct( RoleMapper $role_mapper ) {
		$this->role_mapper = $role_mapper;
	}

	/**
	 * Persist membership before changing WordPress access, then verify both writes.
	 *
	 * @param int                $user_id      WordPress user ID.
	 * @param int                $local_org_id Local organization ID.
	 * @param MembershipSnapshot $membership   Validated remote membership.
	 *
	 * @return true|\WP_Error
	 */
	public function apply( int $user_id, int $local_org_id, MembershipSnapshot $membership ) {
		$result = $this->save_membership( $user_id, $local_org_id, $membership );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->role_mapper->apply_role( $user_id, $membership->workos_role() );

		// Read persisted roles, not the WP_User instance modified by set_role().
		clean_user_cache( $user_id );
		$user = get_user_by( 'id', $user_id );

		if ( ! $user || array_values( $user->roles ) !== [ $membership->wp_role() ] ) {
			return new \WP_Error( 'workos_role_save_failed', __( 'The WordPress roles do not match the refreshed membership. Review the user roles before retrying.', 'integration-workos' ) );
		}

		delete_transient( 'workos_profile_memberships_' . md5( $membership->workos_user_id() ) );

		return true;
	}

	/**
	 * Save the local membership and check the persisted row before assigning access.
	 *
	 * @param int                $user_id      WordPress user ID.
	 * @param int                $local_org_id Local organization ID.
	 * @param MembershipSnapshot $membership   Validated remote membership.
	 *
	 * @return true|\WP_Error
	 */
	private function save_membership( int $user_id, int $local_org_id, MembershipSnapshot $membership ) {
		Manager::add_membership(
			$local_org_id,
			$user_id,
			[
				'workos_membership_id' => $membership->id(),
				'workos_role'          => $membership->workos_role(),
				'wp_role'              => $membership->wp_role(),
			]
		);
		wp_cache_delete( 'membership_' . $user_id . '_' . $membership->organization_id(), 'workos' );

		// The storage API returns void. Verify the row before changing WordPress access.
		$stored = false;

		foreach ( Manager::get_user_orgs( $user_id ) as $user_org ) {
			if ( $user_org->workos_org_id === $membership->organization_id() && $user_org->workos_role === $membership->workos_role() && $user_org->wp_role === $membership->wp_role() ) {
				$stored = true;
				break;
			}
		}

		if ( ! $stored || Manager::get_membership_id_for_user( $user_id, $membership->organization_id() ) !== $membership->id() ) {
			return new \WP_Error( 'workos_membership_save_failed', __( 'Could not save the refreshed membership. Retry the refresh.', 'integration-workos' ) );
		}

		return true;
	}
}
