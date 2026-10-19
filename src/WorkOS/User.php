<?php
/**
 * Public helper for querying WorkOS metadata on WP users.
 *
 * @package WorkOS
 */

namespace WorkOS;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only accessors for the WorkOS fields a WP user may carry.
 *
 * All user meta the plugin stores is surfaced here so third-party code
 * never has to reach into `get_user_meta()` directly. The meta keys are
 * exposed as class constants for callers that need them (e.g. SQL
 * queries, REST schemas) — but day-to-day code should use the methods.
 *
 * ### "SSO user" vs "active WorkOS session"
 *
 * - {@see self::is_sso()} returns true whenever the user is *linked* to
 *   a WorkOS identity (has a `_workos_user_id`). Remains true after
 *   logout — the link is persistent.
 * - {@see self::has_active_session()} returns true only while a WorkOS
 *   access token is stored — i.e. the user has signed in via WorkOS
 *   and has not logged out. This is the right check for "is this
 *   request running under an SSO session right now?"
 *
 * Neither method verifies the access token is still valid against
 * WorkOS's JWKS — use `Api\Client::verify_access_token()` for that.
 *
 * ### Session helpers
 *
 * Two methods go beyond reading meta:
 *
 * - {@see self::get_valid_access_token()} returns a token that hasn't
 *   expired, refreshing it (and rotating the stored tokens) when needed.
 * - {@see self::get_logout_url()} builds the WorkOS logout URL for the
 *   stored session, or falls back to `wp_logout_url()`.
 *
 * ### Example usage
 *
 * ```php
 * use WorkOS\User;
 *
 * public function addWorkosData( array $data ): array {
 *     if ( ! User::is_sso() ) {
 *         return $data;
 *     }
 *
 *     $data['workos_user_id']     = User::get_workos_id();
 *     $data['workos_org_id']      = User::get_organization_id();
 *     $data['workos_signed_in']   = User::has_active_session();
 *
 *     return $data;
 * }
 * ```
 *
 * Or via the global helper shortcuts:
 *
 * ```php
 * if ( workos_is_sso_user() ) { ... }
 * $token = workos_get_access_token( $user_id );       // As stored; may be expired.
 * $token = workos_get_valid_access_token( $user_id ); // Refreshed when needed; may be WP_Error.
 * ```
 */
final class User {

	/** User meta key — the WorkOS `user_...` identifier linking a WP user to a WorkOS identity. */
	public const META_WORKOS_ID = '_workos_user_id';

	/** User meta key — current WorkOS access token (JWT). Cleared on logout. */
	public const META_ACCESS_TOKEN = '_workos_access_token';

	/** User meta key — current WorkOS refresh token. Cleared on logout. */
	public const META_REFRESH_TOKEN = '_workos_refresh_token';

	/** User meta key — current WorkOS session id (`sid` JWT claim). Cleared on logout. */
	public const META_SESSION_ID = '_workos_session_id';

	/** User meta key — pinned WorkOS organization id on this user. */
	public const META_ORG_ID = '_workos_org_id';

	/** User meta key — last time this WP user's profile was synced from WorkOS. */
	public const META_LAST_SYNCED = '_workos_last_synced_at';

	/** User meta key — snapshot of the WorkOS profile at last sync (drift detection). */
	public const META_PROFILE_HASH = '_workos_profile_hash';

	/** User meta key — flag set when a user is deactivated in WorkOS. */
	public const META_DEACTIVATED = '_workos_deactivated';

	/** User meta key — flag indicating this WP user's first login via WorkOS. */
	public const META_FIRST_LOGIN = '_workos_first_login';

	/**
	 * Seconds before expiry at which {@see self::get_valid_access_token()}
	 * refreshes. Matches the React shell's pre-emptive refresh.
	 */
	public const ACCESS_TOKEN_REFRESH_LEEWAY = 60;

	/**
	 * Whether the user is linked to a WorkOS identity.
	 *
	 * Remains true after the user logs out — the WorkOS link persists
	 * across sessions. Use {@see self::has_active_session()} to check
	 * whether the user is currently signed in via WorkOS.
	 *
	 * @param int $user_id WP user ID; 0 or omitted targets the current user.
	 *
	 * @return bool
	 */
	public static function is_sso( int $user_id = 0 ): bool {
		return '' !== self::get_workos_id( $user_id );
	}

	/**
	 * Whether the user has a stored WorkOS access token.
	 *
	 * True from login until logout. Does NOT verify that the token is
	 * unexpired or that WorkOS still considers the session valid — use
	 * `Api\Client::verify_access_token()` for those checks.
	 *
	 * @param int $user_id WP user ID; 0 or omitted targets the current user.
	 *
	 * @return bool
	 */
	public static function has_active_session( int $user_id = 0 ): bool {
		return '' !== self::get_access_token( $user_id );
	}

