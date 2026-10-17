<?php
/**
 * Bidirectional user synchronization.
 *
 * @package WorkOS\Sync
 */

namespace WorkOS\Sync;

use WorkOS\ActivityLog\EventLogger;
use WorkOS\Auth\ChangeEmail\RestApi as ChangeEmailRestApi;
use WorkOS\Config;
use WorkOS\Organization\Manager as OrganizationManager;
use WorkOS\Webhook\EventGuard;
use WP_Session_Tokens;

defined( 'ABSPATH' ) || exit;

/**
 * Handles user data sync between WorkOS and WordPress.
 */
class UserSync {

	/**
	 * User meta: `updated_at` of the last WorkOS user payload applied to this account.
	 */
	public const META_UPDATED_AT = '_workos_updated_at';

	/**
	 * User meta holding the most recent refused re-link attempt, shown on the
	 * user's profile. Cleared when the account is linked to a new identity.
	 */
	public const META_LINK_REFUSED = '_workos_link_refused';

	/**
	 * User meta: hash of the WorkOS ID and site metadata last sent, so an
	 * unchanged set costs no API call. Only stored once a site adds its own keys.
	 */
	public const META_METADATA_HASH = '_workos_metadata_hash';

	/**
	 * WorkOS metadata limits, per https://workos.com/docs/authkit/metadata:
	 * up to 10 key-value pairs per user, keys up to 40 characters and values
	 * up to 600 characters, ASCII only.
	 */
	public const METADATA_MAX_KEYS      = 10;
	public const METADATA_MAX_KEY_LEN   = 40;
	public const METADATA_MAX_VALUE_LEN = 600;

	/**
	 * Flag to prevent infinite sync loops.
	 *
	 * @var bool
	 */
	private static bool $syncing = false;

	/**
	 * Constructor — registers WP->WorkOS sync hooks.
	 */
	public function __construct() {
		// WP -> WorkOS: push profile updates and new user creation.
		add_action( 'profile_update', [ $this, 'push_profile_to_workos' ], 10, 3 );
		add_action( 'user_register', [ $this, 'push_user_to_workos' ], 10, 2 );

		// WorkOS -> WP: webhook handlers (dot notation matches WorkOS webhook event names).
		add_action( 'workos_webhook_user.updated', [ $this, 'handle_user_updated' ] );
		add_action( 'workos_webhook_user.created', [ $this, 'handle_user_created' ] );
		add_action( 'workos_webhook_user.deleted', [ $this, 'handle_user_deleted' ] );

		// Sync platform metadata on login.
		add_action( 'workos_user_authenticated', [ $this, 'sync_metadata_on_login' ], 10, 2 );

		// Push the site's own metadata keys when a profile is saved.
		add_action( 'profile_update', [ $this, 'sync_metadata_on_profile_update' ], 20 );
	}

	/**
	 * Check if a sync operation is currently in progress.
	 *
	 * @return bool
	 */
	public static function is_syncing(): bool {
		return self::$syncing;
	}

	/**
	 * Find an existing WP user by WorkOS ID or email, or create a new one.
	 *
	 * With Re-link Protection on, an email match on a WP user that is already
	 * linked to a different WorkOS user returns a `workos_link_conflict`
	 * error instead of that WP user.
	 *
	 * @param array  $workos_user WorkOS user data.
	 * @param string $source      What is asking, for logs (e.g. 'login', 'webhook').
	 *
	 * @return \WP_User|\WP_Error
	 */
	public static function find_or_create_wp_user( array $workos_user, string $source = '' ) {
		$workos_id = $workos_user['id'] ?? '';
		$email     = $workos_user['email'] ?? '';

		if ( empty( $workos_id ) || empty( $email ) ) {
			return new \WP_Error(
				'workos_invalid_user',
				__( 'WorkOS user data is missing required fields.', 'integration-workos' )
			);
		}

		// 1. Check if already linked by WorkOS ID.
		$linked_user_id = self::get_wp_user_id_by_workos_id( $workos_id );
		if ( $linked_user_id ) {
			$user = get_user_by( 'id', $linked_user_id );
			if ( $user ) {
				self::update_wp_user_from_workos( $user->ID, $workos_user );
				return $user;
			}
		}

		// 2. Try email match (auto-link).
		$existing = get_user_by( 'email', $email );
		if ( $existing ) {
			if ( ! self::link_user( $existing->ID, $workos_user, $source ) ) {
				return new \WP_Error(
					'workos_link_conflict',
					__( 'This account is already connected to a different sign-in. Contact support to connect it.', 'integration-workos' ),
					[ 'status' => 409 ]
				);
			}

			return $existing;
		}

		// 3. Create new WP user.
		$username = self::generate_username( $workos_user );
		$user_id  = wp_insert_user(
			[
				'user_login'   => $username,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 64, true, true ),
				'first_name'   => $workos_user['first_name'] ?? '',
				'last_name'    => $workos_user['last_name'] ?? '',
				'display_name' => self::build_display_name( $workos_user ),
				'role'         => get_option( 'default_role', 'subscriber' ),
			]
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		self::link_user( $user_id, $workos_user, $source );

		update_user_meta( $user_id, '_workos_first_login', '1' );

		/**
		 * Fires when a brand-new WP user is created via WorkOS authentication.
		 *
		 * Does NOT fire for email-match auto-links.
		 *
		 * @param int   $user_id     WP user ID.
		 * @param array $workos_user WorkOS user data.
		 */
		do_action( 'workos_user_created', $user_id, $workos_user );

		return get_user_by( 'id', $user_id );
	}

