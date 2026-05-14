<?php
/**
 * Password-based AuthKit endpoints.
 *
 * @package WorkOS\REST\Auth
 */

namespace WorkOS\REST\Auth;

use WorkOS\Auth\AuthKit\Profile;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

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
	 * Minimum observable response time for reset_start, in microseconds.
	 *
	 * The WorkOS send-reset API answers visibly faster when the email is
	 * unknown (no message queued) than when it's a valid account. A
	 * floor larger than the typical delta flattens both paths so an
	 * attacker cannot distinguish them via response-time timing.
	 * 900 ms is well above both cases on healthy networks.
	 */
	private const RESPONSE_TIME_FLOOR_US = 900000;

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
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function authenticate( WP_REST_Request $request ) {
		$profile = $this->resolve_profile( $request );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		$nonce_ok = $this->verify_nonce( $request, $profile );
		if ( is_wp_error( $nonce_ok ) ) {
			return $nonce_ok;
		}

		if ( ! $profile->has_method( Profile::METHOD_PASSWORD ) ) {
			return new WP_Error(
				'workos_authkit_method_disabled',
				__( 'Password sign-in is not enabled for this login.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		$email    = strtolower( trim( (string) $request->get_param( 'email' ) ) );
		$password = (string) $request->get_param( 'password' );

		if ( '' === $email || ! is_email( $email ) || '' === $password ) {
			return new WP_Error(
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

		if ( is_wp_error( $workos_response ) && workos()->option( 'allow_password_fallback', true ) ) {
			// WorkOS rejected the password — fall back to WordPress authentication.
			// Handles migrated users whose passwords were never synced to WorkOS.
			$wp_user = wp_authenticate( $email, $password );

			if ( ! is_wp_error( $wp_user ) ) {
				$workos_user_id = $this->resolve_workos_user_id( $wp_user, $email );

				if ( $workos_user_id ) {
					if ( workos()->option( 'wp_password_fallback_email_confirmation', false ) ) {
						// Email confirmation path: identity is verified via a magic code
						// instead of syncing the plaintext password to WorkOS.
						workos()->api()->send_magic_auth_code( $email, $this->get_radar_token( $request ) );

						return new WP_REST_Response(
							[
								'email_confirmation_required' => true,
								'email' => $email,
							],
							200
						);
					}

					// Direct sync path: one-time password migration to WorkOS so future
					// logins authenticate directly without needing the fallback.
					workos()->api()->update_user( $workos_user_id, [ 'password' => $password ] );

					$workos_response = workos()->api()->authenticate_with_password(
						$email,
						$password,
						$this->get_radar_token( $request )
					);
				}
			}
		}

		// `complete()` handles the `organization_selection_required` error
		// transparently when the profile has a pinned org. Pass the WP_Error
		// through so it can decide.
		$result = $this->login_completer->complete(
			$workos_response,
			$profile,
			$this->sanitize_redirect( (string) $request->get_param( 'redirect_to' ) )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * POST /auth/password/reset/start
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function reset_start( WP_REST_Request $request ) {
		$profile = $this->resolve_profile( $request );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		$nonce_ok = $this->verify_nonce( $request, $profile );
		if ( is_wp_error( $nonce_ok ) ) {
			return $nonce_ok;
		}

		if ( ! $profile->is_password_reset_flow_enabled() ) {
			return new WP_Error(
				'workos_authkit_reset_disabled',
				__( 'Password reset is not enabled for this login.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		$email = strtolower( trim( (string) $request->get_param( 'email' ) ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error(
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
		// Also normalize response time: WorkOS answers valid + unknown
		// emails at visibly different latencies, which leaks existence via
		// side-channel timing despite the identical body. A fixed floor
		// flattens both paths to the same observable wall-clock.
		$start_ns = hrtime( true );

		workos()->api()->send_password_reset(
			$email,
			$this->build_password_reset_url( $profile ),
			$this->get_radar_token( $request )
		);

		$this->sleep_until_floor( $start_ns, self::RESPONSE_TIME_FLOOR_US );

		return new WP_REST_Response(
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
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function reset_confirm( WP_REST_Request $request ) {
		$profile = $this->resolve_profile( $request );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		$nonce_ok = $this->verify_nonce( $request, $profile );
		if ( is_wp_error( $nonce_ok ) ) {
			return $nonce_ok;
		}

		if ( ! $profile->is_password_reset_flow_enabled() ) {
			return new WP_Error(
				'workos_authkit_reset_disabled',
				__( 'Password reset is not enabled for this login.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		$token        = (string) $request->get_param( 'token' );
		$new_password = (string) $request->get_param( 'new_password' );

		if ( '' === $token || '' === $new_password ) {
			return new WP_Error(
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

		return new WP_REST_Response(
			[ 'ok' => true ],
			200
		);
	}

	/**
	 * Return the WorkOS user ID linked to a WP user, creating the link if needed.
	 *
	 * @param \WP_User $wp_user WP user object.
	 * @param string   $email   Email address (used for the WorkOS lookup).
	 *
	 * @return string WorkOS user ID, or empty string if it could not be resolved.
	 */
	private function resolve_workos_user_id( \WP_User $wp_user, string $email ): string {
		$workos_user_id = (string) get_user_meta( $wp_user->ID, '_workos_user_id', true );
		if ( $workos_user_id ) {
			return $workos_user_id;
		}

		$existing = workos()->api()->list_users( [ 'email' => $email ] );
		if ( ! is_wp_error( $existing ) && ! empty( $existing['data'][0] ) ) {
			\WorkOS\Sync\UserSync::link_user( $wp_user->ID, $existing['data'][0] );
			return (string) $existing['data'][0]['id'];
		}

		$synced = \WorkOS\Sync\UserSync::sync_existing_user( $wp_user->ID );
		if ( ! is_wp_error( $synced ) ) {
			return (string) get_user_meta( $wp_user->ID, '_workos_user_id', true );
		}

		return '';
	}

	/**
	 * Build the URL emailed to users for completing a password reset.
	 *
	 * @param Profile $profile Active profile.
	 *
	 * @return string
	 */
	private function build_password_reset_url( Profile $profile ): string {
		$url = add_query_arg(
			[
				'workos_action' => 'reset-password',
				'profile'       => $profile->get_slug(),
			],
			wp_login_url()
		);

		// `wp_login_url()` runs through the `login_url`/`home_url` filters,
		// which a third-party plugin escapes via `esc_url()` (`&` → `&amp;`).
		// WorkOS emails the URL verbatim, so decode before handing it over.
		return htmlspecialchars_decode( $url, ENT_QUOTES | ENT_HTML5 );
	}

	/**
	 * Sleep the caller's thread until at least $floor_us microseconds
	 * have elapsed since $start_ns.
	 *
	 * Used to flatten response-time differences that would otherwise
	 * leak whether a submitted email corresponds to a real account.
	 *
	 * @param float $start_ns hrtime(true) timestamp taken at the start.
	 * @param int   $floor_us Minimum observable elapsed microseconds.
	 *
	 * @return void
	 */
	private function sleep_until_floor( float $start_ns, int $floor_us ): void {
		$elapsed_us = (int) ( ( hrtime( true ) - $start_ns ) / 1000 );
		$remaining  = $floor_us - $elapsed_us;
		if ( $remaining > 0 ) {
			usleep( $remaining );
		}
	}
}
