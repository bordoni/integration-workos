<?php
/**
 * Which account a paused email-verification sign-in belongs to.
 *
 * @package WorkOS\Auth\AuthKit
 */

namespace WorkOS\Auth\AuthKit;

defined( 'ABSPATH' ) || exit;

/**
 * Remembers the email behind a pending authentication token.
 *
 * WorkOS has no lookup from a pending token to its user, so a resend would
 * otherwise need the browser to name the account. Keyed on a hash, so the
 * stored row can't be replayed as the token itself.
 */
class PendingEmailVerification {

	/**
	 * How long a resend stays possible.
	 */
	private const TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * Remember the email for a pending token.
	 *
	 * @param string $pending_token Pending authentication token.
	 * @param string $email         Account email.
	 */
	public static function remember( string $pending_token, string $email ): void {
		set_transient( self::key( $pending_token ), $email, self::TTL );
	}

	/**
	 * The email for a pending token.
	 *
	 * @param string $pending_token Pending authentication token.
	 *
	 * @return string Email, or '' when unknown or expired.
	 */
	public static function email_for( string $pending_token ): string {
		return (string) get_transient( self::key( $pending_token ) );
	}

	/**
	 * Forget a pending token once its sign-in has moved on.
	 *
	 * @param string $pending_token Pending authentication token.
	 */
	public static function forget( string $pending_token ): void {
		delete_transient( self::key( $pending_token ) );
	}

	/**
	 * Transient key for a pending token.
	 *
	 * @param string $pending_token Pending authentication token.
	 *
	 * @return string
	 */
	private static function key( string $pending_token ): string {
		return 'workos_email_verify_' . substr( hash( 'sha256', $pending_token ), 0, 40 );
	}
}
