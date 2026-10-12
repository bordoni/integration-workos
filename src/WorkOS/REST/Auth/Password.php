<?php
/**
 * Password-based AuthKit endpoints.
 *
 * @package WorkOS\REST\Auth
 */

namespace WorkOS\REST\Auth;

use WorkOS\Auth\AuthKit\FrontendRoute;
use WorkOS\Auth\AuthKit\Profile;
use WorkOS\RateLimit\TieredRateLimiter;
use WorkOS\Auth\PasswordResetAdmin\RedirectValidator;
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

		// Two subjects, deliberately different. Mail is counted against the
		// mailbox, since tag and dot variants of one inbox would otherwise
		// each get an allowance. Password guessing is counted against the
		// address as typed, because that is what WorkOS keys an account on:
		// `alice@` and `alice+work@` can both be registered, and merging
		// them would let an attacker on one spend the other's budget.
		$mailbox = $this->canonicalizer->canonical( $email );

		$ip      = $this->client_ip->get();
		$rate_ok = $this->rate_limit(
			[
				[ 'password_ip', $ip, TieredRateLimiter::REQUEST_IP ],
				[ 'password_email', $email, TieredRateLimiter::REQUEST_SUBJECT ],
			]
		);
		if ( is_wp_error( $rate_ok ) ) {
			return $rate_ok;
		}

		// Reserve BEFORE calling WorkOS: authenticating an unverified account
		// makes WorkOS mail a verification code as a side effect, so by the
		// time we see the response the email is already out. Refunded below
		// when the outcome sent nothing.
		$reserved = $this->rate_limit(
			[
				[ self::BUCKET_PASSWORD_OTP_IP, $ip, TieredRateLimiter::SEND_IP ],
				[ self::BUCKET_PASSWORD_OTP_RECIPIENT, $mailbox, TieredRateLimiter::SEND_RECIPIENT ],
			]
		);
		if ( is_wp_error( $reserved ) ) {
			return $reserved;
		}

		$workos_response = workos()->api()->authenticate_with_password(
			$email,
			$password,
			$this->get_radar_token( $request )
		);

		// An unverified account already passed the password check; the
		// fallback would only sync it again and mail a second code.
		if ( is_wp_error( $workos_response ) && ! $this->triggered_verification_email( $workos_response ) && workos()->option( 'allow_password_fallback', true ) ) {
			// WorkOS rejected the password — fall back to WordPress authentication.
			// Handles migrated users whose passwords were never synced to WorkOS.
			$wp_user = wp_authenticate( $email, $password );

			if ( ! is_wp_error( $wp_user ) ) {
				$workos_user_id = $this->resolve_workos_user_id( $wp_user, $email );

				if ( $workos_user_id ) {
					if ( workos()->option( 'wp_password_fallback_email_confirmation', false ) ) {
						// Email confirmation path: identity is verified via a magic code
						// instead of syncing the plaintext password to WorkOS. This send
						// consumes the OTP slot reserved above; the early return skips
						// the refund on purpose.
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

		// Every path that reaches here without a verification-required error
		// sent no email (completed login, wrong password, other API errors),
		// so the reserved OTP slot goes back.
		if ( ! $this->triggered_verification_email( $workos_response ) ) {
			$this->rate_limiter->refund( self::BUCKET_PASSWORD_OTP_IP, $ip, TieredRateLimiter::SEND_IP );
			$this->rate_limiter->refund( self::BUCKET_PASSWORD_OTP_RECIPIENT, $mailbox, TieredRateLimiter::SEND_RECIPIENT );
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

		$mailbox = $this->canonicalizer->canonical( $email );

		$ip      = $this->client_ip->get();
		$rate_ok = $this->rate_limit(
			[
				[ 'pw_reset_ip', $ip, TieredRateLimiter::REQUEST_IP ],
				[ 'pw_reset_email', $mailbox, TieredRateLimiter::REQUEST_SUBJECT ],
			]
		);
		if ( is_wp_error( $rate_ok ) ) {
			return $rate_ok;
		}

		// Charged whether or not WorkOS ends up mailing, so the 429 boundary
		// is the same for existing and unknown accounts.
		$send_ok = $this->rate_limit(
			[
				[ self::BUCKET_OTP_SEND_IP, $ip, TieredRateLimiter::SEND_IP ],
				[ self::BUCKET_OTP_SEND_RECIPIENT, $mailbox, TieredRateLimiter::SEND_RECIPIENT ],
			]
		);
		if ( is_wp_error( $send_ok ) ) {
			return $send_ok;
		}

		$redirect_url = ( new RedirectValidator() )->validate(
			(string) $request->get_param( 'redirect_url' ),
			$profile
		);

		// Fire the WorkOS send call but *always* return 200 to prevent email
		// enumeration (an attacker cannot tell whether an account exists).
		// Also normalize response time: WorkOS answers valid + unknown
		// emails at visibly different latencies, which leaks existence via
		// side-channel timing despite the identical body. A fixed floor
		// flattens both paths to the same observable wall-clock.
		$start_ns = hrtime( true );

		workos()->api()->send_password_reset(
			$email,
			$this->build_password_reset_url( $profile, $redirect_url ),
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

		$ip      = $this->client_ip->get();
		$rate_ok = $this->rate_limit(
			[
				[ 'pw_reset_confirm_ip', $ip, TieredRateLimiter::REQUEST_IP ],
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

		$redirect_url = ( new RedirectValidator() )->validate(
			(string) $request->get_param( 'redirect_url' ),
			$profile
		);

		// Mirror the new password into the linked WP user so the WordPress
		// password-fallback path (e.g. `?fallback=1` on wp-login.php, the
		// `wp_authenticate` filter, REST app passwords) stays in sync with
		// what the user just chose in the React shell. Runs *before*
		// LoginCompleter so the auth cookie minted below survives the
		// session invalidation `wp_set_password()` performs.
		$this->mirror_password_to_wp_user( $workos_response, $new_password );

		// Default response shape — the "reset finished, please sign in" path.
		$response_body = [
			'ok'           => true,
			'redirect_url' => $redirect_url,
		];

		// Auto-login path. Profile toggle controls whether the reset confirm
		// drops the user back at the sign-in screen or signs them in
		// straight away. We feed the new password back into the password
		// authenticate call and reuse LoginCompleter so MFA / org selection
		// / entitlement gates behave the same as a normal sign-in.
		if ( $profile->is_auto_login_after_reset_enabled() ) {
			$email = $this->extract_email( $workos_response );
			if ( '' !== $email ) {
				$auth_response = workos()->api()->authenticate_with_password(
					$email,
					$new_password,
					$this->get_radar_token( $request )
				);

				$result = $this->login_completer->complete(
					$auth_response,
					$profile,
					$redirect_url
				);

				if ( ! is_wp_error( $result ) ) {
					// Either a finished login (with `user` + `redirect_to`) or
					// an MFA challenge — both shapes are useful to the React
					// shell. Surface the validated post-reset URL as the
					// authoritative `redirect_to` when LoginCompleter didn't
					// emit one of its own.
					if ( isset( $result['redirect_to'] ) && '' === $result['redirect_to'] ) {
						$result['redirect_to'] = $redirect_url;
					}
					$response_body = array_merge(
						$response_body,
						$result,
						[ 'signed_in' => empty( $result['mfa_required'] ) ]
					);
				}
				// On WP_Error from authenticate or LoginCompleter, fall through
				// to the plain "reset finished" response. The password change
				// still succeeded — the user can sign in manually.
			}
		}

		return new WP_REST_Response( $response_body, 200 );
	}

	/**
	 * Whether a WorkOS authenticate response means a verification email
	 * was sent as a side effect.
	 *
	 * WorkOS mails a one-time code before we ever see the
	 * `email_verification_required` response, unless its email sending is
	 * disabled. We can't tell that setting from here, so the slot stays
	 * charged either way.
	 *
	 * @param mixed $workos_response Response from authenticate_with_password.
	 *
	 * @return bool
	 */
	private function triggered_verification_email( $workos_response ): bool {
		if ( ! is_wp_error( $workos_response ) ) {
			return false;
		}

		$data = $workos_response->get_error_data();

		return 'email_verification_required' === ( $data['body']['code'] ?? '' );
	}

	/**
	 * Mirror a freshly-reset WorkOS password into the linked WP user row.
	 *
	 * The WP password fallback (`?fallback=1`, `wp_authenticate`, REST app
	 * passwords) sits outside WorkOS. If we let it drift, a user who reset
	 * their WorkOS password could still log in via `wp_authenticate` using
	 * the old credential — confusing at best, a security regression at worst.
	 * Best-effort: a missing link, a WP user without the meta, or a WP error
	 * never fails the reset itself; the password change against WorkOS has
	 * already succeeded by the time we get here.
	 *
	 * @param mixed  $workos_response Parsed reset_password response (carries
	 *                                the `user` object with the WorkOS id).
	 * @param string $new_password    Plaintext password the user just set.
	 *
	 * @return void
	 */
	private function mirror_password_to_wp_user( $workos_response, string $new_password ): void {
		if ( ! is_array( $workos_response ) ) {
			return;
		}

		$workos_user_id = (string) ( $workos_response['user']['id'] ?? '' );
		if ( '' === $workos_user_id ) {
			return;
		}

		$wp_user_id = \WorkOS\Sync\UserSync::get_wp_user_id_by_workos_id( $workos_user_id );
		if ( $wp_user_id <= 0 ) {
			return;
		}

		// `wp_set_password()` hashes the password and invalidates all
		// existing sessions for the user — exactly what we want here.
		wp_set_password( $new_password, $wp_user_id );
	}

	/**
	 * Pull the user's email out of a WorkOS API response.
	 *
	 * `reset_password` and `authenticate_with_password` both nest the user
	 * object under `user`; older shapes occasionally surfaced fields at the
	 * top level, so check both before giving up.
	 *
	 * @param mixed $workos_response Parsed WorkOS response body.
	 *
	 * @return string Lowercased email, or empty if absent / unparseable.
	 */
	private function extract_email( $workos_response ): string {
		if ( ! is_array( $workos_response ) ) {
			return '';
		}

		$candidates = [
			$workos_response['user']['email'] ?? null,
			$workos_response['email'] ?? null,
		];

		foreach ( $candidates as $candidate ) {
			if ( is_string( $candidate ) && '' !== $candidate ) {
				return strtolower( trim( $candidate ) );
			}
		}

		return '';
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
	 * Points at the AuthKit React shell at /workos/login/{slug}, which
	 * already mounts directly into the `reset_confirm` step when `token`
	 * is present in the URL (FrontendRoute::maybe_render). WorkOS appends
	 * its generated reset token as `&token=…` before sending the email.
	 *
	 * @param Profile $profile      Active profile.
	 * @param string  $redirect_url Optional same-host URL to send the user to
	 *                              after they finish resetting.
	 *
	 * @return string
	 */
	private function build_password_reset_url( Profile $profile, string $redirect_url = '' ): string {
		// `home_url()` runs through filters that may HTML-encode `&`. Decode
		// the base before `add_query_arg()` so `&#038;` doesn't shear the
		// query off (see prior fix: commits 750dfa5 / f040ac4), AND decode
		// the final URL — a same-host `redirect_to` may carry its own
		// escaped ampersands through, and WorkOS emails the URL verbatim,
		// so any entity left in == a broken link out.
		$base = html_entity_decode(
			FrontendRoute::url_for_profile( $profile ),
			ENT_QUOTES | ENT_HTML5
		);

		$args = [];
		if ( '' !== $redirect_url ) {
			$args['redirect_to'] = $redirect_url;
		}

		$url = $args ? add_query_arg( $args, $base ) : $base;

		return html_entity_decode( $url, ENT_QUOTES | ENT_HTML5 );
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
