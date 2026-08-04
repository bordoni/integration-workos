<?php
/**
 * Shared engine behind every lock.
 *
 * @package WorkOS\Lock
 */

namespace WorkOS\Lock;

use WorkOS\Lock\Contracts\Lockable;
use WorkOS\Lock\Contracts\Store;
use WorkOS\Time\Clock;
use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Abstract lock implementing the whole counter/cooldown engine.
 *
 * A concrete lock supplies a slug and its default policy; everything else is
 * inherited. In practice that makes a new lock about thirty lines — see
 * {@see \WorkOS\Lock\Locks\OtpSendLock} for the shape, and `docs/locks.md`
 * for the recipe.
 *
 * ## Why counters live in the database
 *
 * The plugin already rejects bursts with a transient-backed fixed-window
 * limiter ({@see \WorkOS\Auth\AuthKit\RateLimiter}). That limiter is cheap and
 * runs first, but it is advisory by construction: without a persistent object
 * cache it does a read-modify-write that concurrent requests can interleave,
 * its storage is evictable, and a fixed window lets a caller spend a full
 * allowance either side of a window boundary. This class is the durable half —
 * slower, written to user meta or options, and measured in hours rather than
 * seconds. The two layer; neither replaces the other.
 *
 * ## The ladder
 *
 * Attempts accumulate as `strikes`. On reaching the threshold the subject is
 * barred for `cooldowns()[breaches]`, `breaches` is incremented, and `strikes`
 * resets. Because `breaches` outlives the cooldown, each subsequent lockout is
 * drawn from a later rung — 5 minutes, then 15, then an hour, and so on, held
 * at the final rung thereafter. A subject that stays quiet for `decay()`
 * seconds is forgiven entirely, so an ordinary user who once mistyped a form
 * is not carrying a penalty a week later.
 */
abstract class BaseLock implements Lockable {

	/**
	 * Where this lock's state is persisted.
	 *
	 * @var Store
	 */
	protected Store $store;

	/**
	 * Time source.
	 *
	 * @var Clock
	 */
	protected Clock $clock;

	/**
	 * Constructor.
	 *
	 * @param Store $store Persistence backend.
	 * @param Clock $clock Time source.
	 */
	public function __construct( Store $store, Clock $clock ) {
		$this->store = $store;
		$this->clock = $clock;
	}

	/**
	 * Machine name identifying this lock.
	 *
	 * @return string
	 */
	abstract public function slug(): string;

	/**
	 * Attempts allowed before a cooldown engages.
	 *
	 * @return int
	 */
	protected function default_threshold(): int {
		return 5;
	}

	/**
	 * Cooldown ladder in seconds, one rung per prior breach.
	 *
	 * The final rung is held for every breach beyond the ladder's length.
	 *
	 * @return array<int, int>
	 */
	protected function default_cooldowns(): array {
		return [
			5 * MINUTE_IN_SECONDS,
			15 * MINUTE_IN_SECONDS,
			HOUR_IN_SECONDS,
			6 * HOUR_IN_SECONDS,
			DAY_IN_SECONDS,
		];
	}

	/**
	 * Quiet period after which a subject is forgiven entirely.
	 *
	 * @return int
	 */
	protected function default_decay(): int {
		return DAY_IN_SECONDS;
	}

	/**
	 * Multiplier applied to the threshold for IP subjects.
	 *
	 * IP subjects are shared far more often than identities are — behind a
	 * CDN, a corporate NAT or a university, thousands of unrelated people
	 * arrive from one address. A per-IP lock as tight as a per-identity one
	 * would lock all of them out together, so IP thresholds are deliberately
	 * loose and their cooldowns capped short.
	 *
	 * @return int
	 */
	protected function ip_threshold_multiplier(): int {
		return 4;
	}

	/**
	 * Longest cooldown an IP subject can be given.
	 *
	 * @return int
	 */
	protected function ip_cooldown_ceiling(): int {
		return 15 * MINUTE_IN_SECONDS;
	}

	/**
	 * Human-readable label, surfaced in the admin and CLI.
	 *
	 * @return string
	 */
	public function label(): string {
		return $this->slug();
	}

	/**
	 * Message returned when this lock engages.
	 *
	 * @return string
	 */
	protected function message(): string {
		return __( 'Too many requests. Please try again shortly.', 'integration-workos' );
	}

