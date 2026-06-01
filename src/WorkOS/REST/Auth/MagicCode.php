<?php
/**
 * Magic-code AuthKit endpoints.
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
 * POST /auth/magic/send    — send a magic-code email.
 * POST /auth/magic/verify  — exchange a magic-code for a session.
 */
class MagicCode extends BaseEndpoint {

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
			self::BASE . '/magic/send',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'send' ],
				'permission_callback' => [ $this, 'public_permission' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			self::BASE . '/magic/verify',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'verify' ],
				'permission_callback' => [ $this, 'public_permission' ],
			]
		);
	}

	/**
	 * POST /auth/magic/send
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function send( WP_REST_Request $request ) {
		$profile = $this->resolve_profile( $request );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		$nonce_ok = $this->verify_nonce( $request, $profile );
		if ( is_wp_error( $nonce_ok ) ) {
			return $nonce_ok;
		}

		if ( ! $profile->has_method( Profile::METHOD_MAGIC_CODE ) ) {
			return new WP_Error(
				'workos_authkit_method_disabled',
				__( 'Magic-code sign-in is not enabled for this login.', 'integration-workos' ),
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
				[ 'magic_send_ip', $ip, self::RATE_LIMIT_IP_ATTEMPTS, self::RATE_LIMIT_WINDOW ],
				[ 'magic_send_email', $email, self::RATE_LIMIT_EMAIL_ATTEMPTS, self::RATE_LIMIT_WINDOW ],
			]
		);
		if ( is_wp_error( $rate_ok ) ) {
			return $rate_ok;
		}

		// If email registration is disabled, only known accounts get a code.
		// For unknown emails, skip the WorkOS call but still return success
		// so account existence isn't exposed.
		if ( $this->registration_allowed( $profile ) || get_user_by( 'email', $email ) ) {
			// Fire-and-forget on the WorkOS call: delivery errors land in the
			// plugin log rather than being surfaced to the client.
			workos()->api()->send_magic_auth_code(
				$email,
				$this->get_radar_token( $request )
			);
		}

		return new WP_REST_Response(
			[
				'ok'      => true,
				'message' => __( 'If an account exists for this email, a code is on its way.', 'integration-workos' ),
			],
			200
		);
	}

	/**
	 * POST /auth/magic/verify
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

		if ( ! $profile->has_method( Profile::METHOD_MAGIC_CODE ) ) {
			return new WP_Error(
				'workos_authkit_method_disabled',
				__( 'Magic-code sign-in is not enabled for this login.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		$email              = strtolower( trim( (string) $request->get_param( 'email' ) ) );
		$code               = (string) $request->get_param( 'code' );
		$pending_auth_token = (string) $request->get_param( 'pending_authentication_token' );

		if ( '' === $email || '' === $code ) {
			return new WP_Error(
				'workos_authkit_invalid_input',
				__( 'An email and code are required.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		$ip      = $this->rate_limiter->client_ip();
		$rate_ok = $this->rate_limit(
			[
				[ 'magic_verify_ip', $ip, self::RATE_LIMIT_IP_ATTEMPTS, self::RATE_LIMIT_WINDOW ],
				[ 'magic_verify_email', $email, self::RATE_LIMIT_EMAIL_ATTEMPTS, self::RATE_LIMIT_WINDOW ],
			]
		);
		if ( is_wp_error( $rate_ok ) ) {
			return $rate_ok;
		}

		// Ensure verify never creates accounts when registration is disabled.
		// `send` skips unknown emails; this prevents race conditions or direct
		// API calls from provisioning users. Return a generic invalid-code error
		// to avoid account enumeration.
		if ( ! $this->registration_allowed( $profile ) && ! get_user_by( 'email', $email ) ) {
			return new WP_Error(
				'workos_authkit_invalid_code',
				__( 'That code is invalid or has expired.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		$workos_response = workos()->api()->authenticate_with_magic_auth(
			$email,
			$code,
			'' !== $pending_auth_token ? $pending_auth_token : null,
			$this->get_radar_token( $request )
		);

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
	 * Whether email-code sign-in may create a new account for the given profile.
	 *
	 * Scoped per form: the legacy customer profile reads its own admin toggle
	 * (`allow_legacy_magic_code_registration`) so it can be locked down without
	 * affecting the default sign-in, which keeps creating accounts for new
	 * customers (`allow_magic_code_registration`). Both default to true to
	 * preserve historical behaviour. The legacy profile slug defaults to
	 * `legacy` (matching the portal's /login/legacy/ form) and is filterable.
	 *
	 * @param Profile $profile Resolved login profile for the request.
	 *
	 * @return bool
	 */
	private function registration_allowed( Profile $profile ): bool {
		$legacy_slug = (string) apply_filters( 'workos_legacy_profile_slug', 'legacy' );

		$option = $profile->get_slug() === $legacy_slug
			? 'allow_legacy_magic_code_registration' // legacy form toggle
			: 'allow_magic_code_registration'; // default form toggle

		return (bool) workos()->option( $option, true );
	}
}
