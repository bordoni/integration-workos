<?php
/**
 * Immutable membership facts validated by MembershipReader and used by local refresh operations.
 *
 * @package WorkOS\Sync\Membership
 */

namespace WorkOS\Sync\Membership;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable membership facts validated by MembershipReader and used by local refresh operations.
 */
final class MembershipSnapshot {

	/**
	 * WorkOS membership ID.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * Linked WorkOS user ID.
	 *
	 * @var string
	 */
	private string $workos_user_id;

	/**
	 * WorkOS organization ID.
	 *
	 * @var string
	 */
	private string $organization_id;

	/**
	 * WorkOS role slug.
	 *
	 * @var string
	 */
	private string $workos_role;

	/**
	 * Mapped WordPress role slug.
	 *
	 * @var string
	 */
	private string $wp_role;

	/**
	 * WorkOS membership version timestamp.
	 *
	 * @var string
	 */
	private string $updated_at;

	/**
	 * Carry the validated membership through the refresh workflow.
	 *
	 * @param string $id WorkOS membership ID.
	 * @param string $workos_user_id Linked WorkOS user ID.
	 * @param string $organization_id WorkOS organization ID.
	 * @param string $workos_role WorkOS role slug.
	 * @param string $wp_role Mapped WordPress role slug.
	 * @param string $updated_at WorkOS membership version timestamp.
	 */
	public function __construct( string $id, string $workos_user_id, string $organization_id, string $workos_role, string $wp_role, string $updated_at ) {
		$this->id              = $id;
		$this->workos_user_id  = $workos_user_id;
		$this->organization_id = $organization_id;
		$this->workos_role     = $workos_role;
		$this->wp_role         = $wp_role;
		$this->updated_at      = $updated_at;
	}

	/**
	 * WorkOS membership ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Linked WorkOS user ID.
	 *
	 * @return string
	 */
	public function workos_user_id(): string {
		return $this->workos_user_id;
	}

	/**
	 * WorkOS organization ID.
	 *
	 * @return string
	 */
	public function organization_id(): string {
		return $this->organization_id;
	}

	/**
	 * WorkOS role slug.
	 *
	 * @return string
	 */
	public function workos_role(): string {
		return $this->workos_role;
	}

	/**
	 * Mapped WordPress role slug.
	 *
	 * @return string
	 */
	public function wp_role(): string {
		return $this->wp_role;
	}

	/**
	 * WorkOS membership version timestamp.
	 *
	 * @return string
	 */
	public function updated_at(): string {
		return $this->updated_at;
	}
}
