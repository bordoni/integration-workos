<?php
/**
 * MFA policy.
 *
 * @package WorkOS\Mfa
 */

namespace WorkOS\Mfa;

use WorkOS\Auth\AuthKit\Profile;
use WorkOS\User;

defined( 'ABSPATH' ) || exit;

/**
 * Decides what a sign-in needs after the first factor succeeded.
 *
 * Inputs, in order: the mode (only `plugin` mode decides anything here),
 * the Login Profile's `mfa.enforce` and factor list, whether the user has
 * a confirmed factor, and whether the user must use MFA (admin flag,
 * required role, or the `workos_mfa_required_for_user` filter).
 *
 * | Profile enforce | Enrolled | Required | Decision  |
 * |-----------------|----------|----------|-----------|
 * | never / no TOTP | yes      | any      | block     |
 * | never / no TOTP | no       | yes      | block     |
 * | never / no TOTP | no       | no       | none      |
 * | if_required     | yes      | any      | challenge |
 * | if_required     | no       | yes      | enroll    |
 * | if_required     | no       | no       | none      |
 * | always          | yes      | any      | challenge |
 * | always          | no       | any      | enroll    |
 *
 * A profile that turns MFA off never downgrades a user who has it: the
 * sign-in is blocked instead, so the admin sees the conflict.
 */
class Policy {

	/**
	 * Sign in without a second factor.
	 */
	public const NONE = 'none';

	/**
	 * Ask for a code from the user's enrolled factor.
	 */
	public const CHALLENGE = 'challenge';

	/**
	 * Have the user enroll a factor, then confirm it with a code.
	 */
	public const ENROLL = 'enroll';

	/**
	 * Refuse the sign-in: the profile can't satisfy the user's MFA.
	 */
	public const BLOCK = 'block';

	/**
	 * MFA settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings MFA settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * What this sign-in needs.
	 *
	 * @param int     $user_id WP user ID.
	 * @param Profile $profile Login Profile the sign-in came through.
	 *
	 * @return string One of the decision constants.
	 */
	public function decide( int $user_id, Profile $profile ): string {
		$enrolled = $this->is_enrolled( $user_id );
		$required = $this->is_required( $user_id, $profile );
		$decision = $this->table( $profile, $enrolled, $required );

		/**
		 * Filters what a sign-in needs after the first factor succeeded.
		 *
		 * Runs in `plugin` mode only. Return `none`, `challenge`, `enroll`
		 * or `block`; anything else is ignored.
		 *
		 * @param string  $decision Decision from the policy table.
		 * @param int     $user_id  WP user ID.
		 * @param Profile $profile  Login Profile the sign-in came through.
		 * @param array   $context  `{ mode, enrolled, required }`.
		 */
		$filtered = (string) apply_filters(
			'workos_mfa_decision',
			$decision,
			$user_id,
			$profile,
			[
				'mode'     => $this->settings->mode(),
				'enrolled' => $enrolled,
				'required' => $required,
			]
		);

		return in_array( $filtered, [ self::NONE, self::CHALLENGE, self::ENROLL, self::BLOCK ], true ) ? $filtered : $decision;
	}

	/**
	 * Whether the user must use MFA.
	 *
	 * True when an admin set the flag, the user has a required role, or
	 * the `workos_mfa_required_for_user` filter says so. Independent of
	 * the mode, so admin screens can show the requirement before the site
	 * switches to `plugin` mode.
	 *
	 * @param int          $user_id WP user ID.
	 * @param Profile|null $profile Login Profile, when known.
	 *
	 * @return bool
	 */
	public function is_required( int $user_id, ?Profile $profile = null ): bool {
		$required = '1' === (string) get_user_meta( $user_id, User::META_MFA_REQUIRED, true );

		if ( ! $required ) {
			$roles = $this->settings->required_roles();
			$user  = $roles ? get_userdata( $user_id ) : false;
			if ( $user && array_intersect( $roles, (array) $user->roles ) ) {
				$required = true;
			}
		}

		/**
		 * Filters whether a user must use MFA.
		 *
		 * @param bool         $required Whether the user must use MFA.
		 * @param int          $user_id  WP user ID.
		 * @param Profile|null $profile  Login Profile, when known.
		 */
		return (bool) apply_filters( 'workos_mfa_required_for_user', $required, $user_id, $profile );
	}

	/**
	 * Whether the user has a confirmed factor (mirrored from WorkOS).
	 *
	 * @param int $user_id WP user ID.
	 *
	 * @return bool
	 */
	public function is_enrolled( int $user_id ): bool {
		return '1' === (string) get_user_meta( $user_id, User::META_MFA_ENABLED, true );
	}

	/**
	 * The decision table, before the filter.
	 *
	 * @param Profile $profile  Login Profile.
	 * @param bool    $enrolled Whether the user has a confirmed factor.
	 * @param bool    $required Whether the user must use MFA.
	 *
	 * @return string
	 */
	private function table( Profile $profile, bool $enrolled, bool $required ): string {
		if ( Settings::MODE_PLUGIN !== $this->settings->mode() ) {
			return self::NONE;
		}

		$enforce = (string) ( $profile->get_mfa()['enforce'] ?? Profile::MFA_ENFORCE_IF_REQUIRED );

		if ( Profile::MFA_ENFORCE_NEVER === $enforce || ! $profile->allows_factor( Profile::FACTOR_TOTP ) ) {
			return ( $enrolled || $required ) ? self::BLOCK : self::NONE;
		}

		if ( $enrolled ) {
			return self::CHALLENGE;
		}

		if ( Profile::MFA_ENFORCE_ALWAYS === $enforce || $required ) {
			return self::ENROLL;
		}

		return self::NONE;
	}
}