	/**
	 * Link a WP user to a WorkOS user.
	 *
	 * Every path that links goes through here. When the WP user is already
	 * linked to a different WorkOS user and Re-link Protection is on, the link
	 * is refused: nothing changes, the attempt is logged and recorded on the
	 * user, and `workos_user_link_refused` fires. `$force` is for explicit
	 * reassignment by an administrator (`wp workos user link --force`).
	 *
	 * One WorkOS user maps to one WP user: with `$force` or Re-link Protection on,
	 * a WorkOS ID that is already linked to a different WP user is never linked
	 * again, and this returns false. With both off, that check is the caller's
	 * (the sign-in paths look the ID up first, in `find_or_create_wp_user()`).
	 *
	 * @param int    $wp_user_id  WP user ID.
	 * @param array  $workos_user WorkOS user data.
	 * @param string $source      What is linking, for logs (e.g. 'login', 'webhook', 'cli_link').
	 * @param bool   $force       Replace an existing link to a different WorkOS user.
	 *
	 * @return bool True when the user is linked to `$workos_user`, false when refused.
	 */
	public static function link_user( int $wp_user_id, array $workos_user, string $source = '', bool $force = false ): bool {
		$new_id = (string) ( $workos_user['id'] ?? '' );
		if ( '' === $new_id ) {
			return false;
		}

		$previous_id = (string) get_user_meta( $wp_user_id, '_workos_user_id', true );
		$is_relink   = '' !== $previous_id && $previous_id !== $new_id;

		$protected = Config::is_relink_protection_enabled();

		if ( $is_relink && ! $force && $protected ) {
			self::refuse_link( $wp_user_id, $previous_id, $workos_user, $source );
			return false;
		}

		if ( $force || $protected ) {
			$holder = self::get_wp_user_id_by_workos_id( $new_id );
			if ( $holder && $holder !== $wp_user_id ) {
				workos_log( sprintf( 'Refused to link WP user #%d to WorkOS user %s: already linked to WP user #%d (source: %s).', $wp_user_id, $new_id, $holder, $source ? $source : 'unknown' ), 'warning' );
				return false;
			}
		}

		update_user_meta( $wp_user_id, '_workos_user_id', $new_id );
		update_user_meta( $wp_user_id, '_workos_last_synced_at', current_time( 'mysql', true ) );
		update_user_meta( $wp_user_id, '_workos_profile_hash', self::hash_profile( $workos_user ) );

		if ( ! empty( $workos_user['organization_id'] ) ) {
			update_user_meta( $wp_user_id, '_workos_org_id', $workos_user['organization_id'] );
		}

		if ( $previous_id !== $new_id ) {
			delete_user_meta( $wp_user_id, self::META_LINK_REFUSED );

			if ( $is_relink ) {
				workos_log( sprintf( 'Re-linked WP user #%d from WorkOS user %s to %s (source: %s).', $wp_user_id, $previous_id, $new_id, $source ? $source : 'unknown' ), 'warning' );
			}

			/**
			 * Fires when a WP user is linked to a WorkOS user it wasn't linked to before.
			 *
			 * Covers first links and re-links; doesn't fire when the same link is
			 * written again. Use it to resume work that waited for an identity.
			 *
			 * @param int    $wp_user_id  WP user ID.
			 * @param string $workos_id   WorkOS user ID now linked.
			 * @param string $previous_id WorkOS user ID linked before, or '' for a first link.
			 * @param string $source      What linked (e.g. 'login', 'webhook', 'cli_link').
			 */
			do_action( 'workos_user_linked', $wp_user_id, $new_id, $previous_id, $source );
		}

		return true;
	}

