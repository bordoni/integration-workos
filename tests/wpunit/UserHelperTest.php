<?php
/**
 * Tests for the public WorkOS\User helper + global function shortcuts.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\User;

/**
 * Read-only accessors over `_workos_*` user meta.
 */
class UserHelperTest extends WPTestCase {

	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * A freshly-created WP user with no WorkOS meta is not linked.
	 */
	public function test_is_sso_is_false_for_plain_wp_user(): void {
		$user_id = self::factory()->user->create();

		$this->assertFalse( User::is_sso( $user_id ) );
		$this->assertFalse( User::has_active_session( $user_id ) );
	}

	/**
	 * is_sso becomes true as soon as `_workos_user_id` is set, regardless
	 * of whether an access token is present. This is the persistent link.
	 */
	public function test_is_sso_is_true_once_linked(): void {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, User::META_WORKOS_ID, 'user_01HXYZ' );

		$this->assertTrue( User::is_sso( $user_id ) );
		// But no access token = no active session yet.
		$this->assertFalse( User::has_active_session( $user_id ) );
	}

	/**
	 * has_active_session flips to true only when the access token is set.
	 */
	public function test_has_active_session_tracks_access_token(): void {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, User::META_WORKOS_ID, 'user_01HXYZ' );
		update_user_meta( $user_id, User::META_ACCESS_TOKEN, 'eyJ.foo.bar' );

		$this->assertTrue( User::has_active_session( $user_id ) );

		// Simulate a logout.
		delete_user_meta( $user_id, User::META_ACCESS_TOKEN );

		// Still linked, but no longer has an active session.
		$this->assertTrue( User::is_sso( $user_id ) );
		$this->assertFalse( User::has_active_session( $user_id ) );
	}

	/**
	 * Zero / omitted user_id targets the currently-authenticated user.
	 */
	public function test_zero_user_id_targets_current_user(): void {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, User::META_WORKOS_ID, 'user_current' );
		update_user_meta( $user_id, User::META_ACCESS_TOKEN, 'tok_current' );

		wp_set_current_user( $user_id );

		$this->assertTrue( User::is_sso() );
		$this->assertTrue( User::has_active_session() );
		$this->assertSame( 'user_current', User::get_workos_id() );
		$this->assertSame( 'tok_current', User::get_access_token() );
	}

	/**
	 * With no logged-in user and no id, every method returns a safe
	 * empty/false — mirrors what the original snippet did.
	 */
	public function test_no_current_user_returns_empty_and_false(): void {
		wp_set_current_user( 0 );

		$this->assertFalse( User::is_sso() );
		$this->assertFalse( User::has_active_session() );
		$this->assertSame( '', User::get_workos_id() );
		$this->assertSame( '', User::get_access_token() );
		$this->assertSame( '', User::get_refresh_token() );
		$this->assertSame( '', User::get_session_id() );
		$this->assertSame( '', User::get_organization_id() );
	}

	/**
	 * All getters surface the stored values.
	 */
	public function test_individual_getters(): void {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, User::META_WORKOS_ID, 'user_01' );
		update_user_meta( $user_id, User::META_ACCESS_TOKEN, 'at_01' );
		update_user_meta( $user_id, User::META_REFRESH_TOKEN, 'rt_01' );
		update_user_meta( $user_id, User::META_SESSION_ID, 'sid_01' );
		update_user_meta( $user_id, User::META_ORG_ID, 'org_01' );

		$this->assertSame( 'user_01', User::get_workos_id( $user_id ) );
		$this->assertSame( 'at_01', User::get_access_token( $user_id ) );
		$this->assertSame( 'rt_01', User::get_refresh_token( $user_id ) );
		$this->assertSame( 'sid_01', User::get_session_id( $user_id ) );
		$this->assertSame( 'org_01', User::get_organization_id( $user_id ) );
	}

	/**
	 * snapshot() packages every read into one structure.
	 */
	public function test_snapshot_returns_combined_payload(): void {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, User::META_WORKOS_ID, 'user_snap' );
		update_user_meta( $user_id, User::META_ACCESS_TOKEN, 'at_snap' );
		update_user_meta( $user_id, User::META_ORG_ID, 'org_snap' );
		update_user_meta( $user_id, User::META_SESSION_ID, 'sid_snap' );

		$snap = User::snapshot( $user_id );

		$this->assertSame( $user_id, $snap['user_id'] );
		$this->assertTrue( $snap['is_sso'] );
		$this->assertTrue( $snap['has_active_session'] );
		$this->assertSame( 'user_snap', $snap['workos_user_id'] );
		$this->assertSame( 'org_snap', $snap['organization_id'] );
		$this->assertSame( 'sid_snap', $snap['session_id'] );
	}

	/**
	 * snapshot() for a plain WP user returns a consistent shape of
	 * empties — third parties can always trust the keys are present.
	 */
	public function test_snapshot_for_plain_user_has_empty_but_consistent_shape(): void {
		$user_id = self::factory()->user->create();

		$snap = User::snapshot( $user_id );

		$this->assertArrayHasKey( 'user_id', $snap );
		$this->assertArrayHasKey( 'is_sso', $snap );
		$this->assertArrayHasKey( 'has_active_session', $snap );
		$this->assertArrayHasKey( 'workos_user_id', $snap );
		$this->assertArrayHasKey( 'organization_id', $snap );
		$this->assertArrayHasKey( 'session_id', $snap );
		$this->assertFalse( $snap['is_sso'] );
		$this->assertFalse( $snap['has_active_session'] );
		$this->assertSame( '', $snap['workos_user_id'] );
	}

	// ------------------------------------------------------------------
	// Global function shortcuts
	// ------------------------------------------------------------------

	public function test_workos_is_sso_user_delegates(): void {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, User::META_WORKOS_ID, 'user_g1' );

		$this->assertTrue( workos_is_sso_user( $user_id ) );
		$this->assertFalse( workos_has_active_session( $user_id ) );
	}

	public function test_workos_get_access_token_delegates(): void {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, User::META_ACCESS_TOKEN, 'tok_g1' );

		$this->assertSame( 'tok_g1', workos_get_access_token( $user_id ) );
	}

	public function test_workos_get_user_id_delegates(): void {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, User::META_WORKOS_ID, 'user_delegate' );

		$this->assertSame( 'user_delegate', workos_get_user_id( $user_id ) );
	}

	/**
	 * Exactly the third-party snippet from the docblock — refactored to
	 * the public helper. Proves the one-liner replacement works.
	 */
	public function test_third_party_integration_snippet(): void {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, User::META_WORKOS_ID, 'user_third' );
		update_user_meta( $user_id, User::META_ACCESS_TOKEN, 'tok_third' );
		wp_set_current_user( $user_id );

		// The replacement for:
		//   $user_id      = get_current_user_id();
		//   $workos_token = $user_id ? get_user_meta( $user_id, '_workos_access_token', true ) : '';
		//   $is_sso_user  = ! empty( $workos_token );
		$is_sso_user = workos_has_active_session();

		$this->assertTrue( $is_sso_user );
	}
}
