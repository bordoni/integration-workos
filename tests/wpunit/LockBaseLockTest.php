<?php
/**
 * Tests for the attempt-lock engine.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Lock\BaseLock;
use WorkOS\Lock\Store\OptionStore;
use WorkOS\Lock\Store\ResolvingStore;
use WorkOS\Lock\Store\UserMetaStore;
use WorkOS\Time\FrozenClock;
use WorkOS\Lock\Subject;
use WP_Error;

/**
 * Threshold, escalation, decay and release semantics.
 *
 * @covers \WorkOS\Lock\BaseLock
 */
class LockBaseLockTest extends WPTestCase {

	/**
	 * Lock under test.
	 *
	 * @var BaseLock
	 */
	private BaseLock $lock;

	/**
	 * Clock the lock under test reads.
	 *
	 * @var FrozenClock
	 */
	private FrozenClock $clock;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->clock = new FrozenClock( 1700000000 );
		$this->lock  = $this->make_lock();
	}

	/**
	 * Move the frozen clock forward.
	 *
	 * @param int $seconds Seconds to advance.
	 *
	 * @return void
	 */
	private function travel_to( int $seconds ): void {
		$this->clock->travel( $seconds );
	}

	/**
	 * Build an anonymous lock with a short, predictable ladder.
	 *
	 * @return BaseLock
	 */
	private function make_lock(): BaseLock {
		$store = new ResolvingStore( new UserMetaStore(), new OptionStore() );

		return new class( $store, $this->clock ) extends BaseLock {
			public function slug(): string {
				return 'test_lock';
			}

			protected function default_threshold(): int {
				return 3;
			}

			protected function default_cooldowns(): array {
				return [ 100, 500, 2000 ];
			}

			protected function default_decay(): int {
				return 10000;
			}
		};
	}

	/**
	 * A fresh subject with no local user, so state lands in an option row.
	 *
	 * @return Subject
	 */
	private function subject(): Subject {
		return $this->lock->for_email( 'nobody-' . wp_generate_password( 8, false ) . '@example.com' );
	}

	// ---------------------------------------------------------------- threshold

	/**
	 * Attempts below the threshold are allowed.
	 */
	public function test_attempts_below_threshold_are_allowed(): void {
		$subject = $this->subject();

		$this->assertTrue( $this->lock->record( $subject ) );
		$this->assertTrue( $this->lock->record( $subject ) );
		$this->assertTrue( $this->lock->check( $subject ) );
	}

	/**
	 * The attempt that reaches the threshold engages the lock.
	 */
	public function test_reaching_threshold_engages_the_lock(): void {
		$subject = $this->subject();

		$this->lock->record( $subject );
		$this->lock->record( $subject );
		$result = $this->lock->record( $subject );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'workos_rate_limited', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertSame( 429, $data['status'] );
		$this->assertSame( 'test_lock', $data['lock'] );
		$this->assertSame( 100, $data['retry_after'] );
	}

	/**
	 * Once engaged, the read-only gate rejects too.
	 */
	public function test_check_rejects_while_locked(): void {
		$subject = $this->subject();

		for ( $i = 0; $i < 3; $i++ ) {
			$this->lock->record( $subject );
		}

		$this->assertInstanceOf( WP_Error::class, $this->lock->check( $subject ) );
	}

	/**
	 * The gate does not count anything.
	 */
	public function test_check_does_not_count(): void {
		$subject = $this->subject();

		for ( $i = 0; $i < 20; $i++ ) {
			$this->assertTrue( $this->lock->check( $subject ) );
		}

		$this->assertSame( 0, $this->lock->status( $subject )->strikes() );
	}

	/**
	 * The cooldown expires on its own.
	 */
	public function test_lock_lifts_once_the_cooldown_passes(): void {
		$subject = $this->subject();

		for ( $i = 0; $i < 3; $i++ ) {
			$this->lock->record( $subject );
		}

		$this->travel_to( 101 );

		$this->assertTrue( $this->lock->check( $subject ) );
	}

	// --------------------------------------------------------------- escalation

	/**
	 * Each successive lockout is drawn from a later rung of the ladder.
	 */
	public function test_cooldown_escalates_across_lockouts(): void {
		$subject  = $this->subject();
		$expected = [ 100, 500, 2000 ];

		foreach ( $expected as $index => $duration ) {
			for ( $i = 0; $i < 2; $i++ ) {
				$this->assertTrue( $this->lock->record( $subject ), "rung {$index}" );
			}

			$result = $this->lock->record( $subject );

			$this->assertInstanceOf( WP_Error::class, $result, "rung {$index}" );
			$this->assertSame( $duration, $result->get_error_data()['retry_after'], "rung {$index}" );

			// Serve the cooldown, then start the next run.
			$this->travel_to( $duration + 1 );
		}
	}

	/**
	 * Past the end of the ladder the final rung is held.
	 */
	public function test_ladder_holds_at_its_final_rung(): void {
		$subject = $this->subject();

		// Four lockouts against a three-rung ladder.
		for ( $round = 0; $round < 4; $round++ ) {
			for ( $i = 0; $i < 2; $i++ ) {
				$this->lock->record( $subject );
			}

			$result = $this->lock->record( $subject );
			$this->travel_to( $result->get_error_data()['retry_after'] + 1 );
		}

		$this->assertSame( 4, $this->lock->status( $subject )->breaches() );

		for ( $i = 0; $i < 2; $i++ ) {
			$this->lock->record( $subject );
		}

		$this->assertSame( 2000, $this->lock->record( $subject )->get_error_data()['retry_after'] );
	}

	/**
	 * The lockout count survives the cooldown that it caused.
	 *
	 * This is what makes the ladder escalate rather than repeat.
	 */
	public function test_breach_count_survives_the_cooldown(): void {
		$subject = $this->subject();

		for ( $i = 0; $i < 3; $i++ ) {
			$this->lock->record( $subject );
		}

		$this->travel_to( 200 );

		$state = $this->lock->status( $subject );
		$this->assertSame( 1, $state->breaches() );
		$this->assertSame( 0, $state->strikes(), 'The run resets even though the lockout count does not.' );
	}

	// -------------------------------------------------------------------- decay

	/**
	 * A quiet subject is forgiven entirely.
	 *
	 * The quiet period is counted from the moment the cooldown *ends*, not from
	 * when it began — otherwise a decay window shorter than the cooldown it
	 * follows would forgive a subject while the lock was still holding.
	 */
	public function test_decay_clears_strikes_and_breaches(): void {
		$subject = $this->subject();

		for ( $i = 0; $i < 3; $i++ ) {
			$this->lock->record( $subject );
		}

		$this->assertSame( 1, $this->lock->status( $subject )->breaches() );

		// 100s cooldown, then the full 10000s decay window.
		$this->travel_to( 100 + 10001 );

		$state = $this->lock->status( $subject );
		$this->assertSame( 0, $state->breaches() );
		$this->assertSame( 0, $state->strikes() );
		$this->assertTrue( $this->lock->check( $subject ) );
	}

	/**
	 * Decay never lifts a cooldown that is still running.
	 */
	public function test_decay_does_not_fire_while_the_lock_is_still_holding(): void {
		add_filter( 'workos_lock_decay_test_lock', static fn() => 60 );

		$subject = $this->subject();

		for ( $i = 0; $i < 3; $i++ ) {
			$this->lock->record( $subject );
		}

		// Decay window (60s) is far shorter than the cooldown (100s).
		$this->travel_to( 80 );

		$this->assertInstanceOf( WP_Error::class, $this->lock->check( $subject ) );

		remove_all_filters( 'workos_lock_decay_test_lock' );
	}

	/**
	 * Decay is measured from the last activity, not the first.
	 */
	public function test_decay_is_measured_from_the_last_attempt(): void {
		$subject = $this->subject();

		$this->lock->record( $subject );
		$this->travel_to( 9000 );
		$this->lock->record( $subject );
		$this->travel_to( 9000 );

		// 18000s since the first attempt, but only 9000s since the second,
		// so the run is still live and the third attempt should engage.
		$this->assertInstanceOf( WP_Error::class, $this->lock->record( $subject ) );
	}

	// ------------------------------------------------------------------ release

	/**
	 * Releasing clears everything, including the escalation memory.
	 */
	public function test_release_clears_all_state(): void {
		$subject = $this->subject();

		for ( $i = 0; $i < 3; $i++ ) {
			$this->lock->record( $subject );
		}

		$this->lock->release( $subject );

		$state = $this->lock->status( $subject );
		$this->assertTrue( $state->is_empty() );
		$this->assertSame( 0, $state->breaches() );
		$this->assertTrue( $this->lock->check( $subject ) );
	}

	/**
	 * A caller ignoring the 429 cannot push their own cooldown further out.
	 */
	public function test_recording_while_locked_does_not_extend_the_cooldown(): void {
		$subject = $this->subject();

		for ( $i = 0; $i < 3; $i++ ) {
			$this->lock->record( $subject );
		}

		$locked_until = $this->lock->status( $subject )->locked_until();

		for ( $i = 0; $i < 10; $i++ ) {
			$this->lock->record( $subject );
		}

		$this->assertSame( $locked_until, $this->lock->status( $subject )->locked_until() );
		$this->assertSame( 1, $this->lock->status( $subject )->breaches() );
	}

	// ----------------------------------------------------------------- subjects

	/**
	 * Different subjects are counted independently.
	 */
	public function test_subjects_are_counted_separately(): void {
		$one = $this->subject();
		$two = $this->subject();

		for ( $i = 0; $i < 3; $i++ ) {
			$this->lock->record( $one );
		}

		$this->assertInstanceOf( WP_Error::class, $this->lock->check( $one ) );
		$this->assertTrue( $this->lock->check( $two ) );
	}

	/**
	 * Unusable subjects are skipped rather than collapsed into one bucket.
	 */
	public function test_invalid_subjects_are_never_counted(): void {
		$subject = $this->lock->for_email( 'not-an-email' );

		$this->assertFalse( $subject->is_valid() );

		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertTrue( $this->lock->record( $subject ) );
		}

		$this->assertTrue( $this->lock->check( $subject ) );
	}

	/**
	 * IP subjects get a looser threshold and a capped cooldown.
	 */
	public function test_ip_subjects_are_treated_more_leniently(): void {
		$email = $this->lock->for_email( 'someone@example.com' );
		$ip    = $this->lock->for_ip( '203.0.113.9' );

		$this->assertSame( 3, $this->lock->threshold( $email ) );
		$this->assertSame( 12, $this->lock->threshold( $ip ), 'IP thresholds are multiplied.' );

		// The 2000s rung is capped for IP subjects.
		$this->assertSame( [ 100, 500, 900 ], $this->lock->cooldowns( $ip ) );
		$this->assertSame( [ 100, 500, 2000 ], $this->lock->cooldowns( $email ) );
	}

	// ------------------------------------------------------------------ filters

	/**
	 * The threshold is filterable.
	 */
	public function test_threshold_filter_is_respected(): void {
		add_filter( 'workos_lock_threshold_test_lock', static fn() => 1 );

		$subject = $this->subject();

		$this->assertInstanceOf( WP_Error::class, $this->lock->record( $subject ) );

		remove_all_filters( 'workos_lock_threshold_test_lock' );
	}

	/**
	 * Disabling a lock makes both entry points no-ops.
	 */
	public function test_disabling_the_lock_short_circuits_it(): void {
		add_filter( 'workos_lock_enabled_test_lock', '__return_false' );

		$subject = $this->subject();

		for ( $i = 0; $i < 20; $i++ ) {
			$this->assertTrue( $this->lock->record( $subject ) );
		}

		$this->assertTrue( $this->lock->check( $subject ) );

		remove_all_filters( 'workos_lock_enabled_test_lock' );
	}

	/**
	 * The subsystem-wide switch disables every lock.
	 */
	public function test_master_switch_short_circuits_the_lock(): void {
		add_filter( 'workos_locks_enabled', '__return_false' );

		$subject = $this->subject();

		for ( $i = 0; $i < 20; $i++ ) {
			$this->assertTrue( $this->lock->record( $subject ) );
		}

		remove_all_filters( 'workos_locks_enabled' );
	}

	/**
	 * Engaging and releasing fire their lifecycle actions.
	 */
	public function test_lifecycle_actions_fire(): void {
		$engaged  = 0;
		$released = 0;

		add_action( 'workos_lock_engaged', static function () use ( &$engaged ) { ++$engaged; } );
		add_action( 'workos_lock_released', static function () use ( &$released ) { ++$released; } );

		$subject = $this->subject();

		for ( $i = 0; $i < 3; $i++ ) {
			$this->lock->record( $subject );
		}

		$this->lock->release( $subject );

		$this->assertSame( 1, $engaged );
		$this->assertSame( 1, $released );

		remove_all_actions( 'workos_lock_engaged' );
		remove_all_actions( 'workos_lock_released' );
	}

	/**
	 * Releasing a subject that holds nothing is silent.
	 */
	public function test_releasing_an_untouched_subject_fires_nothing(): void {
		$released = 0;
		add_action( 'workos_lock_released', static function () use ( &$released ) { ++$released; } );

		$this->lock->release( $this->subject() );

		$this->assertSame( 0, $released );

		remove_all_actions( 'workos_lock_released' );
	}
}
