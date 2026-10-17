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
 * Whether mail sent to an address can be delivered.
 *
 * False for addresses on reserved domains, which sites use to park accounts
 * during cleanups and merges (`someone@sanitized.fake`): the `.fake`,
 * `.invalid`, `.test`, `.example` and `.localhost` TLDs and `example.com`,
 * `example.net` and `example.org` (with their subdomains), and for anything
 * without a domain. The plugin won't push such an address to WorkOS or
 * create a WordPress user from it.
 *
 * @param string $email Email address.
 *
 * @return bool
 */
function workos_is_deliverable_email( string $email ): bool {
	$at     = strrpos( $email, '@' );
	$domain = false === $at ? '' : strtolower( rtrim( trim( substr( $email, $at + 1 ) ), '.' ) );

	$deliverable = '' !== $domain;

	if ( $deliverable ) {
		$labels = explode( '.', $domain );
		$tld    = end( $labels );
		$sld    = count( $labels ) >= 2 ? $labels[ count( $labels ) - 2 ] . '.' . $tld : '';

		$deliverable = ! in_array( $tld, [ 'fake', 'invalid', 'test', 'example', 'localhost' ], true )
			&& ! in_array( $sld, [ 'example.com', 'example.net', 'example.org' ], true );
	}

	/**
	 * Filters whether an email address is deliverable.
	 *
	 * @param bool   $deliverable Whether mail to the address can be delivered.
	 * @param string $email       Email address.
	 */
	return (bool) apply_filters( 'workos_is_deliverable_email', $deliverable, $email );
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
