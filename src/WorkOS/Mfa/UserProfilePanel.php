<?php
/**
 * Two-step verification on the user's own profile screen.
 *
 * @package WorkOS\Mfa
 */

namespace WorkOS\Mfa;

use WorkOS\User;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the account panel to `profile.php` for linked users.
 */
class UserProfilePanel {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'show_user_profile', [ $this, 'render' ], 22 );
	}

	/**
	 * Render the panel.
	 *
	 * @param WP_User $user Profile owner (the current user on profile.php).
	 *
	 * @return void
	 */
	public function render( WP_User $user ): void {
		if ( ! User::is_sso( $user->ID ) ) {
			return;
		}

		/** This filter is documented in src/WorkOS/Mfa/RestApi.php */
		if ( ! apply_filters( 'workos_mfa_can_manage', true, $user->ID ) ) {
			return;
		}

		printf( '<h2>%s</h2>', esc_html__( 'Two-step verification', 'integration-workos' ) );
		echo Shortcode::mount( $user->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from an int.
	}
}
