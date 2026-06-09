<?php
/**
 * Token + hash helpers for the change-email flow.
 *
 * @package WorkOS\Auth\ChangeEmail
 */

namespace WorkOS\Auth\ChangeEmail;

defined( 'ABSPATH' ) || exit;

/**
 * Generates pending-change tokens and the matching salted hashes used
 * for storage.
 *
 * The plaintext token is only ever shipped out in an email; only the
 * hmac-sha256 hash lands in user_meta. Confirmation compares with
 * {@see hash_equals()} so a timing-based oracle isn't viable.
 */
class TokenFactory {

	private const TOKEN_LENGTH = 40;

	/**
	 * Generate a new opaque token suitable for emailing.
	 *
	 * Uses `wp_generate_password()` because it wraps
	 * `random_bytes()`/`random_int()` and is the WP-standard CSPRNG
	 * source. Symbols are disabled so the token survives email-client
	 * link rewriting without escaping.
	 *
	 * @return string
	 */
	public function generate(): string {
		return wp_generate_password( self::TOKEN_LENGTH, false, false );
	}

	/**
	 * Hash a token for at-rest storage.
	 *
	 * Salted with `wp_salt('auth')` so the hash isn't portable between
	 * WordPress installations — even if an attacker exfiltrates the meta
	 * row, replaying the hash on a different site is meaningless.
	 *
	 * @param string $token Plaintext token.
	 *
	 * @return string Hex-encoded hash.
	 */
	public function hash( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}

	/**
	 * Constant-time compare a candidate token against a stored hash.
	 *
	 * @param string $candidate    Plaintext token from the user.
	 * @param string $stored_hash  Hex hash previously written by {@see hash()}.
	 *
	 * @return bool
	 */
	public function verify( string $candidate, string $stored_hash ): bool {
		if ( '' === $candidate || '' === $stored_hash ) {
			return false;
		}

		return hash_equals( $stored_hash, $this->hash( $candidate ) );
	}
}
