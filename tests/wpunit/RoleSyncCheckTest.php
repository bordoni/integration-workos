<?php
/**
 * Tests for RoleSyncCheck.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use WorkOS\Sync\UserSync;

use WorkOS\Sync\MembershipSync;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Admin\UserList;
use WorkOS\App;
use WorkOS\Config;
use WorkOS\Database\Schema;
use WorkOS\Options\Production;
use WorkOS\Organization\Manager;
use WorkOS\Sync\RoleMapper;
use WorkOS\Sync\RoleSyncCheck;

/**
 * Batched role sync check, stored result, and per-user rechecks.
 */
final class RoleSyncCheckTest extends WPTestCase {

	/**
	 * Local organization ID.
	 *
	 * @var int
	 */
	private int $org_id;

	/**
	 * Set up each test.
	 */
	public function setUp(): void {
		parent::setUp();

		Config::set_active_environment( 'production' );
		update_option( 'workos_production', [
			'api_key'         => 'sk_test_fake',
			'client_id'       => 'client_fake',
			'environment_id'  => 'environment_test',
			'organization_id' => 'org_check',
		] );
		App::container()->get( Production::class )->reset();

		Schema::activate();

		add_filter( 'pre_http_request', [ $this, 'block_http' ], 10, 3 );

		remove_all_actions( 'user_register' );
		remove_all_actions( 'profile_update' );
		remove_all_actions( 'set_user_role' );
		remove_all_actions( 'add_user_role' );
		remove_all_actions( 'remove_user_role' );
		remove_all_actions( 'workos_org_membership_changed' );
		remove_all_actions( 'deleted_user' );

		delete_option( RoleSyncCheck::OPTION );

		$this->org_id = Manager::upsert_organization( [ 'id' => 'org_check', 'name' => 'Check Org' ] );
	}