	/**
	 * Record a refused re-link.
	 *
	 * @param int    $wp_user_id  WP user ID.
	 * @param string $current_id  WorkOS user ID the WP user keeps.
	 * @param array  $workos_user WorkOS user data that tried to claim it.
	 * @param string $source      What tried to link.
	 */
	private static function refuse_link( int $wp_user_id, string $current_id, array $workos_user, string $source ): void {
		$attempted_id    = (string) ( $workos_user['id'] ?? '' );
		$attempted_email = (string) ( $workos_user['email'] ?? '' );
		$source          = $source ? $source : 'unknown';

		update_user_meta(
			$wp_user_id,
			self::META_LINK_REFUSED,
			[
				'workos_user_id' => $attempted_id,
				'email'          => $attempted_email,
				'source'         => $source,
				'time'           => time(),
			]
		);

		workos_log( sprintf( 'Refused to re-link WP user #%d from WorkOS user %s to %s (source: %s).', $wp_user_id, $current_id, $attempted_id, $source ), 'warning' );

		EventLogger::log(
			'link_refused',
			[
				'user_id'        => $wp_user_id,
				'workos_user_id' => $current_id,
				'metadata'       => [
					'attempted_workos_user_id' => $attempted_id,
					'attempted_email'          => $attempted_email,
					'source'                   => $source,
				],
			]
		);

		/**
		 * Fires when Re-link Protection refuses to move a WP user to a different WorkOS user.
		 *
		 * @param int    $wp_user_id  WP user ID.
		 * @param string $current_id  WorkOS user ID the WP user stays linked to.
		 * @param array  $workos_user WorkOS user data that tried to claim it.
		 * @param string $source      What tried to link (e.g. 'login', 'webhook').
		 */
		do_action( 'workos_user_link_refused', $wp_user_id, $current_id, $workos_user, $source );
	}

	/**
	 * Unlink a WP user from WorkOS.
	 *
	 * @param int $wp_user_id WP user ID.
	 */
	public static function unlink_user( int $wp_user_id ): void {
		delete_user_meta( $wp_user_id, '_workos_user_id' );
		delete_user_meta( $wp_user_id, '_workos_org_id' );
		delete_user_meta( $wp_user_id, '_workos_last_synced_at' );
		delete_user_meta( $wp_user_id, '_workos_profile_hash' );
		delete_user_meta( $wp_user_id, '_workos_access_token' );
		delete_user_meta( $wp_user_id, '_workos_refresh_token' );
		delete_user_meta( $wp_user_id, '_workos_deactivated' );
		delete_user_meta( $wp_user_id, self::META_LINK_REFUSED );
		delete_user_meta( $wp_user_id, self::META_METADATA_HASH );
	}

	/**
	 * Push a WP profile update to WorkOS.
	 *
	 * @param int      $user_id  WP user ID.
	 * @param \WP_User $old_data Old user data.
	 * @param array    $userdata New user data.
	 */
	public function push_profile_to_workos( int $user_id, \WP_User $old_data, array $userdata ): void {
		if ( self::$syncing ) {
			return;
		}

		$workos_id = get_user_meta( $user_id, '_workos_user_id', true );
		if ( ! $workos_id ) {
			return;
		}

		// Race guard for the WP-side email-change flow. The confirm handler
		// pushes to WorkOS itself and then mirrors with wp_update_user(),
		// which fires `profile_update` and lands us here. Without this guard
		// we'd issue a second, redundant update_user() to WorkOS on the
		// latency-sensitive confirm path. Same transient the webhook handler
		// checks; see ChangeEmail\RestApi::confirm().
		if ( get_transient( ChangeEmailRestApi::TRANSIENT_PREFIX . $user_id ) ) {
			return;
		}

		$update = [];

		if ( isset( $userdata['first_name'] ) && $userdata['first_name'] !== $old_data->first_name ) {
			$update['first_name'] = $userdata['first_name'];
		}
		if ( isset( $userdata['last_name'] ) && $userdata['last_name'] !== $old_data->last_name ) {
			$update['last_name'] = $userdata['last_name'];
		}
		if ( isset( $userdata['user_email'] ) && $userdata['user_email'] !== $old_data->user_email ) {
			$update['email'] = $userdata['user_email'];
		}

		if ( empty( $update ) ) {
			return;
		}

		workos()->api()->update_user( $workos_id, $update );
		update_user_meta( $user_id, '_workos_last_synced_at', current_time( 'mysql', true ) );
	}

	/**
	 * Push a newly created WP user to WorkOS.
	 *
	 * @param int   $user_id  WP user ID.
	 * @param array $userdata Data passed to wp_insert_user().
	 */
	public function push_user_to_workos( int $user_id, array $userdata ): void {
		if ( self::$syncing ) {
			return;
		}

		if ( ! workos()->is_enabled() ) {
			return;
		}

		$email = $userdata['user_email'] ?? '';
		if ( empty( $email ) ) {
			return;
		}

		$payload = [
			'email'          => $email,
			'email_verified' => true,
		];

		if ( ! empty( $userdata['first_name'] ) ) {
			$payload['first_name'] = $userdata['first_name'];
		}
		if ( ! empty( $userdata['last_name'] ) ) {
			$payload['last_name'] = $userdata['last_name'];
		}

		$result = workos()->api()->create_user( $payload );

		if ( is_wp_error( $result ) ) {
			workos_log( 'Failed to create user in WorkOS: ' . $result->get_error_message(), 'error' );
			return;
		}

		if ( ! empty( $result['id'] ) ) {
			self::link_user( $user_id, $result, 'user_register' );
			self::ensure_workos_org_membership( $result['id'] );
			self::update_platform_metadata( $result['id'], $user_id );
		}
	}

