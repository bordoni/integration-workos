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
use WorkOS\Mfa\CodeGuard;
use WorkOS\Mfa\FactorService;
use WorkOS\Mfa\PendingLogin;
use WorkOS\RateLimit\TieredRateLimiter;
use WorkOS\User;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * MFA (TOTP + SMS) endpoints used during login *and* account settings.
 *
 * During login, the browser carries the plugin's `mfa_token` (WorkOS or
 * the plugin's MFA policy asked for a code, or the user must enroll first;
 * see `Mfa\PendingLogin`). WorkOS's pending token never leaves the server:
 *
 *  - POST /auth/mfa/enroll     — create the factor to enroll.
 *  - POST /auth/mfa/challenge  — start a challenge on one of the sign-in's factors.
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
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return new WP_Error(
				'workos_authkit_not_logged_in',
				__( 'You must be signed in to manage MFA factors.', 'integration-workos' ),
				[ 'status' => 401 ]
			);
		}

		/** This filter is documented in src/WorkOS/Mfa/RestApi.php */
		if ( ! apply_filters( 'workos_mfa_can_manage', true, $user_id ) ) {
			return new WP_Error(
				'workos_mfa_forbidden',
				__( 'You can’t manage two-step verification for this account.', 'integration-workos' ),
				[ 'status' => 403 ]
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

		// Only a parked sign-in may challenge, and only its own factors.
		// The challenge is remembered so /verify can refuse any other.
		$mfa_token = (string) $request->get_param( 'mfa_token' );
		$pending   = PendingLogin::get( $mfa_token, $profile->get_slug() );
		if ( null === $pending ) {
			return $this->expired();
		}
		if ( ! in_array( $factor_id, (array) $pending['factor_ids'], true ) ) {
			return $this->factor_not_found();
		}

		$result = workos()->api()->challenge_auth_factor( $factor_id );
		if ( is_wp_error( $result ) ) {
			return FactorService::without_body( $result );
		}

		$challenge_id = (string) ( $result['id'] ?? '' );

		$pending['challenges'][]                       = $challenge_id;
		$pending['challenge_factors'][ $challenge_id ] = $factor_id;
		PendingLogin::update( $mfa_token, $pending );

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

		return $this->verify_pending( $request, $profile, (string) $request->get_param( 'mfa_token' ) );
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
			// The policy said enroll, but a factor may have appeared since
			// (another tab, the WorkOS dashboard). Enrolling now would add a
			// second authenticator without a code from the first.
			$existing = $service->list_factors( (int) $pending['user_id'] );
			if ( is_wp_error( $existing ) ) {
				return FactorService::without_body( $existing );
			}
			if ( ! empty( $existing ) ) {
				PendingLogin::forget( $mfa_token );
				return $this->expired();
			}

			$result = $service->start_enrollment( (int) $pending['user_id'] );
		}

		if ( is_wp_error( $result ) ) {
			return FactorService::without_body( $result );
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
			$guard   = App::container()->get( CodeGuard::class );
			$subject = '' !== (string) ( $pending['workos_user_id'] ?? '' )
				? CodeGuard::for_workos_user( (string) $pending['workos_user_id'] )
				: 'pending:' . hash( 'sha256', (string) $pending['pending_authentication_token'] );

			$begun = $guard->begin( $subject );
			if ( is_wp_error( $begun ) ) {
				return $begun;
			}

			$workos_response = workos()->api()->authenticate_with_totp(
				(string) $pending['pending_authentication_token'],
				$challenge_id,
				$code,
				$this->get_radar_token( $request )
			);

			if ( $this->is_wrong_code( $workos_response ) ) {
				$guard->failed( $subject );
				return $this->wrong_code( $mfa_token, $pending, 0 );
			}

			$guard->passed( $subject );
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

			if ( ! empty( $pending['enroll'] ) && ! empty( $result['user']['id'] ) && '' !== $factor_id ) {
				$service->note_enrolled( (int) $result['user']['id'], $factor_id );
			}

			return new WP_REST_Response( $result, 200 );
		}

		$user_id = (int) $pending['user_id'];
		$checked = ! empty( $pending['enroll'] )
			? $service->confirm_enrollment( $user_id, $factor_id, $code, $challenge_id )
			: $service->check_code( $user_id, $this->challenged_factor( $pending, $challenge_id, $factor_id ), $code, $challenge_id );

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
	 * The factor a remembered challenge was made for.
	 *
	 * @param array  $pending      Parked sign-in.
	 * @param string $challenge_id Challenge ID.
	 * @param string $fallback     Factor to use for records without the map.
	 *
	 * @return string
	 */
	private function challenged_factor( array $pending, string $challenge_id, string $fallback ): string {
		$map = (array) ( $pending['challenge_factors'] ?? [] );

		return (string) ( $map[ $challenge_id ] ?? $fallback );
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
		$factors = App::container()->get( FactorService::class )->list_factors( get_current_user_id() );
		if ( is_wp_error( $factors ) ) {
			return FactorService::without_body( $factors );
		}

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

		$user_id = get_current_user_id();
		$rate_ok = $this->rate_limit( [ [ 'mfa_enroll_user', (string) $user_id, TieredRateLimiter::REQUEST_SUBJECT ] ] );
		if ( is_wp_error( $rate_ok ) ) {
			return $rate_ok;
		}

		// Same path as the account panel: a current code first when the
		// account already has a factor, and the new one stays unconfirmed
		// until `POST /users/{id}/mfa/confirm` checks its first code.
		$service = App::container()->get( FactorService::class );
		$holder  = $service->require_current_factor( $user_id, (string) $request->get_param( 'factor_id' ), (string) $request->get_param( 'code' ) );
		if ( is_wp_error( $holder ) ) {
			return FactorService::without_body( $holder );
		}

		$result = $service->start_enrollment( $user_id );
		if ( is_wp_error( $result ) ) {
			return FactorService::without_body( $result );
		}

		return new WP_REST_Response( $result, 201 );
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

		$user_id        = get_current_user_id();
		$workos_user_id = User::get_workos_id( $user_id );
		if ( '' === $workos_user_id ) {
			return new WP_Error(
				'workos_authkit_no_workos_user',
				__( 'Your account is not linked to a WorkOS user.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		$rate_ok = $this->rate_limit( [ [ 'mfa_enroll_user', (string) $user_id, TieredRateLimiter::REQUEST_SUBJECT ] ] );
		if ( is_wp_error( $rate_ok ) ) {
			return $rate_ok;
		}

		// A borrowed session mustn't add the attacker's phone as a second
		// factor: with a factor already on the account, a current code first.
		$service = App::container()->get( FactorService::class );
		$holder  = $service->require_current_factor( $user_id, (string) $request->get_param( 'factor_id' ), (string) $request->get_param( 'code' ) );
		if ( is_wp_error( $holder ) ) {
			return FactorService::without_body( $holder );
		}

		$result = workos()->api()->enroll_sms_factor( $workos_user_id, $phone_number );
		if ( is_wp_error( $result ) ) {
			return FactorService::without_body( $result );
		}

		$service->note_enrolled( $user_id, (string) ( $result['id'] ?? '' ), 'sms' );

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
	 * Removes an MFA factor the *current* user owns, given a current `code`
	 * from it. The ownership check
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

		$user_id = get_current_user_id();
		$rate_ok = $this->rate_limit( [ [ 'mfa_delete_user', (string) $user_id, TieredRateLimiter::REQUEST_SUBJECT ] ] );
		if ( is_wp_error( $rate_ok ) ) {
			return $rate_ok;
		}

		$service = App::container()->get( FactorService::class );
		$factors = $service->list_factors( $user_id );
		if ( is_wp_error( $factors ) ) {
			return FactorService::without_body( $factors );
		}

		// Return 404 rather than 403 so we don't leak the existence of
		// factor IDs that belong to other users.
		if ( ! in_array( $factor_id, array_column( $factors, 'id' ), true ) ) {
			return $this->factor_not_found();
		}

		// A current code from the factor, so a borrowed session can't turn
		// MFA off.
		$valid = $service->check_code( $user_id, $factor_id, (string) $request->get_param( 'code' ) );
		if ( is_wp_error( $valid ) ) {
			return FactorService::without_body( $valid );
		}

		$result = $service->delete_factor( $user_id, $factor_id );
		if ( is_wp_error( $result ) ) {
			return FactorService::without_body( $result );
		}

		return new WP_REST_Response( [ 'deleted' => true ], 200 );
	}
}
