<?php
/**
 * Email-verification AuthKit endpoints.
 *
 * @package WorkOS\REST\Auth
 */

namespace WorkOS\REST\Auth;

use WorkOS\Auth\AuthKit\PendingEmailVerification;
use WorkOS\RateLimit\TieredRateLimiter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * POST /auth/email-verification/verify — finish a sign-in WorkOS paused
 * with `email_verification_required`, using the code it mailed.
 * POST /auth/email-verification/resend — mail that account a new code.
 */
class EmailVerification extends BaseEndpoint {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::BASE . '/email-verification/verify',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'verify' ],
				'permission_callback' => [ $this, 'public_permission' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			self::BASE . '/email-verification/resend',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'resend' ],
				'permission_callback' => [ $this, 'public_permission' ],
			]
		);
	}

	/**
	 * POST /auth/email-verification/verify
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function verify( WP_REST_Request $request ) {
		$profile = $this->resolve_profile( $request );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		$nonce_ok = $this->verify_nonce( $request, $profile );
		if ( is_wp_error( $nonce_ok ) ) {
			return $nonce_ok;
		}

		$code          = trim( (string) $request->get_param( 'code' ) );
		$pending_token = (string) $request->get_param( 'pending_authentication_token' );

		if ( '' === $code || '' === $pending_token ) {
			return new WP_Error(
				'workos_authkit_invalid_input',
				__( 'A verification code is required.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		// Guessing is counted per pending sign-in: that is what one code
		// belongs to.
		$rate_ok = $this->rate_limit(
			[
				[ 'email_verify_ip', $this->client_ip->get(), TieredRateLimiter::REQUEST_IP ],
				[ 'email_verify_token', $pending_token, TieredRateLimiter::REQUEST_SUBJECT ],
			]
		);
		if ( is_wp_error( $rate_ok ) ) {
			return $rate_ok;
		}

		$workos_response = workos()->api()->authenticate_with_email_verification(
			$code,
			$pending_token,
			$this->get_radar_token( $request )
		);

		$result = $this->login_completer->complete(
			$workos_response,
			$profile,
			$this->sanitize_redirect( (string) $request->get_param( 'redirect_to' ) )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// The email is verified now, even if MFA comes next.
		PendingEmailVerification::forget( $pending_token );

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * POST /auth/email-verification/resend
	 *
	 * Takes only the pending token: the account comes from what the
	 * password step remembered, so the browser can't aim mail at anyone.
	 * Unknown tokens get the same answer as known ones.
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function resend( WP_REST_Request $request ) {
		$profile = $this->resolve_profile( $request );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		$nonce_ok = $this->verify_nonce( $request, $profile );
		if ( is_wp_error( $nonce_ok ) ) {
			return $nonce_ok;
		}

		$ip      = $this->client_ip->get();
		$rate_ok = $this->rate_limit(
			[
				[ 'email_verify_resend_ip', $ip, TieredRateLimiter::REQUEST_IP ],
			]
		);
		if ( is_wp_error( $rate_ok ) ) {
			return $rate_ok;
		}

		$sent = new WP_REST_Response(
			[
				'ok'      => true,
				'message' => __( 'A new code is on its way. Use the newest one.', 'integration-workos' ),
			],
			200
		);

		$email = PendingEmailVerification::email_for( (string) $request->get_param( 'pending_authentication_token' ) );
		if ( '' === $email ) {
			return $sent;
		}

		$send_ok = $this->rate_limit(
			[
				[ self::BUCKET_PASSWORD_OTP_IP, $ip, TieredRateLimiter::SEND_IP ],
				[ self::BUCKET_PASSWORD_OTP_RECIPIENT, $this->canonicalizer->canonical( $email ), TieredRateLimiter::SEND_RECIPIENT ],
			]
		);
		if ( is_wp_error( $send_ok ) ) {
			return $send_ok;
		}

		$users   = workos()->api()->list_users( [ 'email' => $email ] );
		$user_id = is_wp_error( $users ) ? '' : (string) ( $users['data'][0]['id'] ?? '' );
		if ( '' !== $user_id ) {
			workos()->api()->send_verification_email( $user_id );
		}

		return $sent;
	}
}