	/**
	 * Sync an existing WordPress user to WorkOS.
	 *
	 * Creates the user in WorkOS and links the accounts.
	 * Intended for manual sync of users that were created before the plugin was active.
	 *
	 * @param int $user_id WordPress user ID.
	 *
	 * @return array|\WP_Error WorkOS user data on success, WP_Error on failure.
	 */
	public static function sync_existing_user( int $user_id ) {
		if ( self::$syncing ) {
			return new \WP_Error( 'workos_sync_in_progress', __( 'A sync operation is already in progress.', 'integration-workos' ) );
		}

		if ( ! workos()->is_enabled() ) {
			return new \WP_Error( 'workos_not_configured', __( 'WorkOS is not configured.', 'integration-workos' ) );
		}

		$workos_id = get_user_meta( $user_id, '_workos_user_id', true );
		if ( $workos_id ) {
			return new \WP_Error( 'workos_already_linked', __( 'User is already linked to WorkOS.', 'integration-workos' ) );
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return new \WP_Error( 'workos_user_not_found', __( 'WordPress user not found.', 'integration-workos' ) );
		}

		$payload = [
			'email'          => $user->user_email,
			'email_verified' => true,
		];

		if ( ! empty( $user->first_name ) ) {
			$payload['first_name'] = $user->first_name;
		}
		if ( ! empty( $user->last_name ) ) {
			$payload['last_name'] = $user->last_name;
		}

		self::$syncing = true;
		$result        = workos()->api()->create_user( $payload );
		self::$syncing = false;

		if ( is_wp_error( $result ) ) {
			workos_log( 'Failed to sync existing user #' . $user_id . ' to WorkOS: ' . $result->get_error_message(), 'error' );
			return $result;
		}

		if ( ! empty( $result['id'] ) ) {
			self::link_user( $user_id, $result, 'manual_sync' );
			self::ensure_workos_org_membership( $result['id'] );
			self::update_platform_metadata( $result['id'], $user_id );
		}

		return $result;
	}

	/**
	 * Re-sync a linked WordPress user from WorkOS.
	 *
	 * Fetches the latest profile data from WorkOS and updates the local WP user.
	 * Intended for manual re-sync when profile data drifts or webhooks are missed.
	 *
	 * @param int $user_id WordPress user ID.
	 *
	 * @return array|\WP_Error WorkOS user data on success, WP_Error on failure.
	 */
	public static function resync_from_workos( int $user_id ) {
		if ( self::$syncing ) {
			return new \WP_Error( 'workos_sync_in_progress', __( 'A sync operation is already in progress.', 'integration-workos' ) );
		}

		if ( ! workos()->is_enabled() ) {
			return new \WP_Error( 'workos_not_configured', __( 'WorkOS is not configured.', 'integration-workos' ) );
		}

		$workos_id = get_user_meta( $user_id, '_workos_user_id', true );
		if ( ! $workos_id ) {
			return new \WP_Error( 'workos_not_linked', __( 'User is not linked to WorkOS.', 'integration-workos' ) );
		}

		$result = workos()->api()->get_user( $workos_id );

		if ( is_wp_error( $result ) ) {
			workos_log( 'Failed to re-sync user #' . $user_id . ' from WorkOS: ' . $result->get_error_message(), 'error' );
			return $result;
		}

		self::update_wp_user_from_workos( $user_id, $result );

		return $result;
	}

	/**
	 * Handle user.updated webhook: sync WorkOS -> WP.
	 *
	 * @param array $event Webhook event.
	 */
	public function handle_user_updated( array $event ): void {
		$workos_user = $event['data'] ?? [];
		$workos_id   = $workos_user['id'] ?? '';

		if ( empty( $workos_id ) ) {
			return;
		}

		$wp_user_id = self::get_wp_user_id_by_workos_id( $workos_id );
		if ( ! $wp_user_id ) {
			return;
		}

		// Race guard for the WP-side email-change flow: while our confirm
		// handler is mid-commit it writes to WorkOS first and then mirrors
		// the change into WP. The webhook fan-back from that WorkOS write
		// would otherwise race the local wp_update_user() and could
		// re-trigger the very mutation that already happened. The transient
		// is set in ChangeEmail\RestApi::confirm() and cleared once the
		// local write succeeds (or on rollback).
		if ( get_transient( ChangeEmailRestApi::TRANSIENT_PREFIX . $wp_user_id ) ) {
			return;
		}

		// A retried or late event older than what's already applied would roll the
		// profile back.
		$last_applied = get_user_meta( $wp_user_id, self::META_UPDATED_AT, true );
		if ( EventGuard::is_stale( $workos_user['updated_at'] ?? '', $last_applied ) ) {
			return;
		}

		// Check if profile actually changed.
		$current_hash = get_user_meta( $wp_user_id, '_workos_profile_hash', true );
		$new_hash     = self::hash_profile( $workos_user );

		if ( $current_hash === $new_hash ) {
			self::record_updated_at( $wp_user_id, $workos_user );
			return;
		}

		self::update_wp_user_from_workos( $wp_user_id, $workos_user );
	}

