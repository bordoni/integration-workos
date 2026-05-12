<?php
/**
 * Cache headers for login redirects.
 *
 * @package WorkOS\Auth\AuthKit
 */

namespace WorkOS\Auth\AuthKit;

defined( 'ABSPATH' ) || exit;

/**
 * Prevents auth-dependent redirects to the login surface from being cached.
 *
 * A logged-out visitor can be redirected from a protected page to the AuthKit
 * login route. If a browser/CDN stores that 302, the post-login redirect back
 * to the protected page can replay the stale logged-out redirect and create a
 * loop. This filter marks those redirects private and uncacheable.
 */
class LoginRedirectCacheHeaders {

	/**
	 * Profile repository.
	 *
	 * @var ProfileRepository
	 */
	private ProfileRepository $profiles;

	/**
	 * Constructor.
	 *
	 * @param ProfileRepository $profiles Profile repository.
	 */
	public function __construct( ProfileRepository $profiles ) {
		$this->profiles = $profiles;
	}

	/**
	 * Register redirect filter.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_redirect', [ $this, 'maybe_prevent_cache' ], 0, 2 );
	}

	/**
	 * Unregister redirect filter.
	 *
	 * @return void
	 */
	public function unregister(): void {
		remove_filter( 'wp_redirect', [ $this, 'maybe_prevent_cache' ], 0 );
	}

	/**
	 * Add no-store headers when a redirect targets a login surface.
	 *
	 * @param string $location Redirect target.
	 * @param int    $status   Redirect HTTP status.
	 *
	 * @return string Unchanged redirect target.
	 */
	public function maybe_prevent_cache( string $location, int $status = 302 ): string {
		if ( $this->should_prevent_cache( $location, $status ) ) {
			$this->send_headers();
		}

		return $location;
	}

	/**
	 * Whether the redirect should be marked uncacheable.
	 *
	 * Public to keep the redirect-matching logic directly testable without
	 * relying on PHP's SAPI header storage.
	 *
	 * @param string $location Redirect target.
	 * @param int    $status   Redirect HTTP status.
	 *
	 * @return bool
	 */
	public function should_prevent_cache( string $location, int $status = 302 ): bool {
		if ( $status < 300 || $status >= 400 || '' === $location ) {
			return false;
		}

		$path = $this->path_from_url( $location );

		if ( '' === $path ) {
			return false;
		}

		if ( $this->same_path( $path, $this->path_from_url( wp_login_url() ) ) ) {
			return true;
		}

		if ( str_starts_with( $this->normalize_path( $path ), '/workos/login/' ) ) {
			return true;
		}

		foreach ( $this->profiles->all() as $profile ) {
			$custom_path = $profile->get_custom_path();
			if ( '' === $custom_path ) {
				continue;
			}

			if ( $this->same_path( $path, '/' . $custom_path . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Send browser/CDN no-store headers.
	 *
	 * @return void
	 */
	private function send_headers(): void {
		if ( headers_sent() ) {
			return;
		}

		nocache_headers();
		header( 'CDN-Cache-Control: no-store' );
		header( 'Cloudflare-CDN-Cache-Control: no-store' );
	}

	/**
	 * Extract a path from an absolute or root-relative URL.
	 *
	 * @param string $url URL or path.
	 *
	 * @return string
	 */
	private function path_from_url( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return '';
		}

		return (string) ( $parts['path'] ?? '' );
	}

	/**
	 * Compare two paths after slash normalization.
	 *
	 * @param string $left  First path.
	 * @param string $right Second path.
	 *
	 * @return bool
	 */
	private function same_path( string $left, string $right ): bool {
		return $this->normalize_path( $left ) === $this->normalize_path( $right );
	}

	/**
	 * Normalize a URL path for comparison.
	 *
	 * @param string $path URL path.
	 *
	 * @return string
	 */
	private function normalize_path( string $path ): string {
		$path = '/' . ltrim( $path, '/' );
		return untrailingslashit( $path );
	}
}