	/**
	 * Tear down each test.
	 */
	public function tearDown(): void {
		global $wpdb;

		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}workos_org_memberships" );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}workos_org_sites" );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}workos_organizations" );

		remove_filter( 'pre_http_request', [ $this, 'block_http' ], 10 );
		remove_all_filters( 'workos_role_sync_check_batch_size' );
		delete_option( RoleSyncCheck::OPTION );
		delete_option( 'workos_production' );
		Config::set_active_environment( 'staging' );
		App::container()->get( Production::class )->reset();

		parent::tearDown();
	}

	/**
	 * Block outgoing HTTP.
	 *
	 * @param false|array $response Pre-filtered response.
	 * @param array       $args     Request arguments.
	 * @param string      $url      Request URL.
	 *
	 * @return array|false
	 */
	public function block_http( $response, array $args, string $url ) {
		if ( false !== strpos( $url, 'api.workos.com' ) ) {
			return [
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'body'     => wp_json_encode( [ 'data' => [] ] ),
			];
		}
		return $response;
	}

	/**
	 * Add a member with a WordPress role.
	 *
	 * @param string $wp_role     WordPress role.
	 * @param string $workos_role WorkOS role on the membership.
	 * @param int    $org_id      Local org ID (defaults to the configured org).
	 *
	 * @return int User ID.
	 */
	private function member( string $wp_role, string $workos_role, int $org_id = 0 ): int {
		$user_id = self::factory()->user->create( [ 'role' => $wp_role ] );
		Manager::add_membership( $org_id ?: $this->org_id, $user_id, [ 'workos_role' => $workos_role ] );
		return $user_id;
	}

	/**
	 * The pre-change check: one get_user_by() per member. Kept here as the reference
	 * the batched check must agree with.
	 *
	 * @return array<int, array>
	 */
	private function reference_out_of_sync(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, workos_role FROM {$wpdb->prefix}workos_org_memberships WHERE org_id = %d AND workos_role != ''",
				$this->org_id
			)
		);

		$map = RoleMapper::get_role_map();
		$out = [];
		foreach ( $rows as $row ) {
			$user = get_user_by( 'id', $row->user_id );
			if ( ! $user ) {
				continue;
			}
			$expected = $map[ $row->workos_role ] ?? $map['member'] ?? 'subscriber';
			$actual   = ! empty( $user->roles ) ? $user->roles[0] : '';
			if ( $expected !== $actual ) {
				$out[ (int) $row->user_id ] = [
					'user_id'          => (int) $row->user_id,
					'display_name'     => $user->display_name,
					'workos_role'      => $row->workos_role,
					'expected_wp_role' => $expected,
					'actual_wp_role'   => $actual,
				];
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * A mix of members, in and out of sync, plus rows the check must skip.
	 *
	 * @return array{in: int[], out: int[]}
	 */
	private function seed_mix(): array {
		$in  = [
			$this->member( 'subscriber', 'member' ),
			$this->member( 'administrator', 'admin' ),
			$this->member( 'editor', 'editor' ),
			$this->member( 'subscriber', 'custom_unmapped' ),
		];
		$out = [
			$this->member( 'editor', 'member' ),
			$this->member( 'subscriber', 'admin' ),
			$this->member( 'author', 'custom_unmapped' ),
		];

		// A member with no WP role at all.
		$no_role = $this->member( 'subscriber', 'editor' );
		( new \WP_User( $no_role ) )->set_role( '' );
		$out[] = $no_role;

		// Skipped: no WorkOS role, or a member of another organization.
		$this->member( 'editor', '' );
		$other = Manager::upsert_organization( [ 'id' => 'org_other', 'name' => 'Other Org' ] );
		$this->member( 'editor', 'member', $other );

		return [ 'in' => $in, 'out' => $out ];
	}

	/**
	 * The batched check finds the same users, with the same details, as the per-user check.
	 */
	public function test_compute_all_matches_per_user_check(): void {
		$seed = $this->seed_mix();

		$batched = RoleSyncCheck::compute_all();
		ksort( $batched );

		$this->assertSame( $this->reference_out_of_sync(), $batched );
		$this->assertEqualsCanonicalizing( $seed['out'], array_keys( $batched ) );
	}

	/**
	 * Small batches give the same answer as one big batch, including at batch boundaries.
	 */
	public function test_batch_size_does_not_change_the_result(): void {
		$this->seed_mix();
		$whole = RoleSyncCheck::compute_all();

		foreach ( [ 1, 2, 3, 4 ] as $size ) {
			$filter = static fn() => $size;
			add_filter( 'workos_role_sync_check_batch_size', $filter );
			$this->assertSame( $whole, RoleSyncCheck::compute_all(), "batch size {$size}" );
			remove_filter( 'workos_role_sync_check_batch_size', $filter );
		}
	}

	/**
	 * The check reads members in one query per batch, never one per user.
	 */
	public function test_check_runs_one_query_per_batch(): void {
		global $wpdb;

		for ( $i = 0; $i < 12; $i++ ) {
			$this->member( 0 === $i % 3 ? 'editor' : 'subscriber', 'member' );
		}
		add_filter( 'workos_role_sync_check_batch_size', static fn() => 5 );

		wp_cache_flush();
		RoleSyncCheck::compute_all(); // Warm the org lookup and role map.
		wp_cache_flush();
		Manager::get_by_workos_id( 'org_check' );

		$before = $wpdb->num_queries;
		$result = RoleSyncCheck::compute_all();
		$used   = $wpdb->num_queries - $before;

		$this->assertCount( 4, $result );
		// 12 members in batches of 5 → 3 queries (5, 5, 2), plus the org and option lookups.
		$this->assertLessThanOrEqual( 5, $used );
	}

	/**
	 * get_out_of_sync_users() keeps its shape for existing callers.
	 */
	public function test_get_out_of_sync_users_keeps_its_shape(): void {
		$seed = $this->seed_mix();

		$list = RoleMapper::get_out_of_sync_users();

		$this->assertSame( range( 0, count( $list ) - 1 ), array_keys( $list ) );
		$this->assertEqualsCanonicalizing( $seed['out'], array_column( $list, 'user_id' ) );
		$this->assertSame(
			[ 'user_id', 'display_name', 'workos_role', 'expected_wp_role', 'actual_wp_role' ],
			array_keys( $list[0] )
		);
	}

	/**
	 * Nothing is stored or returned until a check runs.
	 */
	public function test_result_is_null_before_any_check(): void {
		$this->seed_mix();
		$this->assertNull( RoleSyncCheck::result() );
	}

	/**
	 * run_now() stores the result with the member count and time.
	 */
	public function test_run_now_stores_result(): void {
		$seed = $this->seed_mix();

		$result = RoleSyncCheck::run_now();

		$this->assertNotNull( $result );
		$this->assertEqualsCanonicalizing( $seed['out'], $result['ids'] );
		$this->assertSame( count( $seed['out'] ), $result['count'] );
		$this->assertSame( count( $seed['in'] ) + count( $seed['out'] ), $result['members'] );
		$this->assertEqualsWithDelta( time(), $result['checked_at'], 5 );
		$this->assertSame( $result, RoleSyncCheck::result() );
	}

	/**
	 * The stored result isn't autoloaded on every request.
	 */
	public function test_result_option_is_not_autoloaded(): void {
		global $wpdb;

		$this->seed_mix();
		RoleSyncCheck::run_now();

		$autoload = $wpdb->get_var(
			$wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", RoleSyncCheck::OPTION )
		);
		$this->assertContains( $autoload, [ 'no', 'off' ] );
	}

	/**
	 * Without Action Scheduler, start() runs the check right away.
	 */
	public function test_start_without_action_scheduler_runs_now(): void {
		if ( RoleSyncCheck::has_action_scheduler() ) {
			$this->markTestSkipped( 'Action Scheduler is loaded in this environment.' );
		}

		$seed = $this->seed_mix();

		$this->assertSame( 'done', RoleSyncCheck::start() );
		$this->assertSame( count( $seed['out'] ), RoleSyncCheck::result()['count'] );
		$this->assertFalse( RoleSyncCheck::is_running() );
	}

	/**
	 * start() with no organization configured does nothing.
	 */
	public function test_start_without_org(): void {
		$opts                    = get_option( 'workos_production' );
		$opts['organization_id'] = '';
		update_option( 'workos_production', $opts );
		App::container()->get( Production::class )->reset();

		$this->assertSame( 'no-org', RoleSyncCheck::start() );
		$this->assertNull( RoleSyncCheck::result() );
	}

	/**
	 * Changing the role map makes the stored result stale.
	 */
	public function test_result_is_stale_after_role_map_change(): void {
		$this->seed_mix();
		RoleSyncCheck::run_now();
		$this->assertNotNull( RoleSyncCheck::result() );

		RoleMapper::save_role_map( [ 'admin' => 'administrator', 'member' => 'editor' ] );
		App::container()->get( Production::class )->reset();

		$this->assertNull( RoleSyncCheck::result() );
	}

	/**
	 * Changing the organization makes the stored result stale.
	 */
	public function test_result_is_stale_after_org_change(): void {
		$this->seed_mix();
		RoleSyncCheck::run_now();

		$opts                    = get_option( 'workos_production' );
		$opts['organization_id'] = 'org_other';
		update_option( 'workos_production', $opts );
		App::container()->get( Production::class )->reset();

		$this->assertNull( RoleSyncCheck::result() );
	}

	/**
	 * Fixing a user's role takes them out of the stored result; breaking it adds them.
	 */
	public function test_recheck_user_follows_role_changes(): void {
		$check = new RoleSyncCheck();
		$seed  = $this->seed_mix();
		RoleSyncCheck::run_now();

		$fixed = $seed['out'][0]; // An editor whose WorkOS role is member.
		( new \WP_User( $fixed ) )->set_role( 'subscriber' );
		$check->recheck_user( $fixed );
		$this->assertNotContains( $fixed, RoleSyncCheck::result()['ids'] );

		$broken = $seed['in'][0]; // A subscriber whose WorkOS role is member.
		( new \WP_User( $broken ) )->set_role( 'author' );
		$check->recheck_user( $broken );
		$this->assertContains( $broken, RoleSyncCheck::result()['ids'] );

		$this->assertSame( count( $seed['out'] ), RoleSyncCheck::result()['count'] );
	}

	/**
	 * The role hooks recheck the user without anyone calling it.
	 */
	public function test_role_hooks_recheck_user(): void {
		$check = new RoleSyncCheck();
		$seed  = $this->seed_mix();
		RoleSyncCheck::run_now();

		$user = $seed['in'][0];
		( new \WP_User( $user ) )->set_role( 'editor' );

		$this->assertContains( $user, RoleSyncCheck::result()['ids'] );

		remove_action( 'set_user_role', [ $check, 'recheck_user' ], 20 );
	}

	/**
	 * Membership changes recheck the user: a removed member drops out of the result.
	 */
	public function test_membership_change_rechecks_user(): void {
		$check = new RoleSyncCheck();
		$seed  = $this->seed_mix();
		RoleSyncCheck::run_now();

		$user = $seed['out'][0];
		Manager::remove_membership( $this->org_id, $user );
		$this->assertNotContains( $user, RoleSyncCheck::result()['ids'] );

		$user = $seed['in'][0]; // Subscriber; becomes an admin in WorkOS.
		Manager::update_membership_role( $user, 'org_check', 'admin', 'subscriber' );
		$this->assertContains( $user, RoleSyncCheck::result()['ids'] );

		remove_action( 'workos_org_membership_changed', [ $check, 'recheck_user' ], 20 );
	}

	/**
	 * Rechecks do nothing until a full check has run.
	 */
	public function test_recheck_user_without_result_does_nothing(): void {
		$check = new RoleSyncCheck();
		$seed  = $this->seed_mix();

		$check->recheck_user( $seed['out'][0] );

		$this->assertNull( RoleSyncCheck::result() );
		$this->assertFalse( get_option( RoleSyncCheck::OPTION ) );
	}

	/**
	 * A deleted user leaves the stored result.
	 */
	public function test_forget_user_on_delete(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';

		$check = new RoleSyncCheck();
		$seed  = $this->seed_mix();
		RoleSyncCheck::run_now();

		$user = $seed['out'][0];
		wp_delete_user( $user );

		$this->assertNotContains( $user, RoleSyncCheck::result()['ids'] );

		remove_action( 'deleted_user', [ $check, 'forget_user' ] );
	}

	/**
	 * A background run that never finished stops counting as running after an hour.
	 */
	public function test_stuck_run_stops_counting_as_running(): void {
		update_option( RoleSyncCheck::OPTION, [ 'running' => [ 'ids' => [], 'members' => 0, 'started' => time() ] ], false );
		$this->assertTrue( RoleSyncCheck::is_running() );

		update_option( RoleSyncCheck::OPTION, [ 'running' => [ 'ids' => [], 'members' => 0, 'started' => time() - 2 * HOUR_IN_SECONDS ] ], false );
		$this->assertFalse( RoleSyncCheck::is_running() );
	}

	/**
	 * A background run picks up after the last batch and adds up to the same result
	 * as a run in one request. (Without Action Scheduler, the rest finishes in this call.)
	 */
	public function test_background_run_resumes_after_last_batch(): void {
		$seed = $this->seed_mix();
		add_filter( 'workos_role_sync_check_batch_size', static fn() => 3 );

		// What the first batch of a background run leaves behind.
		$first = RoleSyncCheck::check_batch( $this->org_id, 0, 3 );
		update_option(
			RoleSyncCheck::OPTION,
			[
				'running' => [
					'ids'     => array_keys( $first['out_of_sync'] ),
					'members' => $first['rows'],
					'started' => time(),
				],
			],
			false
		);
		$this->assertTrue( RoleSyncCheck::is_running() );

		( new RoleSyncCheck() )->handle_action( [ 'after' => $first['last_id'] ] );

		$this->assertFalse( RoleSyncCheck::is_running() );
		$result = RoleSyncCheck::result();
		$this->assertNotNull( $result );
		$this->assertEqualsCanonicalizing( $seed['out'], $result['ids'] );
		$this->assertSame( count( $seed['in'] ) + count( $seed['out'] ), $result['members'] );
	}

	/**
	 * A batch action for a run that was never started (or already finished) does nothing.
	 */
	public function test_batch_without_running_run_does_nothing(): void {
		$this->seed_mix();
		( new RoleSyncCheck() )->handle_action( [ 'after' => 0 ] );
		$this->assertNull( RoleSyncCheck::result() );
	}

	/**
	 * prime_user_orgs() loads a page of users' organizations in one query, and
	 * get_user_orgs() then returns the same thing without querying.
	 */
	public function test_prime_user_orgs_one_query(): void {
		global $wpdb;

		$other   = Manager::upsert_organization( [ 'id' => 'org_prime', 'name' => 'Another Org' ] );
		$members = [];
		for ( $i = 0; $i < 6; $i++ ) {
			$members[] = $this->member( 'subscriber', 'member' );
		}
		Manager::add_membership( $other, $members[0], [ 'workos_role' => 'admin' ] );
		$loner = self::factory()->user->create();
		$ids   = array_merge( $members, [ $loner ] );

		$expected = [];
		foreach ( $ids as $id ) {
			$expected[ $id ] = Manager::get_user_orgs( $id );
		}

		wp_cache_flush();
		$before = $wpdb->num_queries;
		Manager::prime_user_orgs( $ids );
		$this->assertSame( 1, $wpdb->num_queries - $before );

		$before = $wpdb->num_queries;
		foreach ( $ids as $id ) {
			$this->assertEquals( $expected[ $id ], Manager::get_user_orgs( $id ), "user {$id}" );
		}
		$this->assertSame( 0, $wpdb->num_queries - $before );
		$this->assertSame( [], Manager::get_user_orgs( $loner ) );
		$this->assertCount( 2, Manager::get_user_orgs( $members[0] ) );
	}

	/**
	 * The users screen's "Out of sync" view shows the stored count and never computes it.
	 */
	public function test_users_view_reads_stored_result(): void {
		global $wpdb;

		$seed = $this->seed_mix();
		$list = new UserList( App::container()->get( MembershipSync::class ), App::container()->get( UserSync::class ) );

		$before = $wpdb->num_queries;
		$views  = $list->add_out_of_sync_view( [] );
		$this->assertArrayNotHasKey( 'workos_out_of_sync', $views, 'No view before a check has run.' );
		$this->assertLessThanOrEqual( 1, $wpdb->num_queries - $before );

		RoleSyncCheck::run_now();

		$before = $wpdb->num_queries;
		$views  = $list->add_out_of_sync_view( [] );
		$this->assertLessThanOrEqual( 1, $wpdb->num_queries - $before );
		$this->assertStringContainsString( '(' . count( $seed['out'] ) . ')', $views['workos_out_of_sync'] );
	}

	/**
	 * The "Out of sync" filter limits the users query to the stored IDs.
	 */
	public function test_users_filter_uses_stored_ids(): void {
		$seed = $this->seed_mix();
		RoleSyncCheck::run_now();

		$_GET['workos_sync_status'] = 'out_of_sync';
		$query                      = new \WP_User_Query();
		( new UserList( App::container()->get( MembershipSync::class ), App::container()->get( UserSync::class ) ) )->filter_out_of_sync_users( $query );
		unset( $_GET['workos_sync_status'] );

		$this->assertEqualsCanonicalizing( $seed['out'], $query->get( 'include' ) );
	}

	/**
	 * Membership writes fire workos_org_membership_changed with the user ID.
	 */
	public function test_membership_writes_fire_changed_action(): void {
		$seen = [];
		add_action(
			'workos_org_membership_changed',
			static function ( $user_id ) use ( &$seen ) {
				$seen[] = (int) $user_id;
			}
		);

		$user = self::factory()->user->create();
		Manager::add_membership( $this->org_id, $user, [ 'workos_role' => 'member' ] );
		Manager::update_membership_role( $user, 'org_check', 'admin', 'administrator' );
		Manager::remove_membership( $this->org_id, $user );

		$this->assertSame( [ $user, $user, $user ], $seen );
	}
}
