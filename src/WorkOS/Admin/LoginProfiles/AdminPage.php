<?php
/**
 * Login Profiles admin page.
 *
 * @package WorkOS\Admin\LoginProfiles
 */

namespace WorkOS\Admin\LoginProfiles;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the WP-admin submenu that hosts the React Profile editor.
 *
 * The page itself is a shell: a wrapper div + enqueued JS/CSS. All CRUD
 * happens against /wp-json/workos/v1/admin/profiles from the React app.
 */
class AdminPage {

	public const MENU_SLUG     = 'workos-login-profiles';
	public const SCRIPT_HANDLE = 'workos-admin-profiles';
	public const STYLE_HANDLE  = 'workos-admin-profiles';

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
				'restUrl' => esc_url_raw( rest_url( 'workos/v1/admin/profiles' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			]
		);
	}
}
