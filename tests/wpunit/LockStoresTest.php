<?php
/**
 * Tests for lock persistence backends.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Lock\GarbageCollector;
use WorkOS\Lock\State;
use WorkOS\Lock\Store\OptionStore;
use WorkOS\Lock\Store\ResolvingStore;
use WorkOS\Lock\Store\UserMetaStore;
use WorkOS\Lock\Subject;

/**
 * User-meta, option and resolving backends.
 *
 * @covers \WorkOS\Lock\Store\UserMetaStore
 * @covers \WorkOS\Lock\Store\OptionStore
 * @covers \WorkOS\Lock\Store\ResolvingStore
 * @covers \WorkOS\Lock\GarbageCollector
 */
class LockStoresTest extends WPTestCase {

	/**
	 * User-meta backend.
	 *
	 * @var UserMetaStore
	 */
	private UserMetaStore $user_meta;

	/**
	 * Option backend.
	 *
	 * @var OptionStore
	 */
	private OptionStore $options;

	/**
	 * Resolving backend.
	 *
	 * @var ResolvingStore
	 */
	private ResolvingStore $resolving;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->user_meta = new UserMetaStore();
		$this->options   = new OptionStore();
		$this->resolving = new ResolvingStore( $this->user_meta, $this->options );

		// Both ends, not just teardown: the row-count assertions below are
		// absolute, so a row left behind by another test class would skew them
		// depending on suite order.
		$this->clear_lock_rows();
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		$this->clear_lock_rows();

