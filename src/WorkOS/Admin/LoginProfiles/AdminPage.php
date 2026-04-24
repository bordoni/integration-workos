<?php
/**
 * Login Profiles admin page.
 *
 * @package WorkOS\Admin\LoginProfiles
 */

namespace WorkOS\Admin\LoginProfiles;

use WorkOS\Auth\AuthKit\Profile;
use WorkOS\Auth\AuthKit\ProfileRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the WP-admin submenu that hosts the React Profile editor.
 *
 * Pre-renders the profile list as JSON via wp_localize_script so the
 * editor hydrates without a REST round-trip on first paint, and parses
 * `?profile=<slug>` from the request URL to deep-link into a profile.
 * All CRUD still happens against /wp-json/workos/v1/admin/profiles.
 */
class AdminPage {

	public const MENU_SLUG     = 'workos-login-profiles';
	public const SCRIPT_HANDLE = 'workos-admin-profiles';
	public const STYLE_HANDLE  = 'workos-admin-profiles';

	/**
	 * Profile repository used to preload the editor.
	 *
	 * @var ProfileRepository
	 */
	private ProfileRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param ProfileRepository $repository Repository for the SSR preload.
	 */
	public function __construct( ProfileRepository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'maybe_enqueue_assets' ] );
	}

	/**
	 * Register the submenu under the main WorkOS settings menu.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_submenu_page(
			'workos',
			__( 'Login Profiles', 'integration-workos' ),
			__( 'Login Profiles', 'integration-workos' ),
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'render' ]
		);
	}

	/**
	 * Render the React mount shell.
	 *
	 * @return void
	 */
	public function render(): void {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Login Profiles', 'integration-workos' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Configure which sign-in methods each Login Profile offers. Each profile can scope to a specific organization and tune branding, signup, and MFA policy.', 'integration-workos' ); ?>
			</p>
			<div id="workos-profiles-admin-root"></div>
		</div>
		<?php
	}

	/**
	 * Enqueue the React admin bundle on our admin page only.
	 *
	 * @param string $hook Current admin page hook.
	 *
	 * @return void
	 */
	public function maybe_enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, self::MENU_SLUG ) ) {
			return;
		}

		// LogoField in admin-profiles uses wp.media() for attachment selection.
		wp_enqueue_media();

		$assets_url = trailingslashit( WORKOS_URL . 'build' );
		$assets_dir = trailingslashit( WORKOS_DIR . 'build' );

		$asset_file = $assets_dir . 'admin-profiles.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: [
				'dependencies' => [ 'wp-element' ],
				'version'      => WORKOS_VERSION,
			];

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			$assets_url . 'admin-profiles.js',
			$asset['dependencies'] ?? [ 'wp-element' ],
			$asset['version'] ?? WORKOS_VERSION,
			true
		);

		wp_set_script_translations( self::SCRIPT_HANDLE, 'integration-workos' );

		wp_enqueue_style(
			self::STYLE_HANDLE,
			$assets_url . 'admin-profiles.css',
			[],
			$asset['version'] ?? WORKOS_VERSION
		);

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'workosProfileAdmin',
			[
				'restUrl'           => esc_url_raw( rest_url( 'workos/v1/admin/profiles' ) ),
				'nonce'             => wp_create_nonce( 'wp_rest' ),
				'pageUrl'           => esc_url_raw( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ),
				'profiles'          => $this->preloaded_profiles(),
				'activeProfileSlug' => $this->active_profile_slug(),
			]
		);
	}

	/**
	 * SSR preload — every profile shaped exactly like the REST list endpoint.
	 *
	 * @return array
	 */
	private function preloaded_profiles(): array {
		$profiles = [];
		foreach ( $this->repository->all() as $profile ) {
			$profiles[] = $profile->to_editor_array();
		}
		return $profiles;
	}

	/**
	 * Resolve the deep-link profile slug from the current request.
	 *
	 * Special value `'new'` opens an empty editor. Anything else is treated
	 * as a profile slug; the React app validates membership against the
	 * preloaded list and falls back to the index view if unknown.
	 *
	 * @return string Slug or empty string when not present.
	 */
	private function active_profile_slug(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['profile'] ) ) {
			return '';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return sanitize_title( wp_unslash( (string) $_GET['profile'] ) );
	}
}
