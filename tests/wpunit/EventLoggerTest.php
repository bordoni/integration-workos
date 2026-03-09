<?php
/**
 * Tests for EventLogger.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\ActivityLog\EventLogger;
use WorkOS\Database\Schema;

/**
 * Activity event logger tests.
 */
class EventLoggerTest extends WPTestCase {

	/**
	 * Set up each test.
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( 'workos_active_environment', 'production' );
		update_option( 'workos_production', [
			'api_key'             => 'sk_test_fake',
			'client_id'           => 'client_fake',
			'environment_id'      => 'environment_test',
			'enable_activity_log' => false,
		] );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		// Ensure tables exist.
		Schema::activate();

		// Clear any existing events.
		EventLogger::clear();

		remove_all_actions( 'user_register' );
	}

	/**
	 * Tear down each test.
	 */
	public function tearDown(): void {
		delete_option( 'workos_production' );
		delete_option( 'workos_active_environment' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		parent::tearDown();
	}

	/**
	 * Enable activity logging.
	 */
	private function enable_logging(): void {
		$opts                       = get_option( 'workos_production' );
		$opts['enable_activity_log'] = true;
		update_option( 'workos_production', $opts );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();
	}

	/**
	 * Test is_enabled returns false by default.
	 */
	public function test_is_enabled_returns_false_by_default(): void {
		$this->assertFalse( EventLogger::is_enabled() );
	}

	/**
	 * Test is_enabled respects option.
	 */
	public function test_is_enabled_respects_option(): void {
		$this->enable_logging();
		$this->assertTrue( EventLogger::is_enabled() );
	}

	/**
	 * Test log inserts row when enabled.
	 */
	public function test_log_inserts_row_when_enabled(): void {
		$this->enable_logging();

		$user_id = self::factory()->user->create( [ 'user_email' => 'logger@example.com' ] );

		EventLogger::log( 'login', [ 'user_id' => $user_id ] );

		$events = EventLogger::get_events();
		$this->assertSame( 1, $events['total'] );
		$this->assertSame( 'login', $events['items'][0]['event_type'] );
	}

	/**
	 * Test log skips when disabled.
	 */
	public function test_log_skips_when_disabled(): void {
		EventLogger::log( 'login', [ 'user_id' => 1 ] );

		$events = EventLogger::get_events();
		$this->assertSame( 0, $events['total'] );
	}

	/**
	 * Test log populates user_email from user_id.
	 */
	public function test_log_populates_user_email_from_user_id(): void {
		$this->enable_logging();

		$user_id = self::factory()->user->create( [ 'user_email' => 'auto@example.com' ] );

		EventLogger::log( 'login', [ 'user_id' => $user_id ] );

		$events = EventLogger::get_events();
		$this->assertSame( 'auto@example.com', $events['items'][0]['user_email'] );
	}

	/**
	 * Test log stores metadata as JSON.
	 */
	public function test_log_stores_metadata_as_json(): void {
		$this->enable_logging();

		EventLogger::log( 'login', [
			'user_id'  => 0,
			'metadata' => [ 'reason' => 'test_login', 'ip' => '1.2.3.4' ],
		] );

		$events   = EventLogger::get_events();
		$metadata = json_decode( $events['items'][0]['metadata'], true );
		$this->assertSame( 'test_login', $metadata['reason'] );
		$this->assertSame( '1.2.3.4', $metadata['ip'] );
	}

	/**
	 * Test get_events paginated results.
	 */
	public function test_get_events_paginated(): void {
		$this->enable_logging();

		for ( $i = 0; $i < 5; $i++ ) {
			EventLogger::log( 'login', [ 'user_id' => 0 ] );
		}

		$page1 = EventLogger::get_events( [ 'per_page' => 2, 'page' => 1 ] );
		$this->assertCount( 2, $page1['items'] );
		$this->assertSame( 5, $page1['total'] );

		$page3 = EventLogger::get_events( [ 'per_page' => 2, 'page' => 3 ] );
		$this->assertCount( 1, $page3['items'] );
	}

	/**
	 * Test get_events filters by event_type.
	 */
	public function test_get_events_filters_by_event_type(): void {
		$this->enable_logging();

		EventLogger::log( 'login', [ 'user_id' => 0 ] );
		EventLogger::log( 'login_failed', [ 'user_id' => 0 ] );
		EventLogger::log( 'login', [ 'user_id' => 0 ] );

		$logins = EventLogger::get_events( [ 'event_type' => 'login' ] );
		$this->assertSame( 2, $logins['total'] );

		$failed = EventLogger::get_events( [ 'event_type' => 'login_failed' ] );
		$this->assertSame( 1, $failed['total'] );
	}

	/**
	 * Test get_stats counts correctly.
	 */
	public function test_get_stats_counts_correctly(): void {
		$this->enable_logging();

		$user1 = self::factory()->user->create();
		$user2 = self::factory()->user->create();

		EventLogger::log( 'login', [ 'user_id' => $user1 ] );
		EventLogger::log( 'login', [ 'user_id' => $user2 ] );
		EventLogger::log( 'login', [ 'user_id' => $user1 ] );
		EventLogger::log( 'login_failed', [ 'user_id' => 0 ] );

		$stats = EventLogger::get_stats( 30 );

		$this->assertSame( 3, $stats['total_logins'] );
		$this->assertSame( 1, $stats['failed_logins'] );
		$this->assertSame( 2, $stats['unique_users'] );
	}

	/**
	 * Test clear removes all events.
	 */
	public function test_clear_removes_all_events(): void {
		$this->enable_logging();

		EventLogger::log( 'login', [ 'user_id' => 0 ] );
		EventLogger::log( 'login', [ 'user_id' => 0 ] );

		EventLogger::clear();

		$events = EventLogger::get_events();
		$this->assertSame( 0, $events['total'] );
	}
}
