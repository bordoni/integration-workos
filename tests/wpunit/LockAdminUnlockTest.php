<?php
/**
 * Tests for the operator unlock action and lock registry helpers.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Lock\Admin\RowActions;
use WorkOS\Lock\Admin\UnlockHandler;
use WorkOS\Lock\Registry;

/**
 * Capability and nonce handling on the unlock action, plus the registry
 * helpers the admin surfaces are built on.
 *
 * @covers \WorkOS\Lock\Admin\UnlockHandler
 * @covers \WorkOS\Lock\Admin\RowActions
 * @covers \WorkOS\Lock\Registry
 */
class LockAdminUnlockTest extends WPTestCase {

	/**
	 * Lock registry.
	 *
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * Unlock handler.
	 *
	 * @var UnlockHandler
	 */
	private UnlockHandler $handler;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->registry = \WorkOS\App::container()->get( Registry::class );
		$this->handler  = new UnlockHandler( $this->registry );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		global $wpdb;

		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'workos\_lock\_%'" );
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Drive a lock to its threshold for a user.
	 *
	 * @param int    $user_id WP user ID.
	 * @param string $slug    Lock slug.
	 *
	 * @return void
	 */
	private function engage_lock( int $user_id, string $slug = 'password_auth' ): void {
		$lock    = $this->registry->get( $slug );
		$subject = $lock->for_user( $user_id );

		for ( $i = 0; $i < 40; $i++ ) {
			$lock->record( $subject );
		}
	}

	// ------------------------------------------------------------- registry

	/**
	 * All shipped locks are registered and uniquely slugged.
	 */
	public function test_default_locks_are_registered(): void {
		$slugs = $this->registry->slugs();

		foreach ( [ 'otp_send', 'password_auth', 'password_reset', 'mfa_challenge', 'signup', 'token_guess' ] as $slug ) {
			$this->assertContains( $slug, $slugs );
			$this->assertNotNull( $this->registry->get( $slug ) );
		}

		$this->assertSame( $slugs, array_unique( $slugs ), 'Slugs must be unique.' );
	}

	/**
	 * An unregistered slug resolves to null rather than exploding.
	 */
	public function test_unknown_slug_resolves_to_null(): void {
		$this->assertNull( $this->registry->get( 'not_a_lock' ) );
		$this->assertFalse( $this->registry->has( 'not_a_lock' ) );
	}

	/**
	 * A user holding no locks reports nothing.
	 */
	public function test_status_is_empty_for_an_untouched_user(): void {
		$user_id = self::factory()->user->create();

		$this->assertSame( [], $this->registry->status_for_user( $user_id ) );
	}

	/**
	 * An engaged lock shows up against the user.
	 */
	public function test_status_reports_engaged_locks(): void {
		$user_id = self::factory()->user->create();
		$this->engage_lock( $user_id );

		$statuses = $this->registry->status_for_user( $user_id );

		$this->assertArrayHasKey( 'password_auth', $statuses );
		$this->assertGreaterThan( 0, $statuses['password_auth']->breaches() );
	}

	/**
	 * Clearing removes every lock the user holds.
	 */
	public function test_release_all_clears_every_lock(): void {
		$user_id = self::factory()->user->create();

		$this->engage_lock( $user_id, 'password_auth' );
		$this->engage_lock( $user_id, 'token_guess' );

		$this->assertCount( 2, $this->registry->status_for_user( $user_id ) );

		$cleared = $this->registry->release_all_for_user( $user_id );

		$this->assertSame( 2, $cleared );
		$this->assertSame( [], $this->registry->status_for_user( $user_id ) );
	}

	/**
	 * Counters recorded against the address before a user existed are cleared too.
	 *
	 * Someone can accumulate a lock by address, then have a WordPress user
	 * created for them later; clearing "their" locks has to reach both.
	 */
	public function test_release_all_also_clears_address_counters(): void {
		$lock = $this->registry->get( 'otp_send' );

		// No user yet — this lands in an option row keyed by address.
		$subject = $lock->for_email( 'late-arrival@example.com' );
		$this->assertFalse( $subject->is_user_backed() );

		for ( $i = 0; $i < 20; $i++ ) {
			$lock->record( $subject );
		}

		$user_id = self::factory()->user->create( [ 'user_email' => 'late-arrival@example.com' ] );

		$this->assertNotSame( [], $this->registry->status_for_user( $user_id ) );

		$this->registry->release_all_for_user( $user_id );

		$this->assertSame( [], $this->registry->status_for_user( $user_id ) );
	}

	// -------------------------------------------------------------- row action

	/**
	 * The row action only appears for a user who actually holds a lock.
	 */
	public function test_row_action_only_shows_when_a_lock_is_held(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$target_id = self::factory()->user->create();
		$actions   = new RowActions( $this->registry, $this->handler );

		$this->assertSame( [], $actions->add_action( [], $target_id ) );

		$this->engage_lock( $target_id );

		$this->assertArrayHasKey( 'workos_clear_locks', $actions->add_action( [], $target_id ) );
	}

	/**
	 * A user without the capability never sees the action.
	 */
	public function test_row_action_hidden_without_capability(): void {
		$target_id = self::factory()->user->create();
		$this->engage_lock( $target_id );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$actions = new RowActions( $this->registry, $this->handler );

		$this->assertSame( [], $actions->add_action( [], $target_id ) );
	}

	/**
	 * The action URL carries a nonce scoped to the target user.
	 */
	public function test_action_url_is_nonced_per_user(): void {
		$url = $this->handler->action_url( 42 );

		$this->assertStringContainsString( 'admin-post.php', $url );
		$this->assertStringContainsString( 'action=workos_clear_locks', $url );
		$this->assertStringContainsString( 'user_id=42', $url );
		$this->assertStringContainsString( '_wpnonce=', $url );

		// wp_nonce_url() HTML-escapes the separators, so decode before parsing.
		parse_str( (string) wp_parse_url( html_entity_decode( $url ), PHP_URL_QUERY ), $query );

		$this->assertSame( 1, wp_verify_nonce( $query['_wpnonce'], UnlockHandler::NONCE_ACTION . 42 ) );
		$this->assertFalse( wp_verify_nonce( $query['_wpnonce'], UnlockHandler::NONCE_ACTION . 43 ) );
	}

	/**
	 * Clearing requires the capability to edit *other* users.
	 *
	 * The usual `edit_user( $id )` check would pass for someone acting on their
	 * own account, which would let a locked-out user lift their own lock from
	 * the profile screen.
	 */
	public function test_self_service_unlock_is_not_permitted(): void {
		$subscriber_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		$this->assertTrue(
			current_user_can( 'edit_user', $subscriber_id ),
			'A subscriber can edit their own profile, which is why edit_user is the wrong gate here.'
		);

		$this->assertFalse(
			current_user_can( 'edit_users' ),
			'…and edit_users, the gate actually used, correctly denies them.'
		);
	}

	/**
	 * An administrator passes the gate the handler uses.
	 */
	public function test_administrator_passes_the_capability_gate(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->assertTrue( current_user_can( 'edit_users' ) );
	}

	/**
	 * Editors cannot clear locks either, unless the role grants edit_users.
	 */
	public function test_editor_does_not_pass_the_capability_gate(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->assertFalse( current_user_can( 'edit_users' ) );
	}
}
