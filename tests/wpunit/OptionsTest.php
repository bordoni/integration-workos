<?php
/**
 * Tests for the Options classes.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Options\Production;
use WorkOS\Options\Staging;
use WorkOS\Options\Global_Options;

/**
 * Options classes tests.
 */
class OptionsTest extends WPTestCase {

	/**
	 * Production options instance.
	 *
	 * @var Production
	 */
	private Production $production;

	/**
	 * Staging options instance.
	 *
	 * @var Staging
	 */
	private Staging $staging;

	/**
	 * Global options instance.
	 *
	 * @var Global_Options
	 */
	private Global_Options $global;

	/**
	 * Set up each test with fresh instances.
	 */
	public function setUp(): void {
		parent::setUp();

		delete_option( 'workos_production' );
		delete_option( 'workos_staging' );
		delete_option( 'workos_global' );
		\WorkOS\Config::set_active_environment( 'staging' );

		$this->production = new Production();
		$this->staging    = new Staging();
		$this->global     = new Global_Options();
	}

	/**
	 * Tear down each test.
	 */
	public function tearDown(): void {
		delete_option( 'workos_production' );
		delete_option( 'workos_staging' );
		delete_option( 'workos_global' );
		\WorkOS\Config::set_active_environment( 'staging' );

		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// get() tests
	// -------------------------------------------------------------------------

	/**
	 * Test get returns default from defaults() when key is not stored.
	 */
	public function test_get_returns_class_default_when_not_stored(): void {
		$this->assertSame( '', $this->production->get( 'api_key' ) );
		$this->assertSame( 'custom', $this->production->get( 'login_mode' ) );
		$this->assertTrue( $this->production->get( 'allow_password_fallback' ) );
		$this->assertSame( 'deactivate', $this->production->get( 'deprovision_action' ) );
		$this->assertSame( 0, $this->production->get( 'reassign_user' ) );
		$this->assertSame( [], $this->production->get( 'role_map' ) );
		$this->assertFalse( $this->production->get( 'audit_logging_enabled' ) );
	}

	/**
	 * Test get returns the caller-provided default when key is not stored.
	 */
	public function test_get_returns_caller_default_over_class_default(): void {
		$this->assertSame( 'custom', $this->production->get( 'api_key', 'custom' ) );
		$this->assertSame( 'headless', $this->production->get( 'login_mode', 'headless' ) );
	}

	/**
	 * Test get returns stored value.
	 */
	public function test_get_returns_stored_value(): void {
		update_option( 'workos_production', [ 'api_key' => 'sk_live_123' ] );
		$opts = new Production();

		$this->assertSame( 'sk_live_123', $opts->get( 'api_key' ) );
	}

	/**
	 * Test get returns stored value even when caller default is provided.
	 */
	public function test_get_stored_value_takes_precedence_over_caller_default(): void {
		update_option( 'workos_production', [ 'login_mode' => 'headless' ] );
		$opts = new Production();

		$this->assertSame( 'headless', $opts->get( 'login_mode', 'redirect' ) );
	}

	/**
	 * Test get for an unknown key returns null by default.
	 */
	public function test_get_unknown_key_returns_null(): void {
		$this->assertNull( $this->production->get( 'nonexistent' ) );
	}

	/**
	 * Test get for an unknown key with caller default returns that default.
	 */
	public function test_get_unknown_key_returns_caller_default(): void {
		$this->assertSame( 'fallback', $this->production->get( 'nonexistent', 'fallback' ) );
	}

	// -------------------------------------------------------------------------
	// set() tests
	// -------------------------------------------------------------------------

	/**
	 * Test set persists value to the database.
	 */
	public function test_set_persists_to_database(): void {
		$this->production->set( 'api_key', 'sk_test_abc' );

		// Verify in-memory.
		$this->assertSame( 'sk_test_abc', $this->production->get( 'api_key' ) );

		// Verify in database.
		$stored = get_option( 'workos_production' );
		$this->assertSame( 'sk_test_abc', $stored['api_key'] );
	}

	/**
	 * Test set preserves other keys.
	 */
	public function test_set_preserves_existing_keys(): void {
		update_option( 'workos_production', [
			'api_key'   => 'sk_existing',
			'client_id' => 'client_existing',
		] );
		$opts = new Production();

		$opts->set( 'api_key', 'sk_updated' );

		$this->assertSame( 'sk_updated', $opts->get( 'api_key' ) );
		$this->assertSame( 'client_existing', $opts->get( 'client_id' ) );
	}

	/**
	 * Test set can store non-string values.
	 */
	public function test_set_stores_non_string_values(): void {
		$this->production->set( 'reassign_user', 42 );
		$this->assertSame( 42, $this->production->get( 'reassign_user' ) );

		$this->production->set( 'audit_logging_enabled', true );
		$this->assertTrue( $this->production->get( 'audit_logging_enabled' ) );

		$role_map = [ 'admin' => 'administrator', 'member' => 'subscriber' ];
		$this->production->set( 'role_map', $role_map );
		$this->assertSame( $role_map, $this->production->get( 'role_map' ) );
	}

	// -------------------------------------------------------------------------
	// delete() tests
	// -------------------------------------------------------------------------

	/**
	 * Test delete removes the key.
	 */
	public function test_delete_removes_key(): void {
		$this->production->set( 'api_key', 'sk_to_delete' );
		$this->production->set( 'client_id', 'client_keep' );

		$this->production->delete( 'api_key' );

		$this->assertSame( '', $this->production->get( 'api_key' ) );
		$this->assertSame( 'client_keep', $this->production->get( 'client_id' ) );
	}

	/**
	 * Test delete persists the removal to the database.
	 */
	public function test_delete_persists_to_database(): void {
		$this->production->set( 'api_key', 'sk_remove' );
		$this->production->delete( 'api_key' );

		$stored = get_option( 'workos_production' );
		$this->assertArrayNotHasKey( 'api_key', $stored );
	}

	/**
	 * Test delete on a nonexistent key does not error.
	 */
	public function test_delete_nonexistent_key_is_safe(): void {
		$this->production->set( 'api_key', 'sk_keep' );
		$this->production->delete( 'nonexistent' );

		$this->assertSame( 'sk_keep', $this->production->get( 'api_key' ) );
	}

	// -------------------------------------------------------------------------
	// all() tests
	// -------------------------------------------------------------------------

	/**
	 * Test all returns empty array when nothing is stored.
	 */
	public function test_all_returns_empty_array_when_no_option(): void {
		$this->assertSame( [], $this->production->all() );
	}

	/**
	 * Test all returns the full stored array.
	 */
	public function test_all_returns_stored_array(): void {
		$data = [
			'api_key'   => 'sk_test',
			'client_id' => 'client_test',
		];
		update_option( 'workos_production', $data );
		$opts = new Production();

		$this->assertSame( $data, $opts->all() );
	}

	/**
	 * Test all caches the value (only one get_option call).
	 */
	public function test_all_uses_in_memory_cache(): void {
		$this->production->set( 'api_key', 'cached' );

		// Directly overwrite the DB behind the cache's back.
		update_option( 'workos_production', [ 'api_key' => 'db_changed' ] );

		// Should still return the cached value.
		$this->assertSame( 'cached', $this->production->get( 'api_key' ) );
	}

	/**
	 * Test all handles corrupt (non-array) option gracefully.
	 */
	public function test_all_handles_non_array_option(): void {
		update_option( 'workos_production', 'not_an_array' );
		$opts = new Production();

		$this->assertSame( [], $opts->all() );
	}

	// -------------------------------------------------------------------------
	// reset() tests
	// -------------------------------------------------------------------------

	/**
	 * Test reset clears cache and re-reads from database.
	 */
	public function test_reset_clears_cache(): void {
		$this->production->set( 'api_key', 'original' );

		// Overwrite the DB directly.
		update_option( 'workos_production', [ 'api_key' => 'updated_in_db' ] );

		// Before reset: still cached.
		$this->assertSame( 'original', $this->production->get( 'api_key' ) );

		// After reset: reads from DB.
		$this->production->reset();
		$this->assertSame( 'updated_in_db', $this->production->get( 'api_key' ) );
	}

	// -------------------------------------------------------------------------
	// Concrete class tests (option_name + defaults)
	// -------------------------------------------------------------------------

	/**
	 * Test Production uses the correct option name.
	 */
	public function test_production_option_name(): void {
		$this->production->set( 'api_key', 'sk_prod' );
		$this->assertSame( 'sk_prod', get_option( 'workos_production' )['api_key'] );
	}

	/**
	 * Test Staging uses the correct option name.
	 */
	public function test_staging_option_name(): void {
		$this->staging->set( 'api_key', 'sk_stag' );
		$this->assertSame( 'sk_stag', get_option( 'workos_staging' )['api_key'] );
	}

	/**
	 * Test Global_Options uses the correct option name.
	 */
	public function test_global_option_name(): void {
		$this->global->set( 'custom_key', 'value' );
		$this->assertSame( 'value', get_option( 'workos_global' )['custom_key'] );
	}

	/**
	 * Test Production and Staging are isolated from each other.
	 */
	public function test_production_and_staging_are_isolated(): void {
		$this->production->set( 'api_key', 'sk_prod_key' );
		$this->staging->set( 'api_key', 'sk_stag_key' );

		$this->assertSame( 'sk_prod_key', $this->production->get( 'api_key' ) );
		$this->assertSame( 'sk_stag_key', $this->staging->get( 'api_key' ) );
	}

	/**
	 * Test Production defaults match expected keys (credentials + settings).
	 */
	public function test_production_defaults(): void {
		$credential_keys = [ 'api_key', 'client_id', 'webhook_secret', 'organization_id', 'environment_id' ];
		foreach ( $credential_keys as $key ) {
			$this->assertSame( '', $this->production->get( $key ), "Production default for '{$key}' should be empty string." );
		}

		// Settings keys now live in per-env options.
		$this->assertSame( 'custom', $this->production->get( 'login_mode' ) );
		$this->assertTrue( $this->production->get( 'allow_password_fallback' ) );
		$this->assertSame( 'deactivate', $this->production->get( 'deprovision_action' ) );
		$this->assertSame( 0, $this->production->get( 'reassign_user' ) );
		$this->assertSame( [], $this->production->get( 'role_map' ) );
		$this->assertFalse( $this->production->get( 'audit_logging_enabled' ) );
	}

	/**
	 * Test Staging defaults match expected keys (credentials + settings).
	 */
	public function test_staging_defaults(): void {
		$credential_keys = [ 'api_key', 'client_id', 'webhook_secret', 'organization_id', 'environment_id' ];
		foreach ( $credential_keys as $key ) {
			$this->assertSame( '', $this->staging->get( $key ), "Staging default for '{$key}' should be empty string." );
		}

		// Settings keys now live in per-env options.
		$this->assertSame( 'custom', $this->staging->get( 'login_mode' ) );
		$this->assertTrue( $this->staging->get( 'allow_password_fallback' ) );
		$this->assertSame( 'deactivate', $this->staging->get( 'deprovision_action' ) );
		$this->assertSame( 0, $this->staging->get( 'reassign_user' ) );
		$this->assertSame( [], $this->staging->get( 'role_map' ) );
		$this->assertFalse( $this->staging->get( 'audit_logging_enabled' ) );
	}

	/**
	 * Test Global_Options defaults are empty (settings migrated to per-env).
	 */
	public function test_global_defaults_empty(): void {
		$this->assertNull( $this->global->get( 'login_mode' ) );
		$this->assertNull( $this->global->get( 'allow_password_fallback' ) );
		$this->assertNull( $this->global->get( 'deprovision_action' ) );
		$this->assertNull( $this->global->get( 'reassign_user' ) );
		$this->assertNull( $this->global->get( 'role_map' ) );
		$this->assertNull( $this->global->get( 'audit_logging_enabled' ) );
	}

	// -------------------------------------------------------------------------
	// Container singleton integration
	// -------------------------------------------------------------------------

	/**
	 * Test container returns singleton instances.
	 */
	public function test_container_returns_singletons(): void {
		$container = \WorkOS\App::container();

		$prod_a = $container->get( Production::class );
		$prod_b = $container->get( Production::class );
		$this->assertSame( $prod_a, $prod_b );

		$stag_a = $container->get( Staging::class );
		$stag_b = $container->get( Staging::class );
		$this->assertSame( $stag_a, $stag_b );

		$global_a = $container->get( Global_Options::class );
		$global_b = $container->get( Global_Options::class );
		$this->assertSame( $global_a, $global_b );
	}

	/**
	 * Test Plugin::option() reads from active environment options.
	 */
	public function test_plugin_option_reads_active_env_options(): void {
		// Default active env is staging (new installs).
		update_option( 'workos_staging', [ 'login_mode' => 'headless' ] );
		\WorkOS\App::container()->get( Staging::class )->reset();
		\WorkOS\Config::set_active_environment( 'staging' );

		$this->assertSame( 'headless', workos()->option( 'login_mode' ) );
	}

	/**
	 * Test Plugin::option() reads from production when active.
	 */
	public function test_plugin_option_reads_production_when_active(): void {
		\WorkOS\Config::set_active_environment( 'production' );
		update_option( 'workos_production', [ 'login_mode' => 'headless' ] );
		\WorkOS\App::container()->get( Production::class )->reset();

		$this->assertSame( 'headless', workos()->option( 'login_mode' ) );
	}

	/**
	 * Test Plugin::option() returns caller default for missing keys.
	 */
	public function test_plugin_option_returns_caller_default(): void {
		$this->assertSame( 'my_fallback', workos()->option( 'nonexistent', 'my_fallback' ) );
	}

}
