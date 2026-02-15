<?php
/**
 * Organization management.
 *
 * @package WorkOS\Organization
 */

namespace WorkOS\Organization;

defined( 'ABSPATH' ) || exit;

/**
 * Manages local organization records synced from WorkOS.
 */
class Manager {

	/**
	 * Constructor — register webhook handlers.
	 */
	public function __construct() {
		add_action( 'workos_webhook_organization.created', [ $this, 'handle_org_created' ] );
		add_action( 'workos_webhook_organization.updated', [ $this, 'handle_org_updated' ] );
		add_action( 'workos_webhook_organization_membership.created', [ $this, 'handle_membership_created' ] );
		add_action( 'workos_webhook_organization_membership.deleted', [ $this, 'handle_membership_deleted' ] );
	}

	/**
	 * Handle organization.created webhook.
	 *
	 * @param array $event Webhook event.
	 */
	public function handle_org_created( array $event ): void {
		$org = $event['data'] ?? [];
		if ( empty( $org['id'] ) ) {
			return;
		}

		self::upsert_organization( $org );
	}

	/**
	 * Handle organization.updated webhook.
	 *
	 * @param array $event Webhook event.
	 */
	public function handle_org_updated( array $event ): void {
		$org = $event['data'] ?? [];
		if ( empty( $org['id'] ) ) {
			return;
		}

		self::upsert_organization( $org );
	}

	/**
	 * Handle organization_membership.created webhook.
	 *
	 * @param array $event Webhook event.
	 */
	public function handle_membership_created( array $event ): void {
		$membership = $event['data'] ?? [];
		$workos_user_id = $membership['user_id'] ?? '';
		$workos_org_id  = $membership['organization_id'] ?? '';

		if ( empty( $workos_user_id ) || empty( $workos_org_id ) ) {
			return;
		}

		$wp_user_id = \WorkOS\Sync\UserSync::get_wp_user_id_by_workos_id( $workos_user_id );
		$local_org  = self::get_by_workos_id( $workos_org_id );

		if ( ! $wp_user_id || ! $local_org ) {
			return;
		}

		self::add_membership( $local_org->id, $wp_user_id, [
			'workos_membership_id' => $membership['id'] ?? '',
			'workos_role'          => $membership['role'] ?? 'member',
		] );
	}

	/**
	 * Handle organization_membership.deleted webhook.
	 *
	 * @param array $event Webhook event.
	 */
	public function handle_membership_deleted( array $event ): void {
		$membership = $event['data'] ?? [];
		$workos_user_id = $membership['user_id'] ?? '';
		$workos_org_id  = $membership['organization_id'] ?? '';

		if ( empty( $workos_user_id ) || empty( $workos_org_id ) ) {
			return;
		}

		$wp_user_id = \WorkOS\Sync\UserSync::get_wp_user_id_by_workos_id( $workos_user_id );
		$local_org  = self::get_by_workos_id( $workos_org_id );

		if ( ! $wp_user_id || ! $local_org ) {
			return;
		}

		self::remove_membership( $local_org->id, $wp_user_id );
	}

	// -------------------------------------------------------------------------
	// CRUD
	// -------------------------------------------------------------------------

	/**
	 * Create or update a local organization from WorkOS data.
	 *
	 * @param array $org_data WorkOS organization data.
	 *
	 * @return int|false Local org ID, or false on failure.
	 */
	public static function upsert_organization( array $org_data ) {
		global $wpdb;
		$table = $wpdb->prefix . 'workos_organizations';

		$workos_org_id = $org_data['id'] ?? '';
		$name          = $org_data['name'] ?? '';
		$slug          = sanitize_title( $name );
		$domains       = ! empty( $org_data['domains'] ) ? wp_json_encode( $org_data['domains'] ) : null;

		$existing = self::get_by_workos_id( $workos_org_id );

		if ( $existing ) {
			$wpdb->update(
				$table,
				[
					'name'       => $name,
					'slug'       => $slug,
					'domains'    => $domains,
					'updated_at' => current_time( 'mysql', true ),
				],
				[ 'workos_org_id' => $workos_org_id ],
				[ '%s', '%s', '%s', '%s' ],
				[ '%s' ]
			);
			return $existing->id;
		}

		$wpdb->insert(
			$table,
			[
				'workos_org_id' => $workos_org_id,
				'name'          => $name,
				'slug'          => $slug,
				'domains'       => $domains,
			],
			[ '%s', '%s', '%s', '%s' ]
		);

		$org_id = $wpdb->insert_id;

		// Auto-link to current site.
		if ( $org_id ) {
			self::link_to_site( $org_id, get_current_blog_id(), true );
		}

		return $org_id ?: false;
	}

