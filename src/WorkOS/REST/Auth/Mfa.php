<?php
/**
 * Multi-factor authentication AuthKit endpoints.
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
 * MFA (TOTP + SMS) endpoints used during login *and* account settings.
 *
 * During login (user has a pending_authentication_token from a prior
 * password/magic/OAuth attempt):
 *
 *  - POST /auth/mfa/challenge  — start a challenge on the chosen factor.
 *  - POST /auth/mfa/verify     — verify the challenge and complete login.
 *
 * From account settings (user is already logged in):
 *
 *  - GET  /auth/mfa/factors         — list enrolled factors.
 *  - POST /auth/mfa/totp/enroll     — begin TOTP enrollment.
 *  - POST /auth/mfa/sms/enroll      — begin SMS enrollment.
 *  - POST /auth/mfa/factor/delete   — remove an enrolled factor.
 */
class Mfa extends BaseEndpoint {

	private const RATE_LIMIT_IP_ATTEMPTS = 10;
	private const RATE_LIMIT_WINDOW      = 60;

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::BASE . '/mfa/challenge',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'challenge' ],
				'permission_callback' => [ $this, 'public_permission' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			self::BASE . '/mfa/verify',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'verify' ],
				'permission_callback' => [ $this, 'public_permission' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			self::BASE . '/mfa/factors',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'list_factors' ],
				'permission_callback' => [ $this, 'authenticated_permission' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			self::BASE . '/mfa/totp/enroll',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'enroll_totp' ],
				'permission_callback' => [ $this, 'authenticated_permission' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			self::BASE . '/mfa/sms/enroll',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'enroll_sms' ],
				'permission_callback' => [ $this, 'authenticated_permission' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			self::BASE . '/mfa/factor/delete',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'delete_factor' ],
				'permission_callback' => [ $this, 'authenticated_permission' ],
			]
		);
	}

	/**
	 * Permission check for the settings-style endpoints.
	 *
	 * @return true|WP_Error
	 */
	public function authenticated_permission() {
		if ( get_current_user_id() <= 0 ) {
			return new WP_Error(
				'workos_authkit_not_logged_in',
				__( 'You must be signed in to manage MFA factors.', 'integration-workos' ),
				[ 'status' => 401 ]
			);
		}
		return true;
	}

	/**
	 * POST /auth/mfa/challenge
	 *
	 * Starts a challenge on a chosen factor. For SMS this triggers the
	 * text message; for TOTP it just returns a challenge id.
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function challenge( WP_REST_Request $request ) {
		$profile = $this->resolve_profile( $request );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		$nonce_ok = $this->verify_nonce( $request, $profile );
		if ( is_wp_error( $nonce_ok ) ) {
			return $nonce_ok;
		}

		$factor_id = (string) $request->get_param( 'factor_id' );
		if ( '' === $factor_id ) {
			return new WP_Error(
				'workos_authkit_invalid_input',
				__( 'A factor id is required.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		$rate_ok = $this->rate_limit(
			[
				[ 'mfa_challenge_ip', $this->rate_limiter->client_ip(), self::RATE_LIMIT_IP_ATTEMPTS, self::RATE_LIMIT_WINDOW ],
			]
		);
		if ( is_wp_error( $rate_ok ) ) {
			return $rate_ok;
		}

		$result = workos()->api()->challenge_auth_factor( $factor_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			[
				'challenge_id' => (string) ( $result['id'] ?? '' ),
				'expires_at'   => (string) ( $result['expires_at'] ?? '' ),
			],
			200
		);
	}

	/**
	 * POST /auth/mfa/verify
	 *
	 * Completes the previously-pending authentication with a TOTP/SMS code.
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

		$pending_auth_token = (string) $request->get_param( 'pending_authentication_token' );
		$challenge_id       = (string) $request->get_param( 'authentication_challenge_id' );
		$code               = (string) $request->get_param( 'code' );

		if ( '' === $pending_auth_token || '' === $challenge_id || '' === $code ) {
			return new WP_Error(
				'workos_authkit_invalid_input',
				__( 'A pending authentication token, challenge id, and code are required.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		$rate_ok = $this->rate_limit(
			[
				[ 'mfa_verify_ip', $this->rate_limiter->client_ip(), self::RATE_LIMIT_IP_ATTEMPTS, self::RATE_LIMIT_WINDOW ],
			]
		);
		if ( is_wp_error( $rate_ok ) ) {
			return $rate_ok;
		}

		$workos_response = workos()->api()->authenticate_with_totp(
			$pending_auth_token,
			$challenge_id,
			$code,
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

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * GET /auth/mfa/factors
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_factors( WP_REST_Request $request ) {
		$workos_user_id = (string) get_user_meta( get_current_user_id(), '_workos_user_id', true );
		if ( '' === $workos_user_id ) {
			return new WP_Error(
				'workos_authkit_no_workos_user',
				__( 'Your account is not linked to a WorkOS user.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		$result = workos()->api()->list_auth_factors( $workos_user_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$factors = array_map(
			static function ( array $factor ): array {
				return [
					'id'      => (string) ( $factor['id'] ?? '' ),
					'type'    => (string) ( $factor['type'] ?? '' ),
					'created' => (string) ( $factor['created_at'] ?? '' ),
				];
			},
			(array) ( $result['data'] ?? $result )
		);

		return new WP_REST_Response( [ 'factors' => $factors ], 200 );
	}

	/**
	 * POST /auth/mfa/totp/enroll
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function enroll_totp( WP_REST_Request $request ) {
		$profile = $this->resolve_profile( $request );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		$nonce_ok = $this->verify_nonce( $request, $profile );
		if ( is_wp_error( $nonce_ok ) ) {
			return $nonce_ok;
		}

		if ( ! $profile->allows_factor( Profile::FACTOR_TOTP ) ) {
			return new WP_Error(
				'workos_authkit_factor_disabled',
				__( 'TOTP is not enabled for this login.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		$user           = wp_get_current_user();
		$workos_user_id = (string) get_user_meta( $user->ID, '_workos_user_id', true );
		if ( '' === $workos_user_id ) {
			return new WP_Error(
				'workos_authkit_no_workos_user',
				__( 'Your account is not linked to a WorkOS user.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		$issuer = get_bloginfo( 'name' );
		$label  = $user->user_email;

		$result = workos()->api()->enroll_totp_factor( $workos_user_id, $issuer, $label );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$totp = $result['totp'] ?? [];

		return new WP_REST_Response(
			[
				'factor_id'    => (string) ( $result['id'] ?? '' ),
				'qr_code'      => (string) ( $totp['qr_code'] ?? '' ),
				'secret'       => (string) ( $totp['secret'] ?? '' ),
				'otpauth_uri'  => (string) ( $totp['uri'] ?? '' ),
			],
			201
		);
	}

	/**
	 * POST /auth/mfa/sms/enroll
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function enroll_sms( WP_REST_Request $request ) {
		$profile = $this->resolve_profile( $request );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		$nonce_ok = $this->verify_nonce( $request, $profile );
		if ( is_wp_error( $nonce_ok ) ) {
			return $nonce_ok;
		}

		if ( ! $profile->allows_factor( Profile::FACTOR_SMS ) ) {
			return new WP_Error(
				'workos_authkit_factor_disabled',
				__( 'SMS is not enabled for this login.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		$phone_number = preg_replace( '/[^\+0-9]/', '', (string) $request->get_param( 'phone_number' ) );
		if ( '' === $phone_number ) {
			return new WP_Error(
				'workos_authkit_invalid_input',
				__( 'A phone number is required.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		$workos_user_id = (string) get_user_meta( get_current_user_id(), '_workos_user_id', true );
		if ( '' === $workos_user_id ) {
			return new WP_Error(
				'workos_authkit_no_workos_user',
				__( 'Your account is not linked to a WorkOS user.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		$result = workos()->api()->enroll_sms_factor( $workos_user_id, $phone_number );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			[
				'factor_id' => (string) ( $result['id'] ?? '' ),
				'type'      => 'sms',
			],
			201
		);
	}

	/**
	 * POST /auth/mfa/factor/delete
	 *
	 * Removes an MFA factor the *current* user owns. The ownership check
	 * is mandatory: because the plugin authenticates to WorkOS with a
	 * tenant-wide API key, WorkOS cannot distinguish which user is
	 * requesting the delete. Without this check, any authenticated WP
	 * user could strip MFA from any other user's account by submitting
	 * that user's factor_id.
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_factor( WP_REST_Request $request ) {
		$profile = $this->resolve_profile( $request );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		$nonce_ok = $this->verify_nonce( $request, $profile );
		if ( is_wp_error( $nonce_ok ) ) {
			return $nonce_ok;
		}

		$factor_id = (string) $request->get_param( 'factor_id' );
		if ( '' === $factor_id ) {
			return new WP_Error(
				'workos_authkit_invalid_input',
				__( 'A factor id is required.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		$workos_user_id = (string) get_user_meta( get_current_user_id(), '_workos_user_id', true );
		if ( '' === $workos_user_id ) {
			return new WP_Error(
				'workos_authkit_no_workos_user',
				__( 'Your account is not linked to a WorkOS user.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		$factors = workos()->api()->list_auth_factors( $workos_user_id );
		if ( is_wp_error( $factors ) ) {
			return $factors;
		}

		$owned_ids = array_map(
			static fn( array $factor ): string => (string) ( $factor['id'] ?? '' ),
			(array) ( $factors['data'] ?? $factors )
		);

		if ( ! in_array( $factor_id, $owned_ids, true ) ) {
			// Return 404 rather than 403 so we don't leak the existence of
			// factor IDs that belong to other users.
			return new WP_Error(
				'workos_authkit_factor_not_found',
				__( 'Factor not found.', 'integration-workos' ),
				[ 'status' => 404 ]
			);
		}

		$result = workos()->api()->delete_auth_factor( $factor_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( [ 'deleted' => true ], 200 );
	}
}
