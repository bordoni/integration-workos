<?php
/**
 * "Change email" button on the WP user-edit / profile screen.
 *
 * @package WorkOS\Auth\ChangeEmail
 */

namespace WorkOS\Auth\ChangeEmail;

use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a button inside the existing WorkOS section on user-edit.php
 * / profile.php. Click fires the admin JS, which prompts for the new
 * address and POSTs to the initiate endpoint.
 *
 * Priority 21 so it sits below the PasswordResetAdmin panel (priority
 * 20) — both render under the existing read-only WorkOS section.
 */
class UserProfilePanel {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'show_user_profile', [ $this, 'render' ], 21 );
		add_action( 'edit_user_profile', [ $this, 'render' ], 21 );
	}

	/**
	 * Render the panel.
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

		printf( '<h3>%s</h3>', esc_html__( 'Change Email', 'integration-workos' ) );

		printf(
			'<p class="description">%s</p>',
			esc_html__(
				'Send a verification email to a new address. The change only commits after the new address is confirmed.',
				'integration-workos'
			)
		);

		printf(
			'<p><button type="button" class="button workos-change-email-trigger" data-user-id="%d">%s</button></p>',
			(int) $user->ID,
			esc_html__( 'Change email…', 'integration-workos' )
		);
	}
}
