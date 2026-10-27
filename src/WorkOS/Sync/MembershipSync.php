<?php
/**
 * Refreshes a linked user's organization membership from the WorkOS API.
 * Shared by full user re-sync and the role-only admin action. Validates the
 * current membership before applying access changes or replacing local data.
 *
 * @package WorkOS\Sync
 */

namespace WorkOS\Sync;

use WorkOS\App;
use WorkOS\Config;
use WorkOS\Organization\Manager;
use WorkOS\Webhook\EventGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Repairs local membership and role drift from an authoritative WorkOS read.
 */
class MembershipSync {

	/**
	 * Fetch and apply the active membership for the configured organization.
	 *
	 * Missing or inactive memberships are reported for review. This operation
	 * does not invent a role or apply a separate deprovisioning policy.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return true|\WP_Error
	 */
	public static function refresh( int $user_id ) {
		if ( ! workos()->is_enabled() ) {
			return new \WP_Error( 'workos_not_configured', __( 'WorkOS is not configured.', 'integration-workos' ) );
		}

		$workos_id = get_user_meta( $user_id, '_workos_user_id', true );
		$org_id    = Config::get_organization_id();
		if ( ! get_userdata( $user_id ) || ! is_string( $workos_id ) || '' === $workos_id ) {
			return new \WP_Error( 'workos_not_linked', __( 'User is not linked to WorkOS.', 'integration-workos' ) );
		}
		if ( '' === $org_id ) {
			return new \WP_Error( 'workos_org_not_configured', __( 'Configure an organization before refreshing roles.', 'integration-workos' ) );
		}

		$response = workos()->api()->list_organization_memberships(
			[
				'user_id'         => $workos_id,
				'organization_id' => $org_id,
				'limit'           => 100,
			]
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! is_array( $response ) || ! isset( $response['data'] ) || ! is_array( $response['data'] ) || ! empty( $response['list_metadata']['after'] ) ) {
			return new \WP_Error( 'workos_invalid_membership_response', __( 'WorkOS returned an invalid or incomplete membership response. No role was changed.', 'integration-workos' ) );
		}
		if ( 1 !== count( $response['data'] ) || ! isset( $response['data'][0] ) || ! is_array( $response['data'][0] ) ) {
			return new \WP_Error( 'workos_membership_not_unique', __( 'Expected one membership for this user and organization. Review their WorkOS membership before retrying.', 'integration-workos' ) );
		}

		$membership = $response['data'][0];
		if ( ( $membership['user_id'] ?? null ) !== $workos_id || ( $membership['organization_id'] ?? null ) !== $org_id
			|| ! isset( $membership['id'] ) || ! is_string( $membership['id'] ) || '' === $membership['id'] ) {
			return new \WP_Error( 'workos_invalid_membership', __( 'WorkOS returned a membership that does not match this user and organization. No role was changed.', 'integration-workos' ) );
		}
		if ( 'active' !== ( $membership['status'] ?? null ) ) {
			return new \WP_Error( 'workos_inactive_membership', __( 'The WorkOS membership is not active. Review access separately; no local role was changed.', 'integration-workos' ) );
		}

		$role    = RoleMapper::role_slug( $membership['role'] ?? '' );
		$map     = RoleMapper::get_role_map();
		$wp_role = $map[ $role ] ?? '';
		if ( '' === $role || ! is_string( $wp_role ) || '' === $wp_role || ! get_role( $wp_role ) ) {
			return new \WP_Error( 'workos_unmapped_role', __( 'Map the current WorkOS role to an existing WordPress role before retrying. No role was changed.', 'integration-workos' ) );
		}

		$updated_at = $membership['updated_at'] ?? '';
		if ( ! is_string( $updated_at ) || '' === EventGuard::latest( $updated_at, '' ) ) {
			return new \WP_Error( 'workos_invalid_membership_timestamp', __( 'WorkOS returned an invalid membership timestamp. No role was changed.', 'integration-workos' ) );
		}
		$org = self::local_organization( $org_id );
		if ( is_wp_error( $org ) ) {
			return $org;
		}

		$applied = get_user_meta( $user_id, RoleMapper::META_MEMBERSHIP_UPDATED_AT, true );
		$applied = is_array( $applied ) ? $applied : [];
		$last    = $applied[ $membership['id'] ] ?? '';
		// Equal timestamps must still repair drift; only a strictly older read is rejected.
		if ( EventGuard::is_stale( $updated_at, $last ) && ! EventGuard::is_stale( $last, $updated_at ) ) {
			return new \WP_Error( 'workos_stale_membership', __( 'A newer membership update has already been processed. Retry the refresh.', 'integration-workos' ) );
		}

		Manager::add_membership(
			(int) $org->id,
			$user_id,
			[
				'workos_membership_id' => $membership['id'],
				'workos_role'          => $role,
				'wp_role'              => $wp_role,
			]
		);
		wp_cache_delete( "membership_{$user_id}_{$org_id}", 'workos' );
		// The storage API returns void. Verify the row before changing WordPress access.
		$stored = false;
		foreach ( Manager::get_user_orgs( $user_id ) as $user_org ) {
			if ( $user_org->workos_org_id === $org_id && $user_org->workos_role === $role && $user_org->wp_role === $wp_role ) {
				$stored = true;
				break;
			}
		}
		if ( ! $stored || Manager::get_membership_id_for_user( $user_id, $org_id ) !== $membership['id'] ) {
			return new \WP_Error( 'workos_membership_save_failed', __( 'Could not save the refreshed membership. Retry the refresh.', 'integration-workos' ) );
		}

		App::container()->get( RoleMapper::class )->apply_role( $user_id, $role );
		// Read persisted roles, not the WP_User instance modified by set_role().
		clean_user_cache( $user_id );
		$user = get_user_by( 'id', $user_id );
		if ( ! $user || array_values( $user->roles ) !== [ $wp_role ] ) {
			return new \WP_Error( 'workos_role_save_failed', __( 'The WordPress roles do not match the refreshed membership. Review the user roles before retrying.', 'integration-workos' ) );
		}
		$applied[ $membership['id'] ] = $updated_at;
		update_user_meta( $user_id, RoleMapper::META_MEMBERSHIP_UPDATED_AT, $applied );
		delete_transient( 'workos_profile_memberships_' . md5( $workos_id ) );

		return true;
	}

	/**
	 * Restore a missing local organization before saving its membership.
	 *
	 * @param string $org_id WorkOS organization ID.
	 * @return object|\WP_Error
	 */
	private static function local_organization( string $org_id ) {
		$org = Manager::get_by_workos_id( $org_id );
		if ( $org ) {
			return $org;
		}
		$data = workos()->api()->get_organization( $org_id );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( ! is_array( $data ) || ( $data['id'] ?? null ) !== $org_id || ! isset( $data['name'] ) || ! is_string( $data['name'] ) ) {
			return new \WP_Error( 'workos_invalid_organization', __( 'WorkOS returned an invalid organization. No role was changed.', 'integration-workos' ) );
		}
		Manager::upsert_organization( $data );
		$org = Manager::get_by_workos_id( $org_id );
		return $org ? $org : new \WP_Error( 'workos_org_save_failed', __( 'Could not save the WorkOS organization.', 'integration-workos' ) );
	}
}
