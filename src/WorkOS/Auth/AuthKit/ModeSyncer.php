<?php
/**
 * Two-way sync between Settings → Authentication → Login Mode and
 * the default Login Profile's `mode`.
 *
 * @package WorkOS\Auth\AuthKit
 */

namespace WorkOS\Auth\AuthKit;

use WorkOS\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the legacy `login_mode` env option and the reserved `default`
 * Login Profile's `mode` field consistent.
 *
 * The admin Settings page shows a "Login Mode" dropdown with three
 * choices — `custom`, `redirect`, `headless` — that writes to the
 * `login_mode` env option. The wp-login.php takeover and the shortcode
 * both resolve their behavior from a Login Profile. Without this syncer
 * the two settings drift apart silently: an admin can save "AuthKit
 * Redirect" in Settings while the default profile keeps `mode=custom`,
 * and the React shell still takes over the login page.
 *
 * Sync rules:
 *
 * - `login_mode == 'custom'`    ⇄ default profile `mode == 'custom'`
 * - `login_mode == 'redirect'`  ⇄ default profile `mode == 'authkit_redirect'`
 * - `login_mode == 'headless'`  — orthogonal; drives the `authenticate`
 *   filter path and leaves the default profile's mode untouched (the
 *   React shell is not active during headless logins).
 *
 * When either side changes, the syncer updates the other. A guard flag
 * prevents infinite recursion during the cross-update.
 */
class ModeSyncer {

	/**
	 * Default-profile mode = `custom` pairs with this login_mode value.
	 */
	public const LOGIN_MODE_CUSTOM = 'custom';

	/**
	 * Default-profile mode = `authkit_redirect` pairs with this login_mode value.
	 */
	public const LOGIN_MODE_REDIRECT = 'redirect';

	/**
	 * Classic WP login form + custom authenticate filter. Independent of profile mode.
	 */
	public const LOGIN_MODE_HEADLESS = 'headless';

	/**
	 * Reentrancy guard.
	 *
	 * @var bool
	 */
	private bool $syncing = false;

	/**
	 * Repository used to read / update the default profile.
	 *
	 * @var ProfileRepository
	 */
	private ProfileRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param ProfileRepository $repository Profile repository.
	 */
	public function __construct( ProfileRepository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		// Env option → profile sync (Production + Staging serialized rows).
		add_action( 'update_option_workos_production', [ $this, 'on_option_update' ], 10, 2 );
		add_action( 'update_option_workos_staging', [ $this, 'on_option_update' ], 10, 2 );
		add_action( 'add_option_workos_production', [ $this, 'on_option_add' ], 10, 2 );
		add_action( 'add_option_workos_staging', [ $this, 'on_option_add' ], 10, 2 );

		// Profile → env option sync.
		add_action( 'workos_login_profile_saved', [ $this, 'on_profile_saved' ] );
	}

	/**
	 * Map a `login_mode` env value to a profile-mode enum value.
	 *
	 * Returns null for `headless` (no corresponding profile mode) and for
	 * unknown values.
	 *
	 * @param string $login_mode Raw value from the env option.
	 *
	 * @return string|null
	 */
	public static function login_mode_to_profile_mode( string $login_mode ): ?string {
		switch ( $login_mode ) {
			case self::LOGIN_MODE_CUSTOM:
				return Profile::MODE_CUSTOM;
			case self::LOGIN_MODE_REDIRECT:
				return Profile::MODE_AUTHKIT_REDIRECT;
			default:
				return null;
		}
	}

	/**
	 * Map a profile-mode enum value back to a `login_mode` env value.
	 *
	 * @param string $profile_mode Profile mode enum.
	 *
	 * @return string|null
	 */
	public static function profile_mode_to_login_mode( string $profile_mode ): ?string {
		switch ( $profile_mode ) {
			case Profile::MODE_CUSTOM:
				return self::LOGIN_MODE_CUSTOM;
			case Profile::MODE_AUTHKIT_REDIRECT:
				return self::LOGIN_MODE_REDIRECT;
			default:
				return null;
		}
	}

	/**
	 * Hook target for `update_option_workos_{env}`.
	 *
	 * @param mixed $old_value Previous option value (serialized array).
	 * @param mixed $value     New option value.
	 *
	 * @return void
	 */
	public function on_option_update( $old_value, $value ): void {
		if ( $this->syncing ) {
			return;
		}

		$old_mode = is_array( $old_value ) ? (string) ( $old_value['login_mode'] ?? '' ) : '';
		$new_mode = is_array( $value ) ? (string) ( $value['login_mode'] ?? '' ) : '';

		if ( $old_mode === $new_mode ) {
			return;
		}

		$this->maybe_push_to_profile( $new_mode );
	}

	/**
	 * Hook target for `add_option_workos_{env}` (first-ever write).
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Option value.
	 *
	 * @return void
	 */
	public function on_option_add( $option, $value ): void {
		if ( $this->syncing ) {
			return;
		}

		$new_mode = is_array( $value ) ? (string) ( $value['login_mode'] ?? '' ) : '';
		if ( '' === $new_mode ) {
			return;
		}

		$this->maybe_push_to_profile( $new_mode );
	}

	/**
	 * Hook target for `workos_login_profile_saved`.
	 *
	 * @param Profile $profile Saved profile.
	 *
	 * @return void
	 */
	public function on_profile_saved( Profile $profile ): void {
		if ( $this->syncing ) {
			return;
		}

		if ( Profile::DEFAULT_SLUG !== $profile->get_slug() ) {
			return;
		}

		$next_login_mode = self::profile_mode_to_login_mode( $profile->get_mode() );
		if ( null === $next_login_mode ) {
			return;
		}

		$this->write_login_mode( $next_login_mode );
	}

	/**
	 * Update the default profile's mode to match the given login_mode value.
	 *
	 * @param string $login_mode Login mode value from the env option.
	 *
	 * @return void
	 */
	private function maybe_push_to_profile( string $login_mode ): void {
		$next_profile_mode = self::login_mode_to_profile_mode( $login_mode );
		if ( null === $next_profile_mode ) {
			// `headless` or unknown — do not touch the profile.
			return;
		}

		$default = $this->repository->ensure_default();
		if ( $default->get_mode() === $next_profile_mode ) {
			return;
		}

		$updated         = $default->to_array();
		$updated['mode'] = $next_profile_mode;

		$this->syncing = true;
		$this->repository->save( Profile::from_array( $updated ) );
		$this->syncing = false;
	}

	/**
	 * Write `login_mode` back into the active environment's option row.
	 *
	 * @param string $login_mode Target value.
	 *
	 * @return void
	 */
	private function write_login_mode( string $login_mode ): void {
		$env         = Config::get_active_environment();
		$option_name = 'staging' === $env ? 'workos_staging' : 'workos_production';

		$current = get_option( $option_name, [] );
		if ( ! is_array( $current ) ) {
			$current = [];
		}

		if ( ( $current['login_mode'] ?? '' ) === $login_mode ) {
			return;
		}

		$current['login_mode'] = $login_mode;

		$this->syncing = true;
		update_option( $option_name, $current );
		$this->syncing = false;
	}
}
