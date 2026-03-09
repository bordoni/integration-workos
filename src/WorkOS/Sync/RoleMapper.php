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
	 * Flag to prevent infinite sync loops.
	 *
	 * @var bool
	 */
	private static bool $syncing = false;

	/**
	 * Check if a role sync operation is currently in progress.
	 *
	 * @return bool
	 */
	public static function is_syncing(): bool {
		return self::$syncing;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'workos_user_authenticated', [ $this, 'sync_role_on_login' ], 10, 2 );
		add_action( 'workos_webhook_organization_membership.updated', [ $this, 'handle_membership_updated' ] );
		add_action( 'set_user_role', [ $this, 'push_role_to_workos' ], 10, 3 );
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
		$workos_org_id  = $membership['organization_id'] ?? '';

		if ( empty( $workos_user_id ) || empty( $workos_role ) ) {
			return;
		}

		$wp_user_id = self::get_wp_user_by_workos_id( $workos_user_id );
		if ( ! $wp_user_id ) {
			return;
		}

		$this->apply_role( $wp_user_id, $workos_role );

		// Keep local membership record in sync.
		if ( ! empty( $workos_org_id ) ) {
			$wp_role = $this->map_role( $workos_role );
			\WorkOS\Organization\Manager::update_membership_role( $wp_user_id, $workos_org_id, $workos_role, $wp_role );
		}
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
			self::$syncing = true;
			$user->set_role( $wp_role );
			self::$syncing = false;
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
	 * Reverse-map a WP role to a WorkOS role.
	 *
	 * @param string $wp_role WordPress role slug.
	 *
	 * @return string WorkOS role slug, or empty string if no mapping.
	 */
	public function reverse_map_role( string $wp_role ): string {
		$map     = self::get_role_map();
		$flipped = array_flip( $map );

		return $flipped[ $wp_role ] ?? '';
	}

	/**
	 * Push a WP role change to WorkOS.
	 *
	 * Hooked to `set_user_role` — fires when a user's role changes in WordPress.
	 *
	 * @param int    $user_id   WP user ID.
	 * @param string $role      New WP role.
	 * @param array  $old_roles Previous WP roles.
	 */
	public function push_role_to_workos( int $user_id, string $role, array $old_roles ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		// Prevent loop: skip if this role change originated from WorkOS sync.
		if ( self::$syncing || UserSync::is_syncing() ) {
			return;
		}

		// Only push for WorkOS-linked users.
		$workos_user_id = get_user_meta( $user_id, '_workos_user_id', true );
		if ( empty( $workos_user_id ) ) {
			return;
		}

		$workos_org_id = \WorkOS\Config::get_organization_id();
		if ( empty( $workos_org_id ) ) {
			return;
		}

		// Map WP role back to WorkOS role.
		$workos_role = $this->reverse_map_role( $role );
		if ( empty( $workos_role ) ) {
			workos_log( 'WP role "' . $role . '" has no WorkOS mapping — skipping push for user #' . $user_id, 'info' );
			return;
		}

		// Find the WorkOS membership ID.
		$membership_id = \WorkOS\Organization\Manager::get_membership_id_for_user( $user_id, $workos_org_id );

		// Fallback: query the API if not stored locally.
		if ( empty( $membership_id ) ) {
			$memberships = workos()->api()->list_organization_memberships(
				[
					'user_id'         => $workos_user_id,
					'organization_id' => $workos_org_id,
				]
			);

			if ( is_wp_error( $memberships ) || empty( $memberships['data'] ) ) {
				workos_log( 'No WorkOS membership found for user #' . $user_id . ' in org ' . $workos_org_id, 'error' );
				return;
			}

			$membership_id = $memberships['data'][0]['id'] ?? '';
			if ( ! empty( $membership_id ) ) {
				\WorkOS\Organization\Manager::store_membership_id( $user_id, $workos_org_id, $membership_id );
			}
		}

		if ( empty( $membership_id ) ) {
			workos_log( 'Cannot push role to WorkOS — no membership ID for user #' . $user_id, 'error' );
			return;
		}

		$result = workos()->api()->update_organization_membership( $membership_id, [ 'role_slug' => $workos_role ] );

		if ( is_wp_error( $result ) ) {
			workos_log( 'Failed to push role to WorkOS for user #' . $user_id . ': ' . $result->get_error_message(), 'error' );
			return;
		}

		// Update local record.
		\WorkOS\Organization\Manager::update_membership_role( $user_id, $workos_org_id, $workos_role, $role );
	}

	/**
	 * Push all WP users' current roles to their WorkOS organization memberships.
	 *
	 * Iterates through all memberships for the configured org, reverse-maps
	 * each user's WP role to a WorkOS role, and updates the WorkOS membership
	 * via the API when there's a mismatch.
	 *
	 * @return array{synced: int, skipped: int, failed: int}
	 */
	public static function push_all_roles_to_workos(): array {
		global $wpdb;

		$counts = [
			'synced'  => 0,
			'skipped' => 0,
			'failed'  => 0,
		];

		$workos_org_id = \WorkOS\Config::get_organization_id();
		if ( empty( $workos_org_id ) ) {
			return $counts;
		}

		$local_org = \WorkOS\Organization\Manager::get_by_workos_id( $workos_org_id );
		if ( ! $local_org ) {
			return $counts;
		}

		$mem_table   = $wpdb->prefix . 'workos_org_memberships';
		$memberships = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT user_id, workos_membership_id, workos_role FROM {$mem_table} WHERE org_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$local_org->id
			)
		);

		if ( empty( $memberships ) ) {
			return $counts;
		}

		$mapper = new self();

		foreach ( $memberships as $membership ) {
			$user = get_user_by( 'id', $membership->user_id );
			if ( ! $user || empty( $user->roles ) ) {
				++$counts['skipped'];
				continue;
			}

			$wp_role     = $user->roles[0];
			$workos_role = $mapper->reverse_map_role( $wp_role );

			// Skip if no reverse mapping exists for this WP role.
			if ( empty( $workos_role ) ) {
				++$counts['skipped'];
				continue;
			}

			// Skip if the WorkOS role already matches.
			if ( $workos_role === $membership->workos_role ) {
				++$counts['skipped'];
				continue;
			}

			$membership_id = $membership->workos_membership_id;

			// Fallback: look up membership via API if not stored locally.
			if ( empty( $membership_id ) ) {
				$workos_user_id = get_user_meta( $membership->user_id, '_workos_user_id', true );
				if ( empty( $workos_user_id ) ) {
					++$counts['skipped'];
					continue;
				}

				$api_memberships = workos()->api()->list_organization_memberships(
					[
						'user_id'         => $workos_user_id,
						'organization_id' => $workos_org_id,
					]
				);

				if ( is_wp_error( $api_memberships ) || empty( $api_memberships['data'] ) ) {
					++$counts['failed'];
					continue;
				}

				$membership_id = $api_memberships['data'][0]['id'] ?? '';
				if ( ! empty( $membership_id ) ) {
					\WorkOS\Organization\Manager::store_membership_id( $membership->user_id, $workos_org_id, $membership_id );
				}
			}

			if ( empty( $membership_id ) ) {
				++$counts['failed'];
				continue;
			}

			$result = workos()->api()->update_organization_membership( $membership_id, [ 'role_slug' => $workos_role ] );

			if ( is_wp_error( $result ) ) {
				workos_log( 'Bulk sync: failed to push role for user #' . $membership->user_id . ': ' . $result->get_error_message(), 'error' );
				++$counts['failed'];
				continue;
			}

			\WorkOS\Organization\Manager::update_membership_role( $membership->user_id, $workos_org_id, $workos_role, $wp_role );
			++$counts['synced'];
		}

		return $counts;
	}

	/**
	 * Get the configured role map.
	 *
	 * @return array<string, string> WorkOS role => WP role.
	 */
	public static function get_role_map(): array {
		$saved = workos()->option( 'role_map', [] );
		return is_array( $saved ) && ! empty( $saved ) ? $saved : self::DEFAULTS;
	}

	/**
	 * Save a role map.
	 *
	 * @param array $map WorkOS role => WP role.
	 */
	public static function save_role_map( array $map ): void {
		$env   = \WorkOS\Config::get_active_environment();
		$class = 'staging' === $env ? \WorkOS\Options\Staging::class : \WorkOS\Options\Production::class;

		\WorkOS\App::container()->get( $class )->set( 'role_map', array_map( 'sanitize_text_field', $map ) );
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
	 * Get users whose WP role doesn't match the expected WorkOS role mapping.
	 *
	 * Queries the org_memberships table for the configured organization and
	 * compares each user's current WP role against what the role map says.
	 *
	 * @return array Array of mismatched user info arrays with keys:
	 *               user_id, display_name, workos_role, expected_wp_role, actual_wp_role.
	 */
	public static function get_out_of_sync_users(): array {
		global $wpdb;

		$org_id = \WorkOS\Config::get_organization_id();
		if ( empty( $org_id ) ) {
			return [];
		}

		$local_org = \WorkOS\Organization\Manager::get_by_workos_id( $org_id );
		if ( ! $local_org ) {
			return [];
		}

		$mem_table = $wpdb->prefix . 'workos_org_memberships';

		$memberships = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT user_id, workos_role FROM {$mem_table} WHERE org_id = %d AND workos_role != ''", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$local_org->id
			)
		);

		if ( empty( $memberships ) ) {
			return [];
		}

		$role_map    = self::get_role_map();
		$out_of_sync = [];

		foreach ( $memberships as $membership ) {
			$user = get_user_by( 'id', $membership->user_id );
			if ( ! $user ) {
				continue;
			}

			$workos_role    = $membership->workos_role;
			$expected       = $role_map[ $workos_role ] ?? $role_map['member'] ?? 'subscriber';
			$actual_roles   = $user->roles;
			$actual_wp_role = ! empty( $actual_roles ) ? $actual_roles[0] : '';

			if ( $expected !== $actual_wp_role ) {
				$out_of_sync[] = [
					'user_id'          => (int) $membership->user_id,
					'display_name'     => $user->display_name,
					'workos_role'      => $workos_role,
					'expected_wp_role' => $expected,
					'actual_wp_role'   => $actual_wp_role,
				];
			}
		}

		return $out_of_sync;
	}

	/**
	 * Get user IDs that are out of sync with the role mapping.
	 *
	 * @return int[] Array of WP user IDs.
	 */
	public static function get_out_of_sync_user_ids(): array {
		return array_column( self::get_out_of_sync_users(), 'user_id' );
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
