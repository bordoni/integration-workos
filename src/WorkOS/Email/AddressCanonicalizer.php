<?php
/**
 * Email-address canonicalization.
 *
 * @package WorkOS\Email
 */

namespace WorkOS\Email;

defined( 'ABSPATH' ) || exit;

/**
 * Reduces an address to the mailbox it actually lands in.
 *
 * `alice@gmail.com`, `alice+1@gmail.com` and `a.l.i.c.e@gmail.com` are three
 * strings and one inbox. Anything that counts or compares per-mailbox has to
 * agree on which string represents it, or a caller gets a fresh allowance for
 * every variant they can type.
 *
 * Deliberately conservative: `+` tags are stripped everywhere because the
 * convention is near-universal, but dots are only ignored for the providers
 * known to ignore them. Elsewhere `a.lice@` and `alice@` are different people.
 */
class AddressCanonicalizer {

	/**
	 * Providers that ignore dots in the local part, mapped to the domain
	 * their variants collapse to.
	 */
	private const DOT_INSENSITIVE_DOMAINS = [
		'gmail.com'      => 'gmail.com',
		'googlemail.com' => 'gmail.com',
	];

	/**
	 * Lowercase and trim an address.
	 *
	 * @param string $email Raw email string.
	 *
	 * @return string Empty when the input isn't an address.
	 */
	public function normalize( string $email ): string {
		$email = strtolower( trim( $email ) );

		return is_email( $email ) ? $email : '';
	}

	/**
	 * Reduce an address to its mailbox.
	 *
	 * @param string $email Raw email string.
	 *
	 * @return string Canonical form, or empty when the input isn't an address.
	 */
	public function canonical( string $email ): string {
		$email = $this->normalize( $email );
		if ( '' === $email ) {
			return '';
		}

		$at = strrpos( $email, '@' );
		if ( false === $at ) {
			return $email;
		}

		$local  = substr( $email, 0, $at );
		$domain = substr( $email, $at + 1 );

		$plus = strpos( $local, '+' );
		if ( false !== $plus ) {
			$local = substr( $local, 0, $plus );
		}

		if ( isset( self::DOT_INSENSITIVE_DOMAINS[ $domain ] ) ) {
			$local  = str_replace( '.', '', $local );
			$domain = self::DOT_INSENSITIVE_DOMAINS[ $domain ];
		}

		// A local part that was only a tag collapses to empty, which would
		// merge unrelated addresses into one mailbox.
		return '' !== $local ? $local . '@' . $domain : $email;
	}
}
