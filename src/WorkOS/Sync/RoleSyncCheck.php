<?php
/**
 * Role sync check: which members' WordPress role doesn't match the role map.
 *
 * @package WorkOS\Sync
 */

namespace WorkOS\Sync;

use WorkOS\Config;
use WorkOS\Organization\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the members of the configured organization whose WordPress role doesn't match
 * what the role map says, and keeps the answer so admin screens never compute it.
 *
 * The check reads members in batches: one query per batch returns each member's WorkOS
 * role and WordPress capabilities, instead of loading every user one at a time. It runs
 * when an admin asks for it (WorkOS → Users → Role Mapping → Check now), or on a
 * schedule through Action Scheduler when that's available. Between runs the stored
 * result follows role and membership changes one user at a time.
 */
class RoleSyncCheck {

	/**
	 * Option holding the last result.
	 */
	public const OPTION = 'workos_role_sync_check';

	/**
	 * Action Scheduler hook (scheduled runs and the batches of a run).
	 */
	public const ACTION = 'workos_role_sync_check';

	/**
	 * Action Scheduler group.
	 */
	public const GROUP = 'workos';

	/**
	 * Members read per batch.
	 */
	public const BATCH_SIZE = 1000;

	/**
	 * Schedules the settings page offers.
	 */
	public const SCHEDULES = [
		'off'    => 0,
		'hourly' => HOUR_IN_SECONDS,
		'daily'  => DAY_IN_SECONDS,
	];

	/**
	 * Constructor — registers hooks.
	 */
	public function __construct() {
		// After RoleMapper (priority 10) has pushed the change to WorkOS.
		add_action( 'set_user_role', [ $this, 'recheck_user' ], 20 );
		add_action( 'add_user_role', [ $this, 'recheck_user' ], 20 );
		add_action( 'remove_user_role', [ $this, 'recheck_user' ], 20 );
		add_action( 'workos_org_membership_changed', [ $this, 'recheck_user' ], 20 );
		add_action( 'deleted_user', [ $this, 'forget_user' ] );

		add_action( self::ACTION, [ $this, 'handle_action' ] );
		foreach ( [ 'production', 'staging' ] as $env ) {
			add_action( "update_option_workos_{$env}", [ self::class, 'on_settings_updated' ], 10, 3 );
			add_action( "add_option_workos_{$env}", [ self::class, 'on_settings_added' ], 10, 2 );
		}
	}

	/**
	 * Whether Action Scheduler is loaded (WooCommerce and many plugins ship it).
	 *
	 * @return bool
	 */
	public static function has_action_scheduler(): bool {
		return function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_schedule_recurring_action' );
	}

	/**
	 * Number of members read per batch.
	 *
	 * @return int
	 */
	public static function batch_size(): int {
		/**
		 * Filters how many members the role sync check reads per query.
		 *
		 * @param int $size Members per batch. Default 1000.
		 */
		return max( 1, (int) apply_filters( 'workos_role_sync_check_batch_size', self::BATCH_SIZE ) );
	}

	/**
	 * What the result was computed against. A result for another organization,
	 * environment or role map is stale.
	 *
	 * @return array{env: string, org: string, map: string}
	 */
	private static function context(): array {
		return [
			'env' => Config::get_active_environment(),
			'org' => Config::get_organization_id(),
			'map' => md5( (string) wp_json_encode( RoleMapper::get_role_map() ) ),
		];
	}

	/**
	 * The stored result, or null when the check has never finished for the current
	 * organization, environment and role map.
	 *
	 * @return array{ids: int[], count: int, members: int, checked_at: int}|null
	 */
	public static function result(): ?array {
		$stored = get_option( self::OPTION, [] );
		if ( ! is_array( $stored ) || empty( $stored['checked_at'] ) ) {
			return null;
		}

		$context = self::context();
		foreach ( [ 'env', 'org', 'map' ] as $key ) {
			if ( ( $stored[ $key ] ?? '' ) !== $context[ $key ] ) {
				return null;
			}
		}

		$ids = array_map( 'intval', (array) ( $stored['ids'] ?? [] ) );

		return [
			'ids'        => $ids,
			'count'      => count( $ids ),
			'members'    => (int) ( $stored['members'] ?? 0 ),
			'checked_at' => (int) $stored['checked_at'],
		];
	}

