<?php
/**
 * Exercises Users-screen membership refresh actions, permissions, and error notices.
 *
 * Uses mocked WorkOS responses to verify that bulk role sync and full re-sync
 * read current memberships without granting permissions from stale local data.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Admin\UserList;
use WorkOS\App;
use WorkOS\Config;
use WorkOS\Options\Production;
use WorkOS\Organization\Manager;

/**
 * Integration coverage for the two inbound bulk actions on the Users screen.
 */
class UserListMembershipSyncTest extends WPTestCase {

	/** @var int Local organization ID. */
	private int $org_id;

	/** @var array Captured WorkOS HTTP requests. */
	private array $requests = [];

	/** @var bool Whether membership requests fail. */
	private bool $fail_membership = false;

	/** @var int[] IDs of created target users. */
	private array $targets = [];

	/** @var string Previous active environment. */
	private string $previous_environment;

	/** @var mixed Previous production options. */
	private $previous_options;

	/**
	 * Configure a local organization and intercept every WorkOS request.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->requests        = [];
		$this->targets         = [];
		$this->fail_membership = false;

		$this->previous_environment = Config::get_active_environment();
		$this->previous_options     = get_option( 'workos_production' );
		Config::set_active_environment( 'production' );
		update_option( 'workos_production', [
			'api_key'         => 'sk_test_fake',
			'client_id'       => 'client_fake',
			'environment_id'  => 'environment_test',
			'organization_id' => 'org_bulk_refresh',
		] );
		App::container()->get( Production::class )->reset();

		remove_all_actions( 'user_register' );
		remove_all_actions( 'profile_update' );
		remove_all_actions( 'set_user_role' );
		remove_all_actions( 'workos_org_membership_changed' );
		add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );

		$this->org_id = Manager::upsert_organization( [ 'id' => 'org_bulk_refresh', 'name' => 'Bulk Refresh' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	/**
	 * Remove the mocked transport, custom role, and custom-table fixtures.
	 */
	public function tearDown(): void {
		global $wpdb;

		remove_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10 );
		remove_role( 'workos_profile_editor' );
		wp_set_current_user( 0 );
		foreach ( $this->targets as $user_id ) {
			wp_cache_delete( "user_orgs_{$user_id}", 'workos' );
			wp_cache_delete( "membership_{$user_id}_org_bulk_refresh", 'workos' );
		}
		$wpdb->delete( $wpdb->prefix . 'workos_org_memberships', [ 'org_id' => $this->org_id ] );
		$wpdb->delete( $wpdb->prefix . 'workos_org_sites', [ 'org_id' => $this->org_id ] );
		$wpdb->delete( $wpdb->prefix . 'workos_organizations', [ 'id' => $this->org_id ] );
		wp_cache_delete( 'org_' . $this->org_id, 'workos' );
		wp_cache_delete( 'org_workos_org_bulk_refresh', 'workos' );
		wp_cache_delete( 'org_site_' . get_current_blog_id(), 'workos' );
		if ( false === $this->previous_options ) {
			delete_option( 'workos_production' );
		} else {
			update_option( 'workos_production', $this->previous_options );
		}
		Config::set_active_environment( $this->previous_environment );
		App::container()->get( Production::class )->reset();

