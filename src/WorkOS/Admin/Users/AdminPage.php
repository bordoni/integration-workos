<?php
/**
 * WorkOS Users admin page.
 *
 * @package WorkOS\Admin\Users
 */

namespace WorkOS\Admin\Users;

use WorkOS\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Submenu under "WorkOS" that mounts the React Users list.
 *
 * Read-only triage UI: paginated list of WorkOS users with search and a
 * per-row "Open in WorkOS" deep-link that takes admins straight to the
 * user's Dashboard page (where the "Re-enable email" action lives — there
 * is no public REST endpoint for it as of this release).
 */
class AdminPage {

	public const MENU_SLUG     = 'workos-users';
	public const SCRIPT_HANDLE = 'workos-admin-users';
	public const STYLE_HANDLE  = 'workos-admin-users';

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
	 * Register the submenu under the main WorkOS menu.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_submenu_page(
			'workos',
			__( 'WorkOS Users', 'integration-workos' ),
			__( 'Users', 'integration-workos' ),
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
			<h1 class="wp-heading-inline"><?php esc_html_e( 'WorkOS Users', 'integration-workos' ); ?></h1>
			<hr class="wp-header-end">
			<p class="description">
				<?php esc_html_e( 'Browse users stored in WorkOS for the active environment. Use the Open in WorkOS action to manage a user in the WorkOS Dashboard — including re-enabling email after a deliverability suppression.', 'integration-workos' ); ?>
			</p>
			<div id="workos-users-admin-root"></div>
		</div>
		<?php
	}

	/**
	 * Enqueue the React admin bundle on our page only.
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

		$asset_file = $assets_dir . 'admin-users.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: [
				'dependencies' => [ 'wp-element', 'wp-i18n' ],
				'version'      => WORKOS_VERSION,
			];

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			$assets_url . 'admin-users.js',
			$asset['dependencies'] ?? [ 'wp-element', 'wp-i18n' ],
			$asset['version'] ?? WORKOS_VERSION,
			true
		);

		wp_set_script_translations( self::SCRIPT_HANDLE, 'integration-workos' );

		wp_enqueue_style(
			self::STYLE_HANDLE,
			$assets_url . 'admin-users.css',
			[],
			$asset['version'] ?? WORKOS_VERSION
		);

		$container            = workos()->getContainer();
		$change_email_enabled = $container
			? $container->get( \WorkOS\Auth\ChangeEmail\Controller::class )->isActive()
			: false;

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'workosUsersAdmin',
			[
				'restUrl'            => esc_url_raw( rest_url( RestApi::NAMESPACE . RestApi::BASE ) ),
				'changeEmailUrl'     => esc_url_raw( rest_url( RestApi::NAMESPACE . '/users/' ) ),
				'nonce'              => wp_create_nonce( 'wp_rest' ),
				'environment'        => Config::get_active_environment(),
				'environmentId'      => Config::get_environment_id(),
				'dashboardBaseUrl'   => 'https://dashboard.workos.com',
				'defaultLimit'       => 25,
				'pluginEnabled'      => workos()->is_enabled(),
				'changeEmailEnabled' => $change_email_enabled,
			]
		);

		// Wire the shared password-reset trigger so the per-row button can
		// fire `POST /workos/v1/admin/users/{wp_user_id}/password-reset`.
		// The handles are registered by PasswordResetAdmin\Assets on `init`;
		// this page only needs to enqueue them.
		wp_enqueue_script( \WorkOS\Auth\PasswordResetAdmin\Assets::SCRIPT_HANDLE );
		wp_enqueue_style( \WorkOS\Auth\PasswordResetAdmin\Assets::STYLE_HANDLE );

		// The change-email action is handled natively by the React bundle
		// (own modal + in-place row refresh), so unlike password reset it
		// does not enqueue the shared ChangeEmail admin handler — it just
		// needs `changeEmailUrl` + `changeEmailEnabled` from the config above.
	}
}
