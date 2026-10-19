<?php
/**
 * Global helper functions.
 *
 * Loaded explicitly from bootstrap.php after WordPress is available.
 *
 * @package WorkOS
 */

use WorkOS\App;
use WorkOS\Auth\PasswordResetAdmin\RestApi;
use WorkOS\User;

defined( 'ABSPATH' ) || exit;

/**
 * Get the WorkOS plugin singleton instance.
 *
 * @return \WorkOS\Plugin
 */
function workos(): \WorkOS\Plugin {
	return \WorkOS\Plugin::instance();
}

/**
 * Log a debug message when WORKOS_DEBUG or WP_DEBUG is active.
 *
 * @param string $message Log message.
 * @param string $level   Log level: 'debug', 'info', 'warning', 'error'.
 */
function workos_log( string $message, string $level = 'debug' ): void {
	$is_debug = ( defined( 'WORKOS_DEBUG' ) && WORKOS_DEBUG )
		|| ( defined( 'WP_DEBUG' ) && WP_DEBUG );

	if ( ! $is_debug ) {
		return;
	}

	$prefix = sprintf( '[WorkOS][%s]', strtoupper( $level ) );

	if ( function_exists( 'error_log' ) ) {
		error_log( "{$prefix} {$message}" ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}

/**
 * Whether a user is linked to a WorkOS identity.
 *
 * Thin shortcut for {@see \WorkOS\User::is_sso()}. Remains true after
 * logout — checks the persistent link, not active session state. Pass
 * 0 (or omit) to target the currently-authenticated user.
 *
 * ```php
 * if ( workos_is_sso_user() ) {
 *     // Customize response for a user with a WorkOS identity.
 * }
 * ```
 *
 * @param int $user_id WP user ID; 0 or omitted targets the current user.
 *
 * @return bool
 */
function workos_is_sso_user( int $user_id = 0 ): bool {
	return User::is_sso( $user_id );
}

/**
 * Whether the user is currently signed in via WorkOS (access token stored).
 *
 * This matches the original `! empty( $workos_token )` check — the right
 * signal for "is this request running under a live WorkOS session?".
 * Use {@see workos_is_sso_user()} if you want to know whether the
 * account is linked regardless of login state.
 *
 * @param int $user_id WP user ID; 0 or omitted targets the current user.
 *
 * @return bool
 */
function workos_has_active_session( int $user_id = 0 ): bool {
	return User::has_active_session( $user_id );
}

/**
 * WorkOS user identifier for a WP user, or empty string when absent.
 *
 * @param int $user_id WP user ID; 0 or omitted targets the current user.
 *
 * @return string
 */
function workos_get_user_id( int $user_id = 0 ): string {
	return User::get_workos_id( $user_id );
}

/**
 * Stored WorkOS access token (JWT) for a user, or empty string when absent.
 *
 * Treat as opaque — the token may be expired. Validate with
 * `Api\Client::verify_access_token()` if authoritative status is needed,
 * or use {@see workos_get_valid_access_token()} to get one that is
 * refreshed when it has expired.
 *
 * @param int $user_id WP user ID; 0 or omitted targets the current user.
 *
 * @return string
 */
function workos_get_access_token( int $user_id = 0 ): string {
	return User::get_access_token( $user_id );
}

/**
 * WorkOS access token (JWT) for a user, refreshed first when it has
 * expired or expires within a minute.
 *
 * Thin shortcut for {@see \WorkOS\User::get_valid_access_token()}. A
 * refresh rotates the stored access and refresh tokens, the same way the
 * `POST /wp-json/workos/v1/auth/session/refresh` endpoint does. Only pass
 * a user ID your own code trusts.
 *
 * ```php
 * $token = workos_get_valid_access_token();
 * if ( is_wp_error( $token ) ) {
 *     return $token; // Not linked, signed out, or the refresh failed.
 * }
 * $response = wp_remote_get( $url, [ 'headers' => [ 'Authorization' => 'Bearer ' . $token ] ] );
 * ```
 *
 * @param int $user_id WP user ID; 0 or omitted targets the current user.
 *
 * @return string|\WP_Error Access token, or `workos_user_not_linked`,
 *                          `workos_no_session`, `workos_refresh_failed`
 *                          or the WorkOS API error.
 */
function workos_get_valid_access_token( int $user_id = 0 ) {
	return User::get_valid_access_token( $user_id );
}

/**
 * Send a WorkOS password-reset email to a user.
 *
 * Runs the same code as the admin "Send password reset" action
 * (`POST /wp-json/workos/v1/admin/users/{id}/password-reset`): the user
 * must be linked and have an email, the Login Profile must allow resets,
 * the per-IP and per-user rate limits apply, `redirect_url` is checked by
 * `RedirectValidator`, and a `password_reset.admin_sent` row is written to
 * the activity log.
 *
 * It does not check capabilities. The REST route requires
 * `current_user_can( 'edit_user', $user_id )`; do the same check before
 * calling this from a request handler.
 *
 * ```php
 * if ( current_user_can( 'edit_user', $user_id ) ) {
 *     $sent = workos_send_password_reset( $user_id, [ 'redirect_url' => home_url( '/account/' ) ] );
 * }
 * ```
 *
 * @param int   $user_id WP user ID.
 * @param array $args {
 *     Optional.
 *
 *     @type string $redirect_url Where to send the user after the reset. Must be on this site;
 *                                anything else falls back to the profile's post-login redirect.
 *     @type string $profile      Login Profile slug. Defaults to the default profile.
 * }
 *
 * @return true|\WP_Error True when WorkOS accepted the request; otherwise the same error
 *                        the REST route returns (`workos_user_not_found`, `workos_user_not_linked`,
 *                        `workos_user_missing_email`, `workos_profile_not_found`,
 *                        `workos_reset_disabled`, `workos_rate_limited`, or the WorkOS API error).
 */
function workos_send_password_reset( int $user_id, array $args = [] ) {
	$result = App::container()->get( RestApi::class )->send_for_user(
		$user_id,
		sanitize_text_field( (string) ( $args['redirect_url'] ?? '' ) ),
		sanitize_title( (string) ( $args['profile'] ?? '' ) )
	);

	return is_wp_error( $result ) ? $result : true;
}

/**
 * URL that ends the user's current WorkOS session.
 *
 * Thin shortcut for {@see \WorkOS\User::get_logout_url()}. Returns WorkOS's
 * session logout URL for the stored session (WorkOS redirects to
 * `$return_to` only when it is on the environment's sign-out redirect
 * list), or `wp_logout_url( $return_to )` when the user has no WorkOS
 * session. The WorkOS URL does not clear the WordPress auth cookie;
 * `wp_logout_url()` ends both.
 *
 * @param int    $user_id   WP user ID; 0 or omitted targets the current user.
 * @param string $return_to Optional URL to land on after sign-out.
 *
 * @return string
 */
function workos_get_logout_url( int $user_id = 0, string $return_to = '' ): string {
	return User::get_logout_url( $user_id, $return_to );
}
