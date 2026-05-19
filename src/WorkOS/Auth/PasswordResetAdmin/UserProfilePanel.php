<?php
/**
 * "Send password reset" button on the WP user-edit screen.
 *
 * @package WorkOS\Auth\PasswordResetAdmin
 */

namespace WorkOS\Auth\PasswordResetAdmin;

use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a button inside the existing WorkOS section on user-edit.php /
 * profile.php. The button posts to the admin REST endpoint via JS — see
 * Assets::register_assets() for the enqueue.
 *
 * Hooks late on `edit_user_profile` / `show_user_profile` (priority 20)
 * so the existing read-only WorkOS panel ({@see \WorkOS\Admin\UserProfile})
 * always renders before this button.
 */
class UserProfilePanel {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'show_user_profile', [ $this, 'render' ], 20 );
		add_action( 'edit_user_profile', [ $this, 'render' ], 20 );
	}

	/**
	 * Render the button.
	 *
	 * @param WP_User $user Target user.
	 *
	 * @return void
	 */
	public function render( WP_User $user ): void {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		$workos_user_id = (string) get_user_meta( $user->ID, '_workos_user_id', true );
		if ( '' === $workos_user_id ) {
			return;
		}

		printf(
			'<h3>%s</h3>',
			esc_html__( 'Password Reset', 'integration-workos' )
		);

		printf(
			'<p class="description">%s</p>',
			esc_html__(
				'Send a password-reset email from WorkOS. The user receives a link to set a new password in-site.',
				'integration-workos'
			)
		);

		printf(
			'<p><button type="button" class="button workos-pwreset-trigger" data-user-id="%d">%s</button></p>',
			(int) $user->ID,
			esc_html__( 'Send password reset email', 'integration-workos' )
		);
	}
}
