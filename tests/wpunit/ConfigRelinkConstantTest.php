<?php
/**
 * Tests for Re-link Protection set by a wp-config.php constant.
 *
 * Constants can't be undefined once set, so this class runs only in the isolated
 * `constants` group (see ConfigSyncConstantsTest):
 *
 * Default run: `slic run wpunit --skip-group constants`
 * Isolated:    `slic run wpunit --group constants`
 *
 * The fixture sets the generic constant on and the staging constant off, so one set
 * of defines covers: generic constant, per-environment constant beating the generic
 * one, a constant beating the option in both directions, the filter beating a
 * constant, and the locked settings checkbox.
 *
 * @group constants
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use PHPUnit\Framework\Attributes\Group;
use WorkOS\Admin\Settings;
use WorkOS\App;
use WorkOS\Config;
use WorkOS\Options\Production;
use WorkOS\Options\Staging;

/**
 * The constant path of is_relink_protection_enabled(), bool_constant() and the settings checkbox.
 *
 * @group constants
 */
#[Group( 'constants' )]
class ConfigRelinkConstantTest extends WPTestCase {

	/**
	 * Define the fixture and write both environments with protection set the other way.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! defined( 'WORKOS_RELINK_PROTECTION' ) ) {
			define( 'WORKOS_RELINK_PROTECTION', true );
		}
		if ( ! defined( 'WORKOS_STAGING_RELINK_PROTECTION' ) ) {
			define( 'WORKOS_STAGING_RELINK_PROTECTION', false );
		}

		update_option( 'workos_production', [ 'relink_protection' => false ] );
		update_option( 'workos_staging', [ 'relink_protection' => true ] );
		App::container()->get( Production::class )->reset();
		App::container()->get( Staging::class )->reset();
	}

	/**
	 * Tear down each test.
	 */
	public function tearDown(): void {
		delete_option( 'workos_production' );
		delete_option( 'workos_staging' );
		Config::set_active_environment( 'staging' );
		App::container()->get( Production::class )->reset();
		App::container()->get( Staging::class )->reset();
		remove_all_filters( 'workos_relink_protection_enabled' );
		unset( $_GET['env'] );

		parent::tearDown();
	}

	/**
	 * The settings checkbox for relink_protection, as rendered for an environment.
	 *
	 * @param string $env Environment being edited.
	 *
	 * @return string
	 */
	private function render_checkbox( string $env ): string {
		$_GET['env'] = $env;

		ob_start();
		( new Settings() )->render_constant_checkbox(
			[
				'setting' => 'relink_protection',
				'label'   => 'Re-link Protection',
			]
		);
		return (string) ob_get_clean();
	}

	/**
	 * The generic constant turns protection on over an option that says off.
	 */
	public function test_generic_constant_turns_protection_on(): void {
		Config::set_active_environment( 'production' );

		$this->assertSame( 'WORKOS_RELINK_PROTECTION', Config::bool_constant( 'relink_protection' ) );
		$this->assertTrue( Config::is_relink_protection_enabled() );
	}

	/**
	 * The per-environment constant beats the generic one and turns protection off over an option that says on.
	 */
	public function test_environment_constant_wins_and_turns_protection_off(): void {
		Config::set_active_environment( 'staging' );

		$this->assertSame( 'WORKOS_STAGING_RELINK_PROTECTION', Config::bool_constant( 'relink_protection' ) );
		$this->assertSame( 'WORKOS_RELINK_PROTECTION', Config::bool_constant( 'relink_protection', 'production' ) );
		$this->assertFalse( Config::is_relink_protection_enabled() );
	}

	/**
	 * The filter still has the last word over a constant.
	 */
	public function test_filter_overrides_constant(): void {
		Config::set_active_environment( 'production' );
		add_filter( 'workos_relink_protection_enabled', '__return_false' );

		$this->assertFalse( Config::is_relink_protection_enabled() );
	}

	/**
	 * A constant locks the checkbox: shown disabled with the constant's value, named, and never submitted.
	 */
	public function test_checkbox_is_locked_by_constant(): void {
		$production = $this->render_checkbox( 'production' );
		$this->assertStringContainsString( 'disabled', $production );
		$this->assertStringContainsString( "checked='checked'", $production );
		$this->assertStringContainsString( 'WORKOS_RELINK_PROTECTION', $production );
		$this->assertStringNotContainsString( 'name=', $production );

		$staging = $this->render_checkbox( 'staging' );
		$this->assertStringContainsString( 'disabled', $staging );
		$this->assertStringNotContainsString( 'checked', $staging );
		$this->assertStringContainsString( 'WORKOS_STAGING_RELINK_PROTECTION', $staging );
		$this->assertStringNotContainsString( 'name=', $staging );
	}
}
