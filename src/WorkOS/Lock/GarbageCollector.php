<?php
/**
 * Scheduled cleanup of expired option-backed lock rows.
 *
 * @package WorkOS\Lock
 */

namespace WorkOS\Lock;

use WorkOS\Lock\Store\OptionStore;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the options table from accumulating dead lock rows.
 *
 * User-meta records need nothing: they decay on read and vanish with the user.
 * Option rows have neither property, so they carry an expiry stamp and this
 * class sweeps them daily.
 *
 * Two passes run per tick. The first drops rows whose expiry has passed. The
 * second enforces a hard ceiling on the total, because the number of rows is
 * driven by how many distinct subjects have been seen — and a caller cycling
 * through throwaway addresses controls that number directly.
 */
class GarbageCollector {

	/**
	 * Cron hook name.
	 */
	public const CRON_HOOK = 'workos_lock_gc';

	/**
	 * Default maximum option rows retained.
	 */
	public const DEFAULT_CEILING = 50000;

	/**
	 * Option-backed store to sweep.
	 *
	 * @var OptionStore
	 */
	private OptionStore $options;

	/**
	 * Constructor.
	 *
	 * @param OptionStore $options Option-backed store.
	 */
	public function __construct( OptionStore $options ) {
		$this->options = $options;
	}

	/**
	 * Attach hooks and make sure the sweep is scheduled.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::CRON_HOOK, [ $this, 'run_scheduled' ] );
		add_action( 'init', [ $this, 'maybe_schedule' ] );
	}

	/**
	 * Remove hooks and clear the schedule.
	 *
	 * @return void
	 */
	public function unregister(): void {
		remove_action( self::CRON_HOOK, [ $this, 'run_scheduled' ] );
		remove_action( 'init', [ $this, 'maybe_schedule' ] );

		$this->unschedule();
	}

	/**
	 * Cron entry point.
	 *
	 * Wraps {@see self::run()} because an action callback must not return a
	 * value; the row count is useful to the CLI and to tests, not to `do_action`.
	 *
	 * @return void
	 */
	public function run_scheduled(): void {
		$this->run();
	}

	/**
	 * Schedule the daily sweep when it is not already queued.
	 *
	 * @return void
	 */
	public function maybe_schedule(): void {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}

		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
	}

	/**
	 * Clear the scheduled sweep.
	 *
	 * @return void
	 */
	public function unschedule(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Run both cleanup passes against the current clock.
	 *
	 * @return int Total rows removed.
	 */
	public function run(): int {
		return $this->run_at( (int) apply_filters( 'workos_lock_current_time', time() ) );
	}

	/**
	 * Run both cleanup passes as of a given moment.
	 *
	 * @param int $now Timestamp to treat as "now".
	 *
	 * @return int Total rows removed.
	 */
	public function run_at( int $now ): int {
		$removed = $this->options->prune_expired( $now );

		/**
		 * Filters the maximum number of option-backed lock rows retained.
		 *
		 * Once the table holds more than this, the oldest rows are dropped by
		 * insertion order. Raise it on sites with very high legitimate volume;
		 * the cost of a dropped row is a forgotten counter, not a broken login.
		 *
		 * @param int $ceiling Maximum rows retained.
		 */
		$ceiling = (int) apply_filters( 'workos_lock_row_ceiling', self::DEFAULT_CEILING );

		$removed += $this->options->prune_to_ceiling( $ceiling );

		return $removed;
	}
}