	/**
	 * Get the WorkOS user identifier for a WP user.
	 *
	 * @param int $user_id WP user ID; 0 or omitted targets the current user.
	 *
	 * @return string WorkOS user id (e.g. `user_01HXYZ...`) or empty string.
	 */
	public static function get_workos_id( int $user_id = 0 ): string {
		return self::meta_string( $user_id, self::META_WORKOS_ID );
	}

	/**
	 * Get the user's stored WorkOS access token.
	 *
	 * This is the same token `REST\TokenAuth` verifies on Bearer-token
	 * requests. Prefer treating it as opaque — the token may be
	 * expired, and `Api\Client::verify_access_token()` is the canonical
	 * way to validate it.
	 *
	 * @param int $user_id WP user ID; 0 or omitted targets the current user.
	 *
	 * @return string
	 */
	public static function get_access_token( int $user_id = 0 ): string {
		return self::meta_string( $user_id, self::META_ACCESS_TOKEN );
	}

	/**
	 * Get an access token for the user that is good for at least
	 * {@see self::ACCESS_TOKEN_REFRESH_LEEWAY} more seconds.
	 *
	 * Returns the stored token while it is fresh. When it has expired, is
	 * about to, or its expiry can't be read, the stored refresh token is
	 * exchanged through `Api\Client::refresh_session()` and the new pair is
	 * stored with `Auth\Login::store_tokens()`, the same calls the
	 * `POST /auth/session/refresh` endpoint makes. Unlike
	 * {@see self::get_access_token()}, this may call WorkOS and rotate the
	 * stored tokens.
	 *
	 * The expiry is read from the stored token without checking its
	 * signature: the token came from WorkOS and was stored server-side, and
	 * the result only decides whether to refresh. Only pass a user ID your
	 * own code trusts, never one taken from an unauthenticated request.
	 *
	 * @param int $user_id WP user ID; 0 or omitted targets the current user.
	 *
	 * @return string|\WP_Error Access token (JWT), or an error:
	 *                          `workos_user_not_linked` (no WorkOS link or no user),
	 *                          `workos_no_session` (signed out, or expired with no refresh token),
	 *                          `workos_refresh_failed` (WorkOS returned no token),
	 *                          or the API error from the refresh call.
	 */
	public static function get_valid_access_token( int $user_id = 0 ) {
		$resolved = self::resolve_user_id( $user_id );
		if ( 0 === $resolved || ! self::is_sso( $resolved ) ) {
			return new \WP_Error(
				'workos_user_not_linked',
				__( 'This user is not linked to a WorkOS account.', 'integration-workos' ),
				[ 'status' => 409 ]
			);
		}

		$access_token = self::get_access_token( $resolved );
		if ( '' !== $access_token && ! self::access_token_needs_refresh( $access_token ) ) {
			return $access_token;
		}

		$refresh_token = self::get_refresh_token( $resolved );
		if ( '' === $refresh_token ) {
			return new \WP_Error(
				'workos_no_session',
				__( 'This user has no active WorkOS session.', 'integration-workos' ),
				[ 'status' => 401 ]
			);
		}

		$response = workos()->api()->refresh_session( $refresh_token );

		if ( ! is_wp_error( $response ) && ( empty( $response['access_token'] ) || ! is_string( $response['access_token'] ) ) ) {
			$response = new \WP_Error(
				'workos_refresh_failed',
				__( 'WorkOS did not return a new access token.', 'integration-workos' ),
				[ 'status' => 502 ]
			);
		}

		if ( is_wp_error( $response ) ) {
			// Another request may have rotated the tokens while this one
			// was waiting, which spends the refresh token we sent. Use its
			// result if it left a fresh token behind.
			$latest = self::get_access_token( $resolved );
			if ( '' !== $latest && $latest !== $access_token && ! self::access_token_needs_refresh( $latest ) ) {
				return $latest;
			}

			return $response;
		}

		Auth\Login::store_tokens( $resolved, $response );

		return $response['access_token'];
	}