	/**
	 * Remember the newest WorkOS `updated_at` applied to this account.
	 *
	 * @param int   $wp_user_id  WP user ID.
	 * @param array $workos_user WorkOS user payload.
	 */
	private static function record_updated_at( int $wp_user_id, array $workos_user ): void {
		$current = (string) get_user_meta( $wp_user_id, self::META_UPDATED_AT, true );
		$latest  = EventGuard::latest( $workos_user['updated_at'] ?? '', $current );
		if ( '' !== $latest && $latest !== $current ) {
			update_user_meta( $wp_user_id, self::META_UPDATED_AT, $latest );
		}
	}

	/**
	 * Handle user.created webhook: provision WP user.
	 *
	 * @param array $event Webhook event.
	 */
	public function handle_user_created( array $event ): void {
		self::$syncing = true;

		$workos_user = $event['data'] ?? [];
		$wp_user     = self::find_or_create_wp_user( $workos_user, 'webhook' );

		if ( ! is_wp_error( $wp_user ) && $wp_user instanceof \WP_User ) {
			OrganizationManager::ensure_user_org_membership( $wp_user->ID, [] );
			self::update_platform_metadata( $workos_user['id'] ?? '', $wp_user->ID );
		}

		self::$syncing = false;
	}

	/**
	 * Handle user.deleted webhook: deprovision WP user.
	 *
	 * @param array $event Webhook event.
	 */
	public function handle_user_deleted( array $event ): void {
		$workos_user = $event['data'] ?? [];
		$workos_id   = $workos_user['id'] ?? '';

		if ( empty( $workos_id ) ) {
			return;
		}

		$wp_user_id = self::get_wp_user_id_by_workos_id( $workos_id );
		if ( ! $wp_user_id ) {
			return;
		}

		self::deprovision_user( $wp_user_id );
	}

	// -------------------------------------------------------------------------
	// WorkOS org membership + platform metadata
	// -------------------------------------------------------------------------

	/**
	 * Ensure a WorkOS user has an organization membership for the configured org.
	 *
	 * Creates the membership via API if none exists. Logs errors silently.
	 *
	 * @param string $workos_user_id WorkOS user ID.
	 */
	public static function ensure_workos_org_membership( string $workos_user_id ): void {
		if ( empty( $workos_user_id ) ) {
			return;
		}

		$org_id = Config::get_organization_id();
		if ( empty( $org_id ) ) {
			return;
		}

		$wp_user_id = self::get_wp_user_id_by_workos_id( $workos_user_id );

		// Check if the user already has a membership in this org.
		$memberships = workos()->api()->list_organization_memberships(
			[
				'user_id'         => $workos_user_id,
				'organization_id' => $org_id,
			]
		);

		if ( ! is_wp_error( $memberships ) && ! empty( $memberships['data'] ) ) {
			// Store the existing membership ID locally.
			$membership_id = $memberships['data'][0]['id'] ?? '';
			if ( $wp_user_id && ! empty( $membership_id ) ) {
				OrganizationManager::store_membership_id( $wp_user_id, $org_id, $membership_id );
			}
			return;
		}

		$result = workos()->api()->create_organization_membership( $workos_user_id, $org_id );

		if ( is_wp_error( $result ) ) {
			workos_log( 'Failed to create org membership for WorkOS user ' . $workos_user_id . ': ' . $result->get_error_message(), 'error' );
			return;
		}

		// Store the newly created membership ID locally.
		$membership_id = $result['id'] ?? '';
		if ( $wp_user_id && ! empty( $membership_id ) ) {
			OrganizationManager::store_membership_id( $wp_user_id, $org_id, $membership_id );
		}
	}

	/**
	 * Write the WP user ID, and any keys the site adds, to WorkOS user metadata.
	 *
	 * Key format: puid:{site_key} — allows multiple WP sites to share one WorkOS tenant.
	 * Fetches existing metadata first to preserve other sites' keys. Keys from the
	 * `workos_user_metadata` filter are merged in; once a site adds keys, an
	 * unchanged set is skipped without an API call (see META_METADATA_HASH).
	 *
	 * @param string $workos_user_id WorkOS user ID.
	 * @param int    $wp_user_id     WordPress user ID.
	 */
	public static function update_platform_metadata( string $workos_user_id, int $wp_user_id ): void {
		if ( empty( $workos_user_id ) || empty( $wp_user_id ) ) {
			return;
		}

		self::write_metadata( $workos_user_id, $wp_user_id, self::build_metadata( $wp_user_id, $workos_user_id ), false );
	}

