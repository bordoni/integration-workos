<?php
/**
 * "Change email" inline action under the WorkOS column on
 * wp-admin/users.php.
 *
 * @package WorkOS\Auth\ChangeEmail
 */

namespace WorkOS\Auth\ChangeEmail;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a "Change email" row action under the WorkOS column. Mirrors
 * {@see \WorkOS\Auth\PasswordResetAdmin\RowActions} so the two surfaces
 * sit next to each other.
 *
 * The link is HTML-only; the click handler in
 * {@see Assets}::ADMIN_SCRIPT_HANDLE prompts for the new email and POSTs
 * to the initiate endpoint.
 */
class RowActions {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'workos_user_list_column_actions', [ $this, 'add_action' ], 11, 3 );
	}

	/**
	 * Append the "Change email" action to the WorkOS column row actions.
	 *
	 * @param array<string,string> $actions   Existing action HTML keyed by slug.
	 * @param int                  $user_id   WordPress user ID.
	 * @param string               $workos_id Linked WorkOS user ID.
	 *
	 * @return array<string,string>
	 */
	public function add_action( array $actions, int $user_id, string $workos_id ): array {
		if ( '' === $workos_id ) {
			return $actions;
		}

		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return $actions;
		}

		$actions['workos_change_email'] = sprintf(
			'<span class="workos-change-email"><a href="#" class="workos-change-email-trigger" data-user-id="%d">%s</a></span>',
			(int) $user_id,
			esc_html__( 'Change email', 'integration-workos' )
		);

		return $actions;
	}
}
