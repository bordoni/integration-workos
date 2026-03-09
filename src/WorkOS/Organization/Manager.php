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
		add_action( 'workos_user_authenticated', [ self::class, 'ensure_user_org_membership' ], 10, 2 );
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
		$membership     = $event['data'] ?? [];
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

		self::add_membership(
			$local_org->id,
			$wp_user_id,
			[
				'workos_membership_id' => $membership['id'] ?? '',
				'workos_role'          => $membership['role'] ?? 'member',
			]
		);
	}

	/**
	 * Handle organization_membership.deleted webhook.
	 *
	 * @param array $event Webhook event.
	 */
	public function handle_membership_deleted( array $event ): void {
		$membership     = $event['data'] ?? [];
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
	// Login membership sync
	// -------------------------------------------------------------------------

	/**
	 * Ensure the authenticated user has a local org membership for the configured organization.
	 *
	 * Hooked to `workos_user_authenticated` so it fires on both redirect and headless login.
	 *
	 * @param int   $wp_user_id  WordPress user ID.
	 * @param array $auth_result Full WorkOS auth response.
	 */
	public static function ensure_user_org_membership( int $wp_user_id, array $auth_result ): void {
		$workos_org_id = \WorkOS\Config::get_organization_id();
		if ( empty( $workos_org_id ) ) {
			return;
		}

		$local_org = self::get_by_workos_id( $workos_org_id );

		// If the local org doesn't exist yet, fetch from WorkOS API and upsert.
		if ( ! $local_org ) {
			$org_data = workos()->api()->get_organization( $workos_org_id );
			if ( ! is_wp_error( $org_data ) && ! empty( $org_data['id'] ) ) {
				self::upsert_organization( $org_data );
				$local_org = self::get_by_workos_id( $workos_org_id );
			}
		}

		if ( ! $local_org ) {
			return;
		}

		// Extract role and membership ID from auth result when available.
		$membership    = $auth_result['organization_membership'] ?? [];
		$workos_role   = $membership['role'] ?? $auth_result['role'] ?? 'member';
		$membership_id = $membership['id'] ?? '';

		$extra = [
			'workos_role' => $workos_role,
		];

		if ( ! empty( $membership_id ) ) {
			$extra['workos_membership_id'] = $membership_id;
		}

		self::add_membership(
			(int) $local_org->id,
			$wp_user_id,
			$extra
		);
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
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
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

			// Invalidate caches.
			wp_cache_delete( "org_workos_{$workos_org_id}", 'workos' );
			wp_cache_delete( "org_{$existing->id}", 'workos' );

			return $existing->id;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			[
				'workos_org_id' => $workos_org_id,
				'name'          => $name,
				'slug'          => $slug,
				'domains'       => $domains,
				'created_at'    => current_time( 'mysql', true ),
				'updated_at'    => current_time( 'mysql', true ),
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		$org_id = $wpdb->insert_id;

		// Auto-link to current site.
		if ( $org_id ) {
			self::link_to_site( $org_id, get_current_blog_id(), true );
		}

		// Invalidate caches.
		wp_cache_delete( "org_workos_{$workos_org_id}", 'workos' );

		return $org_id ? $org_id : false;
	}

	/**
	 * Get a local organization by its WorkOS ID.
	 *
	 * @param string $workos_org_id WorkOS organization ID.
	 *
	 * @return object|null Row object or null.
	 */
	public static function get_by_workos_id( string $workos_org_id ): ?object {
		$cache_key = "org_workos_{$workos_org_id}";
		$cached    = wp_cache_get( $cache_key, 'workos' );
		if ( false !== $cached ) {
			return $cached ? $cached : null;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'workos_organizations';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT * FROM {$table} WHERE workos_org_id = %s", $workos_org_id )
		);

		wp_cache_set( $cache_key, $row ? $row : 0, 'workos' );

		return $row ? $row : null;
	}

	/**
	 * Get a local organization by ID.
	 *
	 * @param int $id Local org ID.
	 *
	 * @return object|null
	 */
	public static function get( int $id ): ?object {
		$cache_key = "org_{$id}";
		$cached    = wp_cache_get( $cache_key, 'workos' );
		if ( false !== $cached ) {
			return $cached ? $cached : null;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'workos_organizations';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id )
		);

		wp_cache_set( $cache_key, $row ? $row : 0, 'workos' );

		return $row ? $row : null;
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

		$cache_key = "org_site_{$site_id}";
		$cached    = wp_cache_get( $cache_key, 'workos' );
		if ( false !== $cached ) {
			return $cached;
		}

		$org_table  = $wpdb->prefix . 'workos_organizations';
		$site_table = $wpdb->prefix . 'workos_org_sites';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT o.* FROM {$org_table} o
				INNER JOIN {$site_table} s ON o.id = s.org_id
				WHERE s.site_id = %d
				ORDER BY o.name",
				$site_id
			)
		);

		wp_cache_set( $cache_key, $result, 'workos' );

		return $result;
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE org_id = %d AND user_id = %d",
				$org_id,
				$user_id
			)
		);

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->update(
				$table,
				array_filter(
					[
						'workos_membership_id' => $extra['workos_membership_id'] ?? '',
						'workos_role'          => $extra['workos_role'] ?? 'member',
						'wp_role'              => $extra['wp_role'] ?? '',
					]
				),
				[ 'id' => $existing ]
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$table,
				[
					'org_id'               => $org_id,
					'user_id'              => $user_id,
					'workos_membership_id' => $extra['workos_membership_id'] ?? '',
					'workos_role'          => $extra['workos_role'] ?? 'member',
					'wp_role'              => $extra['wp_role'] ?? '',
					'joined_at'            => current_time( 'mysql', true ),
				],
				[ '%d', '%d', '%s', '%s', '%s', '%s' ]
			);
		}

		// Invalidate caches.
		wp_cache_delete( "user_orgs_{$user_id}", 'workos' );
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->delete(
			$table,
			[
				'org_id'  => $org_id,
				'user_id' => $user_id,
			],
			[ '%d', '%d' ]
		);

		// Invalidate caches.
		wp_cache_delete( "user_orgs_{$user_id}", 'workos' );
	}

	/**
	 * Get all organizations a user belongs to.
	 *
	 * @param int $user_id WP user ID.
	 *
	 * @return array List of organization objects with membership data.
	 */
	public static function get_user_orgs( int $user_id ): array {
		$cache_key = "user_orgs_{$user_id}";
		$cached    = wp_cache_get( $cache_key, 'workos' );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$org_table = $wpdb->prefix . 'workos_organizations';
		$mem_table = $wpdb->prefix . 'workos_org_memberships';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT o.*, m.workos_role, m.wp_role, m.joined_at
				FROM {$org_table} o
				INNER JOIN {$mem_table} m ON o.id = m.org_id
				WHERE m.user_id = %d
				ORDER BY o.name",
				$user_id
			)
		);

		wp_cache_set( $cache_key, $result, 'workos' );

		return $result;
	}

	/**
	 * Get the WorkOS membership ID for a user in a given WorkOS organization.
	 *
	 * @param int    $wp_user_id   WordPress user ID.
	 * @param string $workos_org_id WorkOS organization ID.
	 *
	 * @return string WorkOS membership ID, or empty string if not found.
	 */
	public static function get_membership_id_for_user( int $wp_user_id, string $workos_org_id ): string {
		$cache_key = "membership_{$wp_user_id}_{$workos_org_id}";
		$cached    = wp_cache_get( $cache_key, 'workos' );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$org_table = $wpdb->prefix . 'workos_organizations';
		$mem_table = $wpdb->prefix . 'workos_org_memberships';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$membership_id = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT m.workos_membership_id FROM {$mem_table} m
				INNER JOIN {$org_table} o ON m.org_id = o.id
				WHERE m.user_id = %d AND o.workos_org_id = %s",
				$wp_user_id,
				$workos_org_id
			)
		);

		$result = $membership_id ? $membership_id : '';
		wp_cache_set( $cache_key, $result, 'workos' );

		return $result;
	}

	/**
	 * Store a WorkOS membership ID on an existing local membership record.
	 *
	 * @param int    $wp_user_id    WordPress user ID.
	 * @param string $workos_org_id WorkOS organization ID.
	 * @param string $membership_id WorkOS membership ID.
	 */
	public static function store_membership_id( int $wp_user_id, string $workos_org_id, string $membership_id ): void {
		global $wpdb;
		$mem_table = $wpdb->prefix . 'workos_org_memberships';

		$local_org = self::get_by_workos_id( $workos_org_id );
		if ( ! $local_org ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			$mem_table,
			[ 'workos_membership_id' => $membership_id ],
			[
				'org_id'  => $local_org->id,
				'user_id' => $wp_user_id,
			],
			[ '%s' ],
			[ '%d', '%d' ]
		);

		// Invalidate caches.
		wp_cache_delete( "membership_{$wp_user_id}_{$workos_org_id}", 'workos' );
	}

	/**
	 * Update both workos_role and wp_role on a local membership record.
	 *
	 * @param int    $wp_user_id    WordPress user ID.
	 * @param string $workos_org_id WorkOS organization ID.
	 * @param string $workos_role   WorkOS role slug.
	 * @param string $wp_role       WordPress role slug.
	 */
	public static function update_membership_role( int $wp_user_id, string $workos_org_id, string $workos_role, string $wp_role ): void {
		global $wpdb;
		$mem_table = $wpdb->prefix . 'workos_org_memberships';

		$local_org = self::get_by_workos_id( $workos_org_id );
		if ( ! $local_org ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			$mem_table,
			[
				'workos_role' => $workos_role,
				'wp_role'     => $wp_role,
			],
			[
				'org_id'  => $local_org->id,
				'user_id' => $wp_user_id,
			],
			[ '%s', '%s' ],
			[ '%d', '%d' ]
		);

		// Invalidate caches.
		wp_cache_delete( "user_orgs_{$wp_user_id}", 'workos' );
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE org_id = %d AND site_id = %d",
				$org_id,
				$site_id
			)
		);

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->update(
				$table,
				[ 'is_primary' => (int) $is_primary ],
				[ 'id' => $existing ],
				[ '%d' ],
				[ '%d' ]
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
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

		// Invalidate caches.
		wp_cache_delete( "org_site_{$site_id}", 'workos' );
	}
}