	/**
	 * Write a linked user's metadata to WorkOS now, without waiting for a
	 * sign-in or profile save. Backs `workos_sync_user_metadata()`.
	 *
	 * Always reads the WorkOS user (the stored hash is ignored), so it also
	 * restores keys that were removed outside WordPress.
	 *
	 * @param int $wp_user_id WordPress user ID.
	 *
	 * @return bool|\WP_Error True when metadata was written, false when WorkOS already had it.
	 */
	public static function sync_user_metadata( int $wp_user_id ) {
		if ( ! workos()->is_enabled() ) {
			return new \WP_Error( 'workos_not_configured', __( 'WorkOS is not configured.', 'integration-workos' ) );
		}

		$workos_id = (string) get_user_meta( $wp_user_id, '_workos_user_id', true );
		if ( '' === $workos_id ) {
			return new \WP_Error( 'workos_not_linked', __( 'User is not linked to WorkOS.', 'integration-workos' ) );
		}

		return self::write_metadata( $workos_id, $wp_user_id, self::build_metadata( $wp_user_id, $workos_id ), true );
	}

	/**
	 * Write the site's own metadata keys when a profile is saved.
	 *
	 * Hooked to `profile_update`. Name and email go out in
	 * `push_profile_to_workos()`; this covers only keys from the
	 * `workos_user_metadata` filter, so a site without any makes no call here.
	 *
	 * @param int $user_id WP user ID.
	 */
	public function sync_metadata_on_profile_update( int $user_id ): void {
		if ( self::$syncing || ! workos()->is_enabled() ) {
			return;
		}

		$workos_id = (string) get_user_meta( $user_id, '_workos_user_id', true );
		if ( '' === $workos_id ) {
			return;
		}

		$metadata = self::build_metadata( $user_id, $workos_id );
		if ( count( $metadata ) < 2 ) {
			return;
		}

		self::write_metadata( $workos_id, $user_id, $metadata, false );
	}

	/**
	 * The plugin's own metadata key for this site.
	 *
	 * @return string
	 */
	private static function platform_metadata_key(): string {
		return 'puid:' . Config::get_site_key();
	}

	/**
	 * Build the metadata this site writes for a user: the `puid:` key plus
	 * whatever `workos_user_metadata` adds, minus entries WorkOS would reject.
	 *
	 * @param int    $wp_user_id     WordPress user ID.
	 * @param string $workos_user_id WorkOS user ID.
	 *
	 * @return array<string, string> The `puid:` key first, then the site's keys.
	 */
	private static function build_metadata( int $wp_user_id, string $workos_user_id ): array {
		$puid_key = self::platform_metadata_key();
		$metadata = [ $puid_key => (string) $wp_user_id ];

		/**
		 * Filters the metadata this site writes to the user's WorkOS record.
		 *
		 * Add your own IDs (a billing customer number, a legacy account ID) so
		 * other apps that sign in through the same WorkOS environment can read
		 * them. Returned keys are merged with the metadata already on the user;
		 * keys you stop returning are left in place, not deleted.
		 *
		 * The plugin's `puid:{site}` key can't be changed or removed, and other
		 * `puid:` keys are reserved. Entries over WorkOS's limits (10 keys per
		 * user, keys up to 40 characters, values up to 600, ASCII only) are
		 * skipped and logged; the rest are still written. Values must be
		 * strings or integers.
		 *
		 * @param array  $metadata   Keys this site writes, starting with the plugin's `puid:{site}` key.
		 * @param int    $wp_user_id WP user ID.
		 * @param string $workos_id  WorkOS user ID.
		 */
		$filtered = apply_filters( 'workos_user_metadata', $metadata, $wp_user_id, $workos_user_id );

		if ( ! is_array( $filtered ) ) {
			workos_log( sprintf( 'workos_user_metadata returned %s for WP user #%d, not an array; writing only the plugin key.', gettype( $filtered ), $wp_user_id ), 'error' );
			return $metadata;
		}

		foreach ( $filtered as $key => $value ) {
			if ( $key === $puid_key ) {
				continue;
			}

			$error = self::metadata_entry_error( $key, $value );
			if ( null !== $error ) {
				workos_log( sprintf( 'Skipped WorkOS metadata key "%s" for WP user #%d: %s.', $key, $wp_user_id, $error ), 'error' );
				continue;
			}

			$metadata[ $key ] = (string) $value;
		}

		return $metadata;
	}

	/**
	 * Why WorkOS would reject one metadata entry, or null when it's fine.
	 *
	 * @param int|string $key   Metadata key.
	 * @param mixed      $value Metadata value.
	 *
	 * @return string|null
	 */
	private static function metadata_entry_error( $key, $value ): ?string {
		if ( ! is_string( $key ) || '' === $key ) {
			return 'keys must be non-empty, non-numeric strings';
		}
		if ( 0 === strpos( $key, 'puid:' ) ) {
			return 'the puid: prefix is reserved for the plugin';
		}
		if ( strlen( $key ) > self::METADATA_MAX_KEY_LEN ) {
			return sprintf( 'key is longer than %d characters', self::METADATA_MAX_KEY_LEN );
		}
		if ( preg_match( '/[^\x00-\x7F]/', $key ) ) {
			return 'key is not ASCII';
		}
		if ( ! is_string( $value ) && ! is_int( $value ) ) {
			return sprintf( 'value is %s; it must be a string or integer', gettype( $value ) );
		}

		$value = (string) $value;
		if ( strlen( $value ) > self::METADATA_MAX_VALUE_LEN ) {
			return sprintf( 'value is longer than %d characters', self::METADATA_MAX_VALUE_LEN );
		}
		if ( preg_match( '/[^\x00-\x7F]/', $value ) ) {
			return 'value is not ASCII';
		}

		return null;
	}