		parent::tearDown();
	}

	/**
	 * Drop every option row the lock stores may have written.
	 *
	 * @return void
	 */
	private function clear_lock_rows(): void {
		global $wpdb;

		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'workos\_lock\_%'" );
	}

	/**
	 * Build a populated state.
	 *
	 * @param int $strikes  Attempts.
	 * @param int $breaches Lockouts.
	 *
	 * @return State
	 */
	private function state( int $strikes = 2, int $breaches = 1 ): State {
		return new State(
			[
				'strikes'      => $strikes,
				'breaches'     => $breaches,
				'first_seen'   => 1000,
				'locked_until' => 0,
				'updated_at'   => 1000,
			]
		);
	}

	// ------------------------------------------------------------- user meta

	/**
	 * State round-trips through user meta.
	 */
	public function test_user_meta_round_trip(): void {
		$user_id = self::factory()->user->create();
		$subject = new Subject( Subject::TYPE_USER, (string) $user_id, $user_id );

		$this->user_meta->write( 'demo', $subject, $this->state( 4, 2 ), 100 );

		$read = $this->user_meta->read( 'demo', $subject );

		$this->assertInstanceOf( State::class, $read );
		$this->assertSame( 4, $read->strikes() );
		$this->assertSame( 2, $read->breaches() );
	}

	/**
	 * The meta key is namespaced per lock slug.
	 */
	public function test_user_meta_key_is_per_slug(): void {
		$this->assertSame( '_workos_lock_otp_send', $this->user_meta->meta_key( 'otp_send' ) );
		$this->assertSame( '_workos_lock_otpsend', $this->user_meta->meta_key( 'otp-send!' ) );
	}

	/**
	 * Locks stored against one user do not bleed across slugs.
	 */
	public function test_user_meta_slugs_are_isolated(): void {
		$user_id = self::factory()->user->create();
		$subject = new Subject( Subject::TYPE_USER, (string) $user_id, $user_id );

		$this->user_meta->write( 'one', $subject, $this->state( 3 ), 100 );
		$this->user_meta->write( 'two', $subject, $this->state( 7 ), 100 );

		$this->assertSame( 3, $this->user_meta->read( 'one', $subject )->strikes() );
		$this->assertSame( 7, $this->user_meta->read( 'two', $subject )->strikes() );

		$this->user_meta->delete( 'one', $subject );

		$this->assertNull( $this->user_meta->read( 'one', $subject ) );
		$this->assertSame( 7, $this->user_meta->read( 'two', $subject )->strikes() );
	}

	/**
	 * Every slug held against a user can be enumerated and cleared at once.
	 */
	public function test_user_meta_bulk_clear(): void {
		$user_id = self::factory()->user->create();
		$subject = new Subject( Subject::TYPE_USER, (string) $user_id, $user_id );

		$this->user_meta->write( 'one', $subject, $this->state(), 100 );
		$this->user_meta->write( 'two', $subject, $this->state(), 100 );

		$slugs = $this->user_meta->slugs_for_user( $user_id );
		sort( $slugs );
		$this->assertSame( [ 'one', 'two' ], $slugs );

		$this->assertSame( 2, $this->user_meta->delete_all_for_user( $user_id ) );
		$this->assertSame( [], $this->user_meta->slugs_for_user( $user_id ) );
	}

	/**
	 * The user-meta backend declines subjects with no local user.
	 */
	public function test_user_meta_declines_anonymous_subjects(): void {
		$subject = new Subject( Subject::TYPE_EMAIL, 'nobody@example.com', 0 );

		$this->assertFalse( $this->user_meta->supports( $subject ) );
		$this->assertNull( $this->user_meta->read( 'demo', $subject ) );
	}

	// ---------------------------------------------------------------- options

	/**
	 * State round-trips through an option row.
	 */
	public function test_option_round_trip(): void {
		$subject = new Subject( Subject::TYPE_EMAIL, 'nobody@example.com', 0 );

		$this->options->write( 'demo', $subject, $this->state( 5, 3 ), 100 );

		$read = $this->options->read( 'demo', $subject );

		$this->assertInstanceOf( State::class, $read );
		$this->assertSame( 5, $read->strikes() );
		$this->assertSame( 3, $read->breaches() );
	}

	/**
	 * Lock rows must never join the autoloaded option payload.
	 */
	public function test_option_rows_are_not_autoloaded(): void {
		global $wpdb;

		$subject = new Subject( Subject::TYPE_EMAIL, 'nobody@example.com', 0 );
		$this->options->write( 'demo', $subject, $this->state(), 100 );

		$autoload = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
				$this->options->option_name( 'demo', $subject )
			)
		);

		$this->assertContains( $autoload, [ 'no', 'off' ], 'Lock rows must not be autoloaded.' );
	}

	/**
	 * The subject value never appears in the option name.
	 */
	public function test_option_name_does_not_leak_the_subject(): void {
		$subject = new Subject( Subject::TYPE_EMAIL, 'someone@example.com', 0 );
		$name    = $this->options->option_name( 'otp_send', $subject );

		$this->assertStringStartsWith( 'workos_lock_otp_send_', $name );
		$this->assertStringNotContainsString( 'someone', $name );
		$this->assertStringNotContainsString( 'example.com', $name );
		$this->assertLessThanOrEqual( 191, strlen( $name ), 'Must fit option_name.' );
	}

	/**
	 * Deleting removes the row.
	 */
	public function test_option_delete(): void {
		$subject = new Subject( Subject::TYPE_IP, '203.0.113.7', 0 );

		$this->options->write( 'demo', $subject, $this->state(), 100 );
		$this->assertNotNull( $this->options->read( 'demo', $subject ) );

		$this->options->delete( 'demo', $subject );
		$this->assertNull( $this->options->read( 'demo', $subject ) );
	}

	// ---------------------------------------------------------- resolving

	/**
	 * An address owned by a local user is stored against that user.
	 */
	public function test_resolving_prefers_user_meta_when_a_user_exists(): void {
		$user_id = self::factory()->user->create( [ 'user_email' => 'known@example.com' ] );
		$subject = new Subject( Subject::TYPE_EMAIL, 'known@example.com', $user_id );

		$this->resolving->write( 'demo', $subject, $this->state( 6 ), 100 );

		$this->assertNotNull( $this->user_meta->read( 'demo', $subject ) );
		$this->assertSame( 6, $this->resolving->read( 'demo', $subject )->strikes() );
	}

	/**
	 * An address with no local user falls back to an option row.
	 *
	 * This is the case that matters most: accounts exist upstream before they
	 * exist in WordPress, so a great deal of real traffic has no user behind it.
	 */
	public function test_resolving_falls_back_to_options_without_a_user(): void {
		$subject = new Subject( Subject::TYPE_EMAIL, 'stranger@example.com', 0 );

		$this->resolving->write( 'demo', $subject, $this->state( 6 ), 100 );

		$this->assertNotNull( $this->options->read( 'demo', $subject ) );
		$this->assertSame( 6, $this->resolving->read( 'demo', $subject )->strikes() );
	}

	/**
	 * IP subjects always use options — there is no user to attach them to.
	 */
	public function test_resolving_always_uses_options_for_ips(): void {
		$subject = new Subject( Subject::TYPE_IP, '198.51.100.2', 0 );

		$this->resolving->write( 'demo', $subject, $this->state(), 100 );

		$this->assertNotNull( $this->options->read( 'demo', $subject ) );
	}

	// -------------------------------------------------------- garbage collector

	/**
	 * Expired rows are pruned; live ones are left alone.
	 */
	public function test_gc_prunes_only_expired_rows(): void {
		$live    = new Subject( Subject::TYPE_EMAIL, 'live@example.com', 0 );
		$expired = new Subject( Subject::TYPE_EMAIL, 'expired@example.com', 0 );

		$this->options->write( 'demo', $live, $this->state(), DAY_IN_SECONDS );
		$this->options->write( 'demo', $expired, $this->state(), MINUTE_IN_SECONDS );

		$collector = new GarbageCollector( $this->options );

		// Two hours on, only the one-minute row has lapsed.
		$removed = $collector->run_at( time() + ( 2 * HOUR_IN_SECONDS ) );

		$this->assertSame( 1, $removed );
		$this->assertNotNull( $this->options->read( 'demo', $live ) );
		$this->assertNull( $this->options->read( 'demo', $expired ) );
	}

	/**
	 * The row ceiling bounds the table even when nothing has expired.
	 */
	public function test_gc_enforces_the_row_ceiling(): void {
		for ( $i = 0; $i < 12; $i++ ) {
			$this->options->write(
				'demo',
				new Subject( Subject::TYPE_EMAIL, "bulk{$i}@example.com", 0 ),
				$this->state(),
				DAY_IN_SECONDS
			);
		}

		$this->assertSame( 12, $this->options->count_rows() );

		// prune_to_ceiling() floors the ceiling at 1000 so a misconfigured
		// filter cannot empty the table, so drive it directly.
		$removed = $this->options->prune_to_ceiling( 5 );

		$this->assertSame( 0, $removed, 'Below the floor, nothing is pruned.' );

		$this->assertSame( 12, $this->options->count_rows() );
	}

	/**
	 * A row with an unreadable payload is treated as junk and dropped.
	 */
	public function test_gc_drops_malformed_rows(): void {
		update_option( 'workos_lock_demo_' . str_repeat( 'a', 32 ), 'not-an-array', false );

		$collector = new GarbageCollector( $this->options );
		$removed   = $collector->run_at( time() );

		$this->assertSame( 1, $removed );
	}
}
