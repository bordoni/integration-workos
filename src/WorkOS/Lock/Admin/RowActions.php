<?php
/**
 * "Clear login locks" inline action on wp-admin/users.php.
 *
 * @package WorkOS\Lock\Admin
 */

namespace WorkOS\Lock\Admin;

use WorkOS\Lock\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a "Clear login locks" action to the WorkOS column on the Users list.
 *
 * Only rendered when the user actually has a lock holding state, so the action
 * doubles as the signal that one engaged — an operator scanning the list can
 * see who is stuck without opening anything.
 *
 * The link is a real nonced URL to `admin-post.php` rather than a JavaScript
 * hook, so it works without scripts and needs no bundle. Hooks the
 * `workos_user_list_column_actions` filter at priority 12, after the
 * password-reset (10) and change-email (11) actions.
 */
class RowActions {

	/**
	 * Lock registry.
	 *
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * Unlock action handler, used to build the nonced URL.
	 *
	 * @var UnlockHandler
	 */
	private UnlockHandler $handler;

	/**
	 * Constructor.
	 *
	 * @param Registry      $registry Lock registry.
	 * @param UnlockHandler $handler  Unlock action handler.
	 */
	public function __construct( Registry $registry, UnlockHandler $handler ) {
		$this->registry = $registry;
		$this->handler  = $handler;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'workos_user_list_column_actions', [ $this, 'add_action' ], 12, 2 );
	}

	/**
	 * Remove hooks.
	 *
	 * @return void
	 */
	public function unregister(): void {
		remove_filter( 'workos_user_list_column_actions', [ $this, 'add_action' ], 12 );
	}

	/**
	 * Append the "Clear login locks" action when the user has one.
	 *
	 * Unlike the sibling row actions this does not require a linked WorkOS ID:
	 * a lock can engage against someone who has never completed a login, which
	 * is exactly when an operator most needs to see it.
	 *
	 * @param array<string,string> $actions Existing action HTML keyed by slug.
	 * @param int                  $user_id WordPress user ID.
	 *
	 * @return array<string,string>
	 */
	public function add_action( array $actions, int $user_id ): array {
		if ( ! current_user_can( 'edit_users' ) ) {
			return $actions;
		}

		if ( [] === $this->registry->status_for_user( $user_id ) ) {
			return $actions;
		}

		$actions['workos_clear_locks'] = sprintf(
			'<span class="workos-clear-locks"><a href="%s">%s</a></span>',
			esc_url( $this->handler->action_url( $user_id ) ),
			esc_html__( 'Clear login locks', 'integration-workos' )
		);

		return $actions;
	}
}