		parent::tearDown();
	}

	/**
	 * Supply current membership data and capture the actual requested URLs.
	 *
	 * @param mixed  $response Existing preempted response.
	 * @param array  $args Request arguments.
	 * @param string $url Request URL.
	 * @return mixed Mock HTTP response.
	 */
	public function intercept_http( $response, array $args, string $url ) {
		if ( false === strpos( $url, 'api.workos.com' ) ) {
			return $response;
		}
		$this->requests[] = [ 'url' => $url, 'method' => $args['method'] ?? 'GET' ];

		if ( false !== strpos( $url, '/organization_memberships' ) ) {
			if ( $this->fail_membership ) {
				return new \WP_Error( 'membership_unavailable', 'Membership lookup unavailable.' );
			}
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
			$body = [
				'data'          => [
					[
						'id'              => 'om_' . ( $query['user_id'] ?? '' ),
						'user_id'         => $query['user_id'] ?? '',
						'organization_id' => 'org_bulk_refresh',
						'status'          => 'active',
						'role'            => [ 'slug' => 'member' ],
						'updated_at'      => '2026-10-27T15:47:03.946Z',
					],
				],
				'list_metadata' => [ 'after' => null ],
			];
		} elseif ( false !== strpos( $url, '/user_management/users/' ) ) {
			$workos_id = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
			$body      = [
				'id'         => $workos_id,
				'first_name' => 'Refreshed',
				'last_name'  => 'Profile',
				'updated_at' => '2026-10-27T15:47:03.946Z',
			];
		} else {
			return new \WP_Error( 'unexpected_request', 'Unexpected WorkOS request.' );
		}

		return [ 'response' => [ 'code' => 200, 'message' => 'OK' ], 'body' => wp_json_encode( $body ) ];
	}

	/**
	 * Create a target whose saved membership and WP role are stale administrators.
	 *
	 * @return int Target user ID.
	 */
	private function target(): int {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator', 'first_name' => 'Original' ] );
		update_user_meta( $user_id, '_workos_user_id', 'user_bulk_' . $user_id );
		Manager::add_membership( $this->org_id, $user_id, [ 'workos_role' => 'admin', 'wp_role' => 'administrator' ] );
		$this->targets[] = $user_id;
		return $user_id;
	}

	/**
	 * Run the public bulk-action entry point and parse its notice parameters.
	 *
	 * @param string $action Bulk action name.
	 * @param int[]  $users Selected users.
	 * @return array Redirect query parameters.
	 */
	private function dispatch( string $action, array $users ): array {
		$list = new UserList();
		$url  = $list->handle_bulk_action( admin_url( 'users.php' ), $action, $users );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		return $query;
	}

	/**
	 * Both role-only and full re-sync perform current membership refreshes.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function inbound_actions(): array {
		return [
			'roles'   => [ 'workos_bulk_sync_roles', 'workos_roles_synced' ],
			'profile' => [ 'workos_bulk_resync', 'workos_resynced' ],
		];
	}

	/**
	 * Both actions apply a fresh role instead of the cached administrator role.
	 *
	 * @dataProvider inbound_actions
	 * @param string $action Bulk action name.
	 * @param string $success_key Success notice parameter.
	 */
	public function test_bulk_refresh_uses_current_membership( string $action, string $success_key ): void {
		$user_id = $this->target();
		Manager::get_user_orgs( $user_id );

		$query = $this->dispatch( $action, [ $user_id ] );

		$this->assertSame( '1', $query[ $success_key ] ?? '' );
		$this->assertSame( [ 'subscriber' ], get_userdata( $user_id )->roles );
		$this->assertSame( 'member', Manager::get_user_orgs( $user_id )[0]->workos_role );
		$this->assertNotEmpty( $this->requests );
		foreach ( $this->requests as $request ) {
			$this->assertSame( 'GET', $request['method'] );
			if ( false !== strpos( $request['url'], '/organization_memberships' ) ) {
				parse_str( (string) wp_parse_url( $request['url'], PHP_URL_QUERY ), $params );
				$this->assertSame( 'org_bulk_refresh', $params['organization_id'] ?? '' );
			}
		}
		$this->assertSame( 'workos_bulk_resync' === $action ? 'Refreshed' : 'Original', get_userdata( $user_id )->first_name );
	}

	/**
	 * A membership outage produces a failure notice without changing the user's role.
	 *
	 * @dataProvider inbound_actions
	 * @param string $action Bulk action name.
	 * @param string $success_key Success notice parameter.
	 */
	public function test_bulk_refresh_reports_membership_failure( string $action, string $success_key ): void {
		$user_id               = $this->target();
		$this->fail_membership = true;

		$query = $this->dispatch( $action, [ $user_id ] );

		$this->assertSame( '1', $query['workos_failed'] ?? '' );
		$this->assertNotEmpty( $query['workos_errors'] ?? '' );
		$this->assertSame( 0, (int) ( $query[ $success_key ] ?? 0 ) );
		$this->assertSame( [ 'administrator' ], get_userdata( $user_id )->roles );
		$this->assertSame( 'admin', Manager::get_user_orgs( $user_id )[0]->workos_role );
	}

	/**
	 * Editing profiles alone does not authorize changing organization-derived roles.
	 *
	 * @dataProvider inbound_actions
	 * @param string $action Bulk action name.
	 * @param string $success_key Success notice parameter.
	 */
	public function test_profile_editor_cannot_refresh_roles( string $action, string $success_key ): void {
		$user_id = $this->target();
		add_role( 'workos_profile_editor', 'Profile editor', [ 'read' => true, 'edit_users' => true ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'workos_profile_editor' ] ) );
		$this->assertTrue( current_user_can( 'edit_users' ) );
		$this->assertFalse( current_user_can( 'promote_user', $user_id ) );

		$query = $this->dispatch( $action, [ $user_id ] );

		$this->assertSame( 0, (int) ( $query[ $success_key ] ?? 0 ) );
		$this->assertEmpty( $this->requests );
		$this->assertSame( [ 'administrator' ], get_userdata( $user_id )->roles );
	}

	/**
	 * Per-target restrictions remain effective even for an administrator bulk action.
	 *
	 * @dataProvider inbound_actions
	 * @param string $action Bulk action name.
	 * @param string $success_key Success notice parameter.
	 */
	public function test_bulk_refresh_respects_target_edit_permission( string $action, string $success_key ): void {
		$user_id = $this->target();
		$deny    = static function ( array $caps, string $cap, int $actor, array $args ) use ( $user_id ): array {
			if ( 'edit_user' === $cap && ( $args[0] ?? 0 ) === $user_id ) {
				return [ 'do_not_allow' ];
			}
			return $caps;
		};
		add_filter( 'map_meta_cap', $deny, 10, 4 );

		try {
			$query = $this->dispatch( $action, [ $user_id ] );
		} finally {
			remove_filter( 'map_meta_cap', $deny, 10 );
		}

		$this->assertSame( 0, (int) ( $query[ $success_key ] ?? 0 ) );
		$this->assertEmpty( $this->requests );
		$this->assertSame( [ 'administrator' ], get_userdata( $user_id )->roles );
	}
}
