<?php
/**
 * AJAX handler for headless login.
 *
 * @package WorkOS\UI
 */

namespace WorkOS\UI;

use WorkOS\Auth\Login;
use WorkOS\Sync\UserSync;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the wp_ajax_nopriv_workos_headless_login action.
 */
class Ajax {

	/**
	 * Constructor — registers AJAX hooks.
	 */
	public function __construct() {
		add_action( 'wp_ajax_workos_headless_login', [ $this, 'handle' ] );
		add_action( 'wp_ajax_nopriv_workos_headless_login', [ $this, 'handle' ] );
	}

	/**
	 * Handle the AJAX login request.
	 */
	public function handle(): void {
		check_ajax_referer( 'workos_login_button', 'nonce' );

		if ( ! workos()->is_enabled() ) {
			wp_send_json_error( [ 'message' => __( 'WorkOS is not configured.', 'workos' ) ] );
		}

		$email    = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$password = wp_unslash( $_POST['password'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( ! $email || ! $password ) {
			wp_send_json_error( [ 'message' => __( 'Email and password are required.', 'workos' ) ] );
		}

		$result = workos()->api()->authenticate_with_password( $email, $password );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		$workos_user = $result['user'] ?? $result;
		$wp_user     = UserSync::find_or_create_wp_user( $workos_user );

		if ( is_wp_error( $wp_user ) ) {
			wp_send_json_error( [ 'message' => $wp_user->get_error_message() ] );
		}

		Login::store_tokens( $wp_user->ID, $result );

		/** This action is documented in Auth/Login.php */
		do_action( 'workos_user_authenticated', $wp_user->ID, $result );

		wp_set_auth_cookie( $wp_user->ID, true );
		wp_set_current_user( $wp_user->ID );

		/** This action is documented in Auth/Login.php */
		do_action( 'wp_login', $wp_user->user_login, $wp_user );

		$redirect_to = sanitize_url( wp_unslash( $_POST['redirect_to'] ?? '' ) );
		if ( ! $redirect_to ) {
			$redirect_to = \WorkOS\Auth\Redirect::resolve( admin_url(), $wp_user );
		}

		wp_send_json_success( [ 'redirect_to' => $redirect_to ] );
	}
}
