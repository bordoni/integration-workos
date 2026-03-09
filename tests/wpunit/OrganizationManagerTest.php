<?php
/**
 * Tests for Organization\Manager.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Organization\Manager;
use WorkOS\Database\Schema;

/**
 * Organization management tests.
 */
class OrganizationManagerTest extends WPTestCase {

	/**
	 * Set up each test.
	 */
	public function setUp(): void {
		parent::setUp();

		\WorkOS\Config::set_active_environment( 'production' );
		update_option( 'workos_production', [
			'api_key'         => 'sk_test_fake',
			'client_id'       => 'client_fake',
			'environment_id'  => 'environment_test',
			'organization_id' => 'org_configured',
		] );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		Schema::activate();

		remove_all_actions( 'user_register' );
	}

	/**
	 * Tear down each test.
	 */
	public function tearDown(): void {
		global $wpdb;

		// Clean up tables.
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}workos_org_memberships" );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}workos_org_sites" );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}workos_organizations" );

		delete_option( 'workos_production' );
		\WorkOS\Config::set_active_environment( 'staging' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		parent::tearDown();
	}

	/**
	 * Test upsert creates new org.
	 */
	public function test_upsert_creates_new_org(): void {
		$org_id = Manager::upsert_organization( [
			'id'   => 'org_new_123',
			'name' => 'Test Organization',
		] );

		$this->assertIsInt( $org_id );
		$this->assertGreaterThan( 0, $org_id );

		$org = Manager::get( $org_id );
		$this->assertSame( 'Test Organization', $org->name );
		$this->assertSame( 'org_new_123', $org->workos_org_id );
	}

	/**
	 * Test upsert updates existing org.
	 */
	public function test_upsert_updates_existing_org(): void {
		$org_id1 = Manager::upsert_organization( [
			'id'   => 'org_update_123',
			'name' => 'Original Name',
		] );

		$org_id2 = Manager::upsert_organization( [
			'id'   => 'org_update_123',
			'name' => 'Updated Name',
		] );

		$this->assertEquals( $org_id1, $org_id2 );

		$org = Manager::get( $org_id1 );
		$this->assertSame( 'Updated Name', $org->name );
	}

	/**
	 * Test upsert links to current site.
	 */
	public function test_upsert_links_to_current_site(): void {
		Manager::upsert_organization( [
			'id'   => 'org_site_link',
			'name' => 'Site Linked Org',
		] );

		$orgs = Manager::get_for_site( get_current_blog_id() );
		$this->assertCount( 1, $orgs );
		$this->assertSame( 'org_site_link', $orgs[0]->workos_org_id );
	}

	/**
	 * Test get_by_workos_id returns org.
	 */
	public function test_get_by_workos_id_returns_org(): void {
		Manager::upsert_organization( [
			'id'   => 'org_get_test',
			'name' => 'Get Test Org',
		] );

		$org = Manager::get_by_workos_id( 'org_get_test' );
		$this->assertNotNull( $org );
		$this->assertSame( 'Get Test Org', $org->name );
	}

	/**
	 * Test get_by_workos_id returns null when missing.
	 */
	public function test_get_by_workos_id_returns_null_when_missing(): void {
		$this->assertNull( Manager::get_by_workos_id( 'org_nonexistent' ) );
	}

	/**
	 * Test add_membership creates membership.
	 */
	public function test_add_membership_creates(): void {
		$org_id  = Manager::upsert_organization( [ 'id' => 'org_mem_test', 'name' => 'Membership Org' ] );
		$user_id = self::factory()->user->create();

		Manager::add_membership( $org_id, $user_id, [ 'workos_role' => 'admin' ] );

		$user_orgs = Manager::get_user_orgs( $user_id );
		$this->assertCount( 1, $user_orgs );
		$this->assertSame( 'admin', $user_orgs[0]->workos_role );
	}

	/**
	 * Test add_membership updates existing.
	 */
	public function test_add_membership_updates_existing(): void {
		$org_id  = Manager::upsert_organization( [ 'id' => 'org_mem_update', 'name' => 'Update Org' ] );
		$user_id = self::factory()->user->create();

		Manager::add_membership( $org_id, $user_id, [ 'workos_role' => 'member' ] );
		Manager::add_membership( $org_id, $user_id, [ 'workos_role' => 'admin' ] );

		$user_orgs = Manager::get_user_orgs( $user_id );
		$this->assertCount( 1, $user_orgs );
		$this->assertSame( 'admin', $user_orgs[0]->workos_role );
	}

	/**
	 * Test remove_membership deletes row.
	 */
	public function test_remove_membership_deletes_row(): void {
		$org_id  = Manager::upsert_organization( [ 'id' => 'org_mem_remove', 'name' => 'Remove Org' ] );
		$user_id = self::factory()->user->create();

		Manager::add_membership( $org_id, $user_id );
		Manager::remove_membership( $org_id, $user_id );

		$user_orgs = Manager::get_user_orgs( $user_id );
		$this->assertCount( 0, $user_orgs );
	}

	/**
	 * Test get_membership_id returns id.
	 */
	public function test_get_membership_id_returns_id(): void {
		$org_id  = Manager::upsert_organization( [ 'id' => 'org_memid', 'name' => 'MemId Org' ] );
		$user_id = self::factory()->user->create();

		Manager::add_membership( $org_id, $user_id, [ 'workos_membership_id' => 'om_12345' ] );

		$result = Manager::get_membership_id_for_user( $user_id, 'org_memid' );
		$this->assertSame( 'om_12345', $result );
	}

	/**
	 * Test get_membership_id returns empty when missing.
	 */
	public function test_get_membership_id_returns_empty_when_missing(): void {
		$result = Manager::get_membership_id_for_user( 999, 'org_nonexistent' );
		$this->assertSame( '', $result );
	}

	/**
	 * Test store_membership_id updates record.
	 */
	public function test_store_membership_id_updates_record(): void {
		$org_id  = Manager::upsert_organization( [ 'id' => 'org_store_memid', 'name' => 'Store Org' ] );
		$user_id = self::factory()->user->create();

		Manager::add_membership( $org_id, $user_id, [ 'workos_membership_id' => 'old_id' ] );
		Manager::store_membership_id( $user_id, 'org_store_memid', 'new_id' );

		$result = Manager::get_membership_id_for_user( $user_id, 'org_store_memid' );
		$this->assertSame( 'new_id', $result );
	}

	/**
	 * Test update_membership_role updates both roles.
	 */
	public function test_update_membership_role_updates_both_roles(): void {
		$org_id  = Manager::upsert_organization( [ 'id' => 'org_role_update', 'name' => 'Role Org' ] );
		$user_id = self::factory()->user->create();

		Manager::add_membership( $org_id, $user_id, [ 'workos_role' => 'member' ] );
		Manager::update_membership_role( $user_id, 'org_role_update', 'admin', 'administrator' );

		$user_orgs = Manager::get_user_orgs( $user_id );
		$this->assertSame( 'admin', $user_orgs[0]->workos_role );
		$this->assertSame( 'administrator', $user_orgs[0]->wp_role );
	}
}
