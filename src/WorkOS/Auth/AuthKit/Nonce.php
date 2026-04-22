<?php
/**
 * Nonce helper for AuthKit REST routes.
 *
 * @package WorkOS\Auth\AuthKit
 */

namespace WorkOS\Auth\AuthKit;

defined( 'ABSPATH' ) || exit;

/**
 * Profile-scoped CSRF nonce for the public AuthKit endpoints.
 *
 * Unlike core REST nonces (which are user-bound), our login flows are
 * reachable by anonymous visitors. We mint a short-lived nonce on the
 * rendered login page (via `GET /workos/v1/auth/nonce`) and require it on
 * every mutation. The action namespaces per profile so a nonce minted for
 * the "members" profile cannot be replayed against the "partners" profile.
 */
class Nonce {

	/**
	 * Nonce action prefix.
	 */
	private const ACTION_PREFIX = 'workos_authkit_';

	/**
	 * Mint a nonce for a profile.
	 *
	 * @param string $profile_slug Slug of the target profile.
	 *
	 * @return string Nonce string for use by the React shell.
	 */
	public function mint( string $profile_slug ): string {
		return wp_create_nonce( $this->action( $profile_slug ) );
	}

	/**
	 * Verify a nonce against a profile.
	 *
	 * @param string $nonce        Nonce provided by the caller.
	 * @param string $profile_slug Slug expected to match.
	 *
	 * @return bool True when the nonce matches the action and is not expired.
	 */
	public function verify( string $nonce, string $profile_slug ): bool {
		if ( '' === $nonce || '' === $profile_slug ) {
			return false;
		}

		return (bool) wp_verify_nonce( $nonce, $this->action( $profile_slug ) );
	}

	/**
	 * Build the action string for a profile slug.
	 *
	 * @param string $profile_slug Slug of the target profile.
	 *
	 * @return string
	 */
	private function action( string $profile_slug ): string {
		return self::ACTION_PREFIX . sanitize_title( $profile_slug );
	}
}
