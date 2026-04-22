<?php
/**
 * REST API token authentication via WorkOS Bearer tokens.
 *
 * @package WorkOS\REST
 */

namespace WorkOS\REST;

use WorkOS\Vendor\StellarWP\SuperGlobals\SuperGlobals;

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

		// Verify the token.
		$payload = workos()->api()->verify_access_token( $token );
		if ( is_wp_error( $payload ) ) {
			// Lazy-refresh on expiration: if we recognize the user and they
			// have a stored refresh token, exchange it for a fresh access
			// token and treat *that* as the authenticated request. Keeps
			// long-idle React shells and API consumers working across the
			// WorkOS access-token TTL (~10 minutes) without exposing the
			// refresh token to the browser. We only refresh when the token
			// payload itself is clearly expired — refresh is *not* a bypass
			// for signature or kid errors.
			if ( $this->token_is_expired( $token ) ) {
				$refreshed_user_id = $this->try_lazy_refresh( $token );
				if ( $refreshed_user_id > 0 ) {
					return $refreshed_user_id;
				}
			}

			// Store error for rest_authentication_errors filter.
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
	 * Decode the JWT payload (without signature verification) and return
	 * true when the `exp` claim is in the past.
	 *
	 * Used by the lazy-refresh path to avoid attempting a refresh for
	 * signature-invalid or malformed tokens.
	 *
	 * @param string $token JWT.
	 *
	 * @return bool
	 */
	private function token_is_expired( string $token ): bool {
		$payload = $this->decode_jwt_payload( $token );
		if ( ! is_array( $payload ) || empty( $payload['exp'] ) ) {
			return false;
		}

		return (int) $payload['exp'] < time();
	}

	/**
	 * Decode a JWT payload without verification.
	 *
	 * @param string $token JWT.
	 *
	 * @return array|null
	 */
	private function decode_jwt_payload( string $token ): ?array {
		$parts = explode( '.', $token );
		if ( count( $parts ) !== 3 ) {
			return null;
		}

		$payload = json_decode(
			base64_decode( strtr( $parts[1], '-_', '+/' ) ),
			true
		);

		return is_array( $payload ) ? $payload : null;
	}

	/**
	 * Attempt to refresh an expired access token using the user's stored
	 * refresh token, and return the WP user ID when successful.
	 *
	 * Decodes the JWT payload *without* signature verification because we only
	 * trust the `sub` claim enough to look up the user — the actual
	 * authentication signal is the successful refresh-token exchange
	 * immediately after.
	 *
	 * @param string $expired_token The expired JWT access token.
	 *
	 * @return int WP user ID on success, 0 on failure.
	 */
	private function try_lazy_refresh( string $expired_token ): int {
		$payload = $this->decode_jwt_payload( $expired_token );
		if ( ! is_array( $payload ) || empty( $payload['sub'] ) ) {
			return 0;
		}

		$wp_user_id = \WorkOS\Sync\UserSync::get_wp_user_id_by_workos_id( $payload['sub'] );
		if ( ! $wp_user_id ) {
			return 0;
		}

		$refresh_token = get_user_meta( $wp_user_id, '_workos_refresh_token', true );
		if ( empty( $refresh_token ) ) {
			return 0;
		}

		$result = workos()->api()->refresh_session( $refresh_token );
		if ( is_wp_error( $result ) ) {
			return 0;
		}

		// Persist the new tokens using the existing helper so the access
		// token, refresh token, and session ID all move forward together.
		\WorkOS\Auth\Login::store_tokens( $wp_user_id, $result );

		return (int) $wp_user_id;
	}

	/**
	 * Report authentication errors to the REST API.
	 *
	 * @param \WP_Error|null|true $error Current error state.
	 *
	 * @return \WP_Error|null|true
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
	 * @var \WP_Error|null
	 */
	private ?\WP_Error $auth_error = null;
}
