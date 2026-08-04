<?php
/**
 * A claimed rate-limit slot.
 *
 * @package WorkOS\RateLimit
 */

namespace WorkOS\RateLimit;

defined( 'ABSPATH' ) || exit;

/**
 * A slot taken by {@see RateLimiter::reserve()}, which can be handed back.
 *
 * The instant matters. Windows are identified by `floor( now / window )`, so
 * the counter a reservation belongs to is fixed at the moment it was taken.
 * A refund that read the clock again would land in whatever window happens
 * to be current when the work finishes — a different counter entirely if a
 * boundary passed in between, leaving the original window charged for a
 * message that was never sent.
 *
 * So the reservation carries its own timestamp, and releasing is defined as
 * "undo exactly what I did", not "decrement whatever is current".
 */
class Reservation {

	/**
	 * Limiter that issued this reservation.
	 *
	 * @var RateLimiter
	 */
	private RateLimiter $limiter;

	/**
	 * Operation identifier.
	 *
	 * @var string
	 */
	private string $bucket;

	/**
	 * Thing being limited.
	 *
	 * @var string
	 */
	private string $subject;

	/**
	 * Windows this reservation was taken against.
	 *
	 * @var array<int, array{0:int,1:int}>
	 */
	private array $tiers;

	/**
	 * Unix timestamp at which the slot was claimed.
	 *
	 * @var int
	 */
	private int $instant;

	/**
	 * Whether the slot has already been handed back.
	 *
	 * @var bool
	 */
	private bool $released = false;

	/**
	 * Constructor.
	 *
	 * @param RateLimiter                    $limiter Limiter that issued this reservation.
	 * @param string                         $bucket  Operation identifier.
	 * @param string                         $subject Thing being limited.
	 * @param array<int, array{0:int,1:int}> $tiers   List of [limit, window_seconds].
	 * @param int                            $instant Unix timestamp at which the slot was claimed.
	 */
	public function __construct( RateLimiter $limiter, string $bucket, string $subject, array $tiers, int $instant ) {
		$this->limiter = $limiter;
		$this->bucket  = $bucket;
		$this->subject = $subject;
		$this->tiers   = $tiers;
		$this->instant = $instant;
	}

	/**
	 * Hand the slot back, because the work it paid for didn't happen.
	 *
	 * Idempotent: releasing twice would credit a window that was only ever
	 * charged once.
	 *
	 * @return void
	 */
	public function release(): void {
		if ( $this->released ) {
			return;
		}

		$this->released = true;

		$this->limiter->release( $this );
	}

	/**
	 * Operation identifier.
	 *
	 * @return string
	 */
	public function bucket(): string {
		return $this->bucket;
	}

	/**
	 * Thing being limited.
	 *
	 * @return string
	 */
	public function subject(): string {
		return $this->subject;
	}

	/**
	 * Windows this reservation was taken against.
	 *
	 * @return array<int, array{0:int,1:int}>
	 */
	public function tiers(): array {
		return $this->tiers;
	}

	/**
	 * Unix timestamp at which the slot was claimed.
	 *
	 * @return int
	 */
	public function instant(): int {
		return $this->instant;
	}
}
