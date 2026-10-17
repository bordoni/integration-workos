<?php
/**
 * Tests for looking up and clearing sign-in lockouts.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

require_once dirname( __DIR__ ) . '/_support/RateLimitWindow.php';

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\ActivityLog\EventLogger;
use WorkOS\Database\Schema;
use WorkOS\Email\AddressCanonicalizer;
use WorkOS\RateLimit\DatabaseRateLimiter;
use WorkOS\RateLimit\Lockouts;
use WorkOS\RateLimit\TieredRateLimiter;
use WP_Error;

/**
 * Lock an address the way the endpoints do, then check and clear it.
 *
 * @covers \WorkOS\RateLimit\Lockouts
 */
class LockoutsTest extends WPTestCase {

	/**
	 * Address as a user would type it: mixed case, a Gmail tag and dots,
	 * so the exact and mailbox subjects differ.
	 */
	private const TYPED = '  Locked.Out+promo@Gmail.com ';

	/**
	 * Exact subject: lowercased and trimmed.
	 */
	private const EXACT = 'locked.out+promo@gmail.com';

	/**
	 * Mailbox subject: the canonical form.
	 */
	private const MAILBOX = 'lockedout@gmail.com';

	/**
	 * Limiter the "endpoints" charge.
	 *
	 * @var TieredRateLimiter
	 */
	private TieredRateLimiter $limiter;

	/**
	 * Service under test.
	 *
	 * @var Lockouts
	 */
	private Lockouts $lockouts;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();

		\workos_tests_wait_for_fresh_window();

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
		remove_all_actions( 'user_register' );

