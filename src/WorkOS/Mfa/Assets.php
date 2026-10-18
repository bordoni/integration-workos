<?php
/**
 * MFA account panel assets.
 *
 * @package WorkOS\Mfa
 */

namespace WorkOS\Mfa;

use WorkOS\User;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the account panel script and enqueues it on the user's own
 * profile screen. The `[workos:mfa]` shortcode enqueues it where it renders.
 */
class Assets {

	/**
	 * Script and style handle.
	 */
	public const HANDLE = 'workos-mfa-settings';

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
	 * Register the script and style.
	 *
	 * @return void
	 */
	public function register_assets(): void {
		$asset_file = workos()->getDir() . 'build/mfa-settings.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: [
				'dependencies' => [ 'wp-element', 'wp-i18n' ],
				'version'      => workos()->getVersion(),
			];
		$version    = (string) ( $asset['version'] ?? workos()->getVersion() );

		wp_register_script(
			self::HANDLE,
			workos()->getUrl() . 'build/mfa-settings.js',
			(array) ( $asset['dependencies'] ?? [] ),
			$version,
			true
		);

		wp_set_script_translations( self::HANDLE, 'integration-workos' );

		wp_localize_script(
			self::HANDLE,
			'workosMfa',
			[
				'restUrl' => esc_url_raw( rest_url( RestApi::NAMESPACE . '/users/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			]
		);

		wp_register_style( self::HANDLE, workos()->getUrl() . 'build/mfa-settings.css', [], $version );
	}

	/**
	 * Enqueue on the signed-in user's own profile screen.
	 *
	 * @param string $hook_suffix Admin page hook.
	 *
	 * @return void
	 */
	public function maybe_enqueue_admin( string $hook_suffix ): void {
		if ( 'profile.php' !== $hook_suffix || ! User::is_sso() ) {
			return;
		}

		self::enqueue();
	}

	/**
	 * Enqueue the panel script and style.
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		wp_enqueue_script( self::HANDLE );
		wp_enqueue_style( self::HANDLE );
	}
}
