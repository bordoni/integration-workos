<?php
/**
 * Global helper functions.
 *
 * Loaded explicitly from bootstrap.php after WordPress is available.
 *
 * @package WorkOS
 */

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
 * `Api\Client::verify_access_token()` if authoritative status is needed.
 *
 * @param int $user_id WP user ID; 0 or omitted targets the current user.
 *
 * @return string
 */
function workos_get_access_token( int $user_id = 0 ): string {
	return User::get_access_token( $user_id );
}

/**
 * Resolve an MFA helper's user argument: 0 or omitted means the current user.
 *
 * @internal Used by the `workos_mfa_*` helpers below.
 *
 * @param int $user_id WP user ID.
 *
 * @return int
 */
function workos_mfa_resolve_user_id( int $user_id ): int {
	return $user_id > 0 ? $user_id : get_current_user_id();
}

/**
 * A user's MFA status, reconciled with WorkOS.
 *
 * Calls WorkOS. Doesn't check capabilities: callers do.
 *
 * ```php
 * $status = workos_mfa_get_status();
 * if ( ! is_wp_error( $status ) && ! $status['enabled'] ) {
 *     // Nudge the user to turn on MFA.
 * }
 * ```
 *
 * Errors: `workos_mfa_user_not_linked` (409), `workos_api_error`.
 *
 * @param int $user_id WP user ID; 0 or omitted targets the current user.
 *
 * @return array|\WP_Error `{ enabled: bool, required: bool, factors: array }`.
 */
function workos_mfa_get_status( int $user_id = 0 ) {
	return \WorkOS\App::container()->get( \WorkOS\Mfa\FactorService::class )->status( workos_mfa_resolve_user_id( $user_id ) );
}

/**
 * Whether a user has a confirmed MFA factor.
 *
 * Reads the mirrored flag; no WorkOS call. {@see workos_mfa_get_status()}
 * reconciles it with WorkOS.
 *
 * @param int $user_id WP user ID; 0 or omitted targets the current user.
 *
 * @return bool
 */
function workos_mfa_is_enabled( int $user_id = 0 ): bool {
	return \WorkOS\App::container()->get( \WorkOS\Mfa\FactorService::class )->is_enabled( workos_mfa_resolve_user_id( $user_id ) );
}

/**
 * Whether a user must use MFA.
 *
 * True when an admin set the require flag, the user has a role listed in
 * the MFA settings, or the `workos_mfa_required_for_user` filter says so.
 *
 * @param int $user_id WP user ID; 0 or omitted targets the current user.
 *
 * @return bool
 */
function workos_mfa_is_required( int $user_id = 0 ): bool {
	$user_id = workos_mfa_resolve_user_id( $user_id );

	return $user_id > 0 && \WorkOS\App::container()->get( \WorkOS\Mfa\Policy::class )->is_required( $user_id );
}

/**
 * Set or clear the admin flag that requires MFA for a user.
 *
 * Logs `mfa.required_set` / `mfa.required_cleared` and fires
 * `workos_mfa_required_changed` when the value changes. Doesn't check
 * capabilities: callers do.
 *
 * ```php
 * // Require MFA for everyone who can publish.
 * add_action( 'set_user_role', function ( $user_id, $role ) {
 *     workos_mfa_set_required( $user_id, in_array( $role, [ 'editor', 'administrator' ], true ) );
 * }, 10, 2 );
 * ```
 *
 * Errors: `workos_mfa_invalid_user` (404).
 *
 * @param int  $user_id  WP user ID.
 * @param bool $required Whether the user must use MFA.
 *
 * @return bool|\WP_Error Whether the flag changed.
 */
function workos_mfa_set_required( int $user_id, bool $required ) {
	if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
		return new \WP_Error(
			'workos_mfa_invalid_user',
			__( 'User not found.', 'integration-workos' ),
			[ 'status' => 404 ]
		);
	}

	return \WorkOS\App::container()->get( \WorkOS\Mfa\FactorService::class )->set_required( $user_id, $required );
}

/**
 * A user's confirmed MFA factors, read from WorkOS.
 *
 * Errors: `workos_mfa_user_not_linked` (409), `workos_api_error`.
 *
 * @param int $user_id WP user ID; 0 or omitted targets the current user.
 *
 * @return array|\WP_Error List of `{ id, type, created }`.
 */
function workos_mfa_list_factors( int $user_id = 0 ) {
	return \WorkOS\App::container()->get( \WorkOS\Mfa\FactorService::class )->list_factors( workos_mfa_resolve_user_id( $user_id ) );
}

/**
 * Remove every MFA factor a user has.
 *
 * For a user who lost their authenticator. Logs `mfa.reset` and fires
 * `workos_mfa_reset`. Doesn't check capabilities: callers do.
 *
 * Errors: `workos_mfa_user_not_linked` (409), `workos_api_error`.
 *
 * @param int $user_id WP user ID.
 *
 * @return int|\WP_Error Number of factors removed.
 */
function workos_mfa_reset( int $user_id ) {
	return \WorkOS\App::container()->get( \WorkOS\Mfa\FactorService::class )->reset( $user_id );
}

/**
 * What a sign-in by this user would need after the first factor.
 *
 * `none`, `challenge`, `enroll` or `block`. Useful to warn a user before
 * they sign in through a profile that would block them. Only `plugin`
 * mode decides anything; other modes always return `none`.
 *
 * @param int                                 $user_id WP user ID; 0 or omitted targets the current user.
 * @param \WorkOS\Auth\AuthKit\Profile|string $profile Login Profile or its slug; default profile when omitted.
 *
 * @return string
 */
function workos_mfa_decision( int $user_id = 0, $profile = '' ): string {
	$user_id = workos_mfa_resolve_user_id( $user_id );

	if ( ! $profile instanceof \WorkOS\Auth\AuthKit\Profile ) {
		$repository = \WorkOS\App::container()->get( \WorkOS\Auth\AuthKit\ProfileRepository::class );
		$profile    = $repository->find_by_slug( '' !== $profile ? (string) $profile : \WorkOS\Auth\AuthKit\Profile::DEFAULT_SLUG );
	}

	if ( $user_id <= 0 || ! $profile ) {
		return \WorkOS\Mfa\Policy::NONE;
	}

	return \WorkOS\App::container()->get( \WorkOS\Mfa\Policy::class )->decide( $user_id, $profile );
}
