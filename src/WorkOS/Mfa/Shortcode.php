<?php
/**
 * `[workos:mfa]` shortcode.
 *
 * @package WorkOS\Mfa
 */

namespace WorkOS\Mfa;

use WorkOS\User;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the account panel where a user turns two-step verification on or
 * off, on any page (e.g. a front-end "My account" screen).
 *
 * Renders nothing for visitors, for accounts without a WorkOS user, or when
 * `workos_mfa_can_manage` says no.
 */
class Shortcode {

	/**
	 * Shortcode tag.
	 */
	public const TAG = 'workos:mfa';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( self::TAG, [ $this, 'render' ] );
	}

	/**
	 * Render the panel mount point.
	 *
	 * @return string
	 */
	public function render(): string {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 || ! User::is_sso( $user_id ) ) {
			return '';
		}

		/** This filter is documented in src/WorkOS/Mfa/RestApi.php */
		if ( ! apply_filters( 'workos_mfa_can_manage', true, $user_id ) ) {
			return '';
		}

		Assets::enqueue();

		return self::mount( $user_id );
	}

	/**
	 * Mount point the panel script hydrates.
	 *
	 * @param int $user_id WP user ID.
	 *
	 * @return string
	 */
	public static function mount( int $user_id ): string {
		return sprintf( '<div class="workos-mfa-panel" data-user-id="%d"></div>', $user_id );
	}
}
