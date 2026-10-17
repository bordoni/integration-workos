<?php
/**
 * Tests for `wp workos ratelimit`.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

require_once dirname( __DIR__ ) . '/_support/WpCliStubs.php';

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\ActivityLog\EventLogger;
use WorkOS\CLI\RateLimitCommand;
use WorkOS\Database\Schema;
use WorkOS\Email\AddressCanonicalizer;
use WorkOS\RateLimit\DatabaseRateLimiter;
use WorkOS\RateLimit\Lockouts;
use WorkOS\RateLimit\TieredRateLimiter;
use WP_CLI;
use WP_CLI\ExitException;
use WP_CLI\Formatter;

/**
 * Runs the command class against WP-CLI doubles (tests/_support/WpCliStubs.php).
 *
 * @covers \WorkOS\CLI\RateLimitCommand
 */
class RateLimitCommandTest extends WPTestCase {

	/**
	 * Address used throughout.
	 */
	private const EMAIL = 'cli-case@example.com';

	/**
	 * Limiter the "endpoints" charge.
	 *
	 * @var TieredRateLimiter
	 */
	private TieredRateLimiter $limiter;

	/**
	 * Command under test.
	 *
	 * @var RateLimitCommand
	 */
	private RateLimitCommand $command;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();

		\WorkOS\Config::set_active_environment( 'production' );
		update_option(
			'workos_production',
			[
				'api_key'             => 'sk_test_fake',
				'client_id'           => 'client_fake',
				'environment_id'      => 'environment_test',
				'enable_activity_log' => true,
			]
		);
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		Schema::activate();
		EventLogger::clear();
		WP_CLI::reset();

		$this->limiter = new TieredRateLimiter( new DatabaseRateLimiter() );
		$this->command = new RateLimitCommand( new Lockouts( $this->limiter, new AddressCanonicalizer() ) );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		delete_option( 'workos_production' );
		\WorkOS\Config::set_active_environment( 'staging' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		parent::tearDown();
	}

	/**
	 * Lock the exact-address password bucket.
	 */
	private function lock_password(): void {
		for ( $i = 0; $i < 6; $i++ ) {
			$this->limiter->attempt( 'password_email', self::EMAIL, TieredRateLimiter::REQUEST_SUBJECT );
		}
	}

	/**
	 * Rows the last display_items() call showed, by bucket and window.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function displayed(): array {
		$this->assertNotEmpty( Formatter::$displayed );
		$last = end( Formatter::$displayed );

		$rows = [];
		foreach ( $last['items'] as $item ) {
			$rows[ $item['bucket'] . '@' . $item['window'] ] = $item;
		}

		return $rows;
	}

	/**
	 * `check` shows the lock with count, limit and time left, then a
	 * warning that something is locked.
	 */
	public function test_check_shows_a_lock(): void {
		$this->lock_password();

		$this->command->check( [ self::EMAIL ], [] );

		$rows = $this->displayed();
		$row  = $rows['password_email@1m'];
		$this->assertSame( 6, $row['count'] );
		$this->assertSame( 5, $row['limit'] );
		$this->assertSame( 'yes', $row['locked'] );
		$this->assertMatchesRegularExpression( '/^\d+s$|^1m$/', $row['time_left'] );

		$this->assertSame( 'no', $rows['magic_send_email@1m']['locked'] );
		$this->assertSame( '', $rows['magic_send_email@1m']['time_left'] );

		// One row per tier: the send buckets have three.
		$this->assertArrayHasKey( 'otp_send_recipient@8h', $rows );

		$this->assertSame( 'warning', end( WP_CLI::$messages )[0] );
	}

	/**
	 * Machine formats get seconds, not durations, and no trailing line.
	 */
	public function test_check_json_uses_seconds(): void {
		$this->lock_password();

		$this->command->check( [ self::EMAIL ], [ 'format' => 'json' ] );

		$row = $this->displayed()['password_email@60'];
		$this->assertIsInt( $row['time_left'] );
		$this->assertGreaterThan( 0, $row['time_left'] );
		$this->assertSame( [], WP_CLI::$messages );
	}

	/**
	 * A clean address says so.
	 */
	public function test_check_clean_address_succeeds(): void {
		$this->command->check( [ 'nobody@example.com' ], [] );

		$this->assertSame( 'success', end( WP_CLI::$messages )[0] );
	}

	/**
	 * Lock, check, clear: the next request goes through.
	 */
	public function test_clear_bucket_lets_the_next_request_through(): void {
		$this->lock_password();

		$this->command->clear( [ self::EMAIL ], [ 'bucket' => 'password_email' ] );

		$this->assertSame( 'success', end( WP_CLI::$messages )[0] );
		$this->assertStringNotContainsString( self::EMAIL, end( WP_CLI::$messages )[1] );
		$this->assertTrue( $this->limiter->attempt( 'password_email', self::EMAIL, TieredRateLimiter::REQUEST_SUBJECT ) );
	}

	/**
	 * Without --bucket every email bucket is cleared, and the clear is
	 * logged as coming from the CLI.
	 */
	public function test_clear_all_logs_cli_source(): void {
		$this->lock_password();

		$this->command->clear( [ self::EMAIL ], [] );

		$this->assertTrue( $this->limiter->attempt( 'password_email', self::EMAIL, TieredRateLimiter::REQUEST_SUBJECT ) );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$meta = $wpdb->get_var( "SELECT metadata FROM {$wpdb->prefix}workos_activity_log WHERE event_type = 'rate_limit_cleared'" );
		$meta = json_decode( (string) $meta, true );
		$this->assertSame( 'cli', $meta['source'] );
		$this->assertCount( 7, $meta['buckets'] );
	}

	/**
	 * Bad input stops with an error.
	 *
	 * @param string $email  Address.
	 * @param string $bucket Bucket, or '' for all.
	 */
	#[\Codeception\Attribute\Examples( 'not-an-email', '' )]
	#[\Codeception\Attribute\Examples( self::EMAIL, 'password_ip' )]
	public function test_clear_bad_input_errors( string $email, string $bucket ): void {
		$this->expectException( ExitException::class );

		$this->command->clear( [ $email ], '' === $bucket ? [] : [ 'bucket' => $bucket ] );
	}

	/**
	 * `check` on a bad address errors too.
	 */
	public function test_check_bad_input_errors(): void {
		$this->expectException( ExitException::class );

		$this->command->check( [ 'not-an-email' ], [] );
	}
}
