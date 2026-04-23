<?php
/**
 * Session-management AuthKit endpoints.
 *
 * @package WorkOS\REST\Auth
 */

namespace WorkOS\REST\Auth;

use WorkOS\Auth\AuthKit\Profile;
use WorkOS\Auth\Login;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * GET  /auth/nonce            — mint a profile-scoped nonce + publish Radar site key.
 * POST /auth/session/refresh  — rotate the current user's access + refresh tokens.
 * POST /auth/session/logout   — clear WP auth cookie + revoke the WorkOS session.
 */
class Session extends BaseEndpoint {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::BASE . '/nonce',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'nonce' ],
				'permission_callback' => [ $this, 'public_permission' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			self::BASE . '/session/refresh',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'refresh' ],
				'permission_callback' => [ $this, 'public_permission' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			self::BASE . '/session/logout',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'logout' ],
				'permission_callback' => [ $this, 'public_permission' ],
			]
		);
	}

	/**
	 * GET /auth/nonce?profile=slug
	 *
	 * Returns the nonce the React shell attaches to every mutation, plus
	 * the Radar public site key when configured so the browser SDK can
	 * bootstrap without a second round-trip.
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function nonce( WP_REST_Request $request ) {
		$profile = $this->resolve_profile( $request );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		return new WP_REST_Response(
			[
				'nonce'          => $this->nonce->mint( $profile->get_slug() ),
				'radar_site_key' => $this->radar->get_site_key(),
			],
			200
		);
	}

	/**
	 * POST /auth/session/refresh
	 *
	 * Exchanges the currently-logged-in user's stored refresh token for a
	 * new access/refresh pair. Requires a logged-in WP session — the
	 * refresh token never leaves PHP.
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function refresh( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return new WP_Error(
				'workos_authkit_not_logged_in',
				__( 'You are not currently signed in.', 'integration-workos' ),
				[ 'status' => 401 ]
			);
		}

		$refresh_token = (string) get_user_meta( $user_id, '_workos_refresh_token', true );
		if ( '' === $refresh_token ) {
			return new WP_Error(
				'workos_authkit_no_refresh_token',
				__( 'No refresh token is available for this session.', 'integration-workos' ),
				[ 'status' => 409 ]
			);
		}

		$workos_response = workos()->api()->refresh_session( $refresh_token );
		if ( is_wp_error( $workos_response ) ) {
			return $workos_response;
		}

		Login::store_tokens( $user_id, $workos_response );

		return new WP_REST_Response(
			[
				'ok'         => true,
				// Expose the access-token `exp` so the React shell can
				// schedule its own pre-emptive refresh ~60s before expiry.
				'expires_at' => $this->extract_exp( $workos_response['access_token'] ?? '' ),
			],
			200
		);
	}

	/**
	 * POST /auth/session/logout
	 *
	 * Clears the WP auth cookie and revokes the WorkOS session so neither
	 * side holds stale credentials. Safe to call while logged out.
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response
	 */
	public function logout( WP_REST_Request $request ) {
		$user_id = get_current_user_id();

		if ( $user_id > 0 ) {
			// wp_logout() will fire handle_logout() which revokes the
			// WorkOS session and clears our usermeta tokens.
			wp_logout();
		}

		return new WP_REST_Response( [ 'ok' => true ], 200 );
	}

	/**
	 * Decode the `exp` claim from a JWT payload without verifying.
	 *
	 * IMPORTANT — this is intentionally signature-unchecked.
	 *
	 * The returned timestamp drives ONLY the browser's pre-emptive
	 * session-refresh timer (the React shell schedules a refresh call
	 * ~60s before the access token expires). No authorization decision
	 * is made against this value; if an attacker forges an unsigned JWT
	 * with a bogus `exp`, the only effect is that the client schedules
	 * a refresh at the wrong time, and the refresh call itself is
	 * authenticated via the WP auth cookie + the signed refresh token
	 * held server-side. WorkOS's JWKS-verified `verify_access_token()`
	 * is the canonical check everywhere else in the plugin that needs
	 * authoritative session state.
	 *
	 * @param string $token JWT.
	 *
	 * @return int|null Unix timestamp, or null when absent / malformed.
	 */
	private function extract_exp( string $token ): ?int {
		$parts = explode( '.', $token );
		if ( count( $parts ) !== 3 ) {
			return null;
		}

		$payload = json_decode(
			// JWT payloads are base64url-encoded by RFC 7519; decoding is not
			// obfuscation. We only read `exp` here, never execute the result.
			base64_decode( strtr( $parts[1], '-_', '+/' ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			true
		);

		return is_array( $payload ) && isset( $payload['exp'] ) ? (int) $payload['exp'] : null;
	}
}
