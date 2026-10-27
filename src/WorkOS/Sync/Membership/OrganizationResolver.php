<?php
/**
 * Resolves the local organization, restoring it from WorkOS when missing.
 *
 * @package WorkOS\Sync\Membership
 */

namespace WorkOS\Sync\Membership;

use WorkOS\Api\Client;
use WorkOS\Organization\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the local organization, restoring it from WorkOS when missing.
 */
final class OrganizationResolver {

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
	 * Restore a missing local organization before saving its membership.
	 *
	 * @param string $org_id WorkOS organization ID.
	 * @return object|\WP_Error
	 */
	public function resolve( string $org_id ) {
		$org = Manager::get_by_workos_id( $org_id );

		if ( $org ) {
			return $org;
		}

		$data = $this->client->get_organization( $org_id );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if (
			! is_array( $data )
			|| ( $data['id'] ?? null ) !== $org_id
			|| ! isset( $data['name'] )
			|| ! is_string( $data['name'] )
		) {
			return new \WP_Error( 'workos_invalid_organization', __( 'WorkOS returned an invalid organization. No role was changed.', 'integration-workos' ) );
		}

		Manager::upsert_organization( $data );
		$org = Manager::get_by_workos_id( $org_id );

		return $org ? $org : new \WP_Error( 'workos_org_save_failed', __( 'Could not save the WorkOS organization.', 'integration-workos' ) );
	}
}
