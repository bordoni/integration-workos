<?php
/**
 * Shared post-authentication finalizer.
 *
 * @package WorkOS\Auth\AuthKit
 */

namespace WorkOS\Auth\AuthKit;

use WorkOS\Auth\Login;
use WorkOS\Auth\Redirect;
use WorkOS\Organization\EntitlementGate;
use WorkOS\Sync\UserSync;
use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Completes a login once the WorkOS `authenticate` endpoint has returned.
 *
 * Every first-factor REST route (password, magic, OAuth callback, signup
 * verify, invitation accept, MFA verify) ends the same way:
 *
 * 1. If WorkOS returned a pending MFA factor, surface that step to the UI.
 * 2. Look up / create the matching WP user.
 * 3. Run the EntitlementGate against the configured organization.
 * 4. Persist WorkOS tokens to usermeta.
 * 5. Set the WP auth cookie and fire `wp_login`.
 * 6. Compute the final redirect.
 *
 * The same method is used by the React shell for in-page auth and by the
 * `workos/callback` OAuth redirect handler, so UX stays consistent across
 * all first-factor paths.
 */
class LoginCompleter {

	/**
	 * Constructor.
	 *
	 * Takes no arguments — every completed AuthKit login is a "remember
	 * me" session by design (matches the legacy headless path). If that
	 * ever needs to be tunable per profile it belongs on the Profile
	 * value object, not as an opaque parameter here.
	 */
	public function __construct() {
	}

	/**
	 * Complete a login or surface the next step.
	 *
	 * @param array   $workos_response Response body from the WorkOS authenticate endpoint.
	 * @param Profile $profile         Active Login Profile.
	 * @param string  $redirect_to     Client-requested redirect (validated).
	 *
	 * @return array|WP_Error
	 *   On success: {
	 *     'user'        => { id, email, display_name },
	 *     'redirect_to' => string,
	 *   }
	 *   When MFA is required: {
	 *     'mfa_required'                 => true,
	 *     'pending_authentication_token' => string,
	 *     'factors'                      => array,
	 *   }
	 */
	public function complete( array $workos_response, Profile $profile, string $redirect_to = '' ) {
		// WorkOS signals a required second factor by returning a pending
		// authentication token and/or an authentication_factor. Hand that
		// state back to the React shell so it can run the MFA challenge
		// step and then POST /auth/mfa/verify.
		if ( ! empty( $workos_response['pending_authentication_token'] ) ) {
			$factors = $this->extract_factors( $workos_response );

			// Enforce the profile's factor allowlist. If WorkOS surfaces a
			// challenge for a factor type the admin has disabled on this
			// profile, reject rather than completing via an unauthorized
			// factor.
			$allowed_types = $profile->get_mfa()['factors'] ?? [];
			if ( ! empty( $allowed_types ) ) {
				foreach ( $factors as $factor ) {
					$type = (string) ( $factor['type'] ?? '' );
					if ( '' !== $type && ! in_array( $type, $allowed_types, true ) ) {
						return new WP_Error(
							'workos_authkit_factor_not_allowed',
							__( 'The multi-factor method returned is not permitted for this login.', 'integration-workos' ),
							[ 'status' => 403 ]
						);
					}
				}
			}

			return [
				'mfa_required'                 => true,
				'pending_authentication_token' => (string) $workos_response['pending_authentication_token'],
				'factors'                      => $factors,
			];
		}

		// When the profile insists MFA is always required and WorkOS did
		// not return a pending factor (i.e., the user has nothing enrolled
		// or WorkOS is not enforcing org-level MFA), we must refuse to
		// complete the login. Silently letting the user through would
		// quietly break the admin's "MFA required" guarantee.
		if ( Profile::MFA_ENFORCE_ALWAYS === ( $profile->get_mfa()['enforce'] ?? '' ) ) {
			return new WP_Error(
				'workos_authkit_mfa_required',
				__( 'This login requires multi-factor authentication, but no factor is enrolled on your account. Please enroll a factor and try again.', 'integration-workos' ),
				[ 'status' => 403 ]
			);
		}

		$workos_user = $workos_response['user'] ?? null;
		if ( ! is_array( $workos_user ) || empty( $workos_user['id'] ) ) {
			return new WP_Error(
				'workos_authkit_invalid_response',
				__( 'Unexpected response from the authentication provider.', 'integration-workos' ),
				[ 'status' => 502 ]
			);
		}

		$wp_user = UserSync::find_or_create_wp_user( $workos_user );
		if ( is_wp_error( $wp_user ) ) {
			return $wp_user;
		}

		$entitlement = EntitlementGate::evaluate( $wp_user->ID, $workos_response );
		if ( is_wp_error( $entitlement ) ) {
			return $entitlement;
		}

		Login::store_tokens( $wp_user->ID, $workos_response );

		wp_set_auth_cookie( $wp_user->ID, true );
		wp_set_current_user( $wp_user->ID );

		/** This action is documented in Auth/Login.php */
		do_action( 'workos_user_authenticated', $wp_user->ID, $workos_response );

		/**
		 * Fires on WP login.
		 *
		 * @param string   $user_login Username.
		 * @param WP_User $wp_user    User object.
		 */
		do_action( 'wp_login', $wp_user->user_login, $wp_user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		return [
			'user'        => [
				'id'           => (int) $wp_user->ID,
				'email'        => (string) $wp_user->user_email,
				'display_name' => (string) $wp_user->display_name,
			],
			'redirect_to' => $this->resolve_redirect( $profile, $redirect_to, $wp_user ),
		];
	}

	/**
	 * Resolve the post-login redirect.
	 *
	 * Profile-level `post_login_redirect` wins over the client's
	 * `redirect_to` — this is intentional: admins use profile redirects to
	 * enforce where each login page lands users. Falls back through the
	 * existing Redirect::resolve() to honor role-based rules.
	 *
	 * @param Profile $profile     Active profile.
	 * @param string  $redirect_to Client-provided redirect URL.
	 * @param WP_User $wp_user     Authenticated user.
	 *
	 * @return string
	 */
	private function resolve_redirect( Profile $profile, string $redirect_to, WP_User $wp_user ): string {
		$profile_redirect = $profile->get_post_login_redirect();
		$requested        = '' !== $profile_redirect ? $profile_redirect : $redirect_to;

		if ( '' === $requested ) {
			$requested = admin_url();
		}

		// Redirect::resolve applies role-based rules AND wp_validate_redirect.
		return Redirect::resolve( $requested, $wp_user );
	}

	/**
	 * Normalize factor metadata returned alongside a pending auth token.
	 *
	 * WorkOS returns either a single `authentication_factor` object or an
	 * array under `authentication_factors` depending on context. Always
	 * return a list so the React shell has a predictable shape.
	 *
	 * @param array $workos_response WorkOS authenticate response.
	 *
	 * @return array
	 */
	private function extract_factors( array $workos_response ): array {
		if ( isset( $workos_response['authentication_factors'] ) && is_array( $workos_response['authentication_factors'] ) ) {
			return $workos_response['authentication_factors'];
		}

		if ( isset( $workos_response['authentication_factor'] ) && is_array( $workos_response['authentication_factor'] ) ) {
			return [ $workos_response['authentication_factor'] ];
		}

		return [];
	}
}
