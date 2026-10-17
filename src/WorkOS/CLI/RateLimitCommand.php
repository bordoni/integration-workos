<?php
/**
 * CLI Rate Limit Command
 *
 * @package WorkOS\CLI
 */

namespace WorkOS\CLI;

use WorkOS\App;
use WorkOS\RateLimit\Lockouts;
use WP_CLI;
use WP_CLI\Formatter;

/**
 * Look up and clear sign-in lockouts for an email address.
 *
 * Covers the rate limits keyed on an email address (password sign-in,
 * magic codes, password reset, signup, and the verification-code sends).
 * Per-IP limits are not tied to an address and aren't listed.
 */
class RateLimitCommand extends \WP_CLI_Command {

	/**
	 * Columns for `check`.
	 */
	private const FIELDS = [ 'bucket', 'key', 'window', 'count', 'limit', 'time_left', 'locked' ];

	/**
	 * Lockout service.
	 *
	 * @var Lockouts
	 */
	private Lockouts $lockouts;

	/**
	 * Constructor. WP-CLI instantiates commands with no arguments.
	 *
	 * @param Lockouts|null $lockouts Lockout service; resolved from the container when omitted.
	 */
	public function __construct( ?Lockouts $lockouts = null ) {
		$this->lockouts = $lockouts ?? App::container()->get( Lockouts::class );
	}

	/**
	 * Show every sign-in rate limit that applies to an email address.
	 *
	 * One row per window. A bucket is locked while any of its windows is
	 * full; `time_left` is when that window's counter expires.
	 *
	 * ## OPTIONS
	 *
	 * <email>
	 * : The address, as the user types it. It is keyed the same way the
	 * limits key it (lowercased, and reduced to its mailbox where that
	 * applies).
	 *
	 * [--format=<format>]
	 * : Output format. Machine formats give `window` and `time_left` in seconds.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp workos ratelimit check jane@example.com
	 *     wp workos ratelimit check jane@example.com --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function check( $args, $assoc_args ) {
		$rows = $this->lockouts->check( (string) ( $args[0] ?? '' ) );
		if ( is_wp_error( $rows ) ) {
			WP_CLI::error( $rows->get_error_message() );
			return;
		}

		$human = 'table' === ( $assoc_args['format'] ?? 'table' );
		$items = [];

		foreach ( $rows as $row ) {
			foreach ( $row['tiers'] as $tier ) {
				$time_left = $tier['count'] > 0 ? $tier['resets_in'] : 0;

				$items[] = [
					'bucket'    => $row['bucket'],
					'key'       => $row['key'],
					'window'    => $human ? self::duration( $tier['window'] ) : $tier['window'],
					'count'     => $tier['count'],
					'limit'     => $tier['limit'],
					'time_left' => $human ? ( $time_left ? self::duration( $time_left ) : '' ) : $time_left,
					'locked'    => $tier['count'] >= $tier['limit'] ? 'yes' : 'no',
				];
			}
		}

		$formatter = new Formatter( $assoc_args, self::FIELDS );
		$formatter->display_items( $items );

		if ( ! $human ) {
			return;
		}

		$locked = array_filter( $rows, static fn( array $row ): bool => $row['locked'] );
		if ( $locked ) {
			WP_CLI::warning(
				sprintf(
					'Locked: %s. Clear with `wp workos ratelimit clear <email> [--bucket=<bucket>]`.',
					implode( ', ', array_column( $locked, 'bucket' ) )
				)
			);
		} else {
			WP_CLI::success( 'No lockouts for this address.' );
		}
	}

	/**
	 * Clear sign-in rate limits for an email address.
	 *
	 * Resets the current window of every tier, so the next request goes
	 * through. Writes an activity-log entry naming the bucket(s) and the
	 * user running the command (pass `--user` to record one).
	 *
	 * ## OPTIONS
	 *
	 * <email>
	 * : The address, as the user types it.
	 *
	 * [--bucket=<bucket>]
	 * : Clear only this bucket. Defaults to every bucket `check` lists.
	 *
	 * ## EXAMPLES
	 *
	 *     wp workos ratelimit clear jane@example.com
	 *     wp workos ratelimit clear jane@example.com --bucket=password_email --user=admin
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function clear( $args, $assoc_args ) {
		$cleared = $this->lockouts->clear(
			(string) ( $args[0] ?? '' ),
			(string) ( $assoc_args['bucket'] ?? '' ),
			'cli'
		);
		if ( is_wp_error( $cleared ) ) {
			WP_CLI::error( $cleared->get_error_message() );
			return;
		}

		WP_CLI::success( sprintf( 'Cleared %s for this address.', implode( ', ', $cleared ) ) );
	}

	/**
	 * Seconds as a short duration: `42s`, `4m 12s`, `1h`, `8h`.
	 *
	 * @param int $seconds Seconds.
	 *
	 * @return string
	 */
	private static function duration( int $seconds ): string {
		$units = [
			'h' => HOUR_IN_SECONDS,
			'm' => MINUTE_IN_SECONDS,
			's' => 1,
		];

		$parts = [];
		foreach ( $units as $unit => $size ) {
			if ( $seconds >= $size ) {
				$parts[]  = intdiv( $seconds, $size ) . $unit;
				$seconds %= $size;
			}
		}

		return $parts ? implode( ' ', $parts ) : '0s';
	}
}
