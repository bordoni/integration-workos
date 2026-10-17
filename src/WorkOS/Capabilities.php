<?php
/**
 * Plugin capabilities for WorkOS user management.
 *
 * @package WorkOS
 */

namespace WorkOS;

use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Maps the plugin's user-management capabilities onto core capabilities.
 *
 * Each plugin capability answers exactly like the core capability the
 * same check used before it existed, so nobody gains or loses access by
 * default. A site that wants a narrower role (say, support staff who can
 * send a password reset but can't edit users) grants the plugin
 * capability to that role directly:
 *
 * ```php
 * add_role( 'workos_support', 'WorkOS Support', [
 *     'read'                       => true,
 *     'workos_view_users'          => true,
 *     'workos_send_password_reset' => true,
 * ] );
 * ```
 *
 * Once a user holds a plugin capability (through a role or on their own
 * account), that grant decides the check instead of the core mapping.
 * Setting it to `false` takes the action away even from a role whose
 * core capabilities would allow it.
 *
 * See docs/capabilities.md.
 */
class Capabilities {

	/** Open the WorkOS → Users screen and its list endpoint. */
	public const VIEW_USERS = 'workos_view_users';

	/** Send a WorkOS password-reset email for a user (checked per user). */
	public const SEND_PASSWORD_RESET = 'workos_send_password_reset';

	/** Start or cancel an email change for a user (checked per user). */
	public const CHANGE_EMAIL = 'workos_change_email';

	/** Clear sign-in lockouts. */
	public const MANAGE_LOCKOUTS = 'workos_manage_lockouts';

	/**
	 * Plugin capability → the core capability it stands in for.
	 *
	 * `edit_user` is a per-user meta capability: every logged-in user has
	 * it for their own account, which is what keeps self-service password
	 * reset and email change working.
	 *
	 * @var array<string, string>
	 */
	public const MAP = [
		self::VIEW_USERS          => 'manage_options',
		self::SEND_PASSWORD_RESET => 'edit_user',
		self::CHANGE_EMAIL        => 'edit_user',
		self::MANAGE_LOCKOUTS     => 'edit_users',
	];

	/**
	 * Register the `map_meta_cap` filter.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'map_meta_cap', [ $this, 'map_meta_cap' ], 10, 4 );
	}

	/**
	 * Resolve a plugin capability to the primitive capabilities it needs.
	 *
	 * @param string[] $caps    Primitive capabilities so far.
	 * @param string   $cap     Capability being checked.
	 * @param int      $user_id User being checked.
	 * @param array    $args    Extra arguments (the target user ID for the per-user capabilities).
	 *
	 * @return string[]
	 */
	public function map_meta_cap( $caps, $cap, $user_id, $args ): array {
		$caps = (array) $caps;

		if ( ! is_string( $cap ) || ! isset( self::MAP[ $cap ] ) ) {
			return $caps;
		}

		$user_id = (int) $user_id;
		$args    = array_values( (array) $args );

		if ( $user_id > 0 && $this->is_set_on_user( $cap, $user_id ) ) {
			return $this->granted_caps( $cap, $user_id, $args );
		}

		return map_meta_cap( self::MAP[ $cap ], $user_id, ...$args );
	}

	/**
	 * Caps required when the user holds the plugin capability directly.
	 *
	 * The user's own grant decides. On multisite, a per-user capability
	 * still can't target a super admin unless the caller is one, the same
	 * guard core applies to `edit_user`.
	 *
	 * @param string $cap     Plugin capability.
	 * @param int    $user_id User being checked.
	 * @param array  $args    Extra arguments.
	 *
	 * @return string[]
	 */
	private function granted_caps( string $cap, int $user_id, array $args ): array {
		$target = isset( $args[0] ) ? (int) $args[0] : 0;

		if (
			'edit_user' === self::MAP[ $cap ]
			&& is_multisite()
			&& $target > 0
			&& $target !== $user_id
			&& is_super_admin( $target )
			&& ! is_super_admin( $user_id )
		) {
			return [ 'do_not_allow' ];
		}

		return [ $cap ];
	}

	/**
	 * Whether the user's roles or own capabilities mention the capability.
	 *
	 * Reads the stored capabilities, so a grant of `false` counts as set
	 * (and denies).
	 *
	 * @param string $cap     Plugin capability.
	 * @param int    $user_id User being checked.
	 *
	 * @return bool
	 */
	private function is_set_on_user( string $cap, int $user_id ): bool {
		$user = get_current_user_id() === $user_id ? wp_get_current_user() : get_userdata( $user_id );

		return $user instanceof WP_User && array_key_exists( $cap, $user->allcaps );
	}
}
