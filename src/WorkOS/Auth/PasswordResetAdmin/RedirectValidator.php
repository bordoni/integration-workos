<?php
/**
 * Validate redirect URLs supplied for the post-reset hop.
 *
 * @package WorkOS\Auth\PasswordResetAdmin
 */

namespace WorkOS\Auth\PasswordResetAdmin;

use WorkOS\Auth\AuthKit\Profile;

defined( 'ABSPATH' ) || exit;

/**
 * Validate redirect_url values for the password-reset flow.
 *
 * The reset URL is sent to WorkOS for inclusion in the user-facing email
 * link, so an unvalidated value lets a caller bounce the recipient to an
 * arbitrary domain after a successful reset. Same-host validation against
 * `home_url()` keeps the redirect on this site; any reject falls back to
 * the profile's configured post-login redirect (or home).
 */
class RedirectValidator {

	/**
	 * Validate a caller-supplied redirect URL.
	 *
	 * @param string|null $url     Raw URL from the caller (may be relative or absolute).
	 * @param Profile     $profile Active profile, used for the fallback URL.
	 *
	 * @return string Validated absolute URL safe to ship to WorkOS / use in a Location.
	 */
	public function validate( ?string $url, Profile $profile ): string {
		$candidate = $this->normalize( (string) $url );

		if ( '' !== $candidate ) {
			$validated = wp_validate_redirect( $candidate, '' );
			if ( '' !== $validated && $this->same_host( $validated ) ) {
				return $validated;
			}
		}

		return $this->fallback( $profile );
	}

	/**
	 * Resolve the fallback redirect when no valid URL was supplied.
	 *
	 * @param Profile $profile Active profile.
	 *
	 * @return string Absolute URL.
	 */
	public function fallback( Profile $profile ): string {
		$preferred = $profile->get_post_login_redirect();
		if ( '' !== $preferred ) {
			$candidate = $this->normalize( $preferred );
			if ( '' !== $candidate ) {
				$validated = wp_validate_redirect( $candidate, '' );
				if ( '' !== $validated && $this->same_host( $validated ) ) {
					return $validated;
				}
			}
		}

		return home_url( '/' );
	}

	/**
	 * Normalize an input URL to an absolute http(s) form.
	 *
	 * Relative paths are resolved against `home_url()`. Schemes other than
	 * http/https are rejected outright.
	 *
	 * @param string $url Raw URL.
	 *
	 * @return string Absolute URL with an http/https scheme, or empty on reject.
	 */
	private function normalize( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}

		// Relative path → resolve against home_url so wp_validate_redirect
		// can compare the host. Anything starting with `//` is protocol-relative
		// and should be treated as cross-origin; reject.
		if ( '/' === $url[0] && ( ! isset( $url[1] ) || '/' !== $url[1] ) ) {
			return home_url( $url );
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = strtolower( $parts['scheme'] );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return '';
		}

		return $url;
	}

	/**
	 * Check that a URL's host matches the WP site host.
	 *
	 * @param string $url Absolute URL.
	 *
	 * @return bool
	 */
	private function same_host( string $url ): bool {
		$url_host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		return '' !== $url_host && $url_host === $site_host;
	}
}
