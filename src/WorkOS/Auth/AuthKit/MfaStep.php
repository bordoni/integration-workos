<?php
/**
 * Reads the MFA step out of a WorkOS authenticate error.
 *
 * @package WorkOS\Auth\AuthKit
 */

namespace WorkOS\Auth\AuthKit;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * WorkOS reports a required second factor as an error, not a success.
 *
 * When the environment requires MFA, `authenticate` answers with a 4xx
 * whose body carries `code`:
 *
 * - `mfa_challenge`  — the user has a factor; the body lists it under
 *   `authentication_factors` next to a `pending_authentication_token`.
 * - `mfa_enrollment` — the user has no factor yet and must enroll one
 *   before the pending token can be completed.
 *
 * `Api\Client::parse_response()` wraps every 4xx as `workos_api_error`
 * with the decoded body under `data.body`, so callers use this class
 * instead of digging through the error data themselves.
 */
class MfaStep {

	/**
	 * WorkOS error code: the user must answer a challenge on an enrolled factor.
	 */
	public const CHALLENGE = 'mfa_challenge';

	/**
	 * WorkOS error code: the user must enroll a factor first.
	 */
	public const ENROLLMENT = 'mfa_enrollment';

	/**
	 * The WorkOS MFA code carried by a response, if any.
	 *
	 * @param mixed $response WorkOS authenticate response or WP_Error.
	 *
	 * @return string `mfa_challenge`, `mfa_enrollment`, or '' for anything else.
	 */
	public static function code( $response ): string {
		if ( ! $response instanceof WP_Error ) {
			return '';
		}

		$code = (string) ( self::body( $response )['code'] ?? '' );

		return in_array( $code, [ self::CHALLENGE, self::ENROLLMENT ], true ) ? $code : '';
	}

	/**
	 * Whether a response is a WorkOS MFA step.
	 *
	 * Paths that would otherwise retry with the WordPress password must
	 * check this first: the WorkOS password was correct, and only the
	 * second factor is missing.
	 *
	 * @param mixed $response WorkOS authenticate response or WP_Error.
	 *
	 * @return bool
	 */
	public static function is_mfa_error( $response ): bool {
		return '' !== self::code( $response );
	}

	/**
	 * Decoded WorkOS error body.
	 *
	 * @param WP_Error $error Error from the API client.
	 *
	 * @return array
	 */
	public static function body( WP_Error $error ): array {
		$data = $error->get_error_data();

		return is_array( $data ) && isset( $data['body'] ) && is_array( $data['body'] ) ? $data['body'] : [];
	}

	/**
	 * Error for sign-in paths that have no way to collect a second factor.
	 *
	 * Used by the wp-login.php headless filter and the login-button AJAX
	 * handler, which take an email and password only.
	 *
	 * @return WP_Error
	 */
	public static function unsupported_error(): WP_Error {
		return new WP_Error(
			'workos_mfa_step_unsupported',
			__( 'This account uses multi-factor authentication, which this sign-in form can’t complete. Contact the site administrator for help signing in.', 'integration-workos' ),
			[ 'status' => 403 ]
		);
	}
}
