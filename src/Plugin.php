<?php
/**
 * Main plugin orchestrator.
 *
 * @package WorkOS
 */

namespace WorkOS;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin singleton that wires up all subsystems.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * API client instance.
	 *
	 * @var Api\Client|null
	 */
	private ?Api\Client $api = null;

	/**
	 * Get or create the singleton.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — hooks everything.
	 */
	private function __construct() {
		// Activation / deactivation.
		register_activation_hook( WORKOS_FILE, [ Database\Schema::class, 'activate' ] );
		register_deactivation_hook( WORKOS_FILE, [ $this, 'deactivate' ] );

		// Core subsystems.
		add_action( 'plugins_loaded', [ $this, 'boot' ] );
	}

	/**
	 * Boot all subsystems after plugins_loaded.
	 */
	public function boot(): void {
		// Ensure schema is current.
		Database\Schema::maybe_upgrade();

		// Settings / Admin.
		if ( is_admin() ) {
			new Admin\Settings();
		}

		// Authentication.
		new Auth\Login();
		new Auth\Registration();
		new Auth\PasswordReset();

		// REST API.
		new REST\TokenAuth();
		new Webhook\Receiver();

		// User sync.
		new Sync\UserSync();
		new Sync\RoleMapper();

		// Organizations.
		new Organization\Manager();

		// Directory sync.
		new Sync\DirectorySync();

		// Audit logging.
		new Sync\AuditLog();
	}

	/**
	 * Deactivation callback.
	 */
	public function deactivate(): void {
		flush_rewrite_rules();
	}

	/**
	 * Get the API client.
	 *
	 * @return Api\Client
	 */
	public function api(): Api\Client {
		if ( null === $this->api ) {
			$this->api = new Api\Client(
				(string) get_option( 'workos_api_key', '' ),
				(string) get_option( 'workos_client_id', '' )
			);
		}
		return $this->api;
	}

	/**
	 * Check if the plugin is configured and enabled.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return ! empty( get_option( 'workos_api_key' ) )
			&& ! empty( get_option( 'workos_client_id' ) );
	}

	/**
	 * Get a plugin option with a default.
	 *
	 * @param string $key     Option key (without workos_ prefix).
	 * @param mixed  $default Default value.
	 *
	 * @return mixed
	 */
	public function option( string $key, $default = '' ) {
		return get_option( "workos_{$key}", $default );
	}
}
