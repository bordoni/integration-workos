<?php
/**
 * A sign-in paused at the MFA step.
 *
 * @package WorkOS\Mfa
 */

namespace WorkOS\Mfa;

defined( 'ABSPATH' ) || exit;

/**
 * Holds a sign-in between the first factor and the code.
 *
 * Two kinds:
 *
 * - `plugin`: WorkOS already signed the user in, and the plugin's policy
 *   wants a code first. The WorkOS response (tokens included) waits here
 *   instead of reaching the browser, and the login finishes from it once
 *   the code checks out.
 * - `workos`: WorkOS answered `mfa_enrollment`. Its pending token and user
 *   wait here so the browser never has to name the WorkOS user to enroll.
 *
 * The browser only holds the random `mfa_token`. The record is keyed on a
 * hash of it, bound to the Login Profile it started on, used once, capped
 * at a few wrong codes, and gone after a few minutes.
 */
class PendingLogin {

	/**
	 * WorkOS signed the user in; the plugin wants a code first.
	 */
	public const KIND_PLUGIN = 'plugin';

	/**
	 * WorkOS wants the user to enroll before it finishes the sign-in.
	 */
	public const KIND_WORKOS = 'workos';

	/**
	 * Wrong codes allowed before the sign-in has to start over.
	 */
	public const MAX_ATTEMPTS = 5;

	/**
	 * Default lifetime, in seconds.
	 */
	private const TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * Park a sign-in and return the token the browser carries.
	 *
	 * @param array $record {
	 *     Sign-in to park.
	 *
	 *     @type string $kind                         KIND_PLUGIN or KIND_WORKOS.
	 *     @type string $profile                      Login Profile slug.
	 *     @type bool   $enroll                       Whether the user must enroll first.
	 *     @type string $redirect_to                  Requested redirect.
	 *     @type bool   $honor_profile_redirect       Whether the profile redirect wins.
	 *     @type int    $user_id                      WP user (plugin kind).
	 *     @type array  $response                     WorkOS authenticate response (plugin kind).
	 *     @type string $pending_authentication_token WorkOS pending token (workos kind).
	 *     @type string $workos_user_id               WorkOS user (workos kind).
	 *     @type string $email                        Account email (workos kind), for the authenticator label.
	 *     @type array  $factor_ids                   Factors the browser may challenge.
	 *     @type array  $factors                      The same factors as `{ id, type }`, to resume the step on another page.
	 * }
	 *
	 * @return string
	 */
	public static function park( array $record ): string {
		$token = wp_generate_password( 43, false, false );

		$record = array_merge(
			[
				'kind'                   => self::KIND_PLUGIN,
				'profile'                => '',
				'enroll'                 => false,
				'redirect_to'            => '',
				'honor_profile_redirect' => true,
				'factor_ids'             => [],
				'factors'                => [],
				'challenges'             => [],
				'attempts'               => 0,
			],
			$record
		);

		set_transient( self::key( $token ), $record, self::ttl() );

		return $token;
	}

	/**
	 * The parked sign-in, if the token is live and belongs to the profile.
	 *
	 * @param string $token        Token from the browser.
	 * @param string $profile_slug Profile the request came through.
	 *
	 * @return array|null
	 */
	public static function get( string $token, string $profile_slug ): ?array {
		if ( '' === $token ) {
			return null;
		}

		$record = get_transient( self::key( $token ) );
		if ( ! is_array( $record ) || ( $record['profile'] ?? '' ) !== $profile_slug ) {
			return null;
		}

		return $record;
	}

	/**
	 * Save changes to a parked sign-in (factors, challenges, attempts).
	 *
	 * The lifetime restarts, which is fine: wrong codes are capped, and the
	 * record is deleted as soon as the sign-in finishes.
	 *
	 * @param string $token  Token from the browser.
	 * @param array  $record Updated record.
	 *
	 * @return void
	 */
	public static function update( string $token, array $record ): void {
		set_transient( self::key( $token ), $record, self::ttl() );
	}

	/**
	 * Count a wrong code. Forgets the sign-in once the cap is reached.
	 *
	 * @param string $token  Token from the browser.
	 * @param array  $record Current record.
	 *
	 * @return bool Whether the sign-in can still be finished.
	 */
	public static function fail( string $token, array $record ): bool {
		$record['attempts'] = (int) ( $record['attempts'] ?? 0 ) + 1;

		if ( $record['attempts'] >= self::MAX_ATTEMPTS ) {
			self::forget( $token );
			return false;
		}

		self::update( $token, $record );
		return true;
	}

	/**
	 * Forget a parked sign-in.
	 *
	 * @param string $token Token from the browser.
	 *
	 * @return void
	 */
	public static function forget( string $token ): void {
		delete_transient( self::key( $token ) );
	}

	/**
	 * Lifetime in seconds.
	 *
	 * @return int
	 */
	private static function ttl(): int {
		/**
		 * Filters how long a sign-in may wait at the MFA step, in seconds.
		 *
		 * @param int $ttl Lifetime. Default 10 minutes.
		 */
		return max( MINUTE_IN_SECONDS, (int) apply_filters( 'workos_mfa_pending_ttl', self::TTL ) );
	}

	/**
	 * Transient key for a token.
	 *
	 * @param string $token Token from the browser.
	 *
	 * @return string
	 */
	private static function key( string $token ): string {
		return 'workos_mfa_pending_' . substr( hash( 'sha256', $token ), 0, 40 );
	}
}
