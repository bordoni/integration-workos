<?php
/**
 * Directory sync webhook handler.
 *
 * @package WorkOS\Sync
 */

namespace WorkOS\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Handles WorkOS Directory Sync (SCIM) webhook events.
 */
class DirectorySync {

	/**
	 * Constructor — register webhook handlers.
	 */
	public function __construct() {
		add_action( 'workos_webhook_dsync.user.created', [ $this, 'handle_user_created' ] );
		add_action( 'workos_webhook_dsync.user.updated', [ $this, 'handle_user_updated' ] );
		add_action( 'workos_webhook_dsync.user.deleted', [ $this, 'handle_user_deleted' ] );
		add_action( 'workos_webhook_dsync.group.user_added', [ $this, 'handle_group_user_added' ] );
		add_action( 'workos_webhook_dsync.group.user_removed', [ $this, 'handle_group_user_removed' ] );
	}

	/**
	 * Handle dsync.user.created — provision a WP user.
	 *
	 * @param array $event Webhook event.
	 */
	public function handle_user_created( array $event ): void {
		$directory_user = $event['data'] ?? [];

		$workos_user = $this->normalize_directory_user( $directory_user );
		if ( ! $workos_user ) {
			return;
		}

		UserSync::find_or_create_wp_user( $workos_user );
	}

	/**
	 * Handle dsync.user.updated — update the linked WP user.
	 *
	 * @param array $event Webhook event.
	 */
	public function handle_user_updated( array $event ): void {
		$directory_user = $event['data'] ?? [];
		$workos_id      = $directory_user['id'] ?? '';

		if ( empty( $workos_id ) ) {
			return;
		}

		$wp_user_id = UserSync::get_wp_user_id_by_workos_id( $workos_id );
		if ( ! $wp_user_id ) {
			// User not in WP, create them.
			$workos_user = $this->normalize_directory_user( $directory_user );
			if ( $workos_user ) {
				UserSync::find_or_create_wp_user( $workos_user );
			}
			return;
		}

		// Trigger the standard user.updated flow.
		do_action( 'workos_webhook_user.updated', $event );
	}

	/**
	 * Handle dsync.user.deleted — deprovision the WP user.
	 *
	 * @param array $event Webhook event.
	 */
	public function handle_user_deleted( array $event ): void {
		$directory_user = $event['data'] ?? [];
		$workos_id      = $directory_user['id'] ?? '';

		if ( empty( $workos_id ) ) {
			return;
		}

		$wp_user_id = UserSync::get_wp_user_id_by_workos_id( $workos_id );
		if ( ! $wp_user_id ) {
			return;
		}

		UserSync::deprovision_user( $wp_user_id );
	}

	/**
	 * Handle dsync.group.user_added — map group to WP role.
	 *
	 * @param array $event Webhook event.
	 */
	public function handle_group_user_added( array $event ): void {
		$data  = $event['data'] ?? [];
		$user  = $data['user'] ?? [];
		$group = $data['group'] ?? [];

		if ( empty( $user['id'] ) || empty( $group['name'] ) ) {
			return;
		}

		$wp_user_id = UserSync::get_wp_user_id_by_workos_id( $user['id'] );
		if ( ! $wp_user_id ) {
			return;
		}

		$this->sync_group_role( $wp_user_id, $group['name'] );
	}

	/**
	 * Handle dsync.group.user_removed — may demote the user.
	 *
	 * @param array $event Webhook event.
	 */
	public function handle_group_user_removed( array $event ): void {
		$data = $event['data'] ?? [];
		$user = $data['user'] ?? [];

		if ( empty( $user['id'] ) ) {
			return;
		}

		$wp_user_id = UserSync::get_wp_user_id_by_workos_id( $user['id'] );
		if ( ! $wp_user_id ) {
			return;
		}

		// Reset to default role when removed from a group.
		$default_role = RoleMapper::get_role_map()['member'] ?? 'subscriber';
		$wp_user      = get_user_by( 'id', $wp_user_id );
		if ( $wp_user ) {
			$wp_user->set_role( $default_role );
		}
	}

	/**
	 * Normalize a directory user into the standard WorkOS user format.
	 *
	 * @param array $directory_user Raw directory user data.
	 *
	 * @return array|null Normalized user data.
	 */
	private function normalize_directory_user( array $directory_user ): ?array {
		$emails = $directory_user['emails'] ?? [];
		$email  = '';

		// Find primary email.
		foreach ( $emails as $entry ) {
			if ( ! empty( $entry['primary'] ) ) {
				$email = $entry['value'] ?? '';
				break;
			}
		}

		// Fallback to first email.
		if ( ! $email && ! empty( $emails[0]['value'] ) ) {
			$email = $emails[0]['value'];
		}

		// Or direct email field.
		if ( ! $email ) {
			$email = $directory_user['email'] ?? '';
		}

		if ( empty( $email ) || empty( $directory_user['id'] ) ) {
			return null;
		}

		return [
			'id'         => $directory_user['id'],
			'email'      => $email,
			'first_name' => $directory_user['first_name'] ?? '',
			'last_name'  => $directory_user['last_name'] ?? '',
		];
	}

	/**
	 * Map a directory group name to a WP role.
	 *
	 * Uses the role mapper's group-to-role mapping.
	 *
	 * @param int    $wp_user_id WP user ID.
	 * @param string $group_name Directory group name.
	 */
	private function sync_group_role( int $wp_user_id, string $group_name ): void {
		$role_map = RoleMapper::get_role_map();

		// Try exact match with group name (case-insensitive).
		$group_lower = strtolower( $group_name );
		foreach ( $role_map as $workos_role => $wp_role ) {
			if ( strtolower( $workos_role ) === $group_lower ) {
				$wp_user = get_user_by( 'id', $wp_user_id );
				if ( $wp_user ) {
					$wp_user->set_role( $wp_role );
				}
				return;
			}
		}
	}
}
