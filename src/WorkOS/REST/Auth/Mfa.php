<?php
/**
 * Multi-factor authentication AuthKit endpoints.
 *
 * @package WorkOS\REST\Auth
 */

namespace WorkOS\REST\Auth;

use WorkOS\ActivityLog\EventLogger;
use WorkOS\App;
use WorkOS\Auth\AuthKit\Profile;
use WorkOS\Auth\Deactivation;
use WorkOS\Mfa\FactorService;
use WorkOS\Mfa\PendingLogin;
use WorkOS\RateLimit\TieredRateLimiter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * MFA (TOTP + SMS) endpoints used during login *and* account settings.
 *
 * During login, the browser carries either WorkOS's
 * `pending_authentication_token` (WorkOS asked for a code) or the plugin's
 * `mfa_token` (the plugin's MFA policy asked for a code, or the user must
 * enroll first; see `Mfa\PendingLogin`):
 *
 *  - POST /auth/mfa/enroll     — create the factor to enroll (`mfa_token` only).
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
			self::BASE . '/mfa/enroll',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'enroll_pending' ],
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

		// Second bucket keyed on the factor id closes a gap in the
		// IP-only limit: a user with multiple enrolled factors could
		// otherwise be targeted by rotating `factor_id` values from a
		// single IP and collecting a fresh OTP on each one.
		$rate_ok = $this->rate_limit(
			[
				[ 'mfa_challenge_ip', $this->client_ip->get(), TieredRateLimiter::REQUEST_IP ],
				[ 'mfa_challenge_factor', $factor_id, TieredRateLimiter::REQUEST_SUBJECT ],
			]
		);
		if ( is_wp_error( $rate_ok ) ) {
			return $rate_ok;
		}

		// A parked sign-in may only challenge its own factors, and the
		// challenge is remembered so /verify can refuse any other.
		$mfa_token = (string) $request->get_param( 'mfa_token' );
		$pending   = null;
		if ( '' !== $mfa_token ) {
			$pending = PendingLogin::get( $mfa_token, $profile->get_slug() );
			if ( null === $pending ) {
				return $this->expired();
			}
			if ( ! in_array( $factor_id, (array) $pending['factor_ids'], true ) ) {
				return $this->factor_not_found();
			}
		}

		$result = workos()->api()->challenge_auth_factor( $factor_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( null !== $pending ) {
			$pending['challenges'][] = (string) ( $result['id'] ?? '' );
			PendingLogin::update( $mfa_token, $pending );
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

		$mfa_token = (string) $request->get_param( 'mfa_token' );
		if ( '' !== $mfa_token ) {
			return $this->verify_pending( $request, $profile, $mfa_token );
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
				[ 'mfa_verify_ip', $this->client_ip->get(), TieredRateLimiter::REQUEST_IP ],
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

		// `complete()` handles the `organization_selection_required` error
		// transparently when the profile has a pinned org. Pass the WP_Error
		// through so it can decide. A wrong code comes back as an error, so
		// any success reaching the profile's `always` check has passed MFA.
		$result = $this->login_completer->complete(
			$workos_response,
			$profile,
			$this->sanitize_redirect( (string) $request->get_param( 'redirect_to' ) ),
			true,
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * POST /auth/mfa/enroll
	 *
	 * Creates the TOTP factor a parked sign-in must enroll, and returns the
	 * QR code and secret once. Calling it again replaces the unfinished
	 * factor. The first code from the app goes to /verify.
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function enroll_pending( WP_REST_Request $request ) {
		$profile = $this->resolve_profile( $request );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		$nonce_ok = $this->verify_nonce( $request, $profile );
		if ( is_wp_error( $nonce_ok ) ) {
			return $nonce_ok;
		}

		$mfa_token = (string) $request->get_param( 'mfa_token' );
		$pending   = PendingLogin::get( $mfa_token, $profile->get_slug() );
		if ( null === $pending ) {
			return $this->expired();
		}

		if ( empty( $pending['enroll'] ) ) {
			return new WP_Error(
				'workos_authkit_invalid_input',
				__( 'This sign-in already has a factor to verify.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! $profile->allows_factor( Profile::FACTOR_TOTP ) ) {
			return new WP_Error(
				'workos_authkit_factor_disabled',
				__( 'TOTP is not enabled for this login.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		$rate_ok = $this->rate_limit(
			[
				[ 'mfa_enroll_ip', $this->client_ip->get(), TieredRateLimiter::REQUEST_IP ],
				[ 'mfa_enroll_token', hash( 'sha256', $mfa_token ), TieredRateLimiter::REQUEST_SUBJECT ],
			]
		);
		if ( is_wp_error( $rate_ok ) ) {
			return $rate_ok;
		}

		$service = App::container()->get( FactorService::class );

		if ( PendingLogin::KIND_WORKOS === $pending['kind'] ) {
			foreach ( (array) $pending['factor_ids'] as $unfinished ) {
				workos()->api()->delete_auth_factor( (string) $unfinished );
			}
			$result = $service->enroll_totp( (string) $pending['workos_user_id'], (string) ( $pending['email'] ?? '' ) );
		} else {
			$result = $service->start_enrollment( (int) $pending['user_id'] );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$pending['factor_ids'] = [ $result['factor_id'] ];
		$pending['challenges'] = '' !== $result['challenge_id'] ? [ $result['challenge_id'] ] : [];
		PendingLogin::update( $mfa_token, $pending );

		return new WP_REST_Response( $result, 201 );
	}

	/**
	 * Finish a parked sign-in with a code.
	 *
	 * Plugin kind: checks the code against the user's factor (confirming it
	 * first when enrolling), then signs in from the parked WorkOS response.
	 * WorkOS kind: completes WorkOS's pending authentication with the code.
	 *
	 * @param WP_REST_Request $request   REST request.
	 * @param Profile         $profile   Active profile.
	 * @param string          $mfa_token Token from the browser.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	private function verify_pending( WP_REST_Request $request, Profile $profile, string $mfa_token ) {
		$pending = PendingLogin::get( $mfa_token, $profile->get_slug() );
		if ( null === $pending ) {
			return $this->expired();
		}

		$challenge_id = (string) $request->get_param( 'authentication_challenge_id' );
		$code         = (string) $request->get_param( 'code' );
		if ( '' === $challenge_id || '' === $code ) {
			return new WP_Error(
				'workos_authkit_invalid_input',
				__( 'A challenge id and code are required.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! in_array( $challenge_id, (array) $pending['challenges'], true ) ) {
			return new WP_Error(
				'workos_authkit_challenge_not_found',
				__( 'That code request has expired. Ask for a new code and try again.', 'integration-workos' ),
				[ 'status' => 404 ]
			);
		}

		$rate_ok = $this->rate_limit(
			[
				[ 'mfa_verify_ip', $this->client_ip->get(), TieredRateLimiter::REQUEST_IP ],
				[ 'mfa_verify_token', hash( 'sha256', $mfa_token ), TieredRateLimiter::REQUEST_SUBJECT ],
			]
		);
		if ( is_wp_error( $rate_ok ) ) {
			return $rate_ok;
		}

		$service   = App::container()->get( FactorService::class );
		$factor_id = (string) ( ( (array) $pending['factor_ids'] )[0] ?? '' );

		if ( PendingLogin::KIND_WORKOS === $pending['kind'] ) {
			$workos_response = workos()->api()->authenticate_with_totp(
				(string) $pending['pending_authentication_token'],
				$challenge_id,
				$code,
				$this->get_radar_token( $request )
			);

			if ( $this->is_wrong_code( $workos_response ) ) {
				return $this->wrong_code( $mfa_token, $pending, 0 );
			}

			PendingLogin::forget( $mfa_token );

			$result = $this->login_completer->complete(
				$workos_response,
				$profile,
				(string) $pending['redirect_to'],
				(bool) $pending['honor_profile_redirect'],
				true
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( ! empty( $result['user']['id'] ) && '' !== $factor_id ) {
				$service->note_enrolled( (int) $result['user']['id'], $factor_id );
			}

			return new WP_REST_Response( $result, 200 );
		}

		$user_id = (int) $pending['user_id'];
		$checked = ! empty( $pending['enroll'] )
			? $service->confirm_enrollment( $user_id, $factor_id, $code, $challenge_id )
			: $service->verify_code( $factor_id, $code, $challenge_id );

		if ( is_wp_error( $checked ) ) {
			return 'workos_mfa_invalid_code' === $checked->get_error_code()
				? $this->wrong_code( $mfa_token, $pending, $user_id )
				: $checked;
		}

		PendingLogin::forget( $mfa_token );

		if ( empty( $pending['enroll'] ) ) {
			EventLogger::log(
				'mfa.challenge_succeeded',
				[
					'user_id'  => $user_id,
					'metadata' => [ 'factor_type' => 'totp' ],
				]
			);

			/**
			 * Fires after a user answers the plugin's MFA challenge at sign-in.
			 *
			 * @param int $user_id WP user ID.
			 */
			do_action( 'workos_mfa_challenge_succeeded', $user_id );
		}

		$wp_user = get_userdata( $user_id );
		if ( ! $wp_user ) {
			return $this->expired();
		}

		// Ten minutes may have passed since the first factor.
		if ( Deactivation::is_deactivated( $user_id ) ) {
			return Deactivation::error();
		}

		return new WP_REST_Response(
			$this->login_completer->finish(
				(array) $pending['response'],
				$wp_user,
				$profile,
				(string) $pending['redirect_to'],
				(bool) $pending['honor_profile_redirect']
			),
			200
		);
	}

	/**
	 * Whether WorkOS refused the TOTP grant because of the code.
	 *
	 * Any 4xx except the steps `LoginCompleter` carries on from (an org
	 * to pick, an email to verify).
	 *
	 * @param array|WP_Error $response WorkOS authenticate response.
	 *
	 * @return bool
	 */
	private function is_wrong_code( $response ): bool {
		if ( ! is_wp_error( $response ) ) {
			return false;
		}

		$data   = $response->get_error_data();
		$status = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
		$code   = is_array( $data ) ? (string) ( $data['body']['code'] ?? '' ) : '';

		return $status >= 400 && $status < 500
			&& ! in_array( $code, [ 'organization_selection_required', 'email_verification_required' ], true );
	}

	/**
	 * Count a wrong code on a parked sign-in.
	 *
	 * @param string $mfa_token Token from the browser.
	 * @param array  $pending   Parked sign-in.
	 * @param int    $user_id   WP user, or 0 when not matched yet (WorkOS kind).
	 *
	 * @return WP_Error
	 */
	private function wrong_code( string $mfa_token, array $pending, int $user_id ): WP_Error {
		if ( $user_id > 0 && empty( $pending['enroll'] ) ) {
			EventLogger::log(
				'mfa.challenge_failed',
				[
					'user_id'  => $user_id,
					'metadata' => [ 'factor_type' => 'totp' ],
				]
			);

			/**
			 * Fires after a wrong code at the plugin's MFA challenge.
			 *
			 * @param int    $user_id    WP user ID.
			 * @param string $error_code Error code.
			 */
			do_action( 'workos_mfa_challenge_failed', $user_id, 'workos_mfa_invalid_code' );
		}

		if ( ! PendingLogin::fail( $mfa_token, $pending ) ) {
			return new WP_Error(
				'workos_authkit_mfa_attempts_exceeded',
				__( 'Too many wrong codes. Start signing in again.', 'integration-workos' ),
				[ 'status' => 403 ]
			);
		}

		return new WP_Error(
			'workos_mfa_invalid_code',
			__( 'That code didn’t work. Check your authenticator app and try again.', 'integration-workos' ),
			[ 'status' => 400 ]
		);
	}

	/**
	 * Error for an unknown, used or expired `mfa_token`.
	 *
	 * @return WP_Error
	 */
	private function expired(): WP_Error {
		return new WP_Error(
			'workos_authkit_mfa_expired',
			__( 'This sign-in has expired. Start signing in again.', 'integration-workos' ),
			[ 'status' => 403 ]
		);
	}

	/**
	 * Error for a factor the sign-in may not use.
	 *
	 * @return WP_Error
	 */
	private function factor_not_found(): WP_Error {
		return new WP_Error(
			'workos_authkit_factor_not_found',
			__( 'Factor not found.', 'integration-workos' ),
			[ 'status' => 404 ]
		);
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

		// WorkOS nests the new factor under `authentication_factor` and adds
		// a ready challenge for it under `authentication_challenge`.
		$factor    = isset( $result['authentication_factor'] ) && is_array( $result['authentication_factor'] ) ? $result['authentication_factor'] : $result;
		$challenge = isset( $result['authentication_challenge'] ) && is_array( $result['authentication_challenge'] ) ? $result['authentication_challenge'] : [];
		$totp      = $factor['totp'] ?? [];

		return new WP_REST_Response(
			[
				'factor_id'    => (string) ( $factor['id'] ?? '' ),
				'challenge_id' => (string) ( $challenge['id'] ?? '' ),
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
			static function ( array $factor ): string {
				return (string) ( $factor['id'] ?? '' );
			},
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
