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
 * at a few wrong codes, and gone a few minutes after it was parked, however
 * often it's touched. The parked WorkOS response is encrypted with a key
 * derived from the token, which the server never stores, so the tokens in
 * it can't be read from the database.
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
	 * Challenges remembered per sign-in (the newest ones).
	 */
	private const MAX_CHALLENGES = 5;

	/**
	 * Lifetime of a resume code, in seconds.
	 */
	private const RESUME_TTL = MINUTE_IN_SECONDS;

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

		$record['expires_at'] = time() + self::ttl();

		set_transient( self::key( $token ), self::seal( $token, $record ), self::ttl() );

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

		if ( (int) ( $record['expires_at'] ?? 0 ) <= time() ) {
			self::forget( $token );
			return null;
		}

		return self::open( $token, $record );
	}

	/**
	 * Save changes to a parked sign-in (factors, challenges, attempts).
	 *
	 * Keeps the original expiry, so touching a sign-in never extends it.
	 * A record that's already gone (finished, expired, or forgotten by a
	 * request running alongside) isn't brought back.
	 *
	 * @param string $token  Token from the browser.
	 * @param array  $record Updated record.
	 *
	 * @return void
	 */
	public static function update( string $token, array $record ): void {
		$left = (int) ( $record['expires_at'] ?? 0 ) - time();
		if ( $left <= 0 || false === get_transient( self::key( $token ) ) ) {
			return;
		}

		$record['challenges']        = array_slice( array_values( (array) ( $record['challenges'] ?? [] ) ), -self::MAX_CHALLENGES );
		$record['challenge_factors'] = array_intersect_key( (array) ( $record['challenge_factors'] ?? [] ), array_flip( $record['challenges'] ) );

		set_transient( self::key( $token ), self::seal( $token, $record ), $left );
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
	 * A one-time code that stands in for the token in a URL.
	 *
	 * The hosted sign-in has to send the browser back to the login page,
	 * and a URL ends up in history, logs and Referer headers. The code in
	 * it works once, for a minute, and only to get the real token into the
	 * page that resumes the sign-in.
	 *
	 * @param string $token Token of a parked sign-in.
	 *
	 * @return string
	 */
	public static function handoff( string $token ): string {
		$code = wp_generate_password( 32, false, false );

		set_transient( self::resume_key( $code ), $token, self::RESUME_TTL );

		return $code;
	}

	/**
	 * Swap a resume code for the token. Works once.
	 *
	 * @param string $code Code from the URL.
	 *
	 * @return string Token, or '' when the code is unknown, used or expired.
	 */
	public static function redeem( string $code ): string {
		if ( '' === $code ) {
			return '';
		}

		$token = get_transient( self::resume_key( $code ) );
		delete_transient( self::resume_key( $code ) );

		return is_string( $token ) ? $token : '';
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

	/**
	 * Transient key for a resume code.
	 *
	 * @param string $code Resume code.
	 *
	 * @return string
	 */
	private static function resume_key( string $code ): string {
		return 'workos_mfa_resume_' . substr( hash( 'sha256', $code ), 0, 40 );
	}

	/**
	 * Encrypt the parked WorkOS response for storage.
	 *
	 * @param string $token  Token from the browser.
	 * @param array  $record Record with a plain `response`.
	 *
	 * @return array
	 */
	private static function seal( string $token, array $record ): array {
		if ( ! isset( $record['response'] ) ) {
			return $record;
		}

		$nonce              = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain              = (string) wp_json_encode( $record['response'] );
		$record['response'] = base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::secret( $token ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		return $record;
	}

	/**
	 * Decrypt the parked WorkOS response.
	 *
	 * @param string $token  Token from the browser.
	 * @param array  $record Stored record.
	 *
	 * @return array|null Record with a plain `response`, or null when it can't be read.
	 */
	private static function open( string $token, array $record ): ?array {
		if ( ! isset( $record['response'] ) ) {
			return $record;
		}

		$raw = base64_decode( (string) $record['response'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}

		$plain = sodium_crypto_secretbox_open(
			substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			self::secret( $token )
		);
		if ( false === $plain ) {
			return null;
		}

		$record['response'] = json_decode( $plain, true );

		return is_array( $record['response'] ) ? $record : null;
	}

	/**
	 * Encryption key for a token's record.
	 *
	 * Derived from the token and the site's salt; the storage key is a
	 * different hash of the token, so it doesn't reveal this one.
	 *
	 * @param string $token Token from the browser.
	 *
	 * @return string 32 raw bytes.
	 */
	private static function secret( string $token ): string {
		return hash_hmac( 'sha256', 'workos_mfa_response|' . $token, wp_salt( 'auth' ), true );
	}
}
