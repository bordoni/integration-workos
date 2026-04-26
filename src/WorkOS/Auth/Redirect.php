<?php
/**
 * Role-based login redirect.
 *
 * @package WorkOS\Auth
 */

namespace WorkOS\Auth;

use WorkOS\App;
use WorkOS\Config;
use WorkOS\Options\Production;
use WorkOS\Options\Staging;

defined( 'ABSPATH' ) || exit;

/**
 * Determines where a user should land after authenticating via WorkOS.
 *
 * Hooks into `login_redirect` for headless/standard flows and exposes a
 * static `resolve()` method for the AuthKit redirect callback.
 */
class Redirect {

	/**
	 * Constructor — registers the login_redirect filter.
	 */
	public function __construct() {
		add_filter( 'login_redirect', [ $this, 'login_redirect' ], 10, 3 );
	}

	/**
	 * Filter the login redirect URL for headless/standard login flows.
	 *
	 * @param string             $redirect_to           Default redirect URL.
	 * @param string             $requested_redirect_to Originally requested redirect URL.
	 * @param \WP_User|\WP_Error $user                User object (or error on failed login).
	 *
	 * @return string
	 */
	public function login_redirect( string $redirect_to, string $requested_redirect_to, $user ): string {
		if ( ! $user instanceof \WP_User ) {
			return $redirect_to;
		}

		if ( ! get_user_meta( $user->ID, '_workos_user_id', true ) ) {
			return $redirect_to;
		}

		return self::resolve( $redirect_to, $user );
	}

	/**
	 * Resolve the final redirect URL for a WorkOS-authenticated user.
	 *
	 * Used by both the `login_redirect` filter and `Login::handle_callback()`.
	 *
	 * @param string   $redirect_to The current redirect URL.
	 * @param \WP_User $user        The authenticated user.
	 *
	 * @return string Final redirect URL.
	 */
	public static function resolve( string $redirect_to, \WP_User $user ): string {
		$is_first_login = self::is_first_login( $user->ID );

		/**
		 * Whether role-based redirect should apply at all for this request.
		 *
		 * Return `false` to skip entirely (e.g., for admin users).
		 *
		 * @param bool     $should_apply      Whether to apply role-based redirect.
		 * @param \WP_User $user              The authenticated user.
		 * @param string   $requested_redirect The current redirect URL.
		 */
		$should_apply = apply_filters( 'workos_redirect_should_apply', true, $user, $redirect_to );

		if ( ! $should_apply ) {
			/**
			 * Fires when role-based redirect is skipped.
			 *
			 * @param \WP_User $user   The authenticated user.
			 * @param string   $reason Reason the redirect was skipped.
			 */
			do_action( 'workos_redirect_skipped', $user, 'filtered_out' );

			return $redirect_to;
		}

		// Check if the redirect_to is an explicit user-initiated destination.
		$is_explicit = self::is_explicit_redirect( $redirect_to );

		/**
		 * Whether the current redirect_to is considered "explicit" (user-initiated).
		 *
		 * By default, any redirect_to that is NOT admin_url() or empty is explicit.
		 *
		 * @param bool     $is_explicit Whether the redirect is explicit.
		 * @param string   $redirect_to The redirect URL.
		 * @param \WP_User $user        The authenticated user.
		 */
		$is_explicit = apply_filters( 'workos_redirect_is_explicit', $is_explicit, $redirect_to, $user );

		if ( $is_explicit ) {
			/** This action is documented above. */
			do_action( 'workos_redirect_skipped', $user, 'explicit_redirect' );

			return $redirect_to;
		}

		// Look up role-based redirect entry, falling back to __default__.
		// `get_redirect_urls()` normalizes legacy string entries to the
		// structured shape, so every entry here is `{url, first_login_only}`.
		$redirect_map = self::get_redirect_urls();
		$role         = self::get_primary_role( $user );
		$entry        = $redirect_map[ $role ] ?? $redirect_map['__default__'] ?? [
			'url'              => '',
			'first_login_only' => false,
		];

		$role_url         = (string) ( $entry['url'] ?? '' );
		$first_login_only = ! empty( $entry['first_login_only'] );

		/**
		 * Override the per-entry "first login only" setting programmatically.
		 *
		 * @param bool     $first_login_only Whether to redirect only on first login.
		 * @param string   $role             The user's primary WP role.
		 * @param \WP_User $user             The authenticated user.
		 */
		$first_login_only = apply_filters( 'workos_redirect_first_login_only', $first_login_only, $role, $user );

		if ( $first_login_only && ! $is_first_login ) {
			/** This action is documented above. */
			do_action( 'workos_redirect_skipped', $user, 'not_first_login' );

			return ! empty( $redirect_to ) ? $redirect_to : admin_url();
		}

		/**
		 * Final redirect URL for a specific user.
		 *
		 * Return empty string to skip role-based redirect.
		 *
		 * @param string   $url            The role-based redirect URL.
		 * @param \WP_User $user           The authenticated user.
		 * @param string   $role           The user's primary WP role.
		 * @param bool     $is_first_login Whether this is the user's first login.
		 */
		$role_url = apply_filters( 'workos_redirect_url', $role_url, $user, $role, $is_first_login );

		if ( ! empty( $role_url ) ) {
			// Convert relative paths to absolute URLs.
			if ( ! preg_match( '#^https?://#i', $role_url ) ) {
				$role_url = home_url( $role_url );
			}

			/**
			 * Fires just before the role-based redirect is applied.
			 *
			 * @param string   $url            The redirect URL.
			 * @param \WP_User $user           The authenticated user.
			 * @param bool     $is_first_login Whether this is the user's first login.
			 */
			do_action( 'workos_redirect_before', $role_url, $user, $is_first_login );

			self::clear_first_login( $user->ID );

			return $role_url;
		}

		/** This action is documented above. */
		do_action( 'workos_redirect_skipped', $user, 'no_matching_role_url' );

		return ! empty( $redirect_to ) ? $redirect_to : admin_url();
	}