	/**
	 * Whether a run is in progress (background runs only). A run that started more
	 * than an hour ago is treated as dead, so a failed batch can't hide "Check now".
	 *
	 * @return bool
	 */
	public static function is_running(): bool {
		$stored = get_option( self::OPTION, [] );
		return is_array( $stored )
			&& ! empty( $stored['running'] )
			&& (int) ( $stored['running']['started'] ?? 0 ) > time() - HOUR_IN_SECONDS;
	}

	/**
	 * The local organization the check covers, or null when none is configured.
	 *
	 * @return object|null
	 */
	private static function local_org(): ?object {
		$workos_org_id = Config::get_organization_id();
		return '' === $workos_org_id ? null : Manager::get_by_workos_id( $workos_org_id );
	}

	/**
	 * Out-of-sync members among the next batch, with each member's details.
	 *
	 * One query returns each member's WorkOS role, display name and WordPress
	 * capabilities, ordered by user ID so a run can resume after the last ID it saw.
	 *
	 * @param int      $org_id  Local organization ID.
	 * @param int      $after   Only members with a user ID above this.
	 * @param int      $limit   Members to read.
	 * @param int|null $user_id Only this user (for single-user rechecks).
	 *
	 * @return array{rows: int, last_id: int, out_of_sync: array<int, array>}
	 */
	public static function check_batch( int $org_id, int $after, int $limit, ?int $user_id = null ): array {
		global $wpdb;

		$mem_table = $wpdb->prefix . 'workos_org_memberships';
		$caps_key  = $wpdb->get_blog_prefix() . 'capabilities';
		$only_user = (int) $user_id;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.user_id, m.workos_role, u.display_name, um.meta_value AS caps
				FROM %i m
				INNER JOIN %i u ON u.ID = m.user_id
				LEFT JOIN %i um ON um.user_id = m.user_id AND um.meta_key = %s
				WHERE m.org_id = %d AND m.workos_role != '' AND m.user_id > %d AND ( 0 = %d OR m.user_id = %d )
				ORDER BY m.user_id
				LIMIT %d",
				$mem_table,
				$wpdb->users,
				$wpdb->usermeta,
				$caps_key,
				$org_id,
				$after,
				$only_user,
				$only_user,
				$limit
			)
		);

		$role_map    = RoleMapper::get_role_map();
		$wp_roles    = wp_roles();
		$out_of_sync = [];
		$last_id     = $after;

		foreach ( (array) $rows as $row ) {
			$last_id = (int) $row->user_id;

			// Same rule as WP_User::$roles: the capability keys that are roles, in order.
			$caps   = maybe_unserialize( (string) $row->caps );
			$actual = '';
			foreach ( is_array( $caps ) ? array_keys( $caps ) : [] as $cap ) {
				if ( $wp_roles->is_role( (string) $cap ) ) {
					$actual = (string) $cap;
					break;
				}
			}

			$expected = $role_map[ $row->workos_role ] ?? $role_map['member'] ?? 'subscriber';
			if ( $expected !== $actual ) {
				$out_of_sync[ $last_id ] = [
					'user_id'          => $last_id,
					'display_name'     => (string) $row->display_name,
					'workos_role'      => (string) $row->workos_role,
					'expected_wp_role' => $expected,
					'actual_wp_role'   => $actual,
				];
			}
		}

		return [
			'rows'        => count( (array) $rows ),
			'last_id'     => $last_id,
			'out_of_sync' => $out_of_sync,
		];
	}

	/**
	 * Every out-of-sync member, computed now in batches. Doesn't touch the stored result.
	 *
	 * @return array<int, array> Details keyed by user ID.
	 */
	public static function compute_all(): array {
		$org = self::local_org();
		if ( ! $org ) {
			return [];
		}

		$all   = [];
		$after = 0;
		$size  = self::batch_size();
		do {
			$batch = self::check_batch( (int) $org->id, $after, $size );
			$all  += $batch['out_of_sync'];
			$after = $batch['last_id'];
		} while ( $batch['rows'] === $size );

		return $all;
	}

	/**
	 * Run the whole check now, in this request, and store the result.
	 *
	 * @return array{ids: int[], count: int, members: int, checked_at: int}|null Null when no organization is configured.
	 */
	public static function run_now(): ?array {
		$org = self::local_org();
		if ( ! $org ) {
			return null;
		}

		$ids     = [];
		$members = 0;
		$after   = 0;
		$size    = self::batch_size();
		do {
			$batch    = self::check_batch( (int) $org->id, $after, $size );
			$ids      = array_merge( $ids, array_keys( $batch['out_of_sync'] ) );
			$members += $batch['rows'];
			$after    = $batch['last_id'];
		} while ( $batch['rows'] === $size );

		self::store( $ids, $members );
		return self::result();
	}

	/**
	 * Start a run: in the background through Action Scheduler when it's there,
	 * otherwise right away.
	 *
	 * @return string 'queued', 'done' or 'no-org'.
	 */
	public static function start(): string {
		if ( ! self::local_org() ) {
			return 'no-org';
		}

		if ( ! self::has_action_scheduler() ) {
			self::run_now();
			return 'done';
		}

		self::begin_background_run();
		return 'queued';
	}

	/**
	 * Mark a background run as started and queue its first batch.
	 */
	private static function begin_background_run(): void {
		$stored            = get_option( self::OPTION, [] );
		$stored            = is_array( $stored ) ? $stored : [];
		$stored['running'] = [
			'ids'     => [],
			'members' => 0,
			'started' => time(),
		];
		update_option( self::OPTION, $stored, false );

		as_enqueue_async_action( self::ACTION, [ [ 'after' => 0 ] ], self::GROUP );
	}

	/**
	 * Action Scheduler callback: a scheduled start, or the next batch of a run.
	 *
	 * @param mixed $args `['after' => int]` for a batch; anything else starts a run.
	 */
	public function handle_action( $args = null ): void {
		if ( ! is_array( $args ) || ! isset( $args['after'] ) ) {
			// The recurring action: start a fresh run unless one is underway, or the active
			// environment's schedule is off (e.g. it was scheduled for the other environment).
			if ( 'off' !== self::schedule() && ! self::is_running() && self::local_org() ) {
				self::begin_background_run();
			}
			return;
		}

		$org    = self::local_org();
		$stored = get_option( self::OPTION, [] );
		if ( ! $org || ! is_array( $stored ) || empty( $stored['running'] ) ) {
			return;
		}

		$size  = self::batch_size();
		$run   = $stored['running'];
		$after = (int) $args['after'];
		do {
			$batch          = self::check_batch( (int) $org->id, $after, $size );
			$run['ids']     = array_merge( (array) $run['ids'], array_keys( $batch['out_of_sync'] ) );
			$run['members'] = (int) $run['members'] + $batch['rows'];
			$after          = $batch['last_id'];

			if ( $batch['rows'] === $size && self::has_action_scheduler() ) {
				$stored['running'] = $run;
				update_option( self::OPTION, $stored, false );
				as_enqueue_async_action( self::ACTION, [ [ 'after' => $after ] ], self::GROUP );
				return;
			}
			// Action Scheduler went away mid-run: finish the rest in this request.
		} while ( $batch['rows'] === $size );

		self::store( $run['ids'], (int) $run['members'] );
	}

	/**
	 * Save a finished run.
	 *
	 * @param int[] $ids     Out-of-sync user IDs.
	 * @param int   $members Members checked.
	 */
	private static function store( array $ids, int $members ): void {
		update_option(
			self::OPTION,
			self::context() + [
				'ids'        => array_values( array_unique( array_map( 'intval', $ids ) ) ),
				'members'    => $members,
				'checked_at' => time(),
			],
			false
		);
	}

	/**
	 * Recheck one user after their role or membership changed, so the stored result
	 * stays right between runs. Does nothing until a run has finished.
	 *
	 * @param int $user_id WP user ID.
	 */
	public function recheck_user( $user_id ): void {
		$user_id = (int) $user_id;
		$result  = self::result();
		$org     = self::local_org();
		if ( ! $result || ! $org || $user_id <= 0 ) {
			return;
		}

		$batch = self::check_batch( (int) $org->id, 0, 1, $user_id );
		$ids   = array_diff( $result['ids'], [ $user_id ] );
		if ( isset( $batch['out_of_sync'][ $user_id ] ) ) {
			$ids[] = $user_id;
		}

		$stored        = get_option( self::OPTION, [] );
		$stored['ids'] = array_values( array_map( 'intval', $ids ) );
		update_option( self::OPTION, $stored, false );
	}

	/**
	 * Drop a deleted user from the stored result.
	 *
	 * @param int $user_id WP user ID.
	 */
	public function forget_user( $user_id ): void {
		$stored = get_option( self::OPTION, [] );
		if ( ! is_array( $stored ) || empty( $stored['ids'] ) ) {
			return;
		}

		$stored['ids'] = array_values( array_diff( array_map( 'intval', $stored['ids'] ), [ (int) $user_id ] ) );
		update_option( self::OPTION, $stored, false );
	}

	/**
	 * The schedule saved for the active environment ('off', 'hourly' or 'daily').
	 *
	 * @return string
	 */
	public static function schedule(): string {
		$schedule = (string) workos()->option( 'role_sync_schedule', 'off' );
		return isset( self::SCHEDULES[ $schedule ] ) ? $schedule : 'off';
	}

	/**
	 * Settings updated (update_option_workos_{env}): follow a changed schedule.
	 *
	 * @param mixed  $old_value Previous settings.
	 * @param mixed  $value     New settings.
	 * @param string $option    Option name.
	 */
	public static function on_settings_updated( $old_value, $value, $option ): void {
		$old = is_array( $old_value ) ? ( $old_value['role_sync_schedule'] ?? 'off' ) : 'off';
		$new = is_array( $value ) ? ( $value['role_sync_schedule'] ?? 'off' ) : 'off';
		if ( $old !== $new ) {
			self::sync_schedule( (string) $option, (string) $new );
		}
	}

	/**
	 * Settings created (add_option_workos_{env}).
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Settings.
	 */
	public static function on_settings_added( $option, $value ): void {
		self::sync_schedule( (string) $option, (string) ( is_array( $value ) ? ( $value['role_sync_schedule'] ?? 'off' ) : 'off' ) );
	}

	/**
	 * Make the recurring Action Scheduler action match a newly saved schedule. Takes the
	 * saved value from the option hook, since the settings object may still hold the old one.
	 * Runs when the settings are saved, never on a normal page load.
	 *
	 * @param string $option   The option that was saved (workos_production or workos_staging).
	 * @param string $schedule The schedule saved in it.
	 */
	private static function sync_schedule( string $option, string $schedule ): void {
		if ( ! self::has_action_scheduler() || 'workos_' . Config::get_active_environment() !== $option ) {
			return;
		}

		// Only the recurring action has no arguments; batches of a running check are left alone.
		as_unschedule_all_actions( self::ACTION, [], self::GROUP );

		$interval = self::SCHEDULES[ $schedule ] ?? 0;
		if ( $interval > 0 ) {
			as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, $interval, self::ACTION, [], self::GROUP );
		}
	}
}