	/**
	 * Build a subject for a WordPress user.
	 *
	 * @param int $user_id WP user ID.
	 *
	 * @return Subject
	 */
	public function for_user( int $user_id ): Subject {
		$user_id = max( 0, $user_id );

		return $this->filter_subject( new Subject( Subject::TYPE_USER, $user_id > 0 ? (string) $user_id : '', $user_id ) );
	}

	/**
	 * Build a subject for an email address.
	 *
	 * When a WordPress user owns the address the subject becomes user-backed
	 * and its counters land in user meta. When none does — the common case for
	 * an account that exists in WorkOS but has never completed a login here —
	 * it falls back to an option row.
	 *
	 * @param string $email Raw email address.
	 *
	 * @return Subject
	 */
	public function for_email( string $email ): Subject {
		$email = strtolower( trim( $email ) );

		if ( '' === $email || ! is_email( $email ) ) {
			return $this->filter_subject( new Subject( Subject::TYPE_EMAIL, '', 0 ) );
		}

		$user    = get_user_by( 'email', $email );
		$user_id = $user instanceof WP_User ? (int) $user->ID : 0;

		return $this->filter_subject( new Subject( Subject::TYPE_EMAIL, $email, $user_id ) );
	}

	/**
	 * Build a subject for a client IP address.
	 *
	 * @param string $ip Client IP.
	 *
	 * @return Subject
	 */
	public function for_ip( string $ip ): Subject {
		$ip = filter_var( trim( $ip ), FILTER_VALIDATE_IP );

		return $this->filter_subject( new Subject( Subject::TYPE_IP, is_string( $ip ) ? $ip : '', 0 ) );
	}

	/**
	 * Build a subject for an arbitrary key.
	 *
	 * @param string $value Key value.
	 *
	 * @return Subject
	 */
	public function for_key( string $value ): Subject {
		return $this->filter_subject( new Subject( Subject::TYPE_KEY, trim( $value ), 0 ) );
	}

	/**
	 * Read-only gate.
	 *
	 * @param Subject $subject Subject to test.
	 *
	 * @return true|WP_Error
	 */
	public function check( Subject $subject ) {
		if ( ! $this->is_enabled( $subject ) || ! $subject->is_valid() ) {
			return true;
		}

		$now   = $this->now();
		$state = $this->load( $subject, $now );

		if ( $state->is_locked( $now ) ) {
			return $this->locked_error( $state, $now );
		}

		return true;
	}

	/**
	 * Count one attempt.
	 *
	 * @param Subject $subject Subject to count against.
	 *
	 * @return true|WP_Error
	 */
	public function record( Subject $subject ) {
		if ( ! $this->is_enabled( $subject ) || ! $subject->is_valid() ) {
			return true;
		}

		$now   = $this->now();
		$state = $this->load( $subject, $now );

		// Already barred — nothing to add, and re-counting would let a caller
		// who ignores the 429 push their own cooldown further out.
		if ( $state->is_locked( $now ) ) {
			return $this->locked_error( $state, $now );
		}

		$state->add_strike( $now );

		$threshold = $this->threshold( $subject );
		$engaged   = $state->strikes() >= $threshold;

		if ( $engaged ) {
			$state->engage( $now, $this->cooldown_for( $subject, $state->breaches() ) );
		}

		$this->persist( $subject, $state, $now );

		if ( ! $engaged ) {
			return true;
		}

		/**
		 * Fires when a lock engages a cooldown for a subject.
		 *
		 * @param string  $slug    Lock slug.
		 * @param Subject $subject The barred subject.
		 * @param State   $state   State after engaging.
		 */
		do_action( 'workos_lock_engaged', $this->slug(), $subject, $state );

		return $this->locked_error( $state, $now );
	}

	/**
	 * Clear all state for a subject.
	 *
	 * @param Subject $subject Subject to clear.
	 *
	 * @return void
	 */
	public function release( Subject $subject ): void {
		if ( ! $subject->is_valid() ) {
			return;
		}

		$existing = $this->store->read( $this->slug(), $subject );
		if ( null === $existing || $existing->is_empty() ) {
			return;
		}

		$this->store->delete( $this->slug(), $subject );

		/**
		 * Fires when a lock is cleared for a subject.
		 *
		 * @param string  $slug    Lock slug.
		 * @param Subject $subject The cleared subject.
		 * @param State   $state   State immediately before clearing.
		 */
		do_action( 'workos_lock_released', $this->slug(), $subject, $existing );
	}