	/**
	 * Merge the site's metadata into the WorkOS user's and write it when it changed.
	 *
	 * @param string                $workos_user_id WorkOS user ID.
	 * @param int                   $wp_user_id     WordPress user ID.
	 * @param array<string, string> $metadata       Keys from build_metadata().
	 * @param bool                  $force          Read WorkOS even when the stored hash matches.
	 *
	 * @return bool|\WP_Error True when a write was sent, false when nothing needed writing.
	 */
	private static function write_metadata( string $workos_user_id, int $wp_user_id, array $metadata, bool $force ) {
		// The hash only applies once the site adds keys of its own, so a site
		// without the filter reads the user on every sync exactly as before.
		$use_hash = count( $metadata ) > 1;
		$hash     = hash( 'sha256', $workos_user_id . '|' . (string) wp_json_encode( $metadata ) );

		if ( $use_hash && ! $force && get_user_meta( $wp_user_id, self::META_METADATA_HASH, true ) === $hash ) {
			return false;
		}

		// Fetch existing user to get current metadata.
		$workos_user = workos()->api()->get_user( $workos_user_id );
		if ( is_wp_error( $workos_user ) ) {
			workos_log( 'Failed to fetch WorkOS user ' . $workos_user_id . ' for metadata update: ' . $workos_user->get_error_message(), 'error' );
			return $workos_user;
		}

		$existing = is_array( $workos_user['metadata'] ?? null ) ? $workos_user['metadata'] : [];
		$merged   = $existing;
		$puid_key = self::platform_metadata_key();
		$changed  = false;

		foreach ( $metadata as $key => $value ) {
			if ( array_key_exists( $key, $merged ) ) {
				if ( ! is_scalar( $merged[ $key ] ) || (string) $merged[ $key ] !== $value ) {
					$merged[ $key ] = $value;
					$changed        = true;
				}
				continue;
			}

			// WorkOS's key limit counts every key on the user, including other
			// sites' and apps'. The plugin's own key is never held back.
			if ( $key !== $puid_key && count( $merged ) >= self::METADATA_MAX_KEYS ) {
				workos_log( sprintf( 'Skipped WorkOS metadata key "%s" for WP user #%d: WorkOS user %s already has %d keys.', $key, $wp_user_id, $workos_user_id, self::METADATA_MAX_KEYS ), 'error' );
				continue;
			}

			$merged[ $key ] = $value;
			$changed        = true;
		}

		if ( $changed ) {
			$result = workos()->api()->update_user( $workos_user_id, [ 'metadata' => $merged ] );

			if ( is_wp_error( $result ) ) {
				workos_log( 'Failed to update metadata for WorkOS user ' . $workos_user_id . ': ' . $result->get_error_message(), 'error' );
				return $result;
			}
		}

		if ( $use_hash ) {
			update_user_meta( $wp_user_id, self::META_METADATA_HASH, $hash );
		}

		return $changed;
	}

	/**
	 * Sync organization membership and platform metadata on login.
	 *
	 * Hooked to `workos_user_authenticated`.
	 *
	 * @param int   $wp_user_id  WordPress user ID.
	 * @param array $auth_result Full WorkOS auth response.
	 */
	public function sync_metadata_on_login( int $wp_user_id, array $auth_result ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$workos_user_id = get_user_meta( $wp_user_id, '_workos_user_id', true );
		if ( empty( $workos_user_id ) ) {
			return;
		}

		self::ensure_workos_org_membership( $workos_user_id );
		self::update_platform_metadata( $workos_user_id, $wp_user_id );
	}

	/**
	 * Recompute and store the profile-change hash for a user from their
	 * current WordPress profile data.
	 *
	 * Used by flows that mutate the user outside the webhook path (e.g. the
	 * email-change confirm handler) so the stored `_workos_profile_hash`
	 * doesn't go stale and trigger a spurious reconciliation on the next
	 * `user.updated` webhook.
	 *
	 * @param int $wp_user_id WP user ID.
	 *
	 * @return void
	 */
	public static function refresh_profile_hash( int $wp_user_id ): void {
		$user = get_user_by( 'id', $wp_user_id );
		if ( ! $user instanceof \WP_User ) {
			return;
		}

		$profile = [
			'email'      => $user->user_email,
			'first_name' => $user->first_name,
			'last_name'  => $user->last_name,
		];

		update_user_meta( $wp_user_id, '_workos_profile_hash', self::hash_profile( $profile ) );
		update_user_meta( $wp_user_id, '_workos_last_synced_at', current_time( 'mysql', true ) );
	}

