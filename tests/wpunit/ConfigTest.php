<?php
/**
 * Tests for the Config class.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\App;
use WorkOS\Config;
use WorkOS\Database\Schema;
use WorkOS\Options\Global_Options;

/**
 * Config class tests.
 */
class ConfigTest extends WPTestCase {

	/**
	 * Reset environment-related options between tests so each scenario starts clean.
	 */
	public function setUp(): void {
		parent::setUp();

		delete_option( 'workos_active_environment' );
		delete_option( 'workos_global' );
		delete_option( 'workos_db_version' );

		// Global_Options is a container singleton and caches its row in-memory,
		// so a freshly-deleted DB option still looks populated until we reset.
		App::container()->get( Global_Options::class )->reset();
	}

	/**
	 * Tear down each test.
	 */
	public function tearDown(): void {
		delete_option( 'workos_active_environment' );
		delete_option( 'workos_global' );
		delete_option( 'workos_db_version' );

		App::container()->get( Global_Options::class )->reset();

		parent::tearDown();
	}

	/**
	 * Test that the Config class exists.
	 */
	public function test_config_class_exists(): void {
		$this->assertTrue( class_exists( Config::class ) );
	}

	/**
	 * Config::set_active_environment() must write to the standalone option that
	 * the admin Settings UI also writes to, otherwise the read and write paths
	 * disagree and runtime falls back to the staging default.
	 */
	public function test_set_active_environment_writes_standalone_option(): void {
		Config::set_active_environment( 'production' );

		$this->assertSame( 'production', get_option( 'workos_active_environment' ) );
	}

	/**
	 * Config::get_active_environment() must read the standalone option directly
	 * so that a save through the WP Settings API (admin UI) is honored at runtime.
	 */
	public function test_get_active_environment_reads_standalone_option(): void {
		update_option( 'workos_active_environment', 'production' );

		$this->assertSame( 'production', Config::get_active_environment() );
	}

	/**
	 * Back-compat: installs that never ran the migration still have the value
	 * in `workos_global['active_environment']` only — Config must honor it.
	 */
	public function test_get_active_environment_falls_back_to_legacy_global(): void {
		update_option(
			'workos_global',
			[
				'active_environment'   => 'production',
				'diagnostics_results'  => [],
				'diagnostics_last_run' => 0,
			]
		);

		$this->assertSame( 'production', Config::get_active_environment() );
	}

	/**
	 * Schema::maybe_upgrade() (db_version 2 → 3) copies the legacy value into
	 * the standalone option and strips it from the global row.
	 */
	public function test_migrate_active_environment_moves_legacy_value_to_standalone_option(): void {
		update_option(
			'workos_global',
			[
				'active_environment'   => 'production',
				'diagnostics_results'  => [],
				'diagnostics_last_run' => 0,
			]
		);
		update_option( 'workos_db_version', 2 );

		Schema::maybe_upgrade();

		$this->assertSame( 'production', get_option( 'workos_active_environment' ) );

		$global = get_option( 'workos_global' );
		$this->assertIsArray( $global );
		$this->assertArrayNotHasKey( 'active_environment', $global );

		$this->assertSame( 3, (int) get_option( 'workos_db_version' ) );
	}

	/**
	 * Test mask_secret with a long value.
	 */
	public function test_mask_secret_long_value(): void {
		$result = Config::mask_secret( 'sk_test_1234567890abcdef' );
		$this->assertStringEndsWith( 'cdef', $result );
		$this->assertStringContainsString( '***', $result );
	}

	/**
	 * Test mask_secret with a short value.
	 */
	public function test_mask_secret_short_value(): void {
		$result = Config::mask_secret( 'ab' );
		$this->assertSame( '**', $result );
	}

	/**
	 * Test mask_secret with empty value.
	 */
	public function test_mask_secret_empty_value(): void {
		$result = Config::mask_secret( '' );
		$this->assertSame( '', $result );
	}

	/**
	 * Test generate_secret returns correct length.
	 */
	public function test_generate_secret_length(): void {
		$secret = Config::generate_secret( 16 );
		// 16 bytes = 32 hex characters.
		$this->assertSame( 32, strlen( $secret ) );
	}

	/**
	 * Test generate_secret returns unique values.
	 */
	public function test_generate_secret_unique(): void {
		$a = Config::generate_secret();
		$b = Config::generate_secret();
		$this->assertNotSame( $a, $b );
	}

	/**
	 * Test is_overridden returns false for unknown settings.
	 */
	public function test_is_overridden_unknown_setting(): void {
		$this->assertFalse( Config::is_overridden( 'nonexistent' ) );
	}
}
