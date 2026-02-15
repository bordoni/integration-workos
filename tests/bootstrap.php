<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package WorkOS
 */

// Load Composer autoloader.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Define test constants.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}

if ( ! defined( 'WORKOS_VERSION' ) ) {
	define( 'WORKOS_VERSION', '1.0.0-dev' );
}

if ( ! defined( 'WORKOS_FILE' ) ) {
	define( 'WORKOS_FILE', dirname( __DIR__ ) . '/workos.php' );
}

if ( ! defined( 'WORKOS_DIR' ) ) {
	define( 'WORKOS_DIR', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'WORKOS_URL' ) ) {
	define( 'WORKOS_URL', 'https://example.com/wp-content/plugins/workos/' );
}

if ( ! defined( 'WORKOS_BASENAME' ) ) {
	define( 'WORKOS_BASENAME', 'workos/workos.php' );
}

// Load the WP test suite if available (integration tests).
$wp_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';

if ( file_exists( $wp_tests_dir . '/includes/functions.php' ) ) {
	require_once $wp_tests_dir . '/includes/functions.php';

	tests_add_filter( 'muplugins_loaded', static function () {
		require WORKOS_FILE;
	} );

	require_once $wp_tests_dir . '/includes/bootstrap.php';
}