	/**
	 * Get the role→redirect entry map from the active environment options.
	 *
	 * Each entry is an array with 'url' and 'first_login_only' keys.
	 * Legacy string values are normalized to the structured format.
	 *
	 * @return array<string, array{url: string, first_login_only: bool}> Role slug → redirect entry.
	 */
	public static function get_redirect_urls(): array {
		$options = self::get_env_options();
		$map     = $options->get( 'redirect_urls', [] );

		if ( ! is_array( $map ) ) {
			$map = [];
		}

		// Legacy global setting — used as default for old-format string entries.
		$global_first_login = (bool) $options->get( 'redirect_first_login_only', false );

		// Normalize legacy string entries to structured format.
		foreach ( $map as $role => $entry ) {
			if ( is_string( $entry ) ) {
				$map[ $role ] = [
					'url'              => $entry,
					'first_login_only' => $global_first_login,
				];
			}
		}

		/**
		 * The full role→redirect entry map from settings.
		 *
		 * Each entry is an array with 'url' (string) and 'first_login_only' (bool).
		 * Allows adding/removing/overriding entries programmatically.
		 *
		 * @param array $map Role slug → redirect entry array.
		 */
		return apply_filters( 'workos_redirect_urls', $map );
	}

	/**
	 * Check whether a user is on their first login.
	 *
	 * @param int $user_id WP user ID.
	 *
	 * @return bool
	 */
	public static function is_first_login( int $user_id ): bool {
		return '1' === get_user_meta( $user_id, '_workos_first_login', true );
	}

	/**
	 * Clear the first-login flag for a user.
	 *
	 * @param int $user_id WP user ID.
	 */
	public static function clear_first_login( int $user_id ): void {
		delete_user_meta( $user_id, '_workos_first_login' );
	}

	/**
	 * Determine if a redirect_to URL is an explicit (user-initiated) destination.
	 *
	 * Anything that is NOT admin_url() or empty is considered explicit.
	 *
	 * @param string $redirect_to The redirect URL.
	 *
	 * @return bool
	 */
	private static function is_explicit_redirect( string $redirect_to ): bool {
		if ( empty( $redirect_to ) ) {
			return false;
		}

		// Normalize for comparison: remove trailing slashes.
		$normalized = untrailingslashit( $redirect_to );
		$admin      = untrailingslashit( admin_url() );

		return $normalized !== $admin;
	}

	/**
	 * Get the primary role for a user.
	 *
	 * @param \WP_User $user The user object.
	 *
	 * @return string Role slug, or empty string if no roles.
	 */
	private static function get_primary_role( \WP_User $user ): string {
		$roles = $user->roles;
		return ! empty( $roles ) ? reset( $roles ) : '';
	}

	/**
	 * Get the Options instance for the active environment.
	 *
	 * @return \WorkOS\Options\Options
	 */
	private static function get_env_options(): \WorkOS\Options\Options {
		$env   = Config::get_active_environment();
		$class = 'staging' === $env ? Staging::class : Production::class;
		return App::container()->get( $class );
	}
}
