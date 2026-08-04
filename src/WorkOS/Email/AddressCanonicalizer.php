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
 * strings and one inbox. Anything that counts per-mailbox has to agree on
 * which string represents it, or a caller gets a fresh allowance for every
 * variant they can type.
 *
 * Both rewrites are opt-in per provider, because both are conventions rather
 * than rules. RFC 5233 is explicit that the encoding of detailed addresses
 * is "site and/or implementation specific", and RFC 5321 leaves local-part
 * semantics to the destination host, so neither `+` nor `.` can be assumed
 * to be inert. Guessing wrong is not a wash: collapsing two real mailboxes
 * into one bucket lets either of them exhaust the other's allowance, which
 * turns a spam control into a way to lock a colleague out.
 *
 * The failure in the other direction is bounded, which is why the lists are
 * conservative and fixed. An address on a provider we don't list gets a
 * counter per tag, but the per-IP send limits still cap how much mail one
 * caller can cause in total.
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
	 * Providers known to treat `+` as a tag separator and deliver to the
	 * address on its left.
	 *
	 * Deliberately not "everyone". `+` is a legal local-part character, and
	 * on corporate or self-hosted mail `business+brian@corp.com` is
	 * routinely a real mailbox with its own owner. Consumer providers also
	 * disagree: Yahoo and iCloud offer disposable addresses instead of
	 * tags, and Proton uses a different separator entirely.
	 */
	private const PLUS_TAG_DOMAINS = [
		'gmail.com',
		'googlemail.com',
		'outlook.com',
		'hotmail.com',
		'live.com',
		'msn.com',
		'fastmail.com',
		'fastmail.fm',
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
	 * Use this for anything keyed per-mailbox. Never use it as the address
	 * to send to: the canonical form is a bucket key, not a destination.
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

		if ( in_array( $domain, self::PLUS_TAG_DOMAINS, true ) ) {
			$plus = strpos( $local, '+' );
			if ( false !== $plus ) {
				$local = substr( $local, 0, $plus );
			}
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
