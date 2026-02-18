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
		wp_redirect( $signup_url );
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

		$args = [
			'redirect_uri' => Login::get_callback_url(),
			'state'        => $state,
			'provider'     => 'authkit',
		];

		$org_id = \WorkOS\Config::get_organization_id();
		if ( $org_id ) {
			$args['organization_id'] = $org_id;
		}

		return workos()->api()->get_authorization_url( $args );
	}
}