	/**
	 * Get the URL that ends the user's current WorkOS session.
	 *
	 * Points at WorkOS's `GET /user_management/sessions/logout` for the
	 * stored session ID, with `return_to` when given. WorkOS only redirects
	 * to `return_to` values on the environment's sign-out redirect list.
	 * With no stored session (not linked, or signed out) this returns
	 * `wp_logout_url( $return_to )`.
	 *
	 * The WorkOS URL ends the WorkOS session only; the WordPress auth
	 * cookie is untouched. `wp_logout_url()` already ends both, because the
	 * plugin revokes the WorkOS session server-side on `wp_logout`.
	 *
	 * @param int    $user_id   WP user ID; 0 or omitted targets the current user.
	 * @param string $return_to Optional URL to land on after sign-out.
	 *
	 * @return string
	 */
	public static function get_logout_url( int $user_id = 0, string $return_to = '' ): string {
		$session_id = self::get_session_id( $user_id );
		if ( '' === $session_id || ! self::is_sso( $user_id ) ) {
			return wp_logout_url( $return_to );
		}

		$args = [ 'session_id' => $session_id ];
		if ( '' !== $return_to ) {
			$args['return_to'] = $return_to;
		}

		return Api\Client::get_base_url() . '/user_management/sessions/logout?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Get the user's stored WorkOS refresh token.
	 *
	 * @param int $user_id WP user ID; 0 or omitted targets the current user.
	 *
	 * @return string
	 */
	public static function get_refresh_token( int $user_id = 0 ): string {
		return self::meta_string( $user_id, self::META_REFRESH_TOKEN );
	}

	/**
	 * Get the WorkOS session id (`sid` JWT claim) for the current session.
	 *
	 * @param int $user_id WP user ID; 0 or omitted targets the current user.
	 *
	 * @return string
	 */
	public static function get_session_id( int $user_id = 0 ): string {
		return self::meta_string( $user_id, self::META_SESSION_ID );
	}

	/**
	 * Get the WorkOS organization id pinned to this user.
	 *
	 * @param int $user_id WP user ID; 0 or omitted targets the current user.
	 *
	 * @return string
	 */
	public static function get_organization_id( int $user_id = 0 ): string {
		return self::meta_string( $user_id, self::META_ORG_ID );
	}

	/**
	 * Return a snapshot of everything the plugin knows about a user as
	 * a single array — handy for REST responses and debug screens.
	 *
	 * @param int $user_id WP user ID; 0 or omitted targets the current user.
	 *
	 * @return array{
	 *     user_id: int,
	 *     is_sso: bool,
	 *     has_active_session: bool,
	 *     workos_user_id: string,
	 *     organization_id: string,
	 *     session_id: string,
	 * }
	 */
	public static function snapshot( int $user_id = 0 ): array {
		$resolved = self::resolve_user_id( $user_id );

		return [
			'user_id'            => $resolved,
			'is_sso'             => self::is_sso( $resolved ),
			'has_active_session' => self::has_active_session( $resolved ),
			'workos_user_id'     => self::get_workos_id( $resolved ),
			'organization_id'    => self::get_organization_id( $resolved ),
			'session_id'         => self::get_session_id( $resolved ),
		];
	}

	/**
	 * Whether a stored access token has expired, expires within the
	 * leeway, or has no readable `exp` claim.
	 *
	 * Reads the claim without checking the signature; see
	 * {@see self::get_valid_access_token()} for why that is enough here.
	 *
	 * @param string $token JWT.
	 *
	 * @return bool
	 */
	private static function access_token_needs_refresh( string $token ): bool {
		$parts = explode( '.', $token );
		if ( 3 !== count( $parts ) ) {
			return true;
		}

		$payload = json_decode(
			// JWT payloads are base64url-encoded (RFC 7519); this reads the
			// `exp` claim, it is not obfuscation.
			(string) base64_decode( strtr( $parts[1], '-_', '+/' ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			true
		);

		if ( ! is_array( $payload ) || ! isset( $payload['exp'] ) || ! is_numeric( $payload['exp'] ) ) {
			return true;
		}

		return (int) $payload['exp'] - self::ACCESS_TOKEN_REFRESH_LEEWAY <= time();
	}

	/**
	 * Read a string-typed meta value, normalized to '' when absent.
	 *
	 * @param int    $user_id WP user ID (0 = current).
	 * @param string $key     Meta key.
	 *
	 * @return string
	 */
	private static function meta_string( int $user_id, string $key ): string {
		$resolved = self::resolve_user_id( $user_id );
		if ( 0 === $resolved ) {
			return '';
		}

		$value = get_user_meta( $resolved, $key, true );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Normalize a user id argument: 0 / negative becomes the current user.
	 *
	 * @param int $user_id Incoming id.
	 *
	 * @return int Positive WP user id, or 0 when no user is available.
	 */
	private static function resolve_user_id( int $user_id ): int {
		if ( $user_id > 0 ) {
			return $user_id;
		}

		$current = get_current_user_id();

		return $current > 0 ? $current : 0;
	}
}
