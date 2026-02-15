<?php
/**
 * Plugin Name: WorkOS Identity
 * Plugin URI:  https://software.liquidweb.com
 * Description: Enterprise identity management for WordPress powered by WorkOS. SSO, directory sync, MFA, and user management.
 * Version:     1.0.0-dev
 * Author:      LiquidWeb Software
 * Author URI:  https://software.liquidweb.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: workos
 * Requires PHP: 7.4
 * Requires at least: 5.9
 *
 * @package WorkOS
 */

namespace WorkOS;

defined( 'ABSPATH' ) || exit;

// Plugin constants.
define( 'WORKOS_VERSION', '1.0.0-dev' );
define( 'WORKOS_FILE', __FILE__ );
define( 'WORKOS_DIR', plugin_dir_path( __FILE__ ) );
define( 'WORKOS_URL', plugin_dir_url( __FILE__ ) );
define( 'WORKOS_BASENAME', plugin_basename( __FILE__ ) );

// Minimum requirements check.
if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'WorkOS requires PHP 7.4 or later.', 'workos' )
			);
		}
	);
	return;
}

// Load bootstrap (Composer autoloader).
$workos_bootstrap = require_once WORKOS_DIR . 'src/includes/bootstrap.php';
if ( false === $workos_bootstrap ) {
	return;
}

// Activation / deactivation hooks.
register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate' );

/**
 * Initialize the plugin on plugins_loaded.
 */
function init(): void {
	Plugin::instance();
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\init' );

/**
 * Plugin activation callback.
 */
function activate(): void {
	Database\Schema::activate();
}

/**
 * Plugin deactivation callback.
 */
function deactivate(): void {
	flush_rewrite_rules();
}
