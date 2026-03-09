<?php
/**
 * Tests for UserSync::link_user(), unlink_user(), get_wp_user_id_by_workos_id().
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Sync\UserSync;

/**
 * User linking meta operations tests.
 */
class UserSyncLinkTest extends WPTestCase {

	/**
	 * Set up each test.
	 */
	public function setUp(): void {
		parent::setUp();
		remove_all_actions( 'user_register' );
	}

	/**
	 * Test link stores WorkOS user ID.
	 */
	public function test_link_stores_workos_user_id(): void {
		$user_id = self::factory()->user->create();

		UserSync::link_user( $user_id, [ 'id' => 'user_abc123', 'email' => 'test@example.com' ] );

		$this->assertSame( 'user_abc123', get_user_meta( $user_id, '_workos_user_id', true ) );
	}

	/**
	 * Test link stores last synced at timestamp.
	 */
	public function test_link_stores_last_synced_at(): void {
		$user_id = self::factory()->user->create();

		UserSync::link_user( $user_id, [ 'id' => 'user_abc123', 'email' => 'test@example.com' ] );

		$synced_at = get_user_meta( $user_id, '_workos_last_synced_at', true );
		$this->assertNotEmpty( $synced_at );
	}

	/**
	 * Test link stores profile hash.
	 */
	public function test_link_stores_profile_hash(): void {
		$user_id = self::factory()->user->create();

		UserSync::link_user( $user_id, [ 'id' => 'user_abc123', 'email' => 'test@example.com' ] );

		$hash = get_user_meta( $user_id, '_workos_profile_hash', true );
		$this->assertNotEmpty( $hash );
		$this->assertSame( 64, strlen( $hash ), 'Profile hash should be a SHA-256 hex string.' );
	}

	/**
	 * Test link stores org_id when present.
	 */
	public function test_link_stores_org_id_when_present(): void {
		$user_id = self::factory()->user->create();

		UserSync::link_user(
			$user_id,
			[
				'id'              => 'user_abc123',
				'email'           => 'test@example.com',
				'organization_id' => 'org_xyz',
			]
		);

		$this->assertSame( 'org_xyz', get_user_meta( $user_id, '_workos_org_id', true ) );
	}

	/**
	 * Test link skips org_id when absent.
	 */
	public function test_link_skips_org_id_when_absent(): void {
		$user_id = self::factory()->user->create();

		UserSync::link_user( $user_id, [ 'id' => 'user_abc123', 'email' => 'test@example.com' ] );

		$this->assertEmpty( get_user_meta( $user_id, '_workos_org_id', true ) );
	}

	/**
	 * Test unlink removes all WorkOS meta.
	 */
	public function test_unlink_removes_all_workos_meta(): void {
		$user_id = self::factory()->user->create();

		// First link.
		UserSync::link_user( $user_id, [ 'id' => 'user_abc123', 'email' => 'test@example.com', 'organization_id' => 'org_xyz' ] );
		update_user_meta( $user_id, '_workos_access_token', 'token_123' );
		update_user_meta( $user_id, '_workos_refresh_token', 'refresh_123' );
		update_user_meta( $user_id, '_workos_deactivated', '1' );

		// Then unlink.
		UserSync::unlink_user( $user_id );

		$this->assertEmpty( get_user_meta( $user_id, '_workos_user_id', true ) );
		$this->assertEmpty( get_user_meta( $user_id, '_workos_org_id', true ) );
		$this->assertEmpty( get_user_meta( $user_id, '_workos_last_synced_at', true ) );
		$this->assertEmpty( get_user_meta( $user_id, '_workos_profile_hash', true ) );
		$this->assertEmpty( get_user_meta( $user_id, '_workos_access_token', true ) );
		$this->assertEmpty( get_user_meta( $user_id, '_workos_refresh_token', true ) );
		$this->assertEmpty( get_user_meta( $user_id, '_workos_deactivated', true ) );
	}

	/**
	 * Test unlink preserves other meta.
	 */
	public function test_unlink_preserves_other_meta(): void {
		$user_id = self::factory()->user->create();

		update_user_meta( $user_id, '_custom_meta', 'preserved_value' );
		UserSync::link_user( $user_id, [ 'id' => 'user_abc123', 'email' => 'test@example.com' ] );

		UserSync::unlink_user( $user_id );

		$this->assertSame( 'preserved_value', get_user_meta( $user_id, '_custom_meta', true ) );
	}

	/**
	 * Test get_wp_user_id_by_workos_id returns correct user.
	 */
	public function test_get_wp_user_id_by_workos_id_returns_correct_user(): void {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, '_workos_user_id', 'user_lookup_test' );

		$found = UserSync::get_wp_user_id_by_workos_id( 'user_lookup_test' );

		$this->assertSame( $user_id, $found );
	}

	/**
	 * Test get_wp_user_id_by_workos_id returns null for unknown ID.
	 */
	public function test_get_wp_user_id_by_workos_id_returns_null_for_unknown(): void {
		$this->assertNull( UserSync::get_wp_user_id_by_workos_id( 'nonexistent_id' ) );
	}
}
