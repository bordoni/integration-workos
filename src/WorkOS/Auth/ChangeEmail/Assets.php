<?php
/**
 * Asset registration for the change-email UIs (admin + frontend confirm).
 *
 * @package WorkOS\Auth\ChangeEmail
 */

namespace WorkOS\Auth\ChangeEmail;

defined( 'ABSPATH' ) || exit;

/**
 * Registers (but does not always enqueue) two JS bundles:
 *
 *  - `workos-admin-change-email`     for the admin row action / panel.
 *  - `workos-change-email-confirm`   for the frontend confirm page.
 *
 * Admin bundle auto-enqueues on users.php / user-edit.php / profile.php
 * (mirrors PasswordResetAdmin\Assets). Frontend bundle is enqueued by
 * {@see FrontendConfirmRoute} when the route matches and by
 * {@see Shortcode} when the shortcode renders.
 */
class Assets {

	public const ADMIN_SCRIPT_HANDLE   = 'workos-admin-change-email';
	public const ADMIN_STYLE_HANDLE    = 'workos-admin-change-email';
	public const CONFIRM_SCRIPT_HANDLE = 'workos-change-email-confirm';
	public const CONFIRM_STYLE_HANDLE  = 'workos-change-email-confirm';

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
	 * Plugin version.
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
	 * Register both bundles so callers can `wp_enqueue_script(handle)`.
	 *
	 * @return void
	 */
	public function register_assets(): void {
		$this->register_admin_assets();
		$this->register_confirm_assets();
	}

	/**
	 * Register the admin bundle.
	 *
	 * @return void
	 */
	private function register_admin_assets(): void {
		$asset_file = $this->plugin_path . 'build/admin-change-email.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: [
				'dependencies' => [ 'wp-i18n' ],
				'version'      => $this->version,
			];

		wp_register_script(
			self::ADMIN_SCRIPT_HANDLE,
			$this->plugin_url . 'build/admin-change-email.js',
			(array) ( $asset['dependencies'] ?? [] ),
			(string) ( $asset['version'] ?? $this->version ),
			true
		);

		wp_set_script_translations( self::ADMIN_SCRIPT_HANDLE, 'integration-workos' );

		wp_localize_script(
			self::ADMIN_SCRIPT_HANDLE,
			'workosChangeEmail',
			[
				'restUrl' => esc_url_raw( rest_url( RestApi::NAMESPACE . '/users/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'strings' => [
					'prompt'        => __( 'Enter the new email address for this user:', 'integration-workos' ),
					'sending'       => __( 'Sending verification…', 'integration-workos' ),
					/* translators: %s: masked email (e.g. "j•••@e•••.com"). */
					'success'       => __( 'Verification email sent to %s.', 'integration-workos' ),
					'errorGeneric'  => __( 'Could not start the email change. Please try again.', 'integration-workos' ),
					'invalidEmail'  => __( 'Please enter a valid email address.', 'integration-workos' ),
				],
			]
		);

		wp_register_style(
			self::ADMIN_STYLE_HANDLE,
			$this->plugin_url . 'build/admin-change-email.css',
			[],
			(string) ( $asset['version'] ?? $this->version )
		);
	}

	/**
	 * Register the frontend confirm-page bundle.
	 *
	 * @return void
	 */
	private function register_confirm_assets(): void {
		$asset_file = $this->plugin_path . 'build/change-email-confirm.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: [
				'dependencies' => [ 'wp-i18n' ],
				'version'      => $this->version,
			];

		wp_register_script(
			self::CONFIRM_SCRIPT_HANDLE,
			$this->plugin_url . 'build/change-email-confirm.js',
			(array) ( $asset['dependencies'] ?? [] ),
			(string) ( $asset['version'] ?? $this->version ),
			true
		);

		wp_set_script_translations( self::CONFIRM_SCRIPT_HANDLE, 'integration-workos' );

		wp_localize_script(
			self::CONFIRM_SCRIPT_HANDLE,
			'workosChangeEmailConfirm',
			[
				'restUrl' => esc_url_raw( rest_url( RestApi::NAMESPACE . '/users/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'strings' => [
					'confirming'   => __( 'Confirming…', 'integration-workos' ),
					'cancelling'   => __( 'Cancelling…', 'integration-workos' ),
					'success'      => __( 'Your email address has been updated.', 'integration-workos' ),
					'cancelled'    => __( 'The email change has been cancelled.', 'integration-workos' ),
					'errorGeneric' => __( 'This confirmation link is no longer valid. Please request a new email change.', 'integration-workos' ),
					'continue'     => __( 'Continue', 'integration-workos' ),
				],
			]
		);

		wp_register_style(
			self::CONFIRM_STYLE_HANDLE,
			$this->plugin_url . 'build/change-email-confirm.css',
			[],
			(string) ( $asset['version'] ?? $this->version )
		);
	}

	/**
	 * Enqueue the admin bundle on relevant admin screens.
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

		wp_enqueue_script( self::ADMIN_SCRIPT_HANDLE );
		wp_enqueue_style( self::ADMIN_STYLE_HANDLE );
	}
}
