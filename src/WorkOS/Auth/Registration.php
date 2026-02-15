<?php
/**
 * Registration redirect to WorkOS AuthKit.
 *
 * @package WorkOS\Auth
 */

namespace WorkOS\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Intercepts WP registration and redirects to WorkOS signup.
 */
class Registration {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'login_init', [ $this, 'redirect_registration' ] );
		add_filter( 'register_url', [ $this, 'filter_register_url' ] );
	}

	/**
	 * Redirect the WP registration page to WorkOS AuthKit.
	 */
	public function redirect_registration(): void {
		if ( ! workos()->is_enabled() ) {
			return;
		}

		$action = sanitize_text_field( wp_unslash( $_REQUEST['action'] ?? '' ) );

		if ( 'register' !== $action ) {
			return;
		}

		$signup_url = $this->get_signup_url();
		wp_safe_redirect( $signup_url );
		exit;
	}

	/**
	 * Filter the register_url to point to WorkOS.
	 *
	 * @param string $url Default registration URL.
	 *
	 * @return string
	 */
	public function filter_register_url( string $url ): string {
		if ( ! workos()->is_enabled() ) {
			return $url;
		}

		return $this->get_signup_url();
	}

	/**
	 * Get the WorkOS signup URL.
	 *
	 * @return string
	 */
	private function get_signup_url(): string {
		$state = wp_create_nonce( 'workos_auth' ) . '|' . admin_url();

		return workos()->api()->get_authorization_url(
			[
				'redirect_uri' => Login::get_callback_url(),
				'state'        => $state,
				'provider'     => 'authkit',
			]
		);
	}
}
