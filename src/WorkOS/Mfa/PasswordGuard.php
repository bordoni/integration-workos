<?php
/**
 * Keeps sign-ins that can't ask for a code from skipping MFA.
 *
 * @package WorkOS\Mfa
 */

namespace WorkOS\Mfa;

use WorkOS\Auth\AuthKit\MfaStep;
use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Refuses password-only sign-ins for users who have or need MFA.
 *
 * In `plugin` mode the second factor is asked for by the AuthKit sign-in
 * flow. Paths that only take a username and password (`wp-login.php` with
 * `?fallback=1`, `wp_signon()`, headless mode, the login button) have no
 * step to ask on, so for a user who has a factor or must use MFA they'd
 * be a way around it. They're refused instead.
 *
 * Application passwords are exempt: they're for API clients, can't answer
 * a challenge, and are created by a user who is already signed in.
 *
 * In `workos` mode WorkOS asks for the code, but a WordPress password
 * never reaches WorkOS. Sites in that mode should turn off the password
 * fallback.
 */
class PasswordGuard {

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
		// After every authenticator (core's run at 20, spam checks at 99).
		add_filter( 'authenticate', [ $this, 'guard' ], 100 );
	}

	/**
	 * `authenticate` filter: refuse a password-only sign-in that would skip MFA.
	 *
	 * @param WP_User|WP_Error|null $user Result so far.
	 *
	 * @return WP_User|WP_Error|null
	 */
	public function guard( $user ) {
		if ( ! $user instanceof WP_User || did_action( 'application_password_did_authenticate' ) ) {
			return $user;
		}

		return $this->must_use_mfa( (int) $user->ID ) ? MfaStep::unsupported_error() : $user;
	}

	/**
	 * Whether a password-only sign-in would skip this user's MFA.
	 *
	 * @param int $user_id WP user ID.
	 *
	 * @return bool
	 */
	public function must_use_mfa( int $user_id ): bool {
		if ( Settings::MODE_PLUGIN !== $this->settings->mode() ) {
			return false;
		}

		$must = $this->policy->is_enrolled( $user_id ) || $this->policy->is_required( $user_id );

		/**
		 * Filters whether a password-only sign-in may skip MFA for a user.
		 *
		 * Return true to let `wp-login.php`, `wp_signon()`, headless mode
		 * and the login button sign this user in without a code.
		 *
		 * @param bool $allow   Default false.
		 * @param int  $user_id WP user ID.
		 */
		return $must && ! apply_filters( 'workos_mfa_allow_password_fallback', false, $user_id );
	}
}
