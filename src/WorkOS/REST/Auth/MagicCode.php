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

		// Fire-and-forget: return 200 regardless so the client cannot enumerate
		// registered accounts. Real errors land in the plugin log.
		workos()->api()->send_magic_auth_code(
			$email,
			$this->get_radar_token( $request )
		);

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

		$workos_response = workos()->api()->authenticate_with_magic_auth(
			$email,
			$code,
			'' !== $pending_auth_token ? $pending_auth_token : null,
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
}
