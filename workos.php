<?php
/**
 * Plugin Name: WorkOS Identity
 * Plugin URI:  https://workos.com
 * Description: Enterprise identity management for WordPress powered by WorkOS. SSO, directory sync, MFA, and user management.
 * Version:     1.0.0-dev
 * Author:      WorkOS
 * Author URI:  https://workos.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: workos
 * Requires PHP: 7.4
 * Requires at least: 5.9
 *
 * @package WorkOS
 */

defined( 'ABSPATH' ) || exit;

// Plugin constants.
define( 'WORKOS_VERSION', '1.0.0-dev' );
define( 'WORKOS_FILE', __FILE__ );
define( 'WORKOS_DIR', plugin_dir_path( __FILE__ ) );
define( 'WORKOS_URL', plugin_dir_url( __FILE__ ) );
define( 'WORKOS_BASENAME', plugin_basename( __FILE__ ) );

// Minimum requirements check.
if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	add_action( 'admin_notices', static function () {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'WorkOS requires PHP 7.4 or later.', 'workos' )
		);
	} );
	return;
}

// Autoloader.
spl_autoload_register( static function ( string $class ) {
	$prefix = 'WorkOS\\';
	if ( strpos( $class, $prefix ) !== 0 ) {
		return;
	}

	$relative = substr( $class, strlen( $prefix ) );
	$file     = WORKOS_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

// Boot the plugin.
require_once WORKOS_DIR . 'src/Plugin.php';

/**
 * Get the plugin singleton instance.
 *
 * @return \WorkOS\Plugin
 */
function workos(): \WorkOS\Plugin {
	return \WorkOS\Plugin::instance();
}

// Initialize.
workos();
