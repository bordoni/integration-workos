<?php
/**
 * Keep deactivated accounts from signing in.
 *
 * @package WorkOS\Auth
 */

namespace WorkOS\Auth;

use WorkOS\User;
use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * An account deprovisioned by WorkOS (`user.deleted`, Deprovision Action
 * "deactivate" or "demote") carries `_workos_deactivated`. Nothing used to read
 * it, so the account could still sign in with its WordPress password through
 * `?fallback=1`, or through WorkOS when a new WorkOS user matched it by email.
 *
 * Every sign-in path checks it now:
 *
 * - WordPress passwords (wp-login.php, `wp_signon()`) and the headless WorkOS
 *   password path: a late `authenticate` filter.
 * - WorkOS sign-in (AuthKit, the OAuth callback): `LoginCompleter::complete()`.
 * - The login button's AJAX form: `UI\Ajax`.
 */
class Deactivation {

	/**
	 * Error code returned to every sign-in path.
	 */
	public const ERROR_CODE = 'workos_user_deactivated';

	/**
	 * Constructor — registers hooks.
	 */
	public function __construct() {
		// After every authenticator (core's run at 20, spam checks at 99).
		add_filter( 'authenticate', [ $this, 'block_deactivated' ], 100 );
	}

	/**
	 * Whether a WP user was deactivated by WorkOS.
	 *
	 * @param int $user_id WP user ID.
	 *
	 * @return bool
	 */
	public static function is_deactivated( int $user_id ): bool {
		$deactivated = $user_id > 0 && (bool) get_user_meta( $user_id, User::META_DEACTIVATED, true );

		/**
		 * Filter whether a WP user counts as deactivated, and so can't sign in.
		 *
		 * @param bool $deactivated Default: the `_workos_deactivated` user meta.
		 * @param int  $user_id     WP user ID.
		 */
		return (bool) apply_filters( 'workos_user_is_deactivated', $deactivated, $user_id );
	}

	/**
	 * The error every sign-in path returns for a deactivated account.
	 *
	 * @return WP_Error
	 */
	public static function error(): WP_Error {
		return new WP_Error(
			self::ERROR_CODE,
			__( 'This account has been deactivated. Contact support if you think this is a mistake.', 'integration-workos' ),
			[ 'status' => 403 ]
		);
	}

	/**
	 * `authenticate` filter: refuse a deactivated account, whoever authenticated it.
	 *
	 * @param WP_User|WP_Error|null $user Result so far.
	 *
	 * @return WP_User|WP_Error|null
	 */
	public function block_deactivated( $user ) {
		if ( $user instanceof WP_User && self::is_deactivated( $user->ID ) ) {
			return self::error();
		}

		return $user;
	}
}
