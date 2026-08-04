<?php
/**
 * Signup AuthKit endpoints.
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
 * POST /auth/signup/create  — register a new WorkOS user.
 * POST /auth/signup/verify  — verify the email code that was sent.
 *
 * Signup is only available on profiles with `signup.enabled = true` and
 * respects `signup.require_invite` (when true, self-serve signup is
 * rejected — users must come through an invitation acceptance flow
 * instead).
 */
class Signup extends BaseEndpoint {

	/**
	 * Requests allowed per window from one address, for account creation.
	 *
	 * Tighter than the default posture: sign-up creates an account, and
	 * creating one against somebody else's address is what makes that
	 * address a target. Verifying isn't — it spends a code the user is
	 * retyping off their own screen, so it keeps the default allowance.
	 */
	private const CREATE_PER_IP = 5;

	/**
	 * Requests allowed per window against one address being signed up.
	 */
	private const CREATE_PER_SUBJECT = 3;

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::BASE . '/signup/create',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create' ],
				'permission_callback' => [ $this, 'public_permission' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			self::BASE . '/signup/verify',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'verify' ],
				'permission_callback' => [ $this, 'public_permission' ],
			]
		);
	}

	/**
	 * POST /auth/signup/create
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function create( WP_REST_Request $request ) {
		$profile = $this->resolve_profile( $request );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		$nonce_ok = $this->verify_nonce( $request, $profile );
		if ( is_wp_error( $nonce_ok ) ) {
			return $nonce_ok;
		}

		$signup = $profile->get_signup();
		if ( empty( $signup['enabled'] ) ) {
			return new WP_Error(
				'workos_authkit_signup_disabled',
				__( 'Sign-up is not enabled for this login.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! empty( $signup['require_invite'] ) ) {
			return new WP_Error(
				'workos_authkit_invitation_required',
				__( 'This login requires an invitation to sign up.', 'integration-workos' ),
				[ 'status' => 403 ]
			);
		}

		$email      = strtolower( trim( (string) $request->get_param( 'email' ) ) );
		$password   = (string) $request->get_param( 'password' );
		$first_name = sanitize_text_field( (string) $request->get_param( 'first_name' ) );
		$last_name  = sanitize_text_field( (string) $request->get_param( 'last_name' ) );

		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error(
				'workos_authkit_invalid_input',
				__( 'Enter a valid email.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		$ip      = $this->client_ip->get();
		$rate_ok = $this->http_rate_limit->for_request( 'signup', $ip, $email, self::CREATE_PER_IP, self::CREATE_PER_SUBJECT );
		if ( is_wp_error( $rate_ok ) ) {
			return $rate_ok;
		}

		$payload = array_filter(
			[
				'email'      => $email,
				'password'   => '' !== $password ? $password : null,
				'first_name' => '' !== $first_name ? $first_name : null,
				'last_name'  => '' !== $last_name ? $last_name : null,
			],
			static function ( $value ): bool {
				return null !== $value;
			}
		);

		$user = workos()->api()->create_user( $payload, $this->get_radar_token( $request ) );

		if ( is_wp_error( $user ) ) {
			return $user;
		}

		// If the user still needs to verify their email, kick off the code
		// email so the React shell can transition straight to the verify step.
		if ( empty( $user['email_verified'] ) && ! empty( $user['id'] ) ) {
			if ( ! is_wp_error( $this->outbound_email->reserve( $email ) ) ) {
				workos()->api()->send_verification_email( $user['id'] );
			}
		}

		return new WP_REST_Response(
			[
				'user'                => [
					'id'             => (string) ( $user['id'] ?? '' ),
					'email'          => (string) ( $user['email'] ?? $email ),
					'email_verified' => (bool) ( $user['email_verified'] ?? false ),
				],
				'verification_needed' => empty( $user['email_verified'] ),
			],
			201
		);
	}

	/**
	 * POST /auth/signup/verify
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

		$user_id = (string) $request->get_param( 'user_id' );
		$code    = (string) $request->get_param( 'code' );

		if ( '' === $user_id || '' === $code ) {
			return new WP_Error(
				'workos_authkit_invalid_input',
				__( 'A user and verification code are required.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		$ip      = $this->client_ip->get();
		$rate_ok = $this->http_rate_limit->for_request( 'signup_verify', $ip );
		if ( is_wp_error( $rate_ok ) ) {
			return $rate_ok;
		}

		$verify_response = workos()->api()->verify_email(
			$user_id,
			$code,
			$this->get_radar_token( $request )
		);

		if ( is_wp_error( $verify_response ) ) {
			// Return a clean, generic error. Do NOT echo back the WorkOS
			// response body (which may include the user_id we looked up)
			// or the caller-supplied user_id — the caller already knows
			// their input, and echoing it on a failed verification adds no
			// value and risks leaking internal identifiers.
			return new WP_Error(
				'workos_authkit_verify_failed',
				__( 'That code is not valid. Request a new one and try again.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		// After email verification, WorkOS returns the user record. We do
		// *not* log the user in automatically — the React shell transitions
		// to the sign-in step with the email pre-filled. This keeps signup
		// and login flows isolated (each one only has one way to succeed).
		return new WP_REST_Response(
			[
				'ok'   => true,
				'user' => [
					'id'             => (string) ( $verify_response['user']['id'] ?? $user_id ),
					'email'          => (string) ( $verify_response['user']['email'] ?? '' ),
					'email_verified' => true,
				],
			],
			200
		);
	}
}
