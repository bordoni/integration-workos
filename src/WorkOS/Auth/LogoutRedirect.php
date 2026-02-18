<?php
/**
 * Role-based logout redirect.
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
 * Determines where a user should land after logging out via WorkOS.
 *
 * Hooks into `logout_redirect` at priority 10 so the WorkOS session
 * revocation in Login::handle_logout() (priority 20) can wrap or
 * override the resolved URL.
 */
class LogoutRedirect {

	/**
	 * Constructor — registers the logout_redirect filter.
	 */
	public function __construct() {
		add_filter( 'logout_redirect', [ $this, 'logout_redirect' ], 10, 3 );
	}

	/**
	 * Filter the logout redirect URL based on user role.
	 *
	 * @param string           $redirect_to           Default redirect URL.
	 * @param string           $requested_redirect_to Originally requested redirect URL.
	 * @param \WP_User|\WP_Error $user                User object (or error on failed logout).
	 *
	 * @return string
	 */
	public function logout_redirect( string $redirect_to, string $requested_redirect_to, $user ): string {
		if ( ! $user instanceof \WP_User ) {
			return $redirect_to;
		}

		if ( ! get_user_meta( $user->ID, '_workos_user_id', true ) ) {
			return $redirect_to;
		}

		return self::resolve( $redirect_to, $user );
	}

	/**
	 * Resolve the final logout redirect URL for a WorkOS-authenticated user.
	 *
	 * @param string   $redirect_to The current redirect URL.
	 * @param \WP_User $user        The authenticated user.
	 *
	 * @return string Final redirect URL.
	 */
	public static function resolve( string $redirect_to, \WP_User $user ): string {
		/**
		 * Whether role-based logout redirect should apply at all for this request.
		 *
		 * Return `false` to skip entirely (e.g., for admin users).
		 *
		 * @param bool     $should_apply      Whether to apply role-based logout redirect.
		 * @param \WP_User $user              The user logging out.
		 * @param string   $requested_redirect The current redirect URL.
		 */
		$should_apply = apply_filters( 'workos_logout_redirect_should_apply', true, $user, $redirect_to );

		if ( ! $should_apply ) {
			/**
			 * Fires when role-based logout redirect is skipped.
			 *
			 * @param \WP_User $user   The user logging out.
			 * @param string   $reason Reason the redirect was skipped.
			 */
			do_action( 'workos_logout_redirect_skipped', $user, 'filtered_out' );

			return $redirect_to;
		}

		// Look up role-based logout redirect URL, falling back to __default__.
		$redirect_map = self::get_logout_redirect_urls();
		$role         = self::get_primary_role( $user );
		$role_url     = $redirect_map[ $role ] ?? $redirect_map['__default__'] ?? '';

		/**
		 * Final logout redirect URL for a specific user.
		 *
		 * Return empty string to skip role-based logout redirect.
		 *
		 * @param string   $url  The role-based logout redirect URL.
		 * @param \WP_User $user The user logging out.
		 * @param string   $role The user's primary WP role.
		 */
		$role_url = apply_filters( 'workos_logout_redirect_url', $role_url, $user, $role );

		if ( ! empty( $role_url ) ) {
			// Convert relative paths to absolute URLs.
			if ( ! preg_match( '#^https?://#i', $role_url ) ) {
				$role_url = home_url( $role_url );
			}

			/**
			 * Fires just before the role-based logout redirect is applied.
			 *
			 * @param string   $url  The redirect URL.
			 * @param \WP_User $user The user logging out.
			 */
			do_action( 'workos_logout_redirect_before', $role_url, $user );

			return $role_url;
		}

		/** This action is documented above. */
		do_action( 'workos_logout_redirect_skipped', $user, 'no_matching_role_url' );

		return $redirect_to;
	}

	/**
	 * Get the role→logout redirect URL map from the active environment options.
	 *
	 * @return array<string, string> Role slug → URL string.
	 */
	public static function get_logout_redirect_urls(): array {
		$options = self::get_env_options();
		$map     = $options->get( 'logout_redirect_urls', [] );

		if ( ! is_array( $map ) ) {
			$map = [];
		}

		/**
		 * The full role→logout redirect URL map from settings.
		 *
		 * Allows adding/removing/overriding entries programmatically.
		 *
		 * @param array $map Role slug → URL string.
		 */
		return apply_filters( 'workos_logout_redirect_urls', $map );
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
