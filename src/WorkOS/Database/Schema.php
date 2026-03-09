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
	private const CURRENT_VERSION = 2;

	/**
	 * Activation hook — create tables.
	 */
	public static function activate(): void {
		self::create_tables();
		update_option( self::VERSION_OPTION, self::CURRENT_VERSION );
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

		update_option( self::VERSION_OPTION, self::CURRENT_VERSION );
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
		) {$charset_collate};

		CREATE TABLE {$wpdb->prefix}workos_activity_log (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_type varchar(50) NOT NULL,
			user_id bigint(20) unsigned DEFAULT 0,
			user_email varchar(191) DEFAULT '',
			workos_user_id varchar(191) DEFAULT '',
			ip_address varchar(45) DEFAULT '',
			user_agent text,
			metadata longtext,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY idx_event_type (event_type),
			KEY idx_user_id (user_id),
			KEY idx_created_at (created_at)
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
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}workos_activity_log" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}workos_org_sites" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}workos_org_memberships" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}workos_organizations" );
		// phpcs:enable
	}
}
