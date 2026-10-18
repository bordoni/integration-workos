<?php
/**
 * Shared post-authentication finalizer.
 *
 * @package WorkOS\Auth\AuthKit
 */

namespace WorkOS\Auth\AuthKit;

use WorkOS\ActivityLog\EventLogger;
use WorkOS\App;
use WorkOS\Auth\Deactivation;
use WorkOS\Auth\Login;
use WorkOS\Auth\Redirect;
use WorkOS\Config;
use WorkOS\Mfa\FactorService;
use WorkOS\Mfa\PendingLogin;
use WorkOS\Mfa\Policy;
use WorkOS\Mfa\Settings as MfaSettings;
use WorkOS\Mfa\VerifiedSessions;
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
	 * Accepts either the parsed WorkOS authenticate response (array) or the
	 * `WP_Error` returned by the API client. WorkOS surfaces the "user must
	 * choose an organization" path as an error with `organization_selection_required`,
	 * so the same method needs to be able to recover from that error.
	 *
	 * @param array|WP_Error $workos_response         Response body from the WorkOS authenticate endpoint, or the WP_Error wrapping a WorkOS error response.
	 * @param Profile        $profile                 Active Login Profile.
	 * @param string         $redirect_to             Client-requested redirect (validated).
	 * @param bool           $honor_profile_redirect  Whether the profile's `post_login_redirect` should override `$redirect_to`. AuthKit-REST callers leave this `true`; the legacy hosted-AuthKit callback passes `false` so the state-supplied URL still wins for that flow.
	 * @param bool           $mfa_satisfied           Whether this response came from a completed second factor. `POST /auth/mfa/verify` passes `true` so an `enforce = always` profile accepts the login it just challenged.
	 *
	 * @return array|WP_Error
	 *   On success: {
	 *     'user'        => { id, email, display_name },
	 *     'redirect_to' => string,
	 *   }
	 *   When WorkOS or the plugin's MFA policy asks for a code, or the
	 *   user must enroll first (either mode): {
	 *     'mfa_required' => true,
	 *     'mfa_token'    => string,
	 *     'enroll'       => bool,
	 *     'factors'      => array,
	 *   }
	 *   When the email must be verified first: {
	 *     'email_verification_required'  => true,
	 *     'pending_authentication_token' => string,
	 *     'email'                        => string,
	 *   }
	 */
	public function complete( $workos_response, Profile $profile, string $redirect_to = '', bool $honor_profile_redirect = true, bool $mfa_satisfied = false ) {
		// WorkOS returns `organization_selection_required` when the
		// authenticate call resolves to a user that belongs to multiple
		// orgs without an org context. If this profile (or the global
		// setting) has an org pinned, complete transparently via the
		// organization-selection grant. With no pinned org we bail with a
		// clear error — there is no in-shell org picker by design.
		if ( is_wp_error( $workos_response ) ) {
			$mfa = $this->mfa_step( $workos_response, $profile, $redirect_to, $honor_profile_redirect );
			if ( is_wp_error( $mfa ) ) {
				return $mfa;
			}
			if ( null !== $mfa ) {
				return $mfa;
			}
		}

		if ( is_wp_error( $workos_response ) ) {
			$verification = $this->email_verification_step( $workos_response );
			if ( null !== $verification ) {
				return $verification;
			}

			$resolved = $this->maybe_resolve_organization_selection( $workos_response, $profile );
			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}
			$workos_response = $resolved;
		}

		// WorkOS signals a required second factor by returning a pending
		// authentication token and/or an authentication_factor. Park it and
		// hand the React shell an `mfa_token`, so the challenge and verify
		// routes only ever touch this sign-in's own factors.
		if ( ! empty( $workos_response['pending_authentication_token'] ) ) {
			return $this->workos_challenge(
				(string) $workos_response['pending_authentication_token'],
				$this->extract_factors( $workos_response ),
				(string) ( $workos_response['user']['id'] ?? '' ),
				$profile,
				$redirect_to,
				$honor_profile_redirect
			);
		}

		// When the profile insists MFA is always required and WorkOS did
		// not return a pending factor (i.e., the user has nothing enrolled
		// or WorkOS is not enforcing org-level MFA), we must refuse to
		// complete the login. Silently letting the user through would
		// quietly break the admin's "MFA required" guarantee. A response
		// from a verified second factor has no pending token by design,
		// so it is the one single-step success that may pass. In plugin
		// mode the policy below handles `always` by asking for a code or an
		// enrollment instead.
		if ( ! $mfa_satisfied && ! $this->plugin_decides() && Profile::MFA_ENFORCE_ALWAYS === ( $profile->get_mfa()['enforce'] ?? '' ) ) {
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

		$wp_user = UserSync::find_or_create_wp_user( $workos_user, 'authkit' );
		if ( is_wp_error( $wp_user ) ) {
			return $wp_user;
		}

		if ( Deactivation::is_deactivated( $wp_user->ID ) ) {
			return Deactivation::error();
		}

		$entitlement = EntitlementGate::evaluate( $wp_user->ID, $workos_response );
		if ( is_wp_error( $entitlement ) ) {
			return $entitlement;
		}

		if ( ! $mfa_satisfied && $this->plugin_decides() ) {
			$step = $this->plugin_mfa_step( $wp_user, $workos_response, $profile, $redirect_to, $honor_profile_redirect );
			if ( null !== $step ) {
				return $step;
			}
		}

		return $this->finish( $workos_response, $wp_user, $profile, $redirect_to, $honor_profile_redirect );
	}

	/**
	 * Sign the user in: store tokens, set the cookie, fire the login hooks.
	 *
	 * The last step of `complete()`, and the way a sign-in parked at the
	 * plugin's MFA step finishes once its code checks out. Callers must
	 * have run every gate first.
	 *
	 * @param array   $workos_response        WorkOS authenticate response.
	 * @param WP_User $wp_user                Matching WP user.
	 * @param Profile $profile                Active Login Profile.
	 * @param string  $redirect_to            Client-requested redirect.
	 * @param bool    $honor_profile_redirect Whether the profile's `post_login_redirect` wins.
	 *
	 * @return array { user, redirect_to }
	 */
	public function finish( array $workos_response, WP_User $wp_user, Profile $profile, string $redirect_to = '', bool $honor_profile_redirect = true ): array {
		Login::store_tokens( $wp_user->ID, $workos_response );
		VerifiedSessions::remember( (int) $wp_user->ID, $workos_response );

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
			'redirect_to' => $this->resolve_redirect( $profile, $redirect_to, $wp_user, $honor_profile_redirect ),
		];
	}

	/**
	 * Resolve the post-login redirect.
	 *
	 * For AuthKit-REST flows, profile-level `post_login_redirect` wins over
	 * the client's `redirect_to` — admins use profile redirects to enforce
	 * where each login page lands users. The legacy hosted-AuthKit callback
	 * passes `$honor_profile_redirect = false` so its state-supplied
	 * `redirect_to` still wins (the legacy contract).
	 *
	 * @param Profile $profile                Active profile.
	 * @param string  $redirect_to            Client-provided redirect URL.
	 * @param WP_User $wp_user                Authenticated user.
	 * @param bool    $honor_profile_redirect Whether to let the profile's `post_login_redirect` override `$redirect_to`.
	 *
	 * @return string
	 */
	private function resolve_redirect( Profile $profile, string $redirect_to, WP_User $wp_user, bool $honor_profile_redirect = true ): string {
		$profile_redirect = $honor_profile_redirect ? $profile->get_post_login_redirect() : '';
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

	/**
	 * Whether the plugin's MFA policy decides who is challenged.
	 *
	 * @return bool
	 */
	private function plugin_decides(): bool {
		return MfaSettings::MODE_PLUGIN === App::container()->get( MfaSettings::class )->mode();
	}

	/**
	 * The plugin's MFA step, after WorkOS signed the user in.
	 *
	 * Asks the policy what this sign-in needs. For a code or an enrollment,
	 * the WorkOS response is parked server-side and the browser gets an
	 * `mfa_token` instead, so no session exists until the code checks out.
	 *
	 * @param WP_User $wp_user                Matching WP user.
	 * @param array   $workos_response        WorkOS authenticate response.
	 * @param Profile $profile                Active Login Profile.
	 * @param string  $redirect_to            Client-requested redirect.
	 * @param bool    $honor_profile_redirect Whether the profile's `post_login_redirect` wins.
	 *
	 * @return array|WP_Error|null MFA step, a refusal, or null to sign in.
	 */
	private function plugin_mfa_step( WP_User $wp_user, array $workos_response, Profile $profile, string $redirect_to, bool $honor_profile_redirect ) {
		$policy   = App::container()->get( Policy::class );
		$decision = $policy->decide( $wp_user->ID, $profile );
		$factors  = [];

		// Ask WorkOS rather than trust the mirrored flag: a factor added
		// outside WordPress (or before this version) must still be
		// challenged. list_factors() sets the flag when it finds one, so the
		// policy is asked again.
		if ( Policy::BLOCK !== $decision ) {
			$factors = App::container()->get( FactorService::class )->list_factors( $wp_user->ID );
			if ( is_wp_error( $factors ) ) {
				return $factors;
			}

			$decision = $policy->decide( $wp_user->ID, $profile );

			// Only factors this profile allows can answer its challenge.
			$factors = array_values(
				array_filter(
					$factors,
					static fn( array $factor ): bool => $profile->allows_factor( (string) $factor['type'] )
				)
			);

			// The user turned MFA on, but WorkOS has no factor this profile
			// can use (removed outside WordPress, or SMS only). The flag
			// stays set, so a challenge with nothing to challenge becomes an
			// enrollment, at this sign-in and every one after it.
			if ( Policy::CHALLENGE === $decision && empty( $factors ) ) {
				$decision = Policy::ENROLL;
			}
		}

		if ( Policy::NONE === $decision ) {
			return null;
		}

		if ( Policy::BLOCK === $decision ) {
			EventLogger::log(
				'mfa.blocked',
				[
					'user_id'  => $wp_user->ID,
					'metadata' => [ 'profile' => $profile->get_slug() ],
				]
			);

			/**
			 * Fires when a sign-in is refused because its Login Profile can't
			 * satisfy the user's MFA (the profile turns MFA off, or doesn't
			 * allow TOTP, and the user has or needs a factor).
			 *
			 * @param int     $user_id WP user ID.
			 * @param Profile $profile Login Profile.
			 */
			do_action( 'workos_mfa_blocked', $wp_user->ID, $profile );

			/**
			 * Filters the message shown when a sign-in is blocked by MFA policy.
			 *
			 * @param string  $message Message.
			 * @param int     $user_id WP user ID.
			 * @param Profile $profile Login Profile.
			 */
			$message = (string) apply_filters(
				'workos_mfa_block_message',
				__( 'Your account uses multi-factor authentication, which this sign-in page doesn’t support. Sign in from another page, or contact the site administrator.', 'integration-workos' ),
				$wp_user->ID,
				$profile
			);

			return new WP_Error( 'workos_mfa_blocked', $message, [ 'status' => 403 ] );
		}

		$enroll = Policy::ENROLL === $decision;
		$token  = PendingLogin::park(
			[
				'kind'                   => PendingLogin::KIND_PLUGIN,
				'profile'                => $profile->get_slug(),
				'enroll'                 => $enroll,
				'redirect_to'            => $redirect_to,
				'honor_profile_redirect' => $honor_profile_redirect,
				'user_id'                => (int) $wp_user->ID,
				'response'               => $workos_response,
				'factor_ids'             => $enroll ? [] : array_column( $factors, 'id' ),
				'factors'                => $enroll ? [] : $this->public_factors( $factors ),
			]
		);

		return [
			'mfa_required' => true,
			'mfa_token'    => $token,
			'enroll'       => $enroll,
			'factors'      => $enroll ? [] : $this->public_factors( $factors ),
		];
	}

	/**
	 * Factor fields the browser needs.
	 *
	 * @param array $factors Factors from FactorService::list_factors().
	 *
	 * @return array List of `{ id, type }`.
	 */
	private function public_factors( array $factors ): array {
		return array_map(
			static function ( array $factor ): array {
				return [
					'id'   => (string) $factor['id'],
					'type' => (string) $factor['type'],
				];
			},
			$factors
		);
	}

	/**
	 * The MFA step, when WorkOS asks for one.
	 *
	 * Both steps park WorkOS's pending token and hand the browser an
	 * `mfa_token`. `mfa_challenge` keeps the factors WorkOS named;
	 * `mfa_enrollment` keeps the WorkOS user, to enroll. Without a WorkOS
	 * user in the error there's nothing to enroll, so it's refused.
	 *
	 * @param WP_Error $error                  Error from the WorkOS authenticate call.
	 * @param Profile  $profile                Active Login Profile.
	 * @param string   $redirect_to            Client-requested redirect.
	 * @param bool     $honor_profile_redirect Whether the profile's `post_login_redirect` wins.
	 *
	 * @return array|WP_Error|null The MFA step, a refusal, or null for any other error.
	 */
	private function mfa_step( WP_Error $error, Profile $profile, string $redirect_to, bool $honor_profile_redirect ) {
		$code = MfaStep::code( $error );
		if ( '' === $code ) {
			return null;
		}

		$body          = MfaStep::body( $error );
		$pending_token = (string) ( $body['pending_authentication_token'] ?? '' );
		if ( '' === $pending_token ) {
			return null;
		}

		$workos_user = isset( $body['user'] ) && is_array( $body['user'] ) ? $body['user'] : [];
		if ( MfaStep::ENROLLMENT === $code && ! empty( $workos_user['id'] ) && $profile->allows_factor( Profile::FACTOR_TOTP ) ) {
			$token = PendingLogin::park(
				[
					'kind'                         => PendingLogin::KIND_WORKOS,
					'profile'                      => $profile->get_slug(),
					'enroll'                       => true,
					'redirect_to'                  => $redirect_to,
					'honor_profile_redirect'       => $honor_profile_redirect,
					'pending_authentication_token' => $pending_token,
					'workos_user_id'               => (string) $workos_user['id'],
					'email'                        => (string) ( $workos_user['email'] ?? '' ),
				]
			);

			return [
				'mfa_required' => true,
				'mfa_token'    => $token,
				'enroll'       => true,
				'factors'      => [],
			];
		}

		if ( MfaStep::ENROLLMENT === $code ) {
			return new WP_Error(
				'workos_authkit_mfa_enrollment_required',
				__( 'This account must set up multi-factor authentication before it can sign in. Contact the site administrator for help.', 'integration-workos' ),
				[ 'status' => 403 ]
			);
		}

		return $this->workos_challenge(
			$pending_token,
			isset( $body['authentication_factors'] ) && is_array( $body['authentication_factors'] ) ? $body['authentication_factors'] : [],
			(string) ( $workos_user['id'] ?? '' ),
			$profile,
			$redirect_to,
			$honor_profile_redirect
		);
	}

	/**
	 * Park a sign-in WorkOS wants a code for.
	 *
	 * The browser gets an `mfa_token`, never WorkOS's pending token, and
	 * may challenge only the factors WorkOS named for this sign-in. Factors
	 * of a type the profile doesn't allow refuse the sign-in rather than
	 * completing through a method the admin turned off.
	 *
	 * @param string  $pending_token          WorkOS pending authentication token.
	 * @param array   $factors                Factors WorkOS returned.
	 * @param string  $workos_user_id         WorkOS user, when WorkOS named one (for the per-account code limit).
	 * @param Profile $profile                Active Login Profile.
	 * @param string  $redirect_to            Client-requested redirect.
	 * @param bool    $honor_profile_redirect Whether the profile's `post_login_redirect` wins.
	 *
	 * @return array|WP_Error
	 */
	private function workos_challenge( string $pending_token, array $factors, string $workos_user_id, Profile $profile, string $redirect_to, bool $honor_profile_redirect ) {
		$allowed = (array) ( $profile->get_mfa()['factors'] ?? [] );
		$listed  = [];
		foreach ( $factors as $factor ) {
			if ( ! is_array( $factor ) || empty( $factor['id'] ) ) {
				continue;
			}

			$type = (string) ( $factor['type'] ?? '' );
			if ( ! empty( $allowed ) && '' !== $type && ! in_array( $type, $allowed, true ) ) {
				return new WP_Error(
					'workos_authkit_factor_not_allowed',
					__( 'The multi-factor method returned is not permitted for this login.', 'integration-workos' ),
					[ 'status' => 403 ]
				);
			}

			$listed[] = [
				'id'   => (string) $factor['id'],
				'type' => $type,
			];
		}

		$token = PendingLogin::park(
			[
				'kind'                         => PendingLogin::KIND_WORKOS,
				'profile'                      => $profile->get_slug(),
				'enroll'                       => false,
				'redirect_to'                  => $redirect_to,
				'honor_profile_redirect'       => $honor_profile_redirect,
				'pending_authentication_token' => $pending_token,
				'workos_user_id'               => $workos_user_id,
				'factor_ids'                   => array_column( $listed, 'id' ),
				'factors'                      => $listed,
			]
		);

		return [
			'mfa_required' => true,
			'mfa_token'    => $token,
			'enroll'       => false,
			'factors'      => $listed,
		];
	}

	/**
	 * The email-verification step, when WorkOS asks for one.
	 *
	 * WorkOS has already mailed the code; the shell collects it and finishes
	 * the sign-in with the pending token.
	 *
	 * @param WP_Error $error Error from the WorkOS authenticate call.
	 *
	 * @return array|null Step payload, or null for any other error.
	 */
	private function email_verification_step( WP_Error $error ): ?array {
		$data = $error->get_error_data();
		$body = is_array( $data ) && isset( $data['body'] ) && is_array( $data['body'] ) ? $data['body'] : [];

		$pending_token = (string) ( $body['pending_authentication_token'] ?? '' );
		if ( 'email_verification_required' !== ( $body['code'] ?? '' ) || '' === $pending_token ) {
			return null;
		}

		$email = (string) ( $body['email'] ?? '' );
		PendingEmailVerification::remember( $pending_token, $email );

		return [
			'email_verification_required'  => true,
			'pending_authentication_token' => $pending_token,
			'email'                        => $email,
		];
	}

	/**
	 * Attempt to recover from a WorkOS `organization_selection_required` error.
	 *
	 * Returns one of:
	 *  - the fresh authenticate response (array) when a pinned org was found
	 *    and the follow-up grant succeeded — caller should treat this as the
	 *    new `$workos_response` and continue;
	 *  - the original WP_Error untouched, if the error wasn't an
	 *    org-selection prompt;
	 *  - a WP_Error from the follow-up authenticate call if that fails;
	 *  - a `workos_authkit_no_pinned_org` WP_Error when WorkOS demands org
	 *    selection but neither the profile nor the global config has an org
	 *    pinned (there is no in-shell picker).
	 *
	 * @param WP_Error $error   Error returned by the prior authenticate call.
	 * @param Profile  $profile Active Login Profile.
	 *
	 * @return array|WP_Error
	 */
	private function maybe_resolve_organization_selection( WP_Error $error, Profile $profile ) {
		$data = $error->get_error_data();
		$body = is_array( $data ) && isset( $data['body'] ) && is_array( $data['body'] ) ? $data['body'] : [];

		$code = (string) ( $body['code'] ?? '' );
		if ( 'organization_selection_required' !== $code ) {
			return $error;
		}

		$pending_token = (string) ( $body['pending_authentication_token'] ?? '' );
		if ( '' === $pending_token ) {
			return $error;
		}

		$pinned_org = $profile->get_organization_id();
		if ( '' === $pinned_org ) {
			$pinned_org = (string) Config::get_organization_id();
		}

		if ( '' === $pinned_org ) {
			return new WP_Error(
				'workos_authkit_no_pinned_org',
				__( 'This account belongs to multiple organizations. Pin an organization on the Login Profile to continue.', 'integration-workos' ),
				[ 'status' => 409 ]
			);
		}

		// If the pinned org is not in the candidate list, the user isn't yet
		// a member. For pre-existing WP users (legacy accounts created
		// before the org pin was set up) we self-heal by creating the
		// membership in WorkOS and retrying — this matches the intent of
		// pinning an org to a Login Profile.
		$organizations = isset( $body['organizations'] ) && is_array( $body['organizations'] ) ? $body['organizations'] : [];
		if ( ! empty( $organizations ) ) {
			$ids = array_filter(
				array_map(
					static function ( $org ) {
						return is_array( $org ) ? (string) ( $org['id'] ?? '' ) : '';
					},
					$organizations
				)
			);
			if ( ! in_array( $pinned_org, $ids, true ) ) {
				$attached = $this->attach_existing_user_to_org( $body, $pinned_org );
				if ( is_wp_error( $attached ) ) {
					return $attached;
				}
			}
		}

		return workos()->api()->authenticate_with_organization_selection( $pending_token, $pinned_org );
	}

	/**
	 * Add an existing user to the pinned organization in WorkOS.
	 *
	 * Self-heals legacy users who pre-date the org pin on the Login Profile:
	 * if the email in the org-selection error already maps to a WP user, we
	 * create the WorkOS membership against the pinned org so the follow-up
	 * organization-selection grant can complete.
	 *
	 * @param array  $body       Decoded error body from WorkOS — contains email and possibly user_id.
	 * @param string $pinned_org Pinned WorkOS organization ID.
	 *
	 * @return true|WP_Error true when the membership exists (or was just created); WP_Error when we
	 *                       refuse to self-heal (no matching local user) or the API call failed.
	 */
	private function attach_existing_user_to_org( array $body, string $pinned_org ) {
		$email = (string) ( $body['email'] ?? '' );
		if ( '' === $email ) {
			return new WP_Error(
				'workos_authkit_pinned_org_mismatch',
				__( 'This account is not a member of the organization pinned to this login.', 'integration-workos' ),
				[ 'status' => 403 ]
			);
		}

		$wp_user = get_user_by( 'email', $email );
		if ( ! $wp_user ) {
			return new WP_Error(
				'workos_authkit_pinned_org_mismatch',
				__( 'This account is not a member of the organization pinned to this login.', 'integration-workos' ),
				[ 'status' => 403 ]
			);
		}

		// Use the user_id WorkOS attached to the org-selection error body —
		// that's the user who just authenticated, so it's the only safe
		// identifier to enroll. WorkOS treats emails as case-insensitive
		// but does not enforce workspace-wide uniqueness, so a lookup by
		// email can pick a different user than the one this session
		// belongs to. If the body has no user_id we refuse to self-heal
		// rather than guessing.
		$workos_user_id = (string) ( $body['user_id'] ?? '' );

		if ( '' === $workos_user_id ) {
			return new WP_Error(
				'workos_authkit_pinned_org_mismatch',
				__( 'This account is not a member of the organization pinned to this login.', 'integration-workos' ),
				[ 'status' => 403 ]
			);
		}

		$source_code = (string) ( $body['code'] ?? 'organization_selection_required' );

		$membership = workos()->api()->create_organization_membership( $workos_user_id, $pinned_org );
		if ( is_wp_error( $membership ) ) {
			$membership_data = $membership->get_error_data();
			$membership_body = is_array( $membership_data ) && isset( $membership_data['body'] ) && is_array( $membership_data['body'] ) ? $membership_data['body'] : [];
			// A duplicate-membership response means we're already good to retry.
			if ( 'entity_already_exists' === (string) ( $membership_body['code'] ?? '' ) ) {
				workos_log(
					sprintf(
						'Auto-enroll: WorkOS user %s already a member of pinned org %s (source: %s); proceeding to organization-selection grant.',
						$workos_user_id,
						$pinned_org,
						$source_code
					),
					'info'
				);
				return true;
			}
			return $membership;
		}

		workos_log(
			sprintf(
				'Auto-enroll: created WorkOS membership for user %s in pinned org %s (source: %s) during AuthKit login recovery.',
				$workos_user_id,
				$pinned_org,
				$source_code
			),
			'info'
		);

		return true;
	}
}
