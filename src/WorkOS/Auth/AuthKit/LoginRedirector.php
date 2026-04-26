<?php
/**
 * Login-page visitor redirect helper.
 *
 * @package WorkOS\Auth\AuthKit
 */

namespace WorkOS\Auth\AuthKit;

use WorkOS\Vendor\StellarWP\SuperGlobals\SuperGlobals;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves where to send a visitor that's already authenticated when
 * they hit one of the AuthKit login surfaces (wp-login.php takeover,
 * /workos/login/{slug}, the [workos:login] shortcode).
 *
 * Centralized so all three surfaces share one precedence:
 *
 *   1. The profile's `post_login_redirect` field (admin's intent).
 *   2. The validated `redirect_to` query arg (if non-empty + safe).
 *   3. `admin_url()` (WP convention for already-logged-in users).
 *
 * Optionally appends inbound query args (utm_*, ref, etc.) when the
 * profile's `forward_query_args` toggle is on. Internal WP / plugin
 * params (`redirect_to`, `_wpnonce`, `interim-login`, `loggedout`,
 * `reauth`, `instance`, `wp_lang`, `action`, `fallback`, anything
 * starting with `workos_`) are always stripped.
 */
class LoginRedirector {

	/**
	 * Query-arg names that must never be forwarded to the destination
	 * because they're WP / plugin internals or would create a loop.
	 *
	 * @var string[]
	 */
	private const INTERNAL_QUERY_ARGS = [
		'redirect_to',
		'_wpnonce',
		'interim-login',
		'loggedout',
		'reauth',
		'instance',
		'wp_lang',
		'action',
		'fallback',
	];

	/**
	 * Resolve the URL to redirect an already-logged-in visitor to.
	 *
	 * Reads `$_GET['redirect_to']` synchronously — call this only during
	 * the request that owns those query args.
	 *
	 * @param Profile $profile Active Login Profile (drives precedence).
	 *
	 * @return string Absolute URL safe to hand to wp_safe_redirect().
	 */
	public static function for_visitor( Profile $profile ): string {
		$dest = self::resolve_destination( $profile );

		if ( $profile->should_forward_query_args() ) {
			$dest = self::with_forwarded_args( $dest );
		}

		return $dest;
	}

	/**
	 * Filter and append the current request's query args to a URL,
	 * stripping internals so the destination doesn't get polluted.
	 *
	 * @param string $url Base destination URL.
	 *
	 * @return string URL with safe query args merged in.
	 */
	public static function with_forwarded_args( string $url ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$incoming = (array) wp_unslash( $_GET );
		$forward  = self::filter_forwardable_args( $incoming );

		if ( empty( $forward ) ) {
			return $url;
		}

		return add_query_arg( $forward, $url );
	}

	/**
	 * Strip internal/auth params from an arbitrary query-arg map.
	 *
	 * @param array<string,mixed> $args Raw query args.
	 *
	 * @return array<string,mixed> Subset safe to forward.
	 */
	public static function filter_forwardable_args( array $args ): array {
		$out = [];
		foreach ( $args as $key => $value ) {
			$key = (string) $key;
			if ( in_array( $key, self::INTERNAL_QUERY_ARGS, true ) ) {
				continue;
			}
			if ( 0 === strpos( $key, 'workos_' ) ) {
				continue;
			}
			$out[ $key ] = $value;
		}
		return $out;
	}

	/**
	 * Apply the precedence rules without query-arg forwarding.
	 *
	 * @param Profile $profile Profile under consideration.
	 *
	 * @return string Absolute destination URL.
	 */
	private static function resolve_destination( Profile $profile ): string {
		$profile_redirect = $profile->get_post_login_redirect();
		if ( '' !== $profile_redirect ) {
			return $profile_redirect;
		}

		$requested = (string) ( SuperGlobals::get_get_var( 'redirect_to' ) ?? '' );
		if ( '' !== $requested ) {
			$validated = (string) wp_validate_redirect( $requested, '' );
			if ( '' !== $validated ) {
				return $validated;
			}
		}

		return admin_url();
	}
}