	/**
	 * Current state for a subject, with decay applied.
	 *
	 * @param Subject $subject Subject to inspect.
	 *
	 * @return State
	 */
	public function status( Subject $subject ): State {
		return $this->load( $subject, $this->now() );
	}

	/**
	 * Attempts allowed before a cooldown engages, for this subject.
	 *
	 * @param Subject $subject Subject.
	 *
	 * @return int
	 */
	public function threshold( Subject $subject ): int {
		$threshold = $this->default_threshold();

		if ( Subject::TYPE_IP === $subject->type() ) {
			$threshold *= max( 1, $this->ip_threshold_multiplier() );
		}

		/**
		 * Filters the attempt threshold for a lock.
		 *
		 * @param int     $threshold Attempts allowed before a cooldown engages.
		 * @param Subject $subject   Subject being counted.
		 * @param string  $slug      Lock slug.
		 */
		$threshold = (int) apply_filters( 'workos_lock_threshold', $threshold, $subject, $this->slug() );

		/**
		 * Filters the attempt threshold for one specific lock.
		 *
		 * @param int     $threshold Attempts allowed before a cooldown engages.
		 * @param Subject $subject   Subject being counted.
		 */
		$threshold = (int) apply_filters( "workos_lock_threshold_{$this->slug()}", $threshold, $subject );

		return max( 1, $threshold );
	}

	/**
	 * Cooldown ladder for this subject.
	 *
	 * @param Subject $subject Subject.
	 *
	 * @return array<int, int>
	 */
	public function cooldowns( Subject $subject ): array {
		$cooldowns = $this->default_cooldowns();

		if ( Subject::TYPE_IP === $subject->type() ) {
			$ceiling   = max( 1, $this->ip_cooldown_ceiling() );
			$cooldowns = array_map(
				static function ( $seconds ) use ( $ceiling ): int {
					return min( (int) $seconds, $ceiling );
				},
				$cooldowns
			);
		}

		/**
		 * Filters the cooldown ladder for a lock.
		 *
		 * One entry per prior breach; the final entry is held for every breach
		 * beyond the ladder's length.
		 *
		 * @param array<int, int> $cooldowns Ladder, in seconds.
		 * @param Subject         $subject   Subject being counted.
		 * @param string          $slug      Lock slug.
		 */
		$cooldowns = (array) apply_filters( 'workos_lock_cooldowns', $cooldowns, $subject, $this->slug() );

		/**
		 * Filters the cooldown ladder for one specific lock.
		 *
		 * @param array<int, int> $cooldowns Ladder, in seconds.
		 * @param Subject         $subject   Subject being counted.
		 */
		$cooldowns = (array) apply_filters( "workos_lock_cooldowns_{$this->slug()}", $cooldowns, $subject );

		$cooldowns = array_values(
			array_filter(
				array_map( 'intval', $cooldowns ),
				static function ( int $seconds ): bool {
					return $seconds > 0;
				}
			)
		);

		return [] === $cooldowns ? [ MINUTE_IN_SECONDS ] : $cooldowns;
	}

	/**
	 * Quiet period after which a subject is forgiven, for this subject.
	 *
	 * @param Subject $subject Subject.
	 *
	 * @return int
	 */
	public function decay( Subject $subject ): int {
		/**
		 * Filters the quiet period after which a subject's counters reset.
		 *
		 * @param int     $decay   Seconds of inactivity before the state is forgiven.
		 * @param Subject $subject Subject being counted.
		 * @param string  $slug    Lock slug.
		 */
		$decay = (int) apply_filters( 'workos_lock_decay', $this->default_decay(), $subject, $this->slug() );

		/**
		 * Filters the quiet period for one specific lock.
		 *
		 * @param int     $decay   Seconds of inactivity before the state is forgiven.
		 * @param Subject $subject Subject being counted.
		 */
		$decay = (int) apply_filters( "workos_lock_decay_{$this->slug()}", $decay, $subject );

		return max( MINUTE_IN_SECONDS, $decay );
	}