		$this->limiter  = new TieredRateLimiter( new DatabaseRateLimiter() );
		$this->lockouts = new Lockouts( $this->limiter, new AddressCanonicalizer() );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'workos_production' );
		\WorkOS\Config::set_active_environment( 'staging' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		parent::tearDown();
	}

	/**
	 * Exhaust a bucket the way an endpoint would.
	 *
	 * @param string $bucket  Bucket.
	 * @param string $subject Subject the endpoint keys on.
	 * @param array  $policy  Policy the endpoint passes.
	 */
	private function exhaust( string $bucket, string $subject, array $policy ): void {
		$first = $policy[0][0];
		for ( $i = 0; $i < $first; $i++ ) {
			$this->assertTrue( $this->limiter->attempt( $bucket, $subject, $policy ) );
		}
		$this->assertInstanceOf( WP_Error::class, $this->limiter->attempt( $bucket, $subject, $policy ) );
	}

	/**
	 * Index check() rows by bucket.
	 *
	 * @param string $email Address.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function check( string $email ): array {
		$rows = $this->lockouts->check( $email );
		$this->assertIsArray( $rows );

		return array_column( $rows, null, 'bucket' );
	}

	/**
	 * Rows of one activity-log event type.
	 *
	 * @param string $event_type Event type.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function log_rows( string $event_type ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}workos_activity_log WHERE event_type = %s", $event_type ),
			ARRAY_A
		);
	}

	/**
	 * Every email-keyed bucket is listed, each with how it keys the address.
	 */
	public function test_check_lists_every_email_bucket(): void {
		$rows = $this->check( 'clean@example.com' );

		$this->assertSame(
			[
				'password_email'         => 'exact',
				'magic_verify_email'     => 'exact',
				'magic_send_email'       => 'mailbox',
				'pw_reset_email'         => 'mailbox',
				'signup_email'           => 'mailbox',
				'otp_send_recipient'     => 'mailbox',
				'password_otp_recipient' => 'mailbox',
			],
			array_map( static fn( array $row ) => $row['key'], $rows )
		);

		foreach ( $rows as $row ) {
			$this->assertFalse( $row['locked'] );
			$this->assertSame( 0, $row['retry_after'] );
			$this->assertNotEmpty( $row['tiers'] );
			foreach ( $row['tiers'] as $tier ) {
				$this->assertSame( 0, $tier['count'] );
				$this->assertGreaterThan( 0, $tier['limit'] );
			}
		}
	}

	/**
	 * Check keys each bucket the way its endpoint does, so a lock on the
	 * exact address and one on the mailbox both show for the typed form.
	 */
	public function test_check_shows_locks_after_the_limiter_canonicalisation(): void {
		$this->exhaust( 'password_email', self::EXACT, TieredRateLimiter::REQUEST_SUBJECT );
		$this->exhaust( 'otp_send_recipient', self::MAILBOX, TieredRateLimiter::SEND_RECIPIENT );

		$rows = $this->check( self::TYPED );

		$this->assertTrue( $rows['password_email']['locked'] );
		$this->assertGreaterThan( 0, $rows['password_email']['retry_after'] );
		$this->assertSame( 5, $rows['password_email']['tiers'][0]['limit'] );
		$this->assertSame( 6, $rows['password_email']['tiers'][0]['count'] );
		$this->assertSame( TieredRateLimiter::hash_subject( self::EXACT ), $rows['password_email']['subject_hash'] );

		$this->assertTrue( $rows['otp_send_recipient']['locked'] );
		$this->assertSame( TieredRateLimiter::hash_subject( self::MAILBOX ), $rows['otp_send_recipient']['subject_hash'] );

		$this->assertFalse( $rows['magic_send_email']['locked'] );
	}

	/**
	 * Check reflects a filtered policy, since that is the limit requests see.
	 */
	public function test_check_uses_the_filtered_policy(): void {
		add_filter(
			'workos_rate_limit_policy',
			static fn( $policy, $bucket ) => 'password_email' === $bucket ? [ [ 2, MINUTE_IN_SECONDS ] ] : $policy,
			10,
			2
		);

		$this->limiter->attempt( 'password_email', self::EXACT, TieredRateLimiter::REQUEST_SUBJECT );
		$this->limiter->attempt( 'password_email', self::EXACT, TieredRateLimiter::REQUEST_SUBJECT );

		$row = $this->check( self::EXACT )['password_email'];
		$this->assertSame( 2, $row['tiers'][0]['limit'] );
		$this->assertTrue( $row['locked'] );
	}

	/**
	 * Lock, check, clear one bucket: the next request through it passes
	 * and the other lock stays.
	 */
	public function test_clear_one_bucket_lets_the_next_request_through(): void {
		$this->exhaust( 'password_email', self::EXACT, TieredRateLimiter::REQUEST_SUBJECT );
		$this->exhaust( 'otp_send_recipient', self::MAILBOX, TieredRateLimiter::SEND_RECIPIENT );

		$cleared = $this->lockouts->clear( self::TYPED, 'password_email', 'cli' );

		$this->assertSame( [ 'password_email' ], $cleared );
		$this->assertTrue( $this->limiter->attempt( 'password_email', self::EXACT, TieredRateLimiter::REQUEST_SUBJECT ) );

		$rows = $this->check( self::TYPED );
		$this->assertTrue( $rows['otp_send_recipient']['locked'] );
	}

	/**
	 * With no bucket, every email bucket is cleared.
	 */
	public function test_clear_all_buckets(): void {
		$this->exhaust( 'password_email', self::EXACT, TieredRateLimiter::REQUEST_SUBJECT );
		$this->exhaust( 'otp_send_recipient', self::MAILBOX, TieredRateLimiter::SEND_RECIPIENT );

		$cleared = $this->lockouts->clear( self::TYPED, '', 'cli' );

		$this->assertCount( 7, $cleared );
		foreach ( $this->check( self::TYPED ) as $row ) {
			$this->assertFalse( $row['locked'], $row['bucket'] . ' should be clear.' );
		}
		$this->assertTrue( $this->limiter->attempt( 'otp_send_recipient', self::MAILBOX, TieredRateLimiter::SEND_RECIPIENT ) );
	}

	/**
	 * Clearing every tier matters for send buckets: a lock on the hour
	 * tier outlives the 5-minute one.
	 */
	public function test_clear_resets_every_tier(): void {
		$policy = TieredRateLimiter::SEND_RECIPIENT;
		$this->exhaust( 'otp_send_recipient', self::MAILBOX, $policy );

		$this->lockouts->clear( self::MAILBOX, 'otp_send_recipient', 'cli' );

		foreach ( $this->check( self::MAILBOX )['otp_send_recipient']['tiers'] as $tier ) {
			$this->assertSame( 0, $tier['count'] );
		}
	}

	/**
	 * Every clear writes one activity-log entry naming who cleared what,
	 * with hashed subjects only.
	 */
	public function test_clear_logs_who_cleared_what(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$this->lockouts->clear( self::TYPED, 'password_email', 'admin' );

		$rows = $this->log_rows( 'rate_limit_cleared' );
		$this->assertCount( 1, $rows );
		$this->assertSame( (string) $admin, (string) $rows[0]['user_id'] );

		$meta = json_decode( (string) $rows[0]['metadata'], true );
		$this->assertSame( 'admin', $meta['source'] );
		$this->assertSame(
			[ 'password_email' => TieredRateLimiter::hash_subject( self::EXACT ) ],
			$meta['buckets']
		);
		$this->assertStringNotContainsString( 'locked.out', (string) $rows[0]['metadata'] );
		$this->assertStringNotContainsString( 'lockedout', (string) $rows[0]['metadata'] );
	}

	/**
	 * An unknown bucket is refused, and nothing is cleared or logged.
	 */
	public function test_clear_unknown_bucket_is_refused(): void {
		$result = $this->lockouts->clear( self::EXACT, 'password_ip', 'cli' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'workos_lockout_unknown_bucket', $result->get_error_code() );
		$this->assertSame( [], $this->log_rows( 'rate_limit_cleared' ) );
	}

	/**
	 * Something that isn't an address is refused by both.
	 */
	public function test_invalid_email_is_refused(): void {
		$this->assertInstanceOf( WP_Error::class, $this->lockouts->check( 'not-an-email' ) );
		$this->assertInstanceOf( WP_Error::class, $this->lockouts->clear( 'not-an-email', '', 'cli' ) );
	}
}
