<?php
/**
 * Persisted counter state for a single lock + subject pair.
 *
 * @package WorkOS\Lock
 */

namespace WorkOS\Lock;

defined( 'ABSPATH' ) || exit;

/**
 * The record a lock keeps about one subject.
 *
 * Two counters, and the distinction between them is the whole point:
 *
 * - `strikes`  — attempts accumulated inside the current run. Reset every time
 *                a cooldown is engaged, and every time the subject goes quiet
 *                for longer than the decay window.
 * - `breaches` — how many times this subject has *already* been locked. This
 *                one survives the cooldown, which is what makes the ladder
 *                escalate: the second lock is longer than the first, the third
 *                longer again. Without it a caller could sit at the threshold
 *                forever, paying the same small cooldown each round.
 *
 * `first_seen` anchors the run, `updated_at` drives decay, and `locked_until`
 * is the absolute timestamp the subject is barred until (0 when not locked).
 */
class State {

	/**
	 * Attempts in the current run.
	 *
	 * @var int
	 */
	private int $strikes = 0;

	/**
	 * Completed lockouts, retained across cooldowns to escalate the ladder.
	 *
	 * @var int
	 */
	private int $breaches = 0;

	/**
	 * Timestamp of the first attempt in the current run.
	 *
	 * @var int
	 */
	private int $first_seen = 0;

	/**
	 * Timestamp the subject is locked until; 0 when not locked.
	 *
	 * @var int
	 */
	private int $locked_until = 0;

	/**
	 * Timestamp of the most recent write.
	 *
	 * @var int
	 */
	private int $updated_at = 0;

	/**
	 * Constructor. Hydrates from a stored array, or builds an empty state.
	 *
	 * @param array<string, mixed> $data Stored payload, as produced by {@see self::to_array()}.
	 */
	public function __construct( array $data = [] ) {
		$this->strikes      = isset( $data['strikes'] ) ? max( 0, (int) $data['strikes'] ) : 0;
		$this->breaches     = isset( $data['breaches'] ) ? max( 0, (int) $data['breaches'] ) : 0;
		$this->first_seen   = isset( $data['first_seen'] ) ? max( 0, (int) $data['first_seen'] ) : 0;
		$this->locked_until = isset( $data['locked_until'] ) ? max( 0, (int) $data['locked_until'] ) : 0;
		$this->updated_at   = isset( $data['updated_at'] ) ? max( 0, (int) $data['updated_at'] ) : 0;
	}

	/**
	 * Attempts in the current run.
	 *
	 * @return int
	 */
	public function strikes(): int {
		return $this->strikes;
	}

	/**
	 * Completed lockouts.
	 *
	 * @return int
	 */
	public function breaches(): int {
		return $this->breaches;
	}

	/**
	 * Timestamp of the first attempt in the current run.
	 *
	 * @return int
	 */
	public function first_seen(): int {
		return $this->first_seen;
	}

	/**
	 * Timestamp the subject is locked until; 0 when not locked.
	 *
	 * @return int
	 */
	public function locked_until(): int {
		return $this->locked_until;
	}

	/**
	 * Timestamp of the most recent write.
	 *
	 * @return int
	 */
	public function updated_at(): int {
		return $this->updated_at;
	}

	/**
	 * Whether the subject is currently barred.
	 *
	 * @param int $now Current timestamp.
	 *
	 * @return bool
	 */
	public function is_locked( int $now ): bool {
		return $this->locked_until > $now;
	}

	/**
	 * Seconds remaining on the current cooldown; 0 when not locked.
	 *
	 * @param int $now Current timestamp.
	 *
	 * @return int
	 */
	public function retry_after( int $now ): int {
		return $this->is_locked( $now ) ? $this->locked_until - $now : 0;
	}

	/**
	 * Whether this state carries nothing worth persisting.
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return 0 === $this->strikes && 0 === $this->breaches && 0 === $this->locked_until;
	}

	/**
	 * Count one attempt.
	 *
	 * @param int $now Current timestamp.
	 *
	 * @return void
	 */
	public function add_strike( int $now ): void {
		if ( 0 === $this->first_seen ) {
			$this->first_seen = $now;
		}

		++$this->strikes;
		$this->updated_at = $now;
	}

	/**
	 * Engage a cooldown: bar the subject, bank the breach, clear the run.
	 *
	 * `breaches` is incremented and deliberately *not* cleared here — it is the
	 * memory that makes the next cooldown longer than this one.
	 *
	 * @param int $now      Current timestamp.
	 * @param int $duration Cooldown length in seconds.
	 *
	 * @return void
	 */
	public function engage( int $now, int $duration ): void {
		$this->locked_until = $now + max( 1, $duration );
		$this->strikes      = 0;
		$this->first_seen   = 0;
		$this->updated_at   = $now;
		++$this->breaches;
	}

	/**
	 * Clear the run *and* the escalation memory.
	 *
	 * Used by decay and by an operator unlock — both mean "treat this subject
	 * as new again", so the ladder restarts from its first rung.
	 *
	 * @param int $now Current timestamp.
	 *
	 * @return void
	 */
	public function reset( int $now ): void {
		$this->strikes      = 0;
		$this->breaches     = 0;
		$this->first_seen   = 0;
		$this->locked_until = 0;
		$this->updated_at   = $now;
	}

	/**
	 * Serializable payload.
	 *
	 * @return array<string, int>
	 */
	public function to_array(): array {
		return [
			'strikes'      => $this->strikes,
			'breaches'     => $this->breaches,
			'first_seen'   => $this->first_seen,
			'locked_until' => $this->locked_until,
			'updated_at'   => $this->updated_at,
		];
	}
}
