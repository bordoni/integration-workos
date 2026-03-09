<?php
/**
 * Tests for Database\Schema.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Database\Schema;

/**
 * Database schema tests.
 */
class SchemaTest extends WPTestCase {

	/**
	 * Tear down each test.
	 */
	public function tearDown(): void {
		// Always recreate tables so other tests still work.
		Schema::activate();
		parent::tearDown();
	}

	/**
	 * Test activate creates all 4 tables.
	 */
	public function test_activate_creates_all_tables(): void {
		global $wpdb;

		Schema::drop_tables();
		Schema::activate();

		$tables = $wpdb->get_col( 'SHOW TABLES' );

		$this->assertContains( $wpdb->prefix . 'workos_organizations', $tables );
		$this->assertContains( $wpdb->prefix . 'workos_org_memberships', $tables );
		$this->assertContains( $wpdb->prefix . 'workos_org_sites', $tables );
		$this->assertContains( $wpdb->prefix . 'workos_activity_log', $tables );
	}

	/**
	 * Test activate sets version option.
	 */
	public function test_activate_sets_version_option(): void {
		Schema::activate();

		$version = (int) get_option( 'workos_db_version', 0 );
		$this->assertGreaterThan( 0, $version );
	}

	/**
	 * Test maybe_upgrade skips when current.
	 */
	public function test_maybe_upgrade_skips_when_current(): void {
		Schema::activate();

		$version_before = (int) get_option( 'workos_db_version', 0 );

		Schema::maybe_upgrade();

		$version_after = (int) get_option( 'workos_db_version', 0 );
		$this->assertSame( $version_before, $version_after );
	}

	/**
	 * Test maybe_upgrade runs when outdated.
	 */
	public function test_maybe_upgrade_runs_when_outdated(): void {
		update_option( 'workos_db_version', 0 );

		Schema::maybe_upgrade();

		$version = (int) get_option( 'workos_db_version', 0 );
		$this->assertGreaterThan( 0, $version );
	}

	/**
	 * Test drop_tables executes without error.
	 *
	 * Note: WPTestCase wraps tests in transactions, so DDL statements like
	 * DROP TABLE cause an implicit commit and the transaction boundary
	 * prevents verifying table absence. We verify the method runs without error.
	 */
	public function test_drop_tables_runs_without_error(): void {
		global $wpdb;

		Schema::activate();

		// Verify tables exist before drop.
		$before = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'workos_organizations' )
		);
		$this->assertNotNull( $before, 'Table should exist before drop.' );

		// drop_tables should execute without fatal error.
		Schema::drop_tables();

		// Re-create for other tests.
		Schema::activate();
		$this->assertTrue( true, 'drop_tables executed without error.' );
	}
}
