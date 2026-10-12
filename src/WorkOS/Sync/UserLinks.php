<?php
/**
 * WorkOS user ID → WP user lookups through an indexed table.
 *
 * @package WorkOS\Sync
 */

namespace WorkOS\Sync;

use WorkOS\User;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the WP user linked to a WorkOS user ID.
 *
 * The link lives in the `_workos_user_id` user meta. Looking a value up there means
 * scanning every row with that meta key, because `meta_value` isn't indexed: about
 * 40 ms per lookup on a site with 25,000 linked users, on every sign-in and webhook.
 * `{base_prefix}workos_user_links` mirrors the meta with an index on the WorkOS ID.
 * It follows every write to the meta through the user meta hooks, and the schema
 * upgrade fills it from the existing meta. The meta stays the source of truth: every
 * hit is checked against it, and until the table is filled lookups use the meta.
 */
class UserLinks {

	/**
	 * Option set once the table has been filled from the existing meta.
	 */
	public const READY_OPTION = 'workos_user_links_ready';

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		// Users and their meta are network-wide, so the links are too.
		return $wpdb->base_prefix . 'workos_user_links';
	}

	/**
	 * Constructor — keeps the table in step with the meta.
	 */
	public function __construct() {
		add_action( 'added_user_meta', [ $this, 'on_meta_change' ], 10, 3 );
		add_action( 'updated_user_meta', [ $this, 'on_meta_change' ], 10, 3 );
		add_action( 'deleted_user_meta', [ $this, 'on_meta_change' ], 10, 3 );
		add_action( 'deleted_user', [ self::class, 'resync_user' ] );
	}

	/**
	 * A user meta row was added, changed or deleted.
	 *
	 * @param int|int[] $meta_id  Meta ID(s).
	 * @param int       $user_id  WP user ID.
	 * @param string    $meta_key Meta key.
	 */
	public function on_meta_change( $meta_id, $user_id, $meta_key ): void {
		if ( User::META_WORKOS_ID === $meta_key ) {
			self::resync_user( (int) $user_id );
		}
	}

	/**
	 * Rewrite one user's rows from their meta.
	 *
	 * @param int $user_id WP user ID.
	 */
	public static function resync_user( $user_id ): void {
		global $wpdb;

		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE wp_user_id = %d', self::table(), $user_id ) );
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO %i (workos_user_id, wp_user_id)
				SELECT meta_value, user_id FROM %i
				WHERE user_id = %d AND meta_key = %s AND meta_value != ''",
				self::table(),
				$wpdb->usermeta,
				$user_id,
				User::META_WORKOS_ID
			)
		);
		// phpcs:enable
	}

	/**
	 * Fill the table from the existing meta (schema upgrade). Safe to run again.
	 */
	public static function rebuild(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO %i (workos_user_id, wp_user_id)
				SELECT meta_value, user_id FROM %i
				WHERE meta_key = %s AND meta_value != ''",
				self::table(),
				$wpdb->usermeta,
				User::META_WORKOS_ID
			)
		);
		// phpcs:enable

		if ( '' === $wpdb->last_error ) {
			update_option( self::READY_OPTION, 1, true );
		}
	}

	/**
	 * Whether lookups can use the table.
	 *
	 * @return bool
	 */
	public static function is_ready(): bool {
		return (bool) get_option( self::READY_OPTION, false );
	}

	/**
	 * The WP user linked to a WorkOS user ID.
	 *
	 * Same answer as the `get_users()` meta query it replaces: a user of the current
	 * site, the first by login when more than one carries the ID.
	 *
	 * @param string $workos_id WorkOS user ID.
	 *
	 * @return int|null WP user ID, or null when no user on this site is linked.
	 */
	public static function find( string $workos_id ): ?int {
		if ( '' === $workos_id ) {
			return null;
		}
		return self::find_many( [ $workos_id ] )[ $workos_id ] ?? null;
	}

	/**
	 * The WP users linked to many WorkOS user IDs, in two queries.
	 *
	 * @param string[] $workos_ids WorkOS user IDs.
	 *
	 * @return array<string, int> WP user ID by WorkOS user ID; unlinked IDs are left out.
	 */
	public static function find_many( array $workos_ids ): array {
		$workos_ids = array_values( array_unique( array_filter( array_map( 'strval', $workos_ids ) ) ) );
		if ( empty( $workos_ids ) ) {
			return [];
		}

		if ( ! self::is_ready() ) {
			$found = [];
			foreach ( $workos_ids as $workos_id ) {
				$wp_user_id = self::find_in_meta( $workos_id );
				if ( null !== $wp_user_id ) {
					$found[ $workos_id ] = $wp_user_id;
				}
			}
			return $found;
		}

		global $wpdb;
		$in = implode( ',', array_fill( 0, count( $workos_ids ), '%s' ) );

		// $in is only %s placeholders, one per ID in $workos_ids.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.workos_user_id, l.wp_user_id
				FROM %i l
				INNER JOIN %i u ON u.ID = l.wp_user_id
				WHERE l.workos_user_id IN ($in)
				ORDER BY u.user_login",
				self::table(),
				$wpdb->users,
				...$workos_ids
			)
		);
		// phpcs:enable

		if ( empty( $rows ) ) {
			return [];
		}

		// One query for every candidate's meta, to check each row against it.
		update_meta_cache( 'user', array_map( 'intval', wp_list_pluck( $rows, 'wp_user_id' ) ) );

		$caps_key = $wpdb->get_blog_prefix() . 'capabilities';
		$found    = [];
		$stale    = [];
		foreach ( $rows as $row ) {
			$workos_id  = (string) $row->workos_user_id;
			$wp_user_id = (int) $row->wp_user_id;
			if ( isset( $found[ $workos_id ] ) ) {
				continue;
			}

			$linked = array_map( 'strval', (array) get_user_meta( $wp_user_id, User::META_WORKOS_ID, false ) );
			if ( ! in_array( $workos_id, $linked, true ) ) {
				// The meta changed without the hooks firing (e.g. a direct SQL write).
				$stale[ $wp_user_id ] = $workos_id;
				continue;
			}

			// get_users() only returns users of the current site on multisite: those
			// with this site's capabilities key. The meta is already loaded above.
			if ( ! is_multisite() || metadata_exists( 'user', $wp_user_id, $caps_key ) ) {
				$found[ $workos_id ] = $wp_user_id;
			}
		}

		foreach ( $stale as $wp_user_id => $workos_id ) {
			self::resync_user( $wp_user_id );
			if ( ! isset( $found[ $workos_id ] ) ) {
				$wp_user_id = self::find_in_meta( $workos_id );
				if ( null !== $wp_user_id ) {
					$found[ $workos_id ] = $wp_user_id;
				}
			}
		}

		return $found;
	}

	/**
	 * The lookup through the meta, for before the table is filled.
	 *
	 * @param string $workos_id WorkOS user ID.
	 *
	 * @return int|null
	 */
	private static function find_in_meta( string $workos_id ): ?int {
		$users = get_users(
			[
				'meta_key'   => User::META_WORKOS_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $workos_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'     => 1,
				'fields'     => 'ID',
			]
		);

		return ! empty( $users ) ? (int) $users[0] : null;
	}
}
