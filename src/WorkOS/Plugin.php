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
	 * Constructor — initializes hooks and components.
	 */
	private function __construct() {
		$this->init_hooks();
		$this->init_components();
	}

	/**
	 * Register core WordPress hooks.
	 */
	private function init_hooks(): void {
		add_action( 'init', [ $this, 'load_textdomain' ] );
	}

	/**
	 * Boot all subsystems.
	 */
	private function init_components(): void {
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
	 * Load the plugin text domain for translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'workos', false, dirname( WORKOS_BASENAME ) . '/languages' );
	}

	/**
	 * Get the API client.
	 *
	 * @return Api\Client
	 */
	public function api(): Api\Client {
		if ( null === $this->api ) {
			$this->api = new Api\Client(
				Config::get_api_key(),
				Config::get_client_id()
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
		return ! empty( Config::get_api_key() )
			&& ! empty( Config::get_client_id() );
	}

	/**
	 * Get a plugin option with a default.
	 *
	 * @param string $key           Option key (without workos_ prefix).
	 * @param mixed  $default_value Default value.
	 *
	 * @return mixed
	 */
	public function option( string $key, $default_value = '' ) {
		return get_option( "workos_{$key}", $default_value );
	}
}
