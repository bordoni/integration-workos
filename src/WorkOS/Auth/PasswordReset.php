<?php
/**
 * Password reset redirect to WorkOS.
 *
 * @package WorkOS\Auth
 */

namespace WorkOS\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Redirects password reset for WorkOS-linked users.
 */
class PasswordReset {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'login_init', [ $this, 'redirect_lostpassword' ] );
		add_filter( 'lostpassword_url', [ $this, 'filter_lostpassword_url' ], 10, 2 );
		add_filter( 'allow_password_reset', [ $this, 'block_reset_for_workos_users' ], 10, 2 );
	}

	/**
	 * Redirect the lost password page to WorkOS.
	 */
	public function redirect_lostpassword(): void {
		if ( ! workos()->is_enabled() ) {
			return;
		}

		$action = sanitize_text_field( wp_unslash( $_REQUEST['action'] ?? '' ) );

		if ( 'lostpassword' !== $action ) {
			return;
		}

		// If fallback is enabled, only redirect if there's no ?fallback=1 param.
		if ( ! empty( $_GET['fallback'] ) && workos()->option( 'allow_password_fallback', true ) ) {
			return;
		}

		// If fallback is enabled and we don't know the user yet, let WP show the form.
		// The actual redirect happens after they submit their email.
		if ( workos()->option( 'allow_password_fallback', true ) ) {
			return;
		}

		// No fallback: redirect to AuthKit.
		$reset_url = $this->get_reset_url();
		wp_redirect( $reset_url );
		exit;
	}

	/**
	 * Filter the lostpassword_url.
	 *
	 * @param string $url      Default URL.
	 * @param string $redirect Redirect destination.
	 *
	 * @return string
	 */
	public function filter_lostpassword_url( string $url, string $redirect ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! workos()->is_enabled() ) {
			return $url;
		}

		// Only change URL if fallback is disabled.
		if ( ! workos()->option( 'allow_password_fallback', true ) ) {
			return $this->get_reset_url();
		}

		return $url;
	}

	/**
	 * Block password reset for WorkOS-linked users.
	 *
	 * @param bool $allow Whether password reset is allowed.
	 * @param int  $user_id The user ID.
	 *
	 * @return bool|\WP_Error
	 */
	public function block_reset_for_workos_users( $allow, $user_id ) {
		if ( ! workos()->is_enabled() ) {
			return $allow;
		}

		$workos_id = get_user_meta( $user_id, '_workos_user_id', true );
		if ( $workos_id ) {
			return new \WP_Error(
				'workos_no_password_reset',
				__( 'Password reset is managed by your organization. Please use your SSO provider to reset your password.', 'integration-workos' )
			);
		}

		return $allow;
	}

	/**
	 * Get the WorkOS password reset URL.
	 *
	 * AuthKit handles password reset when the user visits the login screen.
	 *
	 * @return string
	 */
	private function get_reset_url(): string {
		$state = wp_create_nonce( 'workos_auth' ) . '|' . wp_login_url();

		return workos()->api()->get_authorization_url(
			[
				'redirect_uri' => Login::get_callback_url(),
				'state'        => $state,
				'provider'     => 'authkit',
			]
		);
	}
}
