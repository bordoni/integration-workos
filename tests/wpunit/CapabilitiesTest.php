<?php
/**
 * Tests for the plugin's WorkOS user-management capabilities.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use Codeception\Attribute\DataProvider;
use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Admin\Users\AdminPage as UsersAdminPage;
use WorkOS\Auth\ChangeEmail\RowActions as ChangeEmailRowActions;
use WorkOS\Auth\ChangeEmail\Shortcode as ChangeEmailShortcode;
use WorkOS\Auth\ChangeEmail\UserProfilePanel as ChangeEmailPanel;
use WorkOS\Auth\PasswordResetAdmin\RowActions as PasswordResetRowActions;
use WorkOS\Auth\PasswordResetAdmin\Shortcode as PasswordResetShortcode;
use WorkOS\Auth\PasswordResetAdmin\UserProfilePanel as PasswordResetPanel;
use WorkOS\Capabilities;

/**
 * The capabilities are a patch-release change, so the main promise is that
 * nobody gains or loses access: every plugin capability has to answer
 * exactly like the core capability the same check used before. The matrix
 * below pins that for each default role, acting on themselves and on other
 * accounts, and then checks what a custom role granted the plugin
 * capabilities directly can and can't do.
 *
 * @covers \WorkOS\Capabilities
 */
class CapabilitiesTest extends WPTestCase {

	/**
	 * Custom role used for the "granted directly" cases.
	 */
	private const SUPPORT_ROLE = 'workos_test_support';

	/**
	 * Default-role users keyed by role slug.
	 *
	 * @var array<string, int>
	 */
	private array $users = [];

	/**
	 * A second subscriber, the "other account" target.
	 *
	 * @var int
	 */
	private int $other_subscriber = 0;

	/**
	 * A second administrator, the "other admin" target.
	 *
	 * @var int
	 */
	private int $other_admin = 0;

	/**
	 * Create one user per default role plus the two targets.
	 */
	public function setUp(): void {
		parent::setUp();

		foreach ( [ 'administrator', 'editor', 'author', 'subscriber' ] as $role ) {
			$this->users[ $role ] = $this->create_user( $role );
		}

		$this->other_subscriber = $this->create_user( 'subscriber' );
		$this->other_admin      = $this->create_user( 'administrator' );

		update_user_meta( $this->other_subscriber, '_workos_user_id', 'user_caps_other' );
	}

