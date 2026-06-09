<?php
/**
 * Email-address masking for user-facing surfaces.
 *
 * @package WorkOS\Email
 */

namespace WorkOS\Email;

defined( 'ABSPATH' ) || exit;

/**
 * Produces a privacy-preserving rendering of an email address for display
 * in admin notices, REST responses, and transactional email bodies.
 *
 * Preserves the first character of the local part and the TLD; everything
 * else is replaced with `•`. Example: `jdoe@example.com` → `j•••@e•••.com`.
 *
 * Single source of truth: the change-email REST API, its notifier, and the
 * password-reset admin endpoint all mask through this service so the masked
 * form is identical everywhere a user might see it.
 */
class AddressMask {

	/**
	 * Mask an email address.
	 *
	 * @param string $email Address to mask.
	 *
	 * @return string Masked form, or `•••` when the input isn't a maskable address.
	 */
	public function mask( string $email ): string {
		$at = strpos( $email, '@' );
		if ( false === $at || $at < 1 ) {
			return '•••';
		}

		$local  = substr( $email, 0, $at );
		$domain = substr( $email, $at + 1 );

		$local_mask  = ( $local[0] ?? '' ) . str_repeat( '•', max( 1, strlen( $local ) - 1 ) );
		$dot         = strrpos( $domain, '.' );
		$domain_mask = false === $dot
			? ( $domain[0] ?? '' ) . str_repeat( '•', max( 1, strlen( $domain ) - 1 ) )
			: ( $domain[0] ?? '' ) . str_repeat( '•', max( 1, $dot - 1 ) ) . substr( $domain, $dot );

		return $local_mask . '@' . $domain_mask;
	}
}
