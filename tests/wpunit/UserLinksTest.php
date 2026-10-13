<?php
/**
 * Tests for UserLinks.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Database\Schema;
use WorkOS\Sync\RoleMapper;
use WorkOS\Sync\UserLinks;
use WorkOS\Sync\UserSync;

/**
 * WorkOS user ID → WP user lookups through the link table.
 */
class UserLinksTest extends WPTestCase {

	/**
	 * Set up each test.
	 */
	public function setUp(): void {
		parent::setUp();

		Schema::activate();

		// No outbound sync while users are created and changed.
		remove_all_actions( 'user_register' );
		remove_all_actions( 'profile_update' );
		remove_all_actions( 'set_user_role' );
	}

	/**
	 * Tear down each test.
	 */
	public function tearDown(): void {
		global $wpdb;

		$wpdb->query( 'TRUNCATE TABLE ' . UserLinks::table() );
		update_option( UserLinks::READY_OPTION, 1 );

		parent::tearDown();
	}

	/**
	 * The table's rows for these users, sorted. (Other suites' schema DDL commits their
	 * fixtures, so the table can hold rows that aren't this test's.)
	 *
	 * @param int[] $user_ids WP user IDs.
	 *
	 * @return array<int, array{0: string, 1: int}>
	 */
	private function rows( array $user_ids ): array {
		global $wpdb;

		$in   = implode( ',', array_map( 'intval', $user_ids ) );
		$rows = $wpdb->get_results( 'SELECT workos_user_id, wp_user_id FROM ' . UserLinks::table() . " WHERE wp_user_id IN ($in) ORDER BY workos_user_id, wp_user_id", ARRAY_N );
		return array_map( static fn( $r ) => [ (string) $r[0], (int) $r[1] ], $rows );
	}

	/**
	 * The lookup the table replaces.
	 *
	 * @param string $workos_id WorkOS user ID.
	 *
	 * @return int|null
	 */
	private function reference( string $workos_id ): ?int {
		$users = get_users(
			[
				'meta_key'   => '_workos_user_id',
				'meta_value' => $workos_id,
				'number'     => 1,
				'fields'     => 'ID',
			]
		);
		return ! empty( $users ) ? (int) $users[0] : null;
	}

	/**
	 * Linking, re-linking and unlinking through the meta API keep the table in step.
	 */
	public function test_table_follows_meta_writes(): void {
		$user = self::factory()->user->create();

		add_user_meta( $user, '_workos_user_id', 'user_a' );
		$this->assertSame( [ [ 'user_a', $user ] ], $this->rows( [ $user ] ) );

		update_user_meta( $user, '_workos_user_id', 'user_b' );
		$this->assertSame( [ [ 'user_b', $user ] ], $this->rows( [ $user ] ) );

		delete_user_meta( $user, '_workos_user_id' );
		$this->assertSame( [], $this->rows( [ $user ] ) );
	}

	/**
	 * Empty values and other meta keys never reach the table.
	 */
	public function test_ignores_empty_and_other_meta(): void {
		$user = self::factory()->user->create();

		update_user_meta( $user, '_workos_user_id', '' );
		update_user_meta( $user, '_workos_org_id', 'org_x' );

		$this->assertSame( [], $this->rows( [ $user ] ) );
	}

	/**
	 * Deleting a user removes their link.
	 */
	public function test_deleted_user_leaves_table(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';

		$user = self::factory()->user->create();
		update_user_meta( $user, '_workos_user_id', 'user_gone' );
		wp_delete_user( $user );

		$this->assertSame( [], $this->rows( [ $user ] ) );
		$this->assertNull( UserLinks::find( 'user_gone' ) );
	}

	/**
	 * The rebuild fills the table from meta written before it existed, and marks it ready.
	 */
	public function test_rebuild_backfills_existing_meta(): void {
		global $wpdb;

		$a = self::factory()->user->create();
		$b = self::factory()->user->create();
		update_user_meta( $a, '_workos_user_id', 'user_a' );
		update_user_meta( $b, '_workos_user_id', 'user_b' );

		$wpdb->query( 'TRUNCATE TABLE ' . UserLinks::table() );
		delete_option( UserLinks::READY_OPTION );
		$this->assertFalse( UserLinks::is_ready() );

		UserLinks::rebuild();
		UserLinks::rebuild(); // Running it again changes nothing.

		$this->assertTrue( UserLinks::is_ready() );
		$expected = [ [ 'user_a', $a ], [ 'user_b', $b ] ];
		$this->assertSame( $expected, $this->rows( [ $a, $b ] ) );
	}

	/**
	 * The schema upgrade to v4 runs the rebuild.
	 */
	public function test_schema_upgrade_backfills(): void {
		global $wpdb;

		$user = self::factory()->user->create();
		update_user_meta( $user, '_workos_user_id', 'user_upgrade' );
		$wpdb->query( 'TRUNCATE TABLE ' . UserLinks::table() );
		delete_option( UserLinks::READY_OPTION );

		update_option( 'workos_db_version', 3 );
		Schema::maybe_upgrade();

		$this->assertSame( 4, (int) get_option( 'workos_db_version' ) );
		$this->assertSame( $user, UserLinks::find( 'user_upgrade' ) );
	}

