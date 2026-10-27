<?php
/**
 * Refreshes a linked user's organization membership from the WorkOS API.
 * Shared by full user re-sync and the role-only admin action. Validates the
 * current membership before applying access changes or replacing local data.
 *
 * @package WorkOS\Sync
 */

namespace WorkOS\Sync;

use WorkOS\Config;
use WorkOS\Sync\Membership\MembershipReader;
use WorkOS\Sync\Membership\MembershipState;
use WorkOS\Sync\Membership\MembershipWriter;
use WorkOS\Sync\Membership\OrganizationResolver;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates remote reads, freshness checks and verified local membership updates.
 */
final class MembershipSync {

	/**
	 * Validated remote memberships.
	 *
	 * @var MembershipReader
	 */
	private MembershipReader $reader;

	/**
	 * Local organization resolution.
	 *
	 * @var OrganizationResolver
	 */
	private OrganizationResolver $organizations;

	/**
	 * Linked identity and applied membership versions.
	 *
	 * @var MembershipState
	 */
	private MembershipState $state;

	/**
	 * Local membership and role updates.
	 *
	 * @var MembershipWriter
	 */
	private MembershipWriter $writer;

	/**
	 * Receive the collaborators that perform each refresh stage.
	 *
	 * @param MembershipReader     $reader        Validated remote reads.
	 * @param OrganizationResolver $organizations Local organization resolution.
	 * @param MembershipState      $state         Identity and version checks.
	 * @param MembershipWriter     $writer        Local membership and role writes.
	 */
	public function __construct( MembershipReader $reader, OrganizationResolver $organizations, MembershipState $state, MembershipWriter $writer ) {
		$this->reader        = $reader;
		$this->organizations = $organizations;
		$this->state         = $state;
		$this->writer        = $writer;
	}

	/**
	 * Refresh an active mapped membership, stopping at the first failed stage.
	 *
	 * Missing or inactive memberships are reported for review, not deprovisioned.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return true|\WP_Error
	 */
	public function refresh( int $user_id ) {
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

		$membership = $this->reader->read( $workos_id, $org_id );

		if ( is_wp_error( $membership ) ) {
			return $membership;
		}

		$organization = $this->organizations->resolve( $org_id );

		if ( is_wp_error( $organization ) ) {
			return $organization;
		}

		$current = $this->state->check_current( $user_id, $membership );

		if ( is_wp_error( $current ) ) {
			return $current;
		}

		$applied = $this->writer->apply( $user_id, (int) $organization->id, $membership );

		if ( is_wp_error( $applied ) ) {
			return $applied;
		}

		return $this->state->record_applied( $user_id, $membership );
	}
}
