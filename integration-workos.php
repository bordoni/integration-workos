<?php
/**
 * Plugin Name: Integration with WorkOS
 * Plugin URI:  https://github.com/bordoni/integration-workos
 * Description: Enterprise identity management for WordPress powered by WorkOS. SSO, directory sync, MFA, and user management.
 * Version:     1.0.0-dev
 * Author:      Gustavo Bordoni
 * Author URI:  https://github.com/bordoni
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: integration-workos
 * Requires PHP: 7.4
 * Requires at least: 5.9
 *
 * @package WorkOS
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/src/WorkOS/Plugin.php';

add_action( 'plugins_loaded', WorkOS\Plugin::bootstrap( __FILE__ ) );
