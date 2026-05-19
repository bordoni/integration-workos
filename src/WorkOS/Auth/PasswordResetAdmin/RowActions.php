<?php
/**
 * "Send password reset" inline action under the WorkOS column on
 * wp-admin/users.php.
 *
 * @package WorkOS\Auth\PasswordResetAdmin
 */

namespace WorkOS\Auth\PasswordResetAdmin;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a "Send password reset" row action to the WorkOS column on the
 * Users list table.
 *
 * The action lives next to "View in WorkOS" and "Re-sync" so all
 * WorkOS-specific row actions cluster in one place, instead of mixing
 * into the native username row actions. Hooks the dedicated
 * `workos_user_list_column_actions` filter exposed by
 * {@see \WorkOS\Admin\UserList::render_linked_column()}; that filter
 * only fires for users with a linked `_workos_user_id`, so the meta
 * check is implicit.
 *
 * The link is intentionally HTML-only: clicking it fires the delegated
 * JS handler enqueued by {@see Assets}, which posts to the admin REST
 * endpoint and surfaces a notice. Without JS the link is a no-op —
 * acceptable since the action only exists for admins.
 */
class RowActions {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'workos_user_list_column_actions', [ $this, 'add_action' ], 10, 3 );
	}

	/**
	 * Append the "Send password reset" action to the WorkOS column row
	 * actions.
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

		$actions['workos_password_reset'] = sprintf(
			'<span class="workos-password-reset"><a href="#" class="workos-pwreset-trigger" data-user-id="%d">%s</a></span>',
			(int) $user_id,
			esc_html__( 'Send password reset', 'integration-workos' )
		);

		return $actions;
	}
}
