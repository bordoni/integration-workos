<?php
/**
 * Keeps sign-ins that can't ask for a code from skipping MFA.
 *
 * @package WorkOS\Mfa
 */

namespace WorkOS\Mfa;

use WorkOS\Auth\AuthKit\MfaStep;
use WorkOS\Auth\AuthKit\Profile;
use WorkOS\Auth\AuthKit\ProfileRepository;
use WorkOS\User;
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
 * a challenge, and are created by a user who is already signed in. The
 * exemption covers only the user the application password belongs to, in
 * the sign-in it authenticated: an XML-RPC `system.multicall` runs several
 * sign-ins in one request.
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
	 * Login Profiles.
	 *
	 * @var ProfileRepository
	 */
	private ProfileRepository $profiles;

	/**
	 * User the current sign-in authenticated with an application password.
	 *
	 * @var int
	 */
	private int $app_password_user = 0;

	/**
	 * Constructor.
	 *
	 * @param Settings          $settings MFA settings.
	 * @param Policy            $policy   MFA policy.
	 * @param ProfileRepository $profiles Login Profiles.
	 */
	public function __construct( Settings $settings, Policy $policy, ProfileRepository $profiles ) {
		$this->settings = $settings;
		$this->policy   = $policy;
		$this->profiles = $profiles;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		// Before every authenticator: each sign-in starts with no exemption.
		add_filter( 'authenticate', [ $this, 'reset' ], 0 );
		// After every authenticator (core's run at 20, spam checks at 99).
		add_filter( 'authenticate', [ $this, 'guard' ], 100 );
		add_action( 'application_password_did_authenticate', [ $this, 'note_app_password' ] );
		add_filter( 'workos_relink_protected', [ $this, 'protect_link' ], 10, 2 );
	}

	/**
	 * `workos_relink_protected` filter: never re-link an account with MFA.
	 *
	 * A re-link points the account at another WorkOS user, whose factors
	 * are not the owner's. Whoever controls that identity would get past
	 * the second factor, or enroll their own. An administrator can still
	 * reassign it with `wp workos user link --force`.
	 *
	 * @param bool $is_protected Whether Re-link Protection applies.
	 * @param int  $wp_user_id   WP user ID.
	 *
	 * @return bool
	 */
	public function protect_link( $is_protected, $wp_user_id ): bool {
		if ( $is_protected ) {
			return true;
		}

		$wp_user_id = (int) $wp_user_id;

		return $wp_user_id > 0 && ( $this->policy->is_enrolled( $wp_user_id ) || $this->policy->is_required( $wp_user_id ) );
	}

	/**
	 * `authenticate` filter (first): forget the previous sign-in's exemption.
	 *
	 * @param WP_User|WP_Error|null $user Result so far.
	 *
	 * @return WP_User|WP_Error|null
	 */
	public function reset( $user ) {
		$this->app_password_user = 0;

		return $user;
	}

	/**
	 * `application_password_did_authenticate`: remember whose it was.
	 *
	 * @param WP_User $user User the application password belongs to.
	 *
	 * @return void
	 */
	public function note_app_password( $user ): void {
		$this->app_password_user = $user instanceof WP_User ? (int) $user->ID : 0;
	}

	/**
	 * `authenticate` filter: refuse a password-only sign-in that would skip MFA.
	 *
	 * @param WP_User|WP_Error|null $user Result so far.
	 *
	 * @return WP_User|WP_Error|null
	 */
	public function guard( $user ) {
		if ( ! $user instanceof WP_User || ( $this->app_password_user > 0 && $this->app_password_user === (int) $user->ID ) ) {
			return $user;
		}

		return $this->must_use_mfa( (int) $user->ID ) ? MfaStep::unsupported_error() : $user;
	}

	/**
	 * Whether a password-only sign-in would skip this user's MFA.
	 *
	 * True when the user has a factor or must use MFA, and, for a user
	 * linked to WorkOS, when the default Login Profile would ask for a code
	 * or an enrollment (`enforce = always`). Accounts not linked to WorkOS
	 * aren't held to a profile setting, so turning on `always` can't lock
	 * out a site's local administrators.
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

		if ( ! $must && User::is_sso( $user_id ) ) {
			$profile = $this->profiles->find_by_slug( Profile::DEFAULT_SLUG );
			$must    = $profile && Policy::NONE !== $this->policy->decide( $user_id, $profile );
		}

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
