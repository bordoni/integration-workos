<?php
/**
 * Plugin bootstrap.
 *
 * Loaded directly by workos.php before the Composer autoloader
 * is available, so this file must NOT rely on autoloading.
 *
 * @package WorkOS
 */

namespace WorkOS;

defined( 'ABSPATH' ) || exit;

/**
 * Handles plugin initialization, activation, and deactivation.
 */
class Bootstrap {

	/**
	 * Absolute path to the main plugin file.
	 *
	 * @var string
	 */
	private static string $plugin_file = '';

	/**
	 * Store the plugin file path, register lifecycle hooks,
	 * and return the callable for `plugins_loaded`.
	 *
	 * @param string $plugin_file Absolute path to the main plugin file.
	 *
	 * @return callable
	 */
	public static function set_plugin_file( string $plugin_file ): callable {
		self::$plugin_file = $plugin_file;

		register_activation_hook( $plugin_file, [ static::class, 'activate' ] );
		register_deactivation_hook( $plugin_file, [ static::class, 'deactivate' ] );

		return [ static::class, 'load_plugin' ];
	}

	/**
	 * Fired on `plugins_loaded` — defines constants, loads Composer, boots Plugin.
	 */
	public static function load_plugin(): void {
		// PHP version gate.
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

		// Plugin constants.
		define( 'WORKOS_VERSION', '1.0.0-dev' );
		define( 'WORKOS_FILE', self::$plugin_file );
		define( 'WORKOS_DIR', plugin_dir_path( self::$plugin_file ) );
		define( 'WORKOS_URL', plugin_dir_url( self::$plugin_file ) );
		define( 'WORKOS_BASENAME', plugin_basename( self::$plugin_file ) );

		// Load Composer autoloader.
		if ( ! self::load_autoloader() ) {
			return;
		}

		// Global helper functions.
		require_once WORKOS_DIR . 'src/includes/functions-helpers.php';

		// Boot the plugin.
		Plugin::instance();
	}

	/**
	 * Plugin activation callback.
	 */
	public static function activate(): void {
		self::load_autoloader();
		Database\Schema::activate();
	}

	/**
	 * Plugin deactivation callback.
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	/**
	 * Get the main plugin file path.
	 *
	 * @return string
	 */
	public static function get_plugin_file(): string {
		return self::$plugin_file;
	}

	/**
	 * Load the Composer autoloader.
	 *
	 * @return bool True if autoloader was loaded successfully.
	 */
	private static function load_autoloader(): bool {
		$autoloader = plugin_dir_path( self::$plugin_file ) . 'vendor/autoload.php';

		if ( ! file_exists( $autoloader ) ) {
			add_action(
				'admin_notices',
				static function () {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html__( 'WorkOS Identity: Composer autoloader not found. Please run `composer install`.', 'workos' )
					);
				}
			);
			return false;
		}

		require_once $autoloader;

		return true;
	}
}
