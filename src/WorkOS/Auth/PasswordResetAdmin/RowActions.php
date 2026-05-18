<?php
/**
 * "Send WorkOS password reset" row action on wp-admin/users.php.
 *
 * @package WorkOS\Auth\PasswordResetAdmin
 */

namespace WorkOS\Auth\PasswordResetAdmin;

use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Adds an inline row action under each linked user on /wp-admin/users.php.
 *
 * The action is intentionally HTML-only: clicking the link fires a small
 * JS handler enqueued by the {@see Assets} class, which posts to the
 * admin REST endpoint and surfaces a notice. Without JS the link is a
 * no-op — that's an acceptable degradation since the action only exists
 * for admins.
 */
class RowActions {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'user_row_actions', [ $this, 'add_row_action' ], 10, 2 );
	}

	/**
	 * Inject the row action.
	 *
	 * @param string[] $actions Existing actions.
	 * @param WP_User  $user    User for this row.
	 *
	 * @return string[]
	 */
	public function add_row_action( array $actions, WP_User $user ): array {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return $actions;
		}

		$workos_user_id = (string) get_user_meta( $user->ID, '_workos_user_id', true );
		if ( '' === $workos_user_id ) {
			return $actions;
		}

		$actions['workos_password_reset'] = sprintf(
			'<a href="#" class="workos-pwreset-trigger" data-user-id="%d">%s</a>',
			(int) $user->ID,
			esc_html__( 'Send WorkOS password reset', 'integration-workos' )
		);

		return $actions;
	}
}
