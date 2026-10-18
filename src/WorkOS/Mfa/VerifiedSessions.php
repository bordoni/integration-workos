<?php
/**
 * WorkOS sessions that passed this site's sign-in.
 *
 * @package WorkOS\Mfa
 */

namespace WorkOS\Mfa;

defined( 'ABSPATH' ) || exit;

/**
 * Remembers which WorkOS sessions finished signing in here, so Bearer
 * tokens from anywhere else can't stand in for the plugin's MFA.
 *
 * Any app on the same WorkOS environment (another site, a staging copy,
 * hosted AuthKit) can get an access token for a user, and it verifies here
 * just the same. In `plugin` mode none of those asked for this site's
 * second factor, so for users who have or need MFA, `REST\TokenAuth` only
 * accepts a token whose session (`sid`) signed in through this site's
 * `LoginCompleter::finish()`, which every MFA path ends in.
 */
class VerifiedSessions {

	/**
	 * User meta key: session IDs that signed in here, newest last.
	 */
	public const META = '_workos_mfa_sessions';

	/**
	 * Sessions remembered per user.
	 */
	private const MAX = 10;

	/**
	 * MFA settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * MFA policy.
	 *
	 * @var Policy
	 */
	private Policy $policy;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings MFA settings.
	 * @param Policy   $policy   MFA policy.
	 */
	public function __construct( Settings $settings, Policy $policy ) {
		$this->settings = $settings;
		$this->policy   = $policy;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'workos_bearer_token_user', [ $this, 'gate' ], 10, 2 );
	}

	/**
	 * Remember the session in a WorkOS response that just signed in here.
	 *
	 * @param int   $user_id  WP user ID.
	 * @param array $response WorkOS authenticate response.
	 *
	 * @return void
	 */
	public static function remember( int $user_id, array $response ): void {
		$sid = self::session_id( (string) ( $response['access_token'] ?? '' ) );
		if ( '' === $sid ) {
			return;
		}

		$sessions   = array_values( array_diff( self::sessions( $user_id ), [ $sid ] ) );
		$sessions[] = $sid;

		update_user_meta( $user_id, self::META, array_slice( $sessions, -self::MAX ) );
	}

	/**
	 * `workos_bearer_token_user` filter: refuse a token that skipped MFA.
	 *
	 * @param int   $wp_user_id WP user the token maps to (0 for none).
	 * @param array $payload    Verified JWT claims.
	 *
	 * @return int
	 */
	public function gate( $wp_user_id, $payload ): int {
		$wp_user_id = (int) $wp_user_id;
		if ( $wp_user_id <= 0 || Settings::MODE_PLUGIN !== $this->settings->mode() ) {
			return $wp_user_id;
		}

		if ( ! $this->policy->is_enrolled( $wp_user_id ) && ! $this->policy->is_required( $wp_user_id ) ) {
			return $wp_user_id;
		}

		$sid    = is_array( $payload ) ? (string) ( $payload['sid'] ?? '' ) : '';
		$passed = '' !== $sid && in_array( $sid, self::sessions( $wp_user_id ), true );

		/**
		 * Filters whether a Bearer token may act for a user who has or needs MFA.
		 *
		 * By default only tokens from sessions that signed in through this
		 * site (and so passed its MFA step) are accepted.
		 *
		 * @param bool  $passed     Whether the token's session signed in here.
		 * @param int   $wp_user_id WP user ID.
		 * @param array $payload    Verified JWT claims.
		 */
		return apply_filters( 'workos_mfa_accept_bearer_token', $passed, $wp_user_id, $payload ) ? $wp_user_id : 0;
	}

	/**
	 * Session IDs that signed in here.
	 *
	 * @param int $user_id WP user ID.
	 *
	 * @return string[]
	 */
	private static function sessions( int $user_id ): array {
		$sessions = get_user_meta( $user_id, self::META, true );

		return is_array( $sessions ) ? array_map( 'strval', $sessions ) : [];
	}

	/**
	 * The `sid` claim of an access token WorkOS just issued.
	 *
	 * Not verified: the token came straight from WorkOS in this request.
	 *
	 * @param string $token Access token.
	 *
	 * @return string
	 */
	private static function session_id( string $token ): string {
		$parts = explode( '.', $token );
		if ( count( $parts ) < 2 ) {
			return '';
		}

		$payload = json_decode( (string) base64_decode( strtr( $parts[1], '-_', '+/' ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		return is_array( $payload ) ? (string) ( $payload['sid'] ?? '' ) : '';
	}
}