	/**
	 * Get a local organization by its WorkOS ID.
	 *
	 * @param string $workos_org_id WorkOS organization ID.
	 *
	 * @return object|null Row object or null.
	 */
	public static function get_by_workos_id( string $workos_org_id ): ?object {
		global $wpdb;
		$table = $wpdb->prefix . 'workos_organizations';

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE workos_org_id = %s", $workos_org_id )
		);

		return $row ?: null;
	}

	/**
	 * Get a local organization by ID.
	 *
	 * @param int $id Local org ID.
	 *
	 * @return object|null
	 */
	public static function get( int $id ): ?object {
		global $wpdb;
		$table = $wpdb->prefix . 'workos_organizations';

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id )
		);

		return $row ?: null;
	}

	/**
	 * Get all organizations linked to a specific site.
	 *
	 * @param int $site_id WP site ID (default: current site).
	 *
	 * @return array List of organization objects.
	 */
	public static function get_for_site( int $site_id = 0 ): array {
		global $wpdb;

		if ( ! $site_id ) {
			$site_id = get_current_blog_id();
		}

		$org_table  = $wpdb->prefix . 'workos_organizations';
		$site_table = $wpdb->prefix . 'workos_org_sites';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT o.* FROM {$org_table} o
				INNER JOIN {$site_table} s ON o.id = s.org_id
				WHERE s.site_id = %d
				ORDER BY o.name",
				$site_id
			)
		);
	}

	// -------------------------------------------------------------------------
	// Memberships
	// -------------------------------------------------------------------------

	/**
	 * Add a user membership to an organization.
	 *
	 * @param int   $org_id     Local org ID.
	 * @param int   $user_id    WP user ID.
	 * @param array $extra      Extra fields (workos_membership_id, workos_role, wp_role).
	 */
	public static function add_membership( int $org_id, int $user_id, array $extra = [] ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'workos_org_memberships';

		// Check if already exists.
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE org_id = %d AND user_id = %d",
				$org_id,
				$user_id
			)
		);

		if ( $existing ) {
			$wpdb->update(
				$table,
				array_filter( [
					'workos_membership_id' => $extra['workos_membership_id'] ?? '',
					'workos_role'          => $extra['workos_role'] ?? 'member',
					'wp_role'              => $extra['wp_role'] ?? '',
				] ),
				[ 'id' => $existing ]
			);
			return;
		}

		$wpdb->insert(
			$table,
			[
				'org_id'               => $org_id,
				'user_id'              => $user_id,
				'workos_membership_id' => $extra['workos_membership_id'] ?? '',
				'workos_role'          => $extra['workos_role'] ?? 'member',
				'wp_role'              => $extra['wp_role'] ?? '',
			],
			[ '%d', '%d', '%s', '%s', '%s' ]
		);
	}

	/**
	 * Remove a user's membership from an organization.
	 *
	 * @param int $org_id  Local org ID.
	 * @param int $user_id WP user ID.
	 */
	public static function remove_membership( int $org_id, int $user_id ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'workos_org_memberships';

		$wpdb->delete( $table, [
			'org_id'  => $org_id,
			'user_id' => $user_id,
		], [ '%d', '%d' ] );
	}

	/**
	 * Get all organizations a user belongs to.
	 *
	 * @param int $user_id WP user ID.
	 *
	 * @return array List of organization objects with membership data.
	 */
	public static function get_user_orgs( int $user_id ): array {
		global $wpdb;
		$org_table  = $wpdb->prefix . 'workos_organizations';
		$mem_table  = $wpdb->prefix . 'workos_org_memberships';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT o.*, m.workos_role, m.wp_role, m.joined_at
				FROM {$org_table} o
				INNER JOIN {$mem_table} m ON o.id = m.org_id
				WHERE m.user_id = %d
				ORDER BY o.name",
				$user_id
			)
		);
	}

	// -------------------------------------------------------------------------
	// Site linking
	// -------------------------------------------------------------------------

	/**
	 * Link an organization to a WP site.
	 *
	 * @param int  $org_id     Local org ID.
	 * @param int  $site_id    WP site ID.
	 * @param bool $is_primary Whether this is the primary site for the org.
	 */
	public static function link_to_site( int $org_id, int $site_id, bool $is_primary = false ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'workos_org_sites';

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE org_id = %d AND site_id = %d",
				$org_id,
				$site_id
			)
		);

		if ( $existing ) {
			$wpdb->update(
				$table,
				[ 'is_primary' => (int) $is_primary ],
				[ 'id' => $existing ],
				[ '%d' ],
				[ '%d' ]
			);
			return;
		}

		$wpdb->insert(
			$table,
			[
				'org_id'     => $org_id,
				'site_id'    => $site_id,
				'is_primary' => (int) $is_primary,
			],
			[ '%d', '%d', '%d' ]
		);
	}
}
