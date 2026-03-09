<?php
/**
 * Tests for LoginBypass.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Auth\LoginBypass;

/**
 * Login bypass tests.
 */
class LoginBypassTest extends WPTestCase {

	/**
	 * Set up each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Reset static $active via reflection.
		$ref = new \ReflectionProperty( LoginBypass::class, 'active' );
		$ref->setAccessible( true );
		$ref->setValue( null, false );
	}

	/**
	 * Tear down each test.
	 */
	public function tearDown(): void {
		$ref = new \ReflectionProperty( LoginBypass::class, 'active' );
		$ref->setAccessible( true );
		$ref->setValue( null, false );

		remove_all_filters( 'workos_bypass_enabled' );
		remove_all_filters( 'workos_bypass_check' );

		// Clean up $_GET.
		unset( $_GET['workos'] );

		parent::tearDown();
	}

	/**
	 * Test is_active returns false by default.
	 */
	public function test_is_active_returns_false_by_default(): void {
		$this->assertFalse( LoginBypass::is_active() );
	}

	/**
	 * Test activate when query param and filter enabled.
	 */
	public function test_activate_when_query_param_and_filter_enabled(): void {
		$_GET['workos'] = '0';
		add_filter( 'workos_bypass_enabled', '__return_true' );

		// Need the activity log table for EventLogger.
		\WorkOS\Database\Schema::activate();

		$bypass = new LoginBypass();
		$bypass->maybe_activate_bypass();

		$this->assertTrue( LoginBypass::is_active() );
	}

	/**
	 * Test does not activate without query param.
	 */
	public function test_does_not_activate_without_query_param(): void {
		add_filter( 'workos_bypass_enabled', '__return_true' );

		$bypass = new LoginBypass();
		$bypass->maybe_activate_bypass();

		$this->assertFalse( LoginBypass::is_active() );
	}

	/**
	 * Test does not activate when bypass disabled.
	 */
	public function test_does_not_activate_when_bypass_disabled(): void {
		$_GET['workos'] = '0';
		// Default is disabled (no filter).

		$bypass = new LoginBypass();
		$bypass->maybe_activate_bypass();

		$this->assertFalse( LoginBypass::is_active() );
	}

	/**
	 * Test bypass_check filter can deny.
	 */
	public function test_bypass_check_filter_can_deny(): void {
		$_GET['workos'] = '0';
		add_filter( 'workos_bypass_enabled', '__return_true' );
		add_filter( 'workos_bypass_check', '__return_false' );

		$bypass = new LoginBypass();
		$bypass->maybe_activate_bypass();

		$this->assertFalse( LoginBypass::is_active() );
	}

	/**
	 * Test fires workos_bypass_activated action.
	 */
	public function test_fires_workos_bypass_activated_action(): void {
		$_GET['workos'] = '0';
		add_filter( 'workos_bypass_enabled', '__return_true' );

		// Need the activity log table for EventLogger.
		\WorkOS\Database\Schema::activate();

		$fired = false;
		add_action( 'workos_bypass_activated', function () use ( &$fired ) {
			$fired = true;
		} );

		$bypass = new LoginBypass();
		$bypass->maybe_activate_bypass();

		$this->assertTrue( $fired );
	}
}
