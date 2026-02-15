<?php
/**
 * Plugin bootstrap.
 *
 * Loads the Composer autoloader and any required includes.
 *
 * @package WorkOS
 */

defined( 'ABSPATH' ) || exit;

// Load Composer autoloader.
$autoloader = WORKOS_DIR . 'vendor/autoload.php';

if ( ! file_exists( $autoloader ) ) {
	add_action( 'admin_notices', static function () {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'WorkOS Identity: Composer autoloader not found. Please run `composer install`.', 'workos' )
		);
	} );
	return false;
}

require_once $autoloader;
require_once WORKOS_DIR . 'src/includes/functions-helpers.php';

return true;
