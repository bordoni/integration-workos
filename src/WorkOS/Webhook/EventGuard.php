<?php
/**
 * Duplicate and out-of-order webhook guards.
 *
 * @package WorkOS\Webhook
 */

namespace WorkOS\Webhook;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps retried and late webhook deliveries from re-applying old data.
 *
 * WorkOS retries a delivery until it gets a 2xx, so the same event can arrive more
 * than once, and a retry from a backlog can arrive after a newer event for the same
 * object. Two guards:
 *
 * - Event IDs that were already handled are remembered for a while, so a repeat
 *   delivery runs no handlers.
 * - Handlers that overwrite local state compare the payload's `updated_at` with the
 *   last one they applied and skip anything that isn't newer.
 */
class EventGuard {

	/**
	 * Transient prefix for handled event IDs.
	 */
	private const SEEN_PREFIX = 'workos_webhook_seen_';

	/**
	 * How long a handled event ID is remembered, in seconds.
	 *
	 * @return int
	 */
	public static function seen_ttl(): int {
		/**
		 * Filters how long a handled webhook event ID is remembered.
		 *
		 * @param int $ttl Seconds. Default 3 days.
		 */
		return max( HOUR_IN_SECONDS, (int) apply_filters( 'workos_webhook_seen_ttl', 3 * DAY_IN_SECONDS ) );
	}

	/**
	 * Transient key for an event ID.
	 *
	 * @param string $event_id WorkOS event ID.
	 *
	 * @return string
	 */
	private static function seen_key( string $event_id ): string {
		return self::SEEN_PREFIX . md5( $event_id );
	}

	/**
	 * Whether this event ID was already handled.
	 *
	 * @param string $event_id WorkOS event ID.
	 *
	 * @return bool
	 */
	public static function was_handled( string $event_id ): bool {
		return '' !== $event_id && false !== get_transient( self::seen_key( $event_id ) );
	}

	/**
	 * Remember that this event ID was handled.
	 *
	 * @param string $event_id WorkOS event ID.
	 */
	public static function mark_handled( string $event_id ): void {
		if ( '' !== $event_id ) {
			set_transient( self::seen_key( $event_id ), 1, self::seen_ttl() );
		}
	}

	/**
	 * Parse a WorkOS timestamp (ISO 8601, usually with milliseconds).
	 *
	 * @param mixed $value Timestamp.
	 *
	 * @return \DateTimeImmutable|null Null when empty or unparseable.
	 */
	private static function parse( $value ): ?\DateTimeImmutable {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		try {
			return new \DateTimeImmutable( $value );
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * Whether a payload is older than (or the same as) the last one applied.
	 *
	 * Unknown either way (no timestamp on the payload, or nothing applied yet) is never
	 * stale, so the handler runs as it did before.
	 *
	 * @param mixed $incoming     The payload's `updated_at`.
	 * @param mixed $last_applied The `updated_at` of the last payload applied.
	 *
	 * @return bool
	 */
	public static function is_stale( $incoming, $last_applied ): bool {
		$incoming     = self::parse( $incoming );
		$last_applied = self::parse( $last_applied );

		return null !== $incoming && null !== $last_applied && $incoming <= $last_applied;
	}

	/**
	 * The later of two timestamps, as given.
	 *
	 * @param mixed $incoming     The payload's `updated_at`.
	 * @param mixed $last_applied The `updated_at` of the last payload applied.
	 *
	 * @return string The one to store; '' when neither parses.
	 */
	public static function latest( $incoming, $last_applied ): string {
		$a = self::parse( $incoming );
		$b = self::parse( $last_applied );

		if ( null === $a ) {
			return null === $b ? '' : (string) $last_applied;
		}
		if ( null === $b || $a > $b ) {
			return (string) $incoming;
		}
		return (string) $last_applied;
	}
}
