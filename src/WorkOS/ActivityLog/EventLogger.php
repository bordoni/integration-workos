<?php
/**
 * Activity event logger.
 *
 * @package WorkOS\ActivityLog
 */

namespace WorkOS\ActivityLog;

use WorkOS\Vendor\StellarWP\SuperGlobals\SuperGlobals;

defined( 'ABSPATH' ) || exit;

/**
 * Static methods for logging and querying auth events.
 */
class EventLogger {

	/**
	 * Log an activity event.
	 *
	 * @param string $event_type Event type (e.g. 'login', 'logout', 'login_failed').
	 * @param array  $data       Optional event data.
	 */
	public static function log( string $event_type, array $data = [] ): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		global $wpdb;

		$user_id        = $data['user_id'] ?? get_current_user_id();
		$user_email     = $data['user_email'] ?? '';
		$workos_user_id = $data['workos_user_id'] ?? '';

		if ( ! $user_email && $user_id ) {
			$user = get_userdata( $user_id );
			if ( $user ) {
				$user_email = $user->user_email;
			}
		}

		if ( ! $workos_user_id && $user_id ) {
			$workos_user_id = get_user_meta( $user_id, '_workos_user_id', true );
		}

		$metadata = $data['metadata'] ?? [];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			"{$wpdb->prefix}workos_activity_log",
			[
				'event_type'     => sanitize_text_field( $event_type ),
				'user_id'        => absint( $user_id ),
				'user_email'     => sanitize_email( $user_email ),
				'workos_user_id' => sanitize_text_field( $workos_user_id ),
				'ip_address'     => self::get_ip_address(),
				'user_agent'     => SuperGlobals::get_server_var( 'HTTP_USER_AGENT' ) ?? '',
				'metadata'       => ! empty( $metadata ) ? wp_json_encode( $metadata ) : null,
				'created_at'     => current_time( 'mysql', true ),
			],
			[ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		/**
		 * Fires after an activity event is logged.
		 *
		 * @param string $event_type Event type.
		 * @param array  $data       Event data.
		 */
		do_action( 'workos_activity_logged', $event_type, $data );
	}

	/**
	 * Get paginated events.
	 *
	 * @param array $args Query arguments (per_page, page, event_type).
	 *
	 * @return array{items: array, total: int}
	 */
	public static function get_events( array $args = [] ): array {
		global $wpdb;

		$per_page   = absint( $args['per_page'] ?? 20 );
		$page       = max( 1, absint( $args['page'] ?? 1 ) );
		$event_type = $args['event_type'] ?? '';
		$offset     = ( $page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$table = "{$wpdb->prefix}workos_activity_log";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		if ( $event_type ) {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE event_type = %s', $table, $event_type )
			);

			$items = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i WHERE event_type = %s ORDER BY created_at DESC LIMIT %d OFFSET %d', $table, $event_type, $per_page, $offset ),
				ARRAY_A
			);
		} else {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table )
			);

			$items = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i ORDER BY created_at DESC LIMIT %d OFFSET %d', $table, $per_page, $offset ),
				ARRAY_A
			);
		}
		// phpcs:enable

		return [
			'items' => is_array( $items ) ? $items : [],
			'total' => $total,
		];
	}

	/**
	 * Get stats for the last N days.
	 *
	 * @param int $days Number of days to look back.
	 *
	 * @return array{total_logins: int, failed_logins: int, unique_users: int}
	 */
	public static function get_stats( int $days = 30 ): array {
		global $wpdb;

		$table = "{$wpdb->prefix}workos_activity_log";
		$since = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$total_logins = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE event_type = 'login' AND created_at >= %s",
				$table,
				$since
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$failed_logins = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE event_type IN ('login_failed', 'login_denied') AND created_at >= %s",
				$table,
				$since
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$unique_users = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT user_id) FROM %i WHERE event_type = 'login' AND user_id > 0 AND created_at >= %s",
				$table,
				$since
			)
		);

		return [
			'total_logins'  => $total_logins,
			'failed_logins' => $failed_logins,
			'unique_users'  => $unique_users,
		];
	}

	/**
	 * Clear all activity log entries.
	 */
	public static function clear(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', "{$wpdb->prefix}workos_activity_log" ) );
	}

	/**
	 * Check if activity logging is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		if ( defined( 'WORKOS_ENABLE_ACTIVITY_LOG' ) ) {
			return (bool) WORKOS_ENABLE_ACTIVITY_LOG;
		}

		return (bool) workos()->option( 'enable_activity_log', false );
	}

	/**
	 * Get the client IP address.
	 *
	 * @return string
	 */
	private static function get_ip_address(): string {
		$headers = [
			'HTTP_CF_CONNECTING_IP',
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'REMOTE_ADDR',
		];

		foreach ( $headers as $header ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Dynamic key; sanitized below via FILTER_VALIDATE_IP.
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
				// X-Forwarded-For may contain multiple IPs; use the first.
				if ( str_contains( $ip, ',' ) ) {
					$ip = trim( explode( ',', $ip )[0] );
				}
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return '';
	}
}
