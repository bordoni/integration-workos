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

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/src/WorkOS/Bootstrap.php';

add_action( 'plugins_loaded', WorkOS\Bootstrap::set_plugin_file( __FILE__ ) );
