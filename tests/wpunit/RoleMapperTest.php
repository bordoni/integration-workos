<?php
/**
 * Tests for RoleMapper.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Sync\RoleMapper;

/**
 * Role mapping, reverse mapping, and role sync tests.
 */
class RoleMapperTest extends WPTestCase {

	/**
	 * Set up each test.
	 */
	public function setUp(): void {
		parent::setUp();

		\WorkOS\Config::set_active_environment( 'production' );
		update_option( 'workos_production', [
			'api_key'        => 'sk_test_fake',
			'client_id'      => 'client_fake',
			'environment_id' => 'environment_test',
		] );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		// Prevent HTTP calls.
		add_filter( 'pre_http_request', [ $this, 'block_http' ], 10, 3 );

		remove_all_actions( 'user_register' );
		remove_all_actions( 'profile_update' );
		remove_all_actions( 'set_user_role' );
	}

	/**
	 * Tear down each test.
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'block_http' ], 10 );
		delete_option( 'workos_production' );
		\WorkOS\Config::set_active_environment( 'staging' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$ref = new \ReflectionProperty( RoleMapper::class, 'syncing' );
		$ref->setAccessible( true );
		$ref->setValue( null, false );

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
	 * Test map_role default mapping.
	 */
	public function test_map_role_default_admin(): void {
		$mapper = new RoleMapper();
		$this->assertSame( 'administrator', $mapper->map_role( 'admin' ) );
	}

	/**
	 * Test map_role default editor.
	 */
	public function test_map_role_default_editor(): void {
		$mapper = new RoleMapper();
		$this->assertSame( 'editor', $mapper->map_role( 'editor' ) );
	}

	/**
	 * Test map_role default member.
	 */
	public function test_map_role_default_member(): void {
		$mapper = new RoleMapper();
		$this->assertSame( 'subscriber', $mapper->map_role( 'member' ) );
	}

	/**
	 * Test map_role fallback for unknown role.
	 */
	public function test_map_role_fallback_for_unknown(): void {
		$mapper = new RoleMapper();
		// Unknown role should fall back to 'member' mapping → 'subscriber'.
		$this->assertSame( 'subscriber', $mapper->map_role( 'custom_unknown_role' ) );
	}

	/**
	 * Test map_role uses custom saved map.
	 */
	public function test_map_role_uses_custom_map(): void {
		$opts             = get_option( 'workos_production' );
		$opts['role_map'] = [
			'admin'  => 'editor',
			'member' => 'author',
		];
		update_option( 'workos_production', $opts );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$mapper = new RoleMapper();
		$this->assertSame( 'editor', $mapper->map_role( 'admin' ) );
		$this->assertSame( 'author', $mapper->map_role( 'member' ) );
	}

	/**
	 * Test map_role uses defaults when saved map is empty.
	 */
	public function test_map_role_uses_defaults_when_empty(): void {
		$opts             = get_option( 'workos_production' );
		$opts['role_map'] = [];
		update_option( 'workos_production', $opts );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$mapper = new RoleMapper();
		$this->assertSame( 'administrator', $mapper->map_role( 'admin' ) );
	}

	/**
	 * Test reverse_map_role returns WorkOS slug.
	 */
	public function test_reverse_map_role_returns_workos_slug(): void {
		$mapper = new RoleMapper();
		$this->assertSame( 'admin', $mapper->reverse_map_role( 'administrator' ) );
	}

	/**
	 * Test reverse_map_role returns empty for unmapped.
	 */
	public function test_reverse_map_role_empty_for_unmapped(): void {
		$mapper = new RoleMapper();
		$this->assertSame( '', $mapper->reverse_map_role( 'contributor' ) );
	}

	/**
	 * Test get_role_map returns saved or defaults.
	 */
	public function test_get_role_map_returns_defaults(): void {
		$map = RoleMapper::get_role_map();
		$this->assertArrayHasKey( 'admin', $map );
		$this->assertArrayHasKey( 'member', $map );
	}

	/**
	 * Test sync_role_on_login sets WP role.
	 */
	public function test_sync_role_on_login_sets_wp_role(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$mapper = new RoleMapper();
		$mapper->sync_role_on_login( $user_id, [ 'role' => 'admin' ] );

		$user = get_user_by( 'id', $user_id );
		$this->assertTrue( in_array( 'administrator', $user->roles, true ) );
	}

	/**
	 * Test sync_role_on_login skips when no role provided.
	 */
	public function test_sync_role_on_login_skips_when_no_role(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$mapper = new RoleMapper();
		$mapper->sync_role_on_login( $user_id, [] );

		$user = get_user_by( 'id', $user_id );
		$this->assertTrue( in_array( 'subscriber', $user->roles, true ) );
	}

	/**
	 * Test sync_role_on_login reads from organization_membership.
	 */
	public function test_sync_role_on_login_reads_from_org_membership(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$mapper = new RoleMapper();
		$mapper->sync_role_on_login( $user_id, [
			'organization_membership' => [ 'role' => 'editor' ],
		] );

		$user = get_user_by( 'id', $user_id );
		$this->assertTrue( in_array( 'editor', $user->roles, true ) );
	}

	/**
	 * Test apply_role skips if already correct.
	 */
	public function test_apply_role_skips_if_already_correct(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

		$mapper = new RoleMapper();
		$mapper->apply_role( $user_id, 'admin' );

		// Should still be administrator.
		$user = get_user_by( 'id', $user_id );
		$this->assertTrue( in_array( 'administrator', $user->roles, true ) );
	}

	/**
	 * Test save_role_map persists to env option.
	 */
	public function test_save_role_map_persists(): void {
		RoleMapper::save_role_map( [
			'admin'  => 'editor',
			'member' => 'author',
		] );

		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$map = RoleMapper::get_role_map();
		$this->assertSame( 'editor', $map['admin'] );
		$this->assertSame( 'author', $map['member'] );
	}

	/**
	 * Test get_wp_roles returns available roles.
	 */
	public function test_get_wp_roles_returns_available_roles(): void {
		$roles = RoleMapper::get_wp_roles();
		$this->assertArrayHasKey( 'administrator', $roles );
		$this->assertArrayHasKey( 'subscriber', $roles );
	}
}
