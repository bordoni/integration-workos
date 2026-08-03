<?php
/**
 * Option-backed lock storage for subjects with no local user.
 *
 * @package WorkOS\Lock\Store
 */

namespace WorkOS\Lock\Store;

use WorkOS\Lock\Contracts\Store;
use WorkOS\Lock\State;
use WorkOS\Lock\Subject;

defined( 'ABSPATH' ) || exit;

/**
 * Stores lock state in a non-autoloaded option row.
 *
 * The fallback for every subject that has no WordPress user behind it: an
 * address that exists only in WorkOS, a client IP, an MFA factor id. Accounts
 * are created upstream and only mirrored into WordPress on a successful login,
 * so this covers a great deal of ordinary traffic — not just edge cases.
 *
 * Option names are `workos_lock_{slug}_{hash}`, where the hash is a truncated
 * SHA-256 of the subject identity. Hashing keeps email addresses out of the
 * options table in plaintext and gives every key a fixed, predictable length
 * (~65 characters, comfortably inside `option_name`'s 191).
 *
 * Rows are written with autoload off — they are read on the specific requests
 * that need them, and must never join the payload loaded on every page.
 *
 * Unlike user meta these rows have nothing to clean them up, so each carries an
 * `expires_at` stamp and {@see \WorkOS\Lock\GarbageCollector} prunes on a
 * schedule.
 */
class OptionStore implements Store {

	/**
	 * Option name prefix.
	 */
	public const OPTION_PREFIX = 'workos_lock_';

	/**
	 * Rows examined per query when pruning.
	 */
	private const PRUNE_BATCH = 500;

	/**
	 * Maximum rows deleted in a single prune run.
	 *
	 * Bounds the work a cron tick can do so a very large table cannot stall
	 * the request that triggered it; the next run picks up where this left off.
	 */
	private const PRUNE_MAX = 10000;

	/**
	 * Whether this store can hold state for the given subject.
	 *
	 * It can hold state for any valid subject, which is what makes it the
	 * fallback. {@see ResolvingStore} decides when it is actually used.
	 *
	 * @param Subject $subject Subject to test.
	 *
	 * @return bool
	 */
	public function supports( Subject $subject ): bool {
		return $subject->is_valid();
	}

	/**
	 * Read stored state.
	 *
	 * @param string  $slug    Lock slug.
	 * @param Subject $subject Subject.
	 *
	 * @return State|null
	 */
	public function read( string $slug, Subject $subject ): ?State {
		if ( ! $this->supports( $subject ) ) {
			return null;
		}

		$stored = get_option( $this->option_name( $slug, $subject ) );

		if ( ! is_array( $stored ) || ! isset( $stored['state'] ) || ! is_array( $stored['state'] ) ) {
			return null;
		}

		return new State( $stored['state'] );
	}

	/**
	 * Persist state.
	 *
	 * @param string  $slug    Lock slug.
	 * @param Subject $subject Subject.
	 * @param State   $state   State to persist.
	 * @param int     $ttl     Seconds after which the row may be pruned.
	 *
	 * @return void
	 */
	public function write( string $slug, Subject $subject, State $state, int $ttl ): void {
		if ( ! $this->supports( $subject ) ) {
			return;
		}

		$payload = [
			'state'      => $state->to_array(),
			'expires_at' => time() + max( MINUTE_IN_SECONDS, $ttl ),
		];

		update_option( $this->option_name( $slug, $subject ), $payload, false );
	}

	/**
	 * Remove stored state.
	 *
	 * @param string  $slug    Lock slug.
	 * @param Subject $subject Subject.
	 *
	 * @return void
	 */
	public function delete( string $slug, Subject $subject ): void {
		if ( ! $this->supports( $subject ) ) {
			return;
		}

		delete_option( $this->option_name( $slug, $subject ) );
	}

	/**
	 * Delete every row whose expiry has passed.
	 *
	 * Works in batches: the expiry lives inside the serialized payload, so rows
	 * are read and filtered in PHP rather than matched in SQL. Cheaper than the
	 * alternative of a second index, and this runs on cron.
	 *
	 * @param int $now Current timestamp.
	 *
	 * @return int Rows deleted.
	 */
	public function prune_expired( int $now ): int {
		global $wpdb;

		$deleted = 0;
		$offset  = 0;

		while ( $deleted < self::PRUNE_MAX ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT %d OFFSET %d",
					$wpdb->esc_like( self::OPTION_PREFIX ) . '%',
					self::PRUNE_BATCH,
					$offset
				)
			);

			if ( empty( $rows ) ) {
				break;
			}

			$survivors = 0;

			foreach ( $rows as $row ) {
				$payload = maybe_unserialize( $row->option_value );
				$expires = is_array( $payload ) && isset( $payload['expires_at'] )
					? (int) $payload['expires_at']
					: 0;

				// A row with no readable expiry is malformed; drop it too.
				if ( $expires > $now ) {
					++$survivors;
					continue;
				}

				delete_option( $row->option_name );
				++$deleted;

				if ( $deleted >= self::PRUNE_MAX ) {
					break;
				}
			}

			// Advance only past rows that survived. Deleted rows are gone from
			// the table, so counting them here would step over the rows that
			// slid into their place.
			$offset += $survivors;

			if ( count( $rows ) < self::PRUNE_BATCH ) {
				break;
			}
		}

		return $deleted;
	}

	/**
	 * Drop the oldest rows once the table holds more than the ceiling allows.
	 *
	 * A caller rotating throwaway addresses mints one row per address, so the
	 * key space is bounded only by their patience. This is the backstop: past
	 * the ceiling the oldest rows go, by insertion order. Losing them costs a
	 * stale counter, which is strictly better than an unbounded options table.
	 *
	 * @param int $ceiling Maximum rows to retain.
	 *
	 * @return int Rows deleted.
	 */
	public function prune_to_ceiling( int $ceiling ): int {
		global $wpdb;

		$ceiling = max( 1000, $ceiling );
		$like    = $wpdb->esc_like( self::OPTION_PREFIX ) . '%';

		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $like )
		);

		if ( $total <= $ceiling ) {
			return 0;
		}

		$excess = min( $total - $ceiling, self::PRUNE_MAX );

		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT %d",
				$like,
				$excess
			)
		);

		$deleted = 0;

		foreach ( (array) $names as $name ) {
			delete_option( $name );
			++$deleted;
		}

		return $deleted;
	}

	/**
	 * Total rows currently held by this store.
	 *
	 * @return int
	 */
	public function count_rows(): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( self::OPTION_PREFIX ) . '%'
			)
		);
	}

	/**
	 * Option name for a lock slug + subject pair.
	 *
	 * @param string  $slug    Lock slug.
	 * @param Subject $subject Subject.
	 *
	 * @return string
	 */
	public function option_name( string $slug, Subject $subject ): string {
		$safe_slug = preg_replace( '/[^a-z0-9_]/i', '', $slug );
		$hash      = substr( hash( 'sha256', $subject->identity() ), 0, 32 );

		return self::OPTION_PREFIX . $safe_slug . '_' . $hash;
	}
}
