<?php
/**
 * Asset registration for the password-reset trigger UI.
 *
 * @package WorkOS\Auth\PasswordResetAdmin
 */

namespace WorkOS\Auth\PasswordResetAdmin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers (but does not enqueue) the click-handler JS and its styles.
 *
 * Surfaces decide when to enqueue:
 *
 *  - {@see UserProfilePanel} enqueues on `admin_enqueue_scripts` for the
 *    user-edit screen.
 *  - {@see RowActions} relies on the surface enqueueing on `users.php`.
 *  - {@see Shortcode} enqueues at render time when the shortcode appears.
 */
class Assets {

	public const SCRIPT_HANDLE = 'workos-admin-password-reset';
	public const STYLE_HANDLE  = 'workos-admin-password-reset';

	/**
	 * Plugin URL base.
	 *
	 * @var string
	 */
	private string $plugin_url;

	/**
	 * Plugin filesystem path.
	 *
	 * @var string
	 */
	private string $plugin_path;

	/**
	 * Plugin version (for cache-busting).
	 *
	 * @var string
	 */
	private string $version;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->plugin_url  = workos()->getUrl();
		$this->plugin_path = workos()->getDir();
		$this->version     = workos()->getVersion();
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', [ $this, 'register_assets' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'maybe_enqueue_admin' ] );
	}

	/**
	 * Register the JS + CSS handles so callers can enqueue them by name.
	 *
	 * @return void
	 */
	public function register_assets(): void {
		$asset_file = $this->plugin_path . 'build/admin-password-reset.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: [
				'dependencies' => [ 'wp-i18n' ],
				'version'      => $this->version,
			];

		wp_register_script(
			self::SCRIPT_HANDLE,
			$this->plugin_url . 'build/admin-password-reset.js',
			(array) ( $asset['dependencies'] ?? [] ),
			(string) ( $asset['version'] ?? $this->version ),
			true
		);

		wp_set_script_translations( self::SCRIPT_HANDLE, 'integration-workos' );

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'workosPasswordReset',
			[
				'restUrl' => esc_url_raw( rest_url( RestApi::NAMESPACE . '/admin/users/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'strings' => [
					'modalTitle'   => __( 'Send password reset?', 'integration-workos' ),
					'modalMessage' => __( 'The user will receive a link from WorkOS to set a new password.', 'integration-workos' ),
					'modalConfirm' => __( 'Send reset email', 'integration-workos' ),
					'modalCancel'  => __( 'Cancel', 'integration-workos' ),
					'sending'      => __( 'Sending…', 'integration-workos' ),
					/* translators: %s: masked email address (e.g. "j•••@e•••.com"). */
					'success'      => __( 'Password reset email sent to %s.', 'integration-workos' ),
					'errorGeneric' => __( 'Could not send the reset email. Please try again.', 'integration-workos' ),
				],
			]
		);

		wp_register_style(
			self::STYLE_HANDLE,
			$this->plugin_url . 'build/admin-password-reset.css',
			[],
			(string) ( $asset['version'] ?? $this->version )
		);
	}

	/**
	 * Enqueue on admin screens where our triggers live.
	 *
	 * @param string $hook_suffix Admin screen hook suffix.
	 *
	 * @return void
	 */
	public function maybe_enqueue_admin( string $hook_suffix ): void {
		$relevant = [ 'users.php', 'user-edit.php', 'profile.php' ];
		if ( ! in_array( $hook_suffix, $relevant, true ) ) {
			return;
		}

		wp_enqueue_script( self::SCRIPT_HANDLE );
		wp_enqueue_style( self::STYLE_HANDLE );
	}
}
