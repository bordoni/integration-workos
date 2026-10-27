<?php
/**
 * Reads and validates WorkOS membership responses before any local access change.
 *
 * @package WorkOS\Sync\Membership
 */

namespace WorkOS\Sync\Membership;

use WorkOS\Api\Client;
use WorkOS\Sync\RoleMapper;
use WorkOS\Webhook\EventGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and validates WorkOS membership responses before any local access change.
 */
final class MembershipReader {

	/**
	 * WorkOS API client.
	 *
	 * @var Client
	 */
	private Client $client;

	/**
	 * Receive the shared WorkOS API client.
	 *
	 * @param Client $client WorkOS API client.
	 */
	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * Read exactly one active, mapped membership for this identity and organization.
	 *
	 * @param string $workos_id Linked WorkOS user ID.
	 * @param string $org_id    Configured WorkOS organization ID.
	 * @return MembershipSnapshot|\WP_Error
	 */
	public function read( string $workos_id, string $org_id ) {
		$response = $this->client->list_organization_memberships(
			[
				'user_id'         => $workos_id,
				'organization_id' => $org_id,
				'limit'           => 100,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if (
			! is_array( $response )
			|| ! isset( $response['data'] )
			|| ! is_array( $response['data'] )
			|| ! empty( $response['list_metadata']['after'] )
		) {
			return new \WP_Error( 'workos_invalid_membership_response', __( 'WorkOS returned an invalid or incomplete membership response. No role was changed.', 'integration-workos' ) );
		}

		if ( 1 !== count( $response['data'] ) || ! isset( $response['data'][0] ) || ! is_array( $response['data'][0] ) ) {
			return new \WP_Error( 'workos_membership_not_unique', __( 'Expected one membership for this user and organization. Review their WorkOS membership before retrying.', 'integration-workos' ) );
		}

		return $this->validate_membership( $response['data'][0], $workos_id, $org_id );
	}

	/**
	 * Normalize verified remote facts before passing them to local writers.
	 *
	 * @param array  $membership WorkOS membership payload.
	 * @param string $workos_id  Expected WorkOS user ID.
	 * @param string $org_id     Expected WorkOS organization ID.
	 * @return MembershipSnapshot|\WP_Error
	 */
	private function validate_membership( array $membership, string $workos_id, string $org_id ) {
		if (
			( $membership['user_id'] ?? null ) !== $workos_id
			|| ( $membership['organization_id'] ?? null ) !== $org_id
			|| ! isset( $membership['id'] )
			|| ! is_string( $membership['id'] )
			|| '' === $membership['id']
		) {
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

		return new MembershipSnapshot( $membership['id'], $workos_id, $org_id, $role, $wp_role, $updated_at );
	}
}
