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
$options = [
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
];

foreach ( $options as $option ) {
	delete_option( $option );
}

// Remove user meta.
global $wpdb;

$meta_keys = [
	'_workos_user_id',
	'_workos_org_id',
	'_workos_last_synced_at',
	'_workos_profile_hash',
	'_workos_access_token',
	'_workos_refresh_token',
	'_workos_deactivated',
];

foreach ( $meta_keys as $key ) {
	$wpdb->delete( $wpdb->usermeta, [ 'meta_key' => $key ], [ '%s' ] );
}

// Drop custom tables.
require_once plugin_dir_path( __FILE__ ) . 'src/Database/Schema.php';
\WorkOS\Database\Schema::drop_tables();

// Clear transients.
delete_transient( 'workos_jwks' );

// Flush rewrite rules.
flush_rewrite_rules();