	/**
	 * Whether this lock is active for the given subject.
	 *
	 * @param Subject $subject Subject.
	 *
	 * @return bool
	 */
	public function is_enabled( Subject $subject ): bool {
		// Honour the subsystem-wide switch here rather than only at
		// registration: the flows that call a lock hold a reference to it
		// directly, so a controller that declined to register would otherwise
		// leave every lock still enforcing.
		$master = (bool) workos()->option( 'locks_enabled', true );

		/**
		 * Filters whether the attempt-lock subsystem is active.
		 *
		 * @param bool $enabled Whether locks are enforced.
		 */
		if ( ! (bool) apply_filters( 'workos_locks_enabled', $master ) ) {
			return false;
		}

		/**
		 * Filters whether a lock is enforced.
		 *
		 * Returning false disables the lock entirely — `check()` and `record()`
		 * both become no-ops. Intended for staging environments and for sites
		 * that enforce equivalent throttling at the edge.
		 *
		 * @param bool    $enabled Whether the lock is enforced.
		 * @param Subject $subject Subject being counted.
		 * @param string  $slug    Lock slug.
		 */
		$enabled = (bool) apply_filters( 'workos_lock_enabled', true, $subject, $this->slug() );

		/**
		 * Filters whether one specific lock is enforced.
		 *
		 * @param bool    $enabled Whether the lock is enforced.
		 * @param Subject $subject Subject being counted.
		 */
		return (bool) apply_filters( "workos_lock_enabled_{$this->slug()}", $enabled, $subject );
	}

	/**
	 * Cooldown length for the next breach.
	 *
	 * @param Subject $subject  Subject.
	 * @param int     $breaches Breaches already recorded.
	 *
	 * @return int
	 */
	protected function cooldown_for( Subject $subject, int $breaches ): int {
		$ladder = $this->cooldowns( $subject );
		$rung   = min( max( 0, $breaches ), count( $ladder ) - 1 );

		return (int) $ladder[ $rung ];
	}

	/**
	 * Read state, applying decay before returning it.
	 *
	 * Decay is applied on read rather than on a schedule so a forgiven subject
	 * behaves as new immediately, without waiting for cron.
	 *
	 * @param Subject $subject Subject.
	 * @param int     $now     Current timestamp.
	 *
	 * @return State
	 */
	protected function load( Subject $subject, int $now ): State {
		$state = $this->store->read( $this->slug(), $subject );

		if ( null === $state ) {
			$state = new State();
		}

		$idle_since = max( $state->updated_at(), $state->locked_until() );

		if ( $idle_since > 0 && ( $now - $idle_since ) >= $this->decay( $subject ) ) {
			$state->reset( $now );
		}

		/**
		 * Filters the state read for a subject.
		 *
		 * @param State   $state   Loaded state, after decay.
		 * @param Subject $subject Subject.
		 * @param string  $slug    Lock slug.
		 */
		return apply_filters( 'workos_lock_state', $state, $subject, $this->slug() );
	}

	/**
	 * Persist state, or drop the record when there is nothing left to keep.
	 *
	 * @param Subject $subject Subject.
	 * @param State   $state   State to persist.
	 * @param int     $now     Current timestamp.
	 *
	 * @return void
	 */
	protected function persist( Subject $subject, State $state, int $now ): void {
		if ( $state->is_empty() ) {
			$this->store->delete( $this->slug(), $subject );

			return;
		}

		$ttl = max( $this->decay( $subject ), $state->retry_after( $now ) + $this->decay( $subject ) );

		$this->store->write( $this->slug(), $subject, $state, $ttl );
	}

	/**
	 * Build the 429 returned when a subject is barred.
	 *
	 * Deliberately identical in code and shape to the burst limiter's error so
	 * REST clients, and the login UI, need no special handling for the two.
	 *
	 * @param State $state Current state.
	 * @param int   $now   Current timestamp.
	 *
	 * @return WP_Error
	 */
	protected function locked_error( State $state, int $now ): WP_Error {
		return new WP_Error(
			'workos_rate_limited',
			$this->message(),
			[
				'status'      => 429,
				'retry_after' => max( 1, $state->retry_after( $now ) ),
				'lock'        => $this->slug(),
			]
		);
	}

	/**
	 * Let integrations rewrite a subject before it is counted.
	 *
	 * @param Subject $subject Subject.
	 *
	 * @return Subject
	 */
	protected function filter_subject( Subject $subject ): Subject {
		/**
		 * Filters a subject before it is used.
		 *
		 * Lets a site re-point counters — for instance collapsing every address
		 * on a shared domain onto one subject.
		 *
		 * @param Subject $subject Subject.
		 * @param string  $slug    Lock slug.
		 */
		return apply_filters( 'workos_lock_subject', $subject, $this->slug() );
	}

	/**
	 * Current timestamp.
	 *
	 * @return int
	 */
	protected function now(): int {
		return $this->clock->now();
	}
}