	/**
	 * Update a WP user from WorkOS data.
	 *
	 * @param int   $wp_user_id  WP user ID.
	 * @param array $workos_user WorkOS user data.
	 */
	private static function update_wp_user_from_workos( int $wp_user_id, array $workos_user ): void {
		self::$syncing = true;

		$update = [ 'ID' => $wp_user_id ];

		if ( ! empty( $workos_user['email'] ) ) {
			$update['user_email'] = $workos_user['email'];
		}
		if ( ! empty( $workos_user['first_name'] ) ) {
			$update['first_name'] = $workos_user['first_name'];
		}
		if ( ! empty( $workos_user['last_name'] ) ) {
			$update['last_name'] = $workos_user['last_name'];
		}

		$display = self::build_display_name( $workos_user );
		if ( $display ) {
			$update['display_name'] = $display;
		}

		wp_update_user( $update );

		update_user_meta( $wp_user_id, '_workos_last_synced_at', current_time( 'mysql', true ) );
		update_user_meta( $wp_user_id, '_workos_profile_hash', self::hash_profile( $workos_user ) );
		self::record_updated_at( $wp_user_id, $workos_user );

		self::$syncing = false;
	}

	/**
	 * Deprovision a user based on the configured action.
	 *
	 * @param int $wp_user_id WP user ID.
	 */
	public static function deprovision_user( int $wp_user_id ): void {
		$action = workos()->option( 'deprovision_action', 'deactivate' );

		switch ( $action ) {
			case 'delete':
				$reassign = (int) workos()->option( 'reassign_user', 0 );
				wp_delete_user( $wp_user_id, $reassign ? $reassign : null );
				break;

			case 'demote':
				$user = get_user_by( 'id', $wp_user_id );
				if ( $user ) {
					$user->set_role( 'subscriber' );
				}
				update_user_meta( $wp_user_id, '_workos_deactivated', true );
				// Sign-in is refused from now on (Auth\Deactivation); end the sessions it already has.
				WP_Session_Tokens::get_instance( $wp_user_id )->destroy_all();
				break;

			case 'deactivate':
			default:
				update_user_meta( $wp_user_id, '_workos_deactivated', true );
				WP_Session_Tokens::get_instance( $wp_user_id )->destroy_all();
				break;
		}
	}

	/**
	 * Look up a WP user ID by their linked WorkOS user ID (indexed; see UserLinks).
	 *
	 * @param string $workos_id WorkOS user ID.
	 *
	 * @return int|null WP user ID or null.
	 */
	public static function get_wp_user_id_by_workos_id( string $workos_id ): ?int {
		return UserLinks::find( $workos_id );
	}

	/**
	 * Generate a unique username from WorkOS user data.
	 *
	 * Uses the email local part as the base and resolves collisions with a
	 * short hash of the full email instead of probing sequential suffixes —
	 * popular shared-mailbox local parts (info, sales, …) build chains deep
	 * enough that a sequential probe walks thousands of users and exhausts
	 * memory. Hashing keeps the lookup count constant regardless of chain
	 * depth, and the same email always derives the same username.
	 *
	 * @param array $workos_user WorkOS user data.
	 *
	 * @return string
	 */
	private static function generate_username( array $workos_user ): string {
		$email = strtolower( trim( (string) ( $workos_user['email'] ?? '' ) ) );
		$base  = sanitize_user( explode( '@', $email, 2 )[0], true );

		if ( ! $base ) {
			$base = 'workos_user';
		}

		// Cap so the widest suffix ('_' + 12 hex) fits user_login's varchar(60).
		$base = substr( $base, 0, 47 );

		if ( ! username_exists( $base ) ) {
			return $base;
		}

		$hash     = hash( 'sha256', $email );
		$username = $base . '_' . substr( $hash, 0, 5 );

		if ( ! username_exists( $username ) ) {
			return $username;
		}

		$username = $base . '_' . substr( $hash, 0, 12 );

		// ~2^-48 territory. The generator is deterministic, so recovery must
		// change the input — salt with an attempt counter, never re-roll.
		for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
			if ( ! username_exists( $username ) ) {
				return $username;
			}

			$username = $base . '_' . substr( hash( 'sha256', $email . '|' . $attempt ), 0, 12 );
		}

		return $username;
	}

	/**
	 * Build a display name from WorkOS user data.
	 *
	 * @param array $workos_user WorkOS user data.
	 *
	 * @return string
	 */
	private static function build_display_name( array $workos_user ): string {
		$parts = array_filter(
			[
				$workos_user['first_name'] ?? '',
				$workos_user['last_name'] ?? '',
			]
		);

		$full_name = implode( ' ', $parts );
		return $full_name ? $full_name : ( $workos_user['email'] ?? '' );
	}

	/**
	 * Hash a WorkOS user profile for change detection.
	 *
	 * @param array $workos_user WorkOS user data.
	 *
	 * @return string SHA-256 hash.
	 */
	private static function hash_profile( array $workos_user ): string {
		$fields = [
			$workos_user['email'] ?? '',
			$workos_user['first_name'] ?? '',
			$workos_user['last_name'] ?? '',
		];

		return hash( 'sha256', implode( '|', $fields ) );
	}
}
