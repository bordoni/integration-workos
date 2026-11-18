<?php
/**
 * Short lock around linking one WorkOS user.
 *
 * @package WorkOS\Sync
 */

namespace WorkOS\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Serializes links of one WorkOS user across requests.
 *
 * {@see UserSync::link_user()} checks the link table and then writes the link. Two
 * requests linking the same WorkOS user at the same moment (a sign-in and the
 * `user.created` webhook, or two webhook retries) could both pass the check. Holding
 * this lock from the check to the write closes that window.
 *
 * MySQL `GET_LOCK()` does the work. Where it isn't available (it returns NULL), a row
 * in the options table is the lock instead: `INSERT IGNORE` on the unique option name,
 * so only one request can create it. A row left by a request that died is taken over
 * once it expires.
 *
 * @internal
 */
class LinkLock {

	/**
	 * Seconds to wait for a lock another request holds.
	 */
	public const TIMEOUT = 5;

	/**
	 * Seconds an options-row lock lives if its request dies holding it.
	 */
	public const ROW_TTL = 30;

	/**
	 * Locks this request holds: how each was taken, by lock name.
	 *
	 * @var array<string, string> 'get_lock', or the options row's value (its expiry time).
	 */
	private static array $held = [];

	/**
	 * Lock name for a WorkOS user ID: at most 64 characters (GET_LOCK's limit).
	 *
	 * Scoped to this site's tables, since GET_LOCK names are server-wide.
	 *
	 * @param string $workos_id WorkOS user ID.
	 *
	 * @return string
	 */
	public static function name( string $workos_id ): string {
		global $wpdb;
		$db = defined( 'DB_NAME' ) ? (string) DB_NAME : '';
		return 'workos_link_' . md5( $db . '|' . $wpdb->base_prefix . '|' . $workos_id );
	}

	/**
	 * Take the lock for a WorkOS user ID.
	 *
	 * Every successful call must be followed by {@see release()}, in a `finally`.
	 *
	 * @param string $workos_id WorkOS user ID.
	 *
	 * @return true|\WP_Error True when held; `workos_link_lock_timeout` when another
	 *                        request (or this one, further up the stack) holds it.
	 */
	public static function acquire( string $workos_id ) {
		global $wpdb;

		$name = self::name( $workos_id );

		// GET_LOCK() is re-entrant for one connection, so a link of the same WorkOS
		// user started from inside the locked section would get straight through.
		// Waiting would never end either: the holder is further up this stack.
		if ( isset( self::$held[ $name ] ) ) {
			return self::timeout_error( $workos_id );
		}

		/**
		 * Seconds to wait for a link of the same WorkOS user in another request.
		 *
		 * @param int    $timeout   Seconds. Default 5.
		 * @param string $workos_id WorkOS user ID.
		 */
		$timeout = max( 0, (int) apply_filters( 'workos_link_lock_timeout', self::TIMEOUT, $workos_id ) );

		$suppress = $wpdb->suppress_errors();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, $timeout ) );
		$wpdb->suppress_errors( $suppress );

		if ( null === $got ) {
			// No GET_LOCK() here (or it failed): use the options row.
			return self::acquire_row( $name, $workos_id, $timeout );
		}

		if ( '1' !== (string) $got ) {
			return self::timeout_error( $workos_id );
		}

		self::$held[ $name ] = 'get_lock';
		return true;
	}

	/**
	 * Release the lock for a WorkOS user ID. Does nothing when this request doesn't hold it.
	 *
	 * @param string $workos_id WorkOS user ID.
	 */
	public static function release( string $workos_id ): void {
		global $wpdb;

		$name = self::name( $workos_id );
		if ( ! isset( self::$held[ $name ] ) ) {
			return;
		}

		$how = self::$held[ $name ];
		unset( self::$held[ $name ] );

		$suppress = $wpdb->suppress_errors();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( 'get_lock' === $how ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		} else {
			// Only our own row: if it expired and another request took it, leave that one.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', self::options_table(), $name, $how ) );
		}
		// phpcs:enable
		$wpdb->suppress_errors( $suppress );
	}

	/**
	 * Take the lock as a row in the options table.
	 *
	 * @param string $name      Lock name (the option name).
	 * @param string $workos_id WorkOS user ID.
	 * @param int    $timeout   Seconds to wait.
	 *
	 * @return true|\WP_Error
	 */
	private static function acquire_row( string $name, string $workos_id, int $timeout ) {
		global $wpdb;

		$table    = self::options_table();
		$deadline = microtime( true ) + $timeout;
		$suppress = $wpdb->suppress_errors();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		while ( true ) {
			$now = time();

			// A row whose request died holding it.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d', $table, $name, $now ) );

			// The option name is unique: only one request inserts the row. add_option()
			// can't be the lock, since its INSERT updates a row that already exists.
			// The value is the expiry time.
			$value    = (string) ( $now + self::ROW_TTL );
			$inserted = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
					$table,
					$name,
					$value
				)
			);

			if ( 1 === $inserted ) {
				$wpdb->suppress_errors( $suppress );
				self::$held[ $name ] = $value;
				return true;
			}

			if ( microtime( true ) >= $deadline ) {
				break;
			}

			usleep( 100000 );
		}
		// phpcs:enable

		$wpdb->suppress_errors( $suppress );
		return self::timeout_error( $workos_id );
	}

	/**
	 * Options table for the row lock: the main site's, since links are network-wide.
	 *
	 * @return string
	 */
	private static function options_table(): string {
		global $wpdb;
		return $wpdb->base_prefix . 'options';
	}

	/**
	 * The error for a lock that couldn't be taken.
	 *
	 * @param string $workos_id WorkOS user ID.
	 *
	 * @return \WP_Error
	 */
	private static function timeout_error( string $workos_id ): \WP_Error {
		return new \WP_Error(
			'workos_link_lock_timeout',
			sprintf(
				/* translators: %s: WorkOS user ID. */
				__( 'WorkOS user %s is being linked by another request. Try again.', 'integration-workos' ),
				$workos_id
			)
		);
	}
}
