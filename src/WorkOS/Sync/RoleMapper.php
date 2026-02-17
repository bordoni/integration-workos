<?php
/**
 * Role mapping between WorkOS and WordPress.
 *
 * @package WorkOS\Sync
 */

namespace WorkOS\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 1: Simple 1:1 role mapping from WorkOS roles to WP roles.
 */
class RoleMapper {

	/**
	 * Default role mapping.
	 */
	private const DEFAULTS = [
		'admin'  => 'administrator',
		'editor' => 'editor',
		'member' => 'subscriber',
	];

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'workos_user_authenticated', [ $this, 'sync_role_on_login' ], 10, 2 );
		add_action( 'workos_webhook_organization_membership.updated', [ $this, 'handle_membership_updated' ] );
	}

	/**
	 * Sync the WP role when a user logs in via WorkOS.
	 *
	 * @param int   $wp_user_id  WordPress user ID.
	 * @param array $workos_data WorkOS user/auth data.
	 */
	public function sync_role_on_login( int $wp_user_id, array $workos_data ): void {
		$workos_role = $workos_data['role'] ?? $workos_data['organization_membership']['role'] ?? '';
		if ( empty( $workos_role ) ) {
			return;
		}

		$this->apply_role( $wp_user_id, $workos_role );
	}

	/**
	 * Handle membership role update webhook.
	 *
	 * @param array $event Webhook event data.
	 */
	public function handle_membership_updated( array $event ): void {
		$membership     = $event['data'] ?? [];
		$workos_user_id = $membership['user_id'] ?? '';
		$workos_role    = $membership['role'] ?? '';

		if ( empty( $workos_user_id ) || empty( $workos_role ) ) {
			return;
		}

		$wp_user_id = self::get_wp_user_by_workos_id( $workos_user_id );
		if ( ! $wp_user_id ) {
			return;
		}

		$this->apply_role( $wp_user_id, $workos_role );
	}

	/**
	 * Apply a mapped WP role to a user.
	 *
	 * @param int    $wp_user_id  WP user ID.
	 * @param string $workos_role WorkOS role slug.
	 */
	public function apply_role( int $wp_user_id, string $workos_role ): void {
		$wp_role = $this->map_role( $workos_role );
		if ( empty( $wp_role ) ) {
			return;
		}

		$user = get_user_by( 'id', $wp_user_id );
		if ( ! $user ) {
			return;
		}

		// Only change if different.
		if ( ! in_array( $wp_role, $user->roles, true ) ) {
			$user->set_role( $wp_role );
		}
	}

	/**
	 * Map a WorkOS role to a WP role.
	 *
	 * @param string $workos_role WorkOS role slug.
	 *
	 * @return string WP role slug, or empty if no mapping.
	 */
	public function map_role( string $workos_role ): string {
		$map = self::get_role_map();
		return $map[ $workos_role ] ?? $map['member'] ?? 'subscriber';
	}

	/**
	 * Get the configured role map.
	 *
	 * @return array<string, string> WorkOS role => WP role.
	 */
	public static function get_role_map(): array {
		$saved = \WorkOS\App::container()->get( \WorkOS\Options\Global_Options::class )->get( 'role_map', [] );
		return is_array( $saved ) && ! empty( $saved ) ? $saved : self::DEFAULTS;
	}

	/**
	 * Save a role map.
	 *
	 * @param array $map WorkOS role => WP role.
	 */
	public static function save_role_map( array $map ): void {
		\WorkOS\App::container()->get( \WorkOS\Options\Global_Options::class )->set( 'role_map', array_map( 'sanitize_text_field', $map ) );
	}

	/**
	 * Get all available WP roles for the mapping UI.
	 *
	 * @return array<string, string> role_slug => role_name.
	 */
	public static function get_wp_roles(): array {
		$roles  = wp_roles()->get_names();
		$result = [];
		foreach ( $roles as $slug => $name ) {
			$result[ $slug ] = translate_user_role( $name );
		}
		return $result;
	}

	/**
	 * Find a WP user ID by their WorkOS ID.
	 *
	 * @param string $workos_id WorkOS user ID.
	 *
	 * @return int|null WP user ID, or null if not found.
	 */
	private static function get_wp_user_by_workos_id( string $workos_id ): ?int {
		$users = get_users(
			[
				'meta_key'   => '_workos_user_id',
				'meta_value' => $workos_id,
				'number'     => 1,
				'fields'     => 'ID',
			]
		);

		return ! empty( $users ) ? (int) $users[0] : null;
	}
}
