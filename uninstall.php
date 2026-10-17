<?php
/**
 * Plugin uninstallation handler.
 *
 * Cleans up all plugin data when the plugin is deleted via WP Admin.
 *
 * @package WorkOS
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Remove options.
$workos_options = [
	// Current serialized option names.
	'workos_staging',
	'workos_production',
	'workos_global',
	'workos_active_environment',
	// Legacy individual option names.
	'workos_api_key',
	'workos_client_id',
	'workos_login_mode',
	'workos_allow_password_fallback',
	'workos_webhook_secret',
	'workos_deprovision_action',
	'workos_reassign_user',
	'workos_role_map',
	'workos_audit_logging_enabled',
	'workos_db_version',
	'workos_user_links_ready',
	'workos_role_sync_check',
];

foreach ( $workos_options as $workos_option ) {
	delete_option( $workos_option );
}

// Remove user meta.
global $wpdb;

$workos_meta_keys = [
	'_workos_user_id',
	'_workos_org_id',
	'_workos_last_synced_at',
	'_workos_profile_hash',
	'_workos_access_token',
	'_workos_refresh_token',
	'_workos_session_id',
	'_workos_deactivated',
	'_workos_updated_at',
	'_workos_membership_updated_at',
	'_workos_sync_error',
];

foreach ( $workos_meta_keys as $workos_key ) {
	$wpdb->delete( $wpdb->usermeta, [ 'meta_key' => $workos_key ], [ '%s' ] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}

// Drop custom tables.
require_once plugin_dir_path( __FILE__ ) . 'src/WorkOS/Database/Schema.php';
\WorkOS\Database\Schema::drop_tables();

// Cancel scheduled role sync checks (Action Scheduler, when another plugin provides it).
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'workos_role_sync_check' );
}

// Clear transients.
delete_transient( 'workos_jwks' );

// Flush rewrite rules.
flush_rewrite_rules();
