<?php
/**
 * REST API token authentication via WorkOS Bearer tokens.
 *
 * @package WorkOS\REST
 */

namespace WorkOS\REST;

use WorkOS\Vendor\StellarWP\SuperGlobals\SuperGlobals;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Adds WorkOS access token verification as a WP REST API auth method.
 */
class TokenAuth {

	/**
	 * Constructor — register the auth filter.
	 */
	public function __construct() {
		add_filter( 'determine_current_user', [ $this, 'authenticate' ], 15 );
		add_filter( 'rest_authentication_errors', [ $this, 'check_errors' ], 15 );
	}

	/**
	 * Attempt to authenticate a REST request using a WorkOS Bearer token.
	 *
	 * Runs at priority 15: after cookie auth (10), before app passwords (20).
	 *
	 * @param int|false $user_id Current user ID or false.
	 *
	 * @return int|false User ID or false.
	 */
	public function authenticate( $user_id ) {
		// Already authenticated by another method.
		if ( $user_id ) {
			return $user_id;
		}

		// Only run in REST context.
		if ( ! $this->is_rest_request() ) {
			return $user_id;
		}

		if ( ! workos()->is_enabled() ) {
			return $user_id;
		}

		$token = $this->get_bearer_token();
		if ( ! $token ) {
			return $user_id;
		}

		// Verify the token — signature AND expiration both must hold.
		//
		// We intentionally do NOT attempt a "lazy refresh" off a Bearer
		// token that fails verification. Doing so would mean trusting the
		// `sub` claim of an unsigned or expired JWT to pick whose stored
		// refresh token to exchange — an attacker-controlled identity
		// lookup. Clients that need to renew an expired access token
		// should hit POST /wp-json/workos/v1/auth/session/refresh, which
		// is authenticated via the WP auth cookie (independently-verified
		// session) rather than the Bearer token itself.
		$payload = workos()->api()->verify_access_token( $token );
		if ( is_wp_error( $payload ) ) {
			$this->auth_error = $payload;
			return $user_id;
		}

		// Map to WP user via the `sub` claim.
		$workos_user_id = $payload['sub'] ?? '';
		if ( empty( $workos_user_id ) ) {
			return $user_id;
		}

		$wp_user_id = \WorkOS\Sync\UserSync::get_wp_user_id_by_workos_id( $workos_user_id );

		return $wp_user_id ? $wp_user_id : $user_id;
	}

	/**
	 * Report authentication errors to the REST API.
	 *
	 * @param WP_Error|null|true $error Current error state.
	 *
	 * @return WP_Error|null|true
	 */
	public function check_errors( $error ) {
		if ( ! empty( $error ) ) {
			return $error;
		}

		if ( ! empty( $this->auth_error ) ) {
			return $this->auth_error;
		}

		return $error;
	}

	/**
	 * Extract Bearer token from Authorization header.
	 *
	 * @return string|null Token or null.
	 */
	private function get_bearer_token(): ?string {
		$auth_header = '';

		// Try standard header first.
		if ( null !== SuperGlobals::get_server_var( 'HTTP_AUTHORIZATION' ) ) {
			$auth_header = SuperGlobals::get_server_var( 'HTTP_AUTHORIZATION' );
		} elseif ( null !== SuperGlobals::get_server_var( 'REDIRECT_HTTP_AUTHORIZATION' ) ) {
			$auth_header = SuperGlobals::get_server_var( 'REDIRECT_HTTP_AUTHORIZATION' );
		} elseif ( function_exists( 'getallheaders' ) ) {
			$headers = getallheaders();
			if ( isset( $headers['Authorization'] ) ) {
				$auth_header = sanitize_text_field( $headers['Authorization'] );
			}
		}

		if ( ! preg_match( '/^Bearer\s+(.+)$/i', $auth_header, $matches ) ) {
			return null;
		}

		return $matches[1];
	}

	/**
	 * Check if the current request is a REST API request.
	 *
	 * @return bool
	 */
	private function is_rest_request(): bool {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}

		$rest_prefix = rest_get_url_prefix();
		$request_uri = SuperGlobals::get_server_var( 'REQUEST_URI' ) ?? '';

		return false !== strpos( $request_uri, "/{$rest_prefix}/" );
	}

	/**
	 * Stored auth error from token verification.
	 *
	 * @var WP_Error|null
	 */
	private ?WP_Error $auth_error = null;
}
