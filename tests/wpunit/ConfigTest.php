<?php
/**
 * Tests for the Config class.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Config;

/**
 * Config class tests.
 */
class ConfigTest extends WPTestCase {

	/**
	 * Test that the Config class exists.
	 */
	public function test_config_class_exists(): void {
		$this->assertTrue( class_exists( Config::class ) );
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