	/**
	 * find() gives the same answer as the meta query it replaces.
	 */
	public function test_find_matches_meta_query(): void {
		$linked = self::factory()->user->create();
		update_user_meta( $linked, '_workos_user_id', 'user_linked' );

		// Two users carrying one ID: the first by login wins, as with get_users().
		$zed  = self::factory()->user->create( [ 'user_login' => 'zed_dupe' ] );
		$adam = self::factory()->user->create( [ 'user_login' => 'adam_dupe' ] );
		update_user_meta( $zed, '_workos_user_id', 'user_dupe' );
		update_user_meta( $adam, '_workos_user_id', 'user_dupe' );

		foreach ( [ 'user_linked', 'user_dupe', 'user_missing' ] as $workos_id ) {
			$this->assertSame( $this->reference( $workos_id ), UserLinks::find( $workos_id ), $workos_id );
		}
		$this->assertSame( $adam, UserLinks::find( 'user_dupe' ) );
		$this->assertNull( UserLinks::find( '' ) );
	}

	/**
	 * A user with no capabilities on this site: the same answer as get_users(), which
	 * leaves them out on multisite and returns them on a single site.
	 */
	public function test_find_follows_get_users_site_rule(): void {
		global $wpdb;

		$user = self::factory()->user->create();
		update_user_meta( $user, '_workos_user_id', 'user_offsite' );
		delete_user_meta( $user, $wpdb->get_blog_prefix() . 'capabilities' );

		$this->assertSame( is_multisite() ? null : $user, $this->reference( 'user_offsite' ) );
		$this->assertSame( $this->reference( 'user_offsite' ), UserLinks::find( 'user_offsite' ) );
	}

	/**
	 * A row the meta no longer backs (a direct SQL write) is repaired, and the meta answers.
	 */
	public function test_stale_row_falls_back_to_meta(): void {
		global $wpdb;

		$old = self::factory()->user->create();
		$new = self::factory()->user->create();
		update_user_meta( $old, '_workos_user_id', 'user_moved' );

		// Move the link behind the hooks' back.
		$wpdb->update( $wpdb->usermeta, [ 'user_id' => $new ], [ 'user_id' => $old, 'meta_key' => '_workos_user_id' ] );
		wp_cache_delete( $old, 'user_meta' );
		wp_cache_delete( $new, 'user_meta' );

		$this->assertSame( $new, UserLinks::find( 'user_moved' ) );
		$this->assertSame( [], $this->rows( [ $old ] ) );
	}

	/**
	 * Before the table is filled, lookups use the meta.
	 */
	public function test_not_ready_uses_meta(): void {
		global $wpdb;

		$user = self::factory()->user->create();
		update_user_meta( $user, '_workos_user_id', 'user_early' );
		$wpdb->query( 'TRUNCATE TABLE ' . UserLinks::table() );
		delete_option( UserLinks::READY_OPTION );

		$this->assertSame( $user, UserLinks::find( 'user_early' ) );
		$this->assertSame( [ 'user_early' => $user ], UserLinks::find_many( [ 'user_early', 'user_none' ] ) );
	}

	/**
	 * find_many() resolves a page of IDs in two queries and leaves unlinked IDs out.
	 */
	public function test_find_many_two_queries(): void {
		global $wpdb;

		$ids = [];
		for ( $i = 0; $i < 10; $i++ ) {
			$user = self::factory()->user->create();
			update_user_meta( $user, '_workos_user_id', "user_many_{$i}" );
			$ids[ "user_many_{$i}" ] = $user;
		}

		wp_cache_flush();
		UserLinks::is_ready(); // Options load once per request anyway.
		$before = $wpdb->num_queries;
		$found  = UserLinks::find_many( array_merge( array_keys( $ids ), [ 'user_many_none' ] ) );

		$this->assertSame( 2, $wpdb->num_queries - $before );
		ksort( $found );
		ksort( $ids );
		$this->assertSame( $ids, $found );
	}

	/**
	 * A lookup is one indexed query (plus the user's meta), not a scan of the meta table.
	 */
	public function test_find_uses_the_index(): void {
		global $wpdb;

		$user = self::factory()->user->create();
		update_user_meta( $user, '_workos_user_id', 'user_indexed' );

		$plan = $wpdb->get_row(
			$wpdb->prepare( 'EXPLAIN SELECT wp_user_id FROM ' . UserLinks::table() . ' WHERE workos_user_id = %s', 'user_indexed' ),
			ARRAY_A
		);
		$this->assertSame( 'PRIMARY', $plan['key'] );
	}

	/**
	 * The callers use the table: sign-in and webhooks (UserSync) and the role push (RoleMapper).
	 */
	public function test_callers_use_the_table(): void {
		$user = self::factory()->user->create();
		update_user_meta( $user, '_workos_user_id', 'user_callers' );

		$this->assertSame( $user, UserSync::get_wp_user_id_by_workos_id( 'user_callers' ) );

		$method = new \ReflectionMethod( RoleMapper::class, 'get_wp_user_by_workos_id' );
		$method->setAccessible( true );
		$this->assertSame( $user, $method->invoke( null, 'user_callers' ) );
	}
}
