<?php
/**
 * MFA settings.
 *
 * @package WorkOS\Mfa
 */

namespace WorkOS\Mfa;

defined( 'ABSPATH' ) || exit;

/**
 * Who decides that a sign-in needs a second factor.
 *
 * WorkOS's own MFA is one environment-wide switch: once it is set to
 * Required, every non-SSO user must enroll at their next sign-in. Sites
 * that want MFA for some users only leave that switch off and let the
 * plugin decide, per user, role and Login Profile.
 *
 * Both values live in the active environment's options, next to the
 * WorkOS keys, because the WorkOS switch is per environment too.
 */
class Settings {

	/**
	 * The plugin decides who is challenged (default).
	 */
	public const MODE_PLUGIN = 'plugin';

	/**
	 * WorkOS enforces MFA for the whole environment; the plugin only runs
	 * the steps WorkOS asks for.
	 */
	public const MODE_WORKOS = 'workos';

	/**
	 * The plugin challenges nobody. Steps WorkOS asks for still run.
	 */
	public const MODE_OFF = 'off';

	/**
	 * Option key for the mode.
	 */
	public const OPTION_MODE = 'mfa_mode';

	/**
	 * Option key for the roles that must use MFA.
	 */
	public const OPTION_REQUIRED_ROLES = 'mfa_required_roles';

	/**
	 * Option key for trusting the identity provider's MFA on SSO sign-ins.
	 */
	public const OPTION_TRUST_SSO = 'mfa_trust_sso';

	/**
	 * Every mode.
	 *
	 * @return string[]
	 */
	public static function modes(): array {
		return [ self::MODE_PLUGIN, self::MODE_WORKOS, self::MODE_OFF ];
	}

	/**
	 * Active mode.
	 *
	 * @return string One of the MODE_* constants.
	 */
	public function mode(): string {
		$mode = (string) workos()->option( self::OPTION_MODE, self::MODE_PLUGIN );

		/**
		 * Filters who decides that a sign-in needs a second factor.
		 *
		 * `plugin` (default): the plugin decides per user, role and Login
		 * Profile. `workos`: the WorkOS environment requires MFA for
		 * everyone and the plugin only runs the steps WorkOS asks for.
		 * `off`: the plugin challenges nobody.
		 *
		 * @param string $mode Active mode.
		 */
		$mode = (string) apply_filters( 'workos_mfa_mode', $mode );

		return in_array( $mode, self::modes(), true ) ? $mode : self::MODE_PLUGIN;
	}

	/**
	 * Roles whose members must use MFA (plugin mode).
	 *
	 * @return string[]
	 */
	public function required_roles(): array {
		$roles = workos()->option( self::OPTION_REQUIRED_ROLES, [] );

		return is_array( $roles ) ? array_values( array_filter( array_map( 'strval', $roles ) ) ) : [];
	}

	/**
	 * Whether an SSO sign-in skips the plugin's code step.
	 *
	 * On by default, like WorkOS's own MFA, which never applies to SSO
	 * users: their identity provider runs its own MFA.
	 *
	 * @return bool
	 */
	public function trust_sso(): bool {
		$trust = (bool) workos()->option( self::OPTION_TRUST_SSO, true );

		/**
		 * Filters whether an SSO sign-in skips the plugin's code step.
		 *
		 * @param bool $trust Whether to trust the identity provider's MFA.
		 */
		return (bool) apply_filters( 'workos_mfa_trust_sso', $trust );
	}
}
