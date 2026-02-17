<?php
/**
 * Database schema management.
 *
 * @package WorkOS\Database
 */

namespace WorkOS\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Handles table creation and schema upgrades.
 */
class Schema {

	/**
	 * Option key for tracking the installed schema version.
	 */
	private const VERSION_OPTION = 'workos_db_version';

	/**
	 * Current schema version.
	 */
	private const CURRENT_VERSION = 3;

	/**
	 * Activation hook — create tables.
	 */
	public static function activate(): void {
		self::create_tables();
		self::migrate_to_v2();
		self::migrate_to_v3();
		update_option( self::VERSION_OPTION, self::CURRENT_VERSION );
		flush_rewrite_rules();
	}

	/**
	 * Check and upgrade schema if needed (runs on plugins_loaded).
	 */
	public static function maybe_upgrade(): void {
		$installed = (int) get_option( self::VERSION_OPTION, 0 );

		if ( $installed >= self::CURRENT_VERSION ) {
			return;
		}

		self::create_tables();

		if ( $installed < 2 ) {
			self::migrate_to_v2();
		}

		if ( $installed < 3 ) {
			self::migrate_to_v3();
		}

		update_option( self::VERSION_OPTION, self::CURRENT_VERSION );
	}

	/**
	 * Migrate single-option credentials to per-environment (production) options.
	 */
	private static function migrate_to_v2(): void {
		$settings = [ 'api_key', 'client_id', 'webhook_secret', 'organization_id', 'environment_id' ];

		foreach ( $settings as $setting ) {
			$old = get_option( "workos_{$setting}", '' );

			if ( '' !== $old ) {
				update_option( "workos_production_{$setting}", $old );
				delete_option( "workos_{$setting}" );
			}
		}

		if ( false === get_option( 'workos_active_environment' ) ) {
			update_option( 'workos_active_environment', 'production' );
		}
	}

	/**
	 * Consolidate individual option rows into serialized arrays.
	 */
	private static function migrate_to_v3(): void {
		// Consolidate per-env credential options.
		$env_settings = [ 'api_key', 'client_id', 'webhook_secret', 'organization_id', 'environment_id' ];

		foreach ( [ 'production', 'staging' ] as $env ) {
			$consolidated = [];
			foreach ( $env_settings as $setting ) {
				$value = get_option( "workos_{$env}_{$setting}", '' );
				if ( '' !== $value ) {
					$consolidated[ $setting ] = $value;
				}
			}
			if ( ! empty( $consolidated ) ) {
				update_option( "workos_{$env}", $consolidated );
			}
			foreach ( $env_settings as $setting ) {
				delete_option( "workos_{$env}_{$setting}" );
			}
		}

		// Consolidate global options.
		$global_map = [
			'login_mode'              => [ 'workos_login_mode', '' ],
			'allow_password_fallback' => [ 'workos_allow_password_fallback', true ],
			'deprovision_action'      => [ 'workos_deprovision_action', 'deactivate' ],
			'reassign_user'           => [ 'workos_reassign_user', 0 ],
			'role_map'                => [ 'workos_role_map', [] ],
			'audit_logging_enabled'   => [ 'workos_audit_logging_enabled', false ],
		];

		$global = [];
		foreach ( $global_map as $key => $old ) {
			$value = get_option( $old[0], $old[1] );
			$global[ $key ] = $value;
			delete_option( $old[0] );
		}
		update_option( 'workos_global', $global );
	}

	/**
	 * Create or update all custom tables via dbDelta.
	 */
	private static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$wpdb->prefix}workos_organizations (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			workos_org_id varchar(191) NOT NULL,
			name varchar(255) NOT NULL,
			slug varchar(191) NOT NULL,
			domains text,
			settings text,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY idx_workos_org_id (workos_org_id),
			UNIQUE KEY idx_slug (slug)
		) {$charset_collate};

		CREATE TABLE {$wpdb->prefix}workos_org_memberships (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			org_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			workos_membership_id varchar(191) DEFAULT '',
			workos_role varchar(191) DEFAULT 'member',
			wp_role varchar(191) DEFAULT '',
			joined_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY idx_org_user (org_id,user_id),
			KEY idx_user_id (user_id)
		) {$charset_collate};

		CREATE TABLE {$wpdb->prefix}workos_org_sites (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			org_id bigint(20) unsigned NOT NULL,
			site_id bigint(20) unsigned NOT NULL DEFAULT 1,
			is_primary tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY idx_org_site (org_id,site_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Drop all custom tables (for uninstall).
	 */
	public static function drop_tables(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}workos_org_sites" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}workos_org_memberships" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}workos_organizations" );
		// phpcs:enable
	}
}
