<?php
/**
 * Main plugin orchestrator.
 *
 * Loaded directly by workos.php before the Composer autoloader
 * is available, so this file must NOT rely on autoloading.
 *
 * @package WorkOS
 */

namespace WorkOS;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class — bootstraps the DI container and boots all subsystems.
 */
class Plugin {

	/**
	 * Plugin instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Plugin file path.
	 *
	 * @var string
	 */
	private string $file;

	/**
	 * Plugin directory path.
	 *
	 * @var string
	 */
	private string $dir;

	/**
	 * Plugin URL.
	 *
	 * @var string
	 */
	private string $url;

	/**
	 * Plugin basename.
	 *
	 * @var string
	 */
	private string $basename;

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	private string $version = '1.0.0-dev';

	/**
	 * Container instance.
	 *
	 * @var Contracts\Container|null
	 */
	private ?Contracts\Container $container = null;

	/**
	 * Constructor.
	 */
	protected function __construct() {
	}

	/**
	 * Bootstrap the plugin.
	 *
	 * Called at file load time to store the plugin file path,
	 * register lifecycle hooks, and return the callable for `plugins_loaded`.
	 *
	 * @param string $file Absolute path to the main plugin file.
	 *
	 * @return callable
	 */
	public static function bootstrap( string $file ): callable {
		$plugin       = new self();
		$plugin->file = $file;

		register_activation_hook( $file, [ static::class, 'activate' ] );
		register_deactivation_hook( $file, [ static::class, 'deactivate' ] );

		return [ $plugin, 'init' ];
	}

	/**
	 * Get the plugin instance.
	 *
	 * @return self|null
	 */
	public static function instance(): ?self {
		return self::$instance;
	}

	/**
	 * Initialize the plugin.
	 *
	 * Fired on `plugins_loaded`.
	 *
	 * @return void
	 */
	public function init(): void {
		// Prevent multiple initializations.
		if ( null !== self::$instance ) {
			return;
		}

		self::$instance = $this;

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

		// Set plugin paths.
		$this->dir      = plugin_dir_path( $this->file );
		$this->url      = plugin_dir_url( $this->file );
		$this->basename = plugin_basename( $this->file );

		// Plugin constants.
		define( 'WORKOS_VERSION', $this->version );
		define( 'WORKOS_FILE', $this->file );
		define( 'WORKOS_DIR', $this->dir );
		define( 'WORKOS_URL', $this->url );
		define( 'WORKOS_BASENAME', $this->basename );

		// Load Composer autoloader.
		if ( ! $this->loadAutoloader() ) {
			return;
		}

		// Load vendor-prefixed dependencies.
		$this->loadPrefixedAutoloader();

		// Global helper functions.
		require_once $this->dir . 'src/includes/functions-helpers.php';

		// Load text domain.
		add_action( 'init', [ $this, 'load_textdomain' ] );

		// Initialize container.
		$this->initializeContainer();

		// Bootstrap application.
		$this->bootstrapApp();
	}

	/**
	 * Plugin activation callback.
	 *
	 * @return void
	 */
	public static function activate(): void {
		$dir        = plugin_dir_path( __DIR__ . '/../../workos.php' );
		$autoloader = $dir . 'vendor/autoload.php';

		if ( file_exists( $autoloader ) ) {
			require_once $autoloader;
		}

		Database\Schema::activate();
	}

	/**
	 * Plugin deactivation callback.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	/**
	 * Get the API client from the container.
	 *
	 * @return Api\Client
	 */
	public function api(): Api\Client {
		return $this->container->get( Api\Client::class );
	}

	/**
	 * Check if the plugin is configured and enabled.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return ! empty( Config::get_api_key() )
			&& ! empty( Config::get_client_id() )
			&& ! empty( Config::get_environment_id() );
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
		$env   = Config::get_active_environment();
		$class = 'staging' === $env ? Options\Staging::class : Options\Production::class;

		return $this->container->get( $class )->get( $key, $default_value );
	}

	/**
	 * Load the plugin text domain for translations.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'workos', false, dirname( $this->basename ) . '/languages' );
	}

	/**
	 * Get the plugin file path.
	 *
	 * @return string
	 */
	public function getFile(): string {
		return $this->file;
	}

	/**
	 * Get the plugin directory path.
	 *
	 * @return string
	 */
	public function getDir(): string {
		return $this->dir;
	}

	/**
	 * Get the plugin URL.
	 *
	 * @return string
	 */
	public function getUrl(): string {
		return $this->url;
	}

	/**
	 * Get the plugin basename.
	 *
	 * @return string
	 */
	public function getBasename(): string {
		return $this->basename;
	}

	/**
	 * Get the plugin version.
	 *
	 * @return string
	 */
	public function getVersion(): string {
		return $this->version;
	}

	/**
	 * Get the container instance.
	 *
	 * @return Contracts\Container|null
	 */
	public function getContainer(): ?Contracts\Container {
		return $this->container;
	}

	/**
	 * Load the Composer autoloader.
	 *
	 * @return bool True if autoloader was loaded successfully.
	 */
	private function loadAutoloader(): bool {
		$autoloader = $this->dir . 'vendor/autoload.php';

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

	/**
	 * Load the vendor-prefixed autoloader.
	 *
	 * @return void
	 */
	private function loadPrefixedAutoloader(): void {
		$prefixed = $this->dir . 'vendor/prefixed/autoload.php';

		if ( file_exists( $prefixed ) ) {
			require_once $prefixed;
		}
	}

	/**
	 * Initialize the DI container.
	 *
	 * @return void
	 */
	private function initializeContainer(): void {
		$this->container = new Contracts\Container();

		// Bind the plugin instance.
		$this->container->singleton( self::class, $this );

		// Register Api\Client as a lazy singleton.
		$this->container->singleton(
			Api\Client::class,
			static function () {
				return new Api\Client(
					Config::get_api_key(),
					Config::get_client_id()
				);
			}
		);

		// Register Options singletons.
		$this->container->singleton( Options\Production::class );
		$this->container->singleton( Options\Staging::class );
		$this->container->singleton( Options\Global_Options::class );

		// Set the container globally via the App facade.
		App::setContainer( $this->container );
	}

	/**
	 * Bootstrap the application.
	 *
	 * @return void
	 */
	private function bootstrapApp(): void {
		// Ensure schema is current.
		Database\Schema::maybe_upgrade();

		// Register the main controller.
		$this->container->register( Controller::class );
	}
}
