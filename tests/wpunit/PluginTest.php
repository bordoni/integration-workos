<?php
/**
 * Tests for the Plugin singleton.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Plugin;

/**
 * Plugin singleton and constants tests.
 */
class PluginTest extends WPTestCase {

	/**
	 * Test that WORKOS_VERSION is defined.
	 */
	public function test_version_constant_is_defined(): void {
		$this->assertTrue( defined( 'WORKOS_VERSION' ) );
		$this->assertNotEmpty( WORKOS_VERSION );
	}

	/**
	 * Test that WORKOS_DIR is defined.
	 */
	public function test_dir_constant_is_defined(): void {
		$this->assertTrue( defined( 'WORKOS_DIR' ) );
		$this->assertNotEmpty( WORKOS_DIR );
	}

	/**
	 * Test that WORKOS_BASENAME is defined.
	 */
	public function test_basename_constant_is_defined(): void {
		$this->assertTrue( defined( 'WORKOS_BASENAME' ) );
		$this->assertNotEmpty( WORKOS_BASENAME );
	}

	/**
	 * Test that the Plugin class exists.
	 */
	public function test_plugin_class_exists(): void {
		$this->assertTrue( class_exists( Plugin::class ) );
	}

	/**
	 * Test that the workos() global function exists.
	 */
	public function test_workos_function_exists(): void {
		$this->assertTrue( function_exists( 'workos' ) );
	}
}
