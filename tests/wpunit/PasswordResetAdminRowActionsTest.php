<?php
/**
 * Tests for the WorkOS-column "Send password reset" row action.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Admin\UserList;
use WorkOS\Auth\PasswordResetAdmin\RowActions;

/**
 * Two layers of coverage:
 *
 *  1. `WorkOS\Admin\UserList::render_column()` applies the new
 *     `workos_user_list_column_actions` filter for linked users — the
 *     extension surface PasswordResetAdmin hooks into.
 *  2. `WorkOS\Auth\PasswordResetAdmin\RowActions::add_action()` injects
 *     the trigger when the caller has `edit_user($id)`, and stays out
 *     when they don't.
 */
class PasswordResetAdminRowActionsTest extends WPTestCase {

	/**
	 * RowActions instance under test.
	 *
	 * @var RowActions
	 */
	private RowActions $row_actions;

	/**
	 * Linked WP user (has _workos_user_id meta).
	 *
	 * @var int
	 */
	private int $linked_user_id = 0;

	/**
	 * Admin user — has `edit_user` on everyone.
	 *
	 * @var int
	 */
	private int $admin_user_id = 0;

	/**
	 * Subscriber — only has `edit_user` on themselves.
	 *
	 * @var int
	 */
	private int $subscriber_id = 0;

	/**
	 * Set up shared fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->row_actions = new RowActions();

		$suffix              = uniqid( 'rat_', true );
		$this->linked_user_id = wp_insert_user(
			[
				'user_login' => 'rat_linked_' . $suffix,
				'user_pass'  => wp_generate_password(),
				'user_email' => 'linked-' . $suffix . '@example.test',
				'role'       => 'subscriber',
			]
		);
		$this->assertIsInt( $this->linked_user_id );
		update_user_meta( $this->linked_user_id, '_workos_user_id', 'user_wos_' . $suffix );

		$this->admin_user_id = wp_insert_user(
			[
				'user_login' => 'rat_admin_' . $suffix,
				'user_pass'  => wp_generate_password(),
				'user_email' => 'admin-' . $suffix . '@example.test',
				'role'       => 'administrator',
			]
		);
		$this->assertIsInt( $this->admin_user_id );

		$this->subscriber_id = wp_insert_user(
			[
				'user_login' => 'rat_sub_' . $suffix,
				'user_pass'  => wp_generate_password(),
				'user_email' => 'sub-' . $suffix . '@example.test',
				'role'       => 'subscriber',
			]
		);
		$this->assertIsInt( $this->subscriber_id );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * UserList::render_column() applies the new filter for linked users,
	 * passing the user id and workos id as filter args.
	 */
	public function test_workos_column_applies_extension_filter(): void {
		wp_set_current_user( $this->admin_user_id );

		$captured = [];
		$cb       = static function ( array $actions, int $user_id, string $workos_id ) use ( &$captured ): array {
			$captured = [
				'actions'   => $actions,
				'user_id'   => $user_id,
				'workos_id' => $workos_id,
			];

			$actions['custom_marker'] = '<span class="custom"><a href="#">Custom</a></span>';

			return $actions;
		};
		add_filter( 'workos_user_list_column_actions', $cb, 10, 3 );

		try {
			$list = new UserList();
			$html = $list->render_column( '', 'workos', $this->linked_user_id );
		} finally {
			remove_filter( 'workos_user_list_column_actions', $cb, 10 );
		}

		$this->assertSame( $this->linked_user_id, $captured['user_id'] ?? null );
		$this->assertStringStartsWith( 'user_wos_', (string) ( $captured['workos_id'] ?? '' ) );
		$this->assertIsArray( $captured['actions'] ?? null );
		$this->assertStringContainsString( 'Custom', $html );
	}

	/**
	 * Unlinked users (no `_workos_user_id` meta) do NOT fire the
	 * extension filter — there's no WorkOS id to pass through.
	 */
	public function test_workos_column_does_not_apply_filter_for_unlinked_users(): void {
		wp_set_current_user( $this->admin_user_id );

		$fired = false;
		$cb    = static function ( array $actions ) use ( &$fired ): array {
			$fired = true;
			return $actions;
		};
		add_filter( 'workos_user_list_column_actions', $cb );

		try {
			$list = new UserList();
			$list->render_column( '', 'workos', $this->subscriber_id );
		} finally {
			remove_filter( 'workos_user_list_column_actions', $cb );
		}

		$this->assertFalse( $fired );
	}

	/**
	 * RowActions::add_action() injects the trigger when the caller has
	 * `edit_user` on the target.
	 */
	public function test_add_action_injects_trigger_when_capability_allows(): void {
		wp_set_current_user( $this->admin_user_id );

		$result = $this->row_actions->add_action( [], $this->linked_user_id, 'user_wos_xyz' );

		$this->assertArrayHasKey( 'workos_password_reset', $result );
		$this->assertStringContainsString(
			'data-user-id="' . $this->linked_user_id . '"',
			$result['workos_password_reset']
		);
		$this->assertStringContainsString( 'workos-pwreset-trigger', $result['workos_password_reset'] );
	}

	/**
	 * RowActions::add_action() leaves the array untouched when the
	 * caller can't edit the target.
	 */
	public function test_add_action_skips_when_capability_missing(): void {
		// Subscriber acting on the linked user (a different account) —
		// edit_user is denied by default.
		wp_set_current_user( $this->subscriber_id );

		$result = $this->row_actions->add_action(
			[ 'view' => '<span>view</span>' ],
			$this->linked_user_id,
			'user_wos_xyz'
		);

		$this->assertArrayNotHasKey( 'workos_password_reset', $result );
		$this->assertArrayHasKey( 'view', $result, 'Existing actions must be preserved.' );
	}

	/**
	 * Self-service path — a logged-in subscriber acting on their own
	 * user id passes the cap check (WP grants `edit_user($self)` to
	 * any logged-in user).
	 */
	public function test_add_action_allows_self_service(): void {
		wp_set_current_user( $this->subscriber_id );

		$result = $this->row_actions->add_action( [], $this->subscriber_id, 'user_wos_self' );

		$this->assertArrayHasKey( 'workos_password_reset', $result );
	}

	/**
	 * An empty workos_id (which can't happen via render_linked_column,
	 * but the public signature allows) is a no-op.
	 */
	public function test_add_action_skips_when_workos_id_empty(): void {
		wp_set_current_user( $this->admin_user_id );

		$result = $this->row_actions->add_action( [], $this->linked_user_id, '' );

		$this->assertArrayNotHasKey( 'workos_password_reset', $result );
	}
}
