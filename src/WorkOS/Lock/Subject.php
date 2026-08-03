<?php
/**
 * The identity a lock counts against.
 *
 * @package WorkOS\Lock
 */

namespace WorkOS\Lock;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable description of *who* is being throttled.
 *
 * A lock never counts "requests" in the abstract — it counts requests made by
 * a particular subject. Four kinds exist:
 *
 * - `user`  — a resolved WordPress user ID.
 * - `email` — an email address. If a WP user owns that address the subject is
 *             user-backed and its counters live in user meta; otherwise they
 *             fall back to an option row. This matters because accounts are
 *             created in WorkOS first and only mirrored into WordPress on a
 *             successful login, so plenty of real traffic references an
 *             address with no local user behind it.
 * - `ip`    — a client address. Deliberately the weakest subject: behind a CDN
 *             or reverse proxy every visitor can share one address, so a long
 *             cooldown here would lock out an entire site. {@see BaseLock}
 *             caps the IP ladder well below the per-identity one.
 * - `key`   — anything else worth counting: an MFA factor id, a token hash.
 *
 * Subjects are built through the `for_*()` methods on a lock, never directly,
 * so callers cannot accidentally skip email normalization or user resolution.
 */
class Subject {

	/**
	 * Subject kinds.
	 */
	public const TYPE_USER  = 'user';
	public const TYPE_EMAIL = 'email';
	public const TYPE_IP    = 'ip';
	public const TYPE_KEY   = 'key';

	/**
	 * Subject kind, one of the TYPE_* constants.
	 *
	 * @var string
	 */
	private string $type;

	/**
	 * The raw subject value (email address, IP, factor id, …).
	 *
	 * @var string
	 */
	private string $value;

	/**
	 * Resolved WordPress user ID, or 0 when the subject has no local user.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Constructor.
	 *
	 * @param string $type    One of the TYPE_* constants.
	 * @param string $value   Raw subject value.
	 * @param int    $user_id Resolved WP user ID, 0 when there is none.
	 */
	public function __construct( string $type, string $value, int $user_id = 0 ) {
		$this->type    = $type;
		$this->value   = $value;
		$this->user_id = max( 0, $user_id );
	}

	/**
	 * Subject kind.
	 *
	 * @return string
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * Raw subject value.
	 *
	 * @return string
	 */
	public function value(): string {
		return $this->value;
	}

	/**
	 * Resolved WordPress user ID (0 when the subject has no local user).
	 *
	 * @return int
	 */
	public function user_id(): int {
		return $this->user_id;
	}

	/**
	 * Whether this subject's state can live in user meta.
	 *
	 * @return bool
	 */
	public function is_user_backed(): bool {
		return $this->user_id > 0;
	}

	/**
	 * Whether this subject carries a usable value.
	 *
	 * Empty subjects are skipped rather than counted — an unparseable email or
	 * a missing IP would otherwise funnel every anonymous caller into one
	 * shared bucket and lock them out collectively.
	 *
	 * @return bool
	 */
	public function is_valid(): bool {
		return '' !== $this->value;
	}

	/**
	 * Stable string identity, used as the hash input for storage keys.
	 *
	 * @return string
	 */
	public function identity(): string {
		return $this->type . ':' . $this->value;
	}
}
