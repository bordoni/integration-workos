<?php
/**
 * Password-based AuthKit endpoints.
 *
 * @package WorkOS\REST\Auth
 */

namespace WorkOS\REST\Auth;

use WorkOS\Auth\AuthKit\Profile;

defined( 'ABSPATH' ) || exit;

/**
 * POST /auth/password/authenticate   — sign in with email + password.
 * POST /auth/password/reset/start    — request a password reset email.
 * POST /auth/password/reset/confirm  — complete a password reset with a token.
 */
class Password extends BaseEndpoint {

	private const RATE_LIMIT_IP_ATTEMPTS    = 10;
	private const RATE_LIMIT_EMAIL_ATTEMPTS = 5;
	private const RATE_LIMIT_WINDOW         = 60;

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::BASE . '/password/authenticate',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'authenticate' ],
				'permission_callback' => [ $this, 'public_permission' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			self::BASE . '/password/reset/start',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'reset_start' ],
				'permission_callback' => [ $this, 'public_permission' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			self::BASE . '/password/reset/confirm',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'reset_confirm' ],
				'permission_callback' => [ $this, 'public_permission' ],
			]
		);
	}

	/**
	 * POST /auth/password/authenticate
	 *
	 * @param \WP_REST_Request $request REST request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function authenticate( \WP_REST_Request $request ) {
		$profile = $this->resolve_profile( $request );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		$nonce_ok = $this->verify_nonce( $request, $profile );
		if ( is_wp_error( $nonce_ok ) ) {
			return $nonce_ok;
		}

		if ( ! $profile->has_method( Profile::METHOD_PASSWORD ) ) {
			return new \WP_Error(
				'workos_authkit_method_disabled',
				__( 'Password sign-in is not enabled for this login.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		$email    = strtolower( trim( (string) $request->get_param( 'email' ) ) );
		$password = (string) $request->get_param( 'password' );

		if ( '' === $email || ! is_email( $email ) || '' === $password ) {
			return new \WP_Error(
				'workos_authkit_invalid_input',
				__( 'Enter a valid email and password.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		$ip      = $this->rate_limiter->client_ip();
		$rate_ok = $this->rate_limit(
			[
				[ 'password_ip', $ip, self::RATE_LIMIT_IP_ATTEMPTS, self::RATE_LIMIT_WINDOW ],
				[ 'password_email', $email, self::RATE_LIMIT_EMAIL_ATTEMPTS, self::RATE_LIMIT_WINDOW ],
			]
		);
		if ( is_wp_error( $rate_ok ) ) {
			return $rate_ok;
		}

		$workos_response = workos()->api()->authenticate_with_password(
			$email,
			$password,
			$this->get_radar_token( $request )
		);

		if ( is_wp_error( $workos_response ) ) {
			return $workos_response;
		}

		$result = $this->login_completer->complete(
			$workos_response,
			$profile,
			$this->sanitize_redirect( (string) $request->get_param( 'redirect_to' ) )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * POST /auth/password/reset/start
	 *
	 * @param \WP_REST_Request $request REST request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function reset_start( \WP_REST_Request $request ) {
		$profile = $this->resolve_profile( $request );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		$nonce_ok = $this->verify_nonce( $request, $profile );
		if ( is_wp_error( $nonce_ok ) ) {
			return $nonce_ok;
		}

		if ( ! $profile->is_password_reset_flow_enabled() ) {
			return new \WP_Error(
				'workos_authkit_reset_disabled',
				__( 'Password reset is not enabled for this login.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		$email = strtolower( trim( (string) $request->get_param( 'email' ) ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return new \WP_Error(
				'workos_authkit_invalid_input',
				__( 'Enter a valid email.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		$ip      = $this->rate_limiter->client_ip();
		$rate_ok = $this->rate_limit(
			[
				[ 'pw_reset_ip', $ip, self::RATE_LIMIT_IP_ATTEMPTS, self::RATE_LIMIT_WINDOW ],
				[ 'pw_reset_email', $email, self::RATE_LIMIT_EMAIL_ATTEMPTS, self::RATE_LIMIT_WINDOW ],
			]
		);
		if ( is_wp_error( $rate_ok ) ) {
			return $rate_ok;
		}

		// Fire the WorkOS send call but *always* return 200 to prevent email
		// enumeration (an attacker cannot tell whether an account exists).
		workos()->api()->send_password_reset(
			$email,
			$this->build_password_reset_url( $profile ),
			$this->get_radar_token( $request )
		);

		return new \WP_REST_Response(
			[
				'ok'      => true,
				'message' => __( 'If an account exists for this email, a password reset link is on its way.', 'integration-workos' ),
			],
			200
		);
	}

	/**
	 * POST /auth/password/reset/confirm
	 *
	 * @param \WP_REST_Request $request REST request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function reset_confirm( \WP_REST_Request $request ) {
		$profile = $this->resolve_profile( $request );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		$nonce_ok = $this->verify_nonce( $request, $profile );
		if ( is_wp_error( $nonce_ok ) ) {
			return $nonce_ok;
		}

		if ( ! $profile->is_password_reset_flow_enabled() ) {
			return new \WP_Error(
				'workos_authkit_reset_disabled',
				__( 'Password reset is not enabled for this login.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		$token        = (string) $request->get_param( 'token' );
		$new_password = (string) $request->get_param( 'new_password' );

		if ( '' === $token || '' === $new_password ) {
			return new \WP_Error(
				'workos_authkit_invalid_input',
				__( 'A reset token and new password are required.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		$ip      = $this->rate_limiter->client_ip();
		$rate_ok = $this->rate_limit(
			[
				[ 'pw_reset_confirm_ip', $ip, self::RATE_LIMIT_IP_ATTEMPTS, self::RATE_LIMIT_WINDOW ],
			]
		);
		if ( is_wp_error( $rate_ok ) ) {
			return $rate_ok;
		}

		$workos_response = workos()->api()->reset_password(
			$token,
			$new_password,
			$this->get_radar_token( $request )
		);

		if ( is_wp_error( $workos_response ) ) {
			return $workos_response;
		}

		return new \WP_REST_Response(
			[ 'ok' => true ],
			200
		);
	}

	/**
	 * Build the URL emailed to users for completing a password reset.
	 *
	 * @param Profile $profile Active profile.
	 *
	 * @return string
	 */
	private function build_password_reset_url( Profile $profile ): string {
		return add_query_arg(
			[
				'workos_action' => 'reset-password',
				'profile'       => $profile->get_slug(),
			],
			wp_login_url()
		);
	}
}