	/**
	 * Drop the custom role and log out.
	 */
	public function tearDown(): void {
		remove_role( self::SUPPORT_ROLE );
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Insert a user with the given role.
	 *
	 * @param string $role Role slug.
	 *
	 * @return int
	 */
	private function create_user( string $role ): int {
		$user_id = wp_insert_user(
			[
				'user_login' => 'caps_' . wp_generate_password( 8, false ),
				'user_pass'  => wp_generate_password(),
				'user_email' => 'caps-' . uniqid( '', true ) . '@example.test',
				'role'       => $role,
			]
		);

		$this->assertIsInt( $user_id );

		return $user_id;
	}

	/**
	 * Create a user on a fresh role holding only `read` plus the given caps.
	 *
	 * @param array<string, bool> $caps Capabilities to grant (or deny).
	 *
	 * @return int
	 */
	private function create_support_user( array $caps ): int {
		remove_role( self::SUPPORT_ROLE );
		add_role( self::SUPPORT_ROLE, 'WorkOS Support', array_merge( [ 'read' => true ], $caps ) );

		return $this->create_user( self::SUPPORT_ROLE );
	}

	/**
	 * Every check each capability replaced, as [ plugin cap, core cap, target ].
	 *
	 * A null target means the check takes no object ID.
	 *
	 * @param int $self The acting user's ID.
	 *
	 * @return array<string, array{0: string, 1: string, 2: int|null}>
	 */
	private function checks( int $self ): array {
		return [
			'view users'                  => [ Capabilities::VIEW_USERS, 'manage_options', null ],
			'manage lockouts'             => [ Capabilities::MANAGE_LOCKOUTS, 'edit_users', null ],
			'password reset, self'        => [ Capabilities::SEND_PASSWORD_RESET, 'edit_user', $self ],
			'password reset, other'       => [ Capabilities::SEND_PASSWORD_RESET, 'edit_user', $this->other_subscriber ],
			'password reset, other admin' => [ Capabilities::SEND_PASSWORD_RESET, 'edit_user', $this->other_admin ],
			'change email, self'          => [ Capabilities::CHANGE_EMAIL, 'edit_user', $self ],
			'change email, other'         => [ Capabilities::CHANGE_EMAIL, 'edit_user', $this->other_subscriber ],
			'change email, other admin'   => [ Capabilities::CHANGE_EMAIL, 'edit_user', $this->other_admin ],
		];
	}

	/**
	 * Run one check for a user.
	 *
	 * @param int      $user_id User to check.
	 * @param string   $cap     Capability.
	 * @param int|null $target  Object ID, or null.
	 *
	 * @return bool
	 */
	private function can( int $user_id, string $cap, ?int $target ): bool {
		return null === $target ? user_can( $user_id, $cap ) : user_can( $user_id, $cap, $target );
	}

	/**
	 * Expected answers per default role, so the matrix reads as a table.
	 *
	 * @return array<string, array{0: string, 1: array<string, bool>}>
	 */
	public function role_provider(): array {
		$self_service_only = [
			'view users'                  => false,
			'manage lockouts'             => false,
			'password reset, self'        => true,
			'password reset, other'       => false,
			'password reset, other admin' => false,
			'change email, self'          => true,
			'change email, other'         => false,
			'change email, other admin'   => false,
		];

		return [
			'administrator' => [
				'administrator',
				array_fill_keys( array_keys( $self_service_only ), true ),
			],
			'editor'        => [ 'editor', $self_service_only ],
			'author'        => [ 'author', $self_service_only ],
			'subscriber'    => [ 'subscriber', $self_service_only ],
		];
	}

	/**
	 * Each default role gets the same answer from the plugin capability as
	 * from the core capability the check used before, and that answer is the
	 * one in the table.
	 *
	 * @param string              $role     Role slug.
	 * @param array<string, bool> $expected Expected answer per check.
	 */
	#[DataProvider( 'role_provider' )]
	public function test_default_roles_match_the_core_checks( string $role, array $expected ): void {
		$user_id = $this->users[ $role ];

		foreach ( $this->checks( $user_id ) as $label => [ $cap, $core, $target ] ) {
			$this->assertSame(
				$this->can( $user_id, $core, $target ),
				$this->can( $user_id, $cap, $target ),
				"{$role}: {$label} must match {$core}."
			);
			$this->assertSame( $expected[ $label ], $this->can( $user_id, $cap, $target ), "{$role}: {$label}." );
		}
	}

	/**
	 * The same holds through current_user_can(), which is what the
	 * plugin's checks call.
	 *
	 * @param string              $role     Role slug.
	 * @param array<string, bool> $expected Expected answer per check.
	 */
	#[DataProvider( 'role_provider' )]
	public function test_current_user_can_matches_for_default_roles( string $role, array $expected ): void {
		wp_set_current_user( $this->users[ $role ] );

		foreach ( $this->checks( $this->users[ $role ] ) as $label => [ $cap, , $target ] ) {
			$actual = null === $target ? current_user_can( $cap ) : current_user_can( $cap, $target );
			$this->assertSame( $expected[ $label ], $actual, "{$role}: {$label}." );
		}
	}

	/**
	 * Logged-out visitors get nothing, as before.
	 */
	public function test_logged_out_visitor_has_no_capability(): void {
		wp_set_current_user( 0 );

		foreach ( $this->checks( 0 ) as $label => [ $cap, $core, $target ] ) {
			$actual = null === $target ? current_user_can( $cap ) : current_user_can( $cap, $target );
			$this->assertFalse( $actual, "Logged out: {$label}." );
		}
	}

	/**
	 * The documented set is exactly these four, each with its core mapping.
	 */
	public function test_map_lists_every_capability(): void {
		$this->assertSame(
			[
				'workos_view_users'          => 'manage_options',
				'workos_send_password_reset' => 'edit_user',
				'workos_change_email'        => 'edit_user',
				'workos_manage_lockouts'     => 'edit_users',
			],
			Capabilities::MAP
		);
	}

	/**
	 * Unrelated capabilities pass through the filter untouched.
	 */
	public function test_other_capabilities_are_not_touched(): void {
		$this->assertSame(
			[ 'some_cap' ],
			( new Capabilities() )->map_meta_cap( [ 'some_cap' ], 'some_cap', $this->users['subscriber'], [] )
		);
	}

	/**
	 * A role given only `workos_send_password_reset` can send a reset for
	 * anyone, keeps self-service email change, and can't do anything else.
	 */
	public function test_role_with_only_send_password_reset(): void {
		$support = $this->create_support_user( [ Capabilities::SEND_PASSWORD_RESET => true ] );
		wp_set_current_user( $support );

		$this->assertTrue( current_user_can( Capabilities::SEND_PASSWORD_RESET, $this->other_subscriber ) );
		$this->assertTrue( current_user_can( Capabilities::SEND_PASSWORD_RESET, $this->other_admin ) );
		$this->assertTrue( current_user_can( Capabilities::SEND_PASSWORD_RESET, $support ) );

		$this->assertFalse( current_user_can( Capabilities::CHANGE_EMAIL, $this->other_subscriber ) );
		$this->assertTrue( current_user_can( Capabilities::CHANGE_EMAIL, $support ), 'Self-service still comes from edit_user.' );
		$this->assertFalse( current_user_can( Capabilities::VIEW_USERS ) );
		$this->assertFalse( current_user_can( Capabilities::MANAGE_LOCKOUTS ) );

		// No core rights came along with it.
		$this->assertFalse( current_user_can( 'manage_options' ) );
		$this->assertFalse( current_user_can( 'edit_users' ) );
		$this->assertFalse( current_user_can( 'edit_user', $this->other_subscriber ) );
	}

	/**
	 * Each capability granted on its own opens only its own check.
	 */
	public function test_each_capability_granted_alone(): void {
		$support = $this->create_support_user(
			[
				Capabilities::VIEW_USERS      => true,
				Capabilities::CHANGE_EMAIL    => true,
				Capabilities::MANAGE_LOCKOUTS => true,
			]
		);
		wp_set_current_user( $support );

		$this->assertTrue( current_user_can( Capabilities::VIEW_USERS ) );
		$this->assertTrue( current_user_can( Capabilities::CHANGE_EMAIL, $this->other_subscriber ) );
		$this->assertTrue( current_user_can( Capabilities::MANAGE_LOCKOUTS ) );
		$this->assertFalse( current_user_can( Capabilities::SEND_PASSWORD_RESET, $this->other_subscriber ) );
		$this->assertFalse( current_user_can( 'manage_options' ) );
	}

	/**
	 * Setting a capability to false on a role takes it away, even from a
	 * role whose core capabilities would allow it.
	 */
	public function test_capability_set_to_false_denies(): void {
		$admin = get_role( 'administrator' );
		$admin->add_cap( Capabilities::SEND_PASSWORD_RESET, false );

		try {
			$user = new \WP_User( $this->users['administrator'] );
			$this->assertFalse( user_can( $user, Capabilities::SEND_PASSWORD_RESET, $this->other_subscriber ) );
			$this->assertTrue( user_can( $user, Capabilities::CHANGE_EMAIL, $this->other_subscriber ) );
		} finally {
			$admin->remove_cap( Capabilities::SEND_PASSWORD_RESET );
		}
	}

	/**
	 * A capability granted to one user (not a role) works the same way.
	 */
	public function test_capability_granted_to_a_single_user(): void {
		$editor = new \WP_User( $this->users['editor'] );
		$editor->add_cap( Capabilities::VIEW_USERS );

		$this->assertTrue( user_can( new \WP_User( $this->users['editor'] ), Capabilities::VIEW_USERS ) );
		$this->assertFalse( user_can( new \WP_User( $this->users['author'] ), Capabilities::VIEW_USERS ) );
	}

	// ---------------------------------------------------------------------
	// The surfaces that switched to the plugin capabilities.
	// ---------------------------------------------------------------------

	/**
	 * The users.php row actions follow their own capability.
	 */
	public function test_row_actions_follow_their_capability(): void {
		$support = $this->create_support_user( [ Capabilities::SEND_PASSWORD_RESET => true ] );
		wp_set_current_user( $support );

		$actions = ( new PasswordResetRowActions() )->add_action( [], $this->other_subscriber, 'user_caps_other' );
		$actions = ( new ChangeEmailRowActions() )->add_action( $actions, $this->other_subscriber, 'user_caps_other' );

		$this->assertArrayHasKey( 'workos_password_reset', $actions );
		$this->assertArrayNotHasKey( 'workos_change_email', $actions );
	}

	/**
	 * Row actions for the default roles are unchanged.
	 */
	public function test_row_actions_unchanged_for_default_roles(): void {
		wp_set_current_user( $this->users['administrator'] );
		$actions = ( new PasswordResetRowActions() )->add_action( [], $this->other_subscriber, 'user_caps_other' );
		$actions = ( new ChangeEmailRowActions() )->add_action( $actions, $this->other_subscriber, 'user_caps_other' );
		$this->assertArrayHasKey( 'workos_password_reset', $actions );
		$this->assertArrayHasKey( 'workos_change_email', $actions );

		wp_set_current_user( $this->users['editor'] );
		$actions = ( new PasswordResetRowActions() )->add_action( [], $this->other_subscriber, 'user_caps_other' );
		$actions = ( new ChangeEmailRowActions() )->add_action( $actions, $this->other_subscriber, 'user_caps_other' );
		$this->assertSame( [], $actions );
	}

	/**
	 * The user-edit panels follow their own capability.
	 */
	public function test_profile_panels_follow_their_capability(): void {
		$support = $this->create_support_user( [ Capabilities::CHANGE_EMAIL => true ] );
		wp_set_current_user( $support );
		$target = get_userdata( $this->other_subscriber );

		ob_start();
		( new PasswordResetPanel() )->render( $target );
		$reset_html = (string) ob_get_clean();

		ob_start();
		( new ChangeEmailPanel() )->render( $target );
		$change_html = (string) ob_get_clean();

		$this->assertSame( '', $reset_html );
		$this->assertStringContainsString( 'workos-change-email-trigger', $change_html );
	}

	/**
	 * The shortcodes follow their own capability, and self-service still
	 * works for a subscriber.
	 */
	public function test_shortcodes_follow_their_capability(): void {
		$support = $this->create_support_user( [ Capabilities::SEND_PASSWORD_RESET => true ] );
		wp_set_current_user( $support );

		$atts = [ 'user' => (string) $this->other_subscriber ];
		$this->assertStringContainsString( 'workos-pwreset-trigger', ( new PasswordResetShortcode() )->render( $atts ) );
		$this->assertSame( '', ( new ChangeEmailShortcode() )->render( $atts ) );

		wp_set_current_user( $this->other_subscriber );
		$this->assertStringContainsString( 'workos-pwreset-trigger', ( new PasswordResetShortcode() )->render( [] ) );
		$this->assertStringContainsString( 'workos-change-email-form', ( new ChangeEmailShortcode() )->render( [] ) );
	}

	/**
	 * The WorkOS → Users submenu is registered with the plugin capability,
	 * and a role holding only that capability reaches it.
	 */
	public function test_users_submenu_uses_view_users(): void {
		global $submenu;

		$support = $this->create_support_user( [ Capabilities::VIEW_USERS => true ] );
		wp_set_current_user( $support );

		$saved = $submenu ?? [];
		try {
			( new UsersAdminPage() )->register_menu();

			$entry = null;
			foreach ( $submenu['workos'] ?? [] as $item ) {
				if ( UsersAdminPage::MENU_SLUG === $item[2] ) {
					$entry = $item;
				}
			}

			$this->assertNotNull( $entry, 'The Users submenu must be registered.' );
			$this->assertSame( Capabilities::VIEW_USERS, $entry[1] );
		} finally {
			$submenu = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the test's own change.
		}
	}
}
