<?php
/**
 * Bidirectional user synchronization.
 *
 * @package WorkOS\Sync
 */

namespace WorkOS\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Handles user data sync between WorkOS and WordPress.
 */
class UserSync {

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
	 * @param array $workos_user WorkOS user data.
	 *
	 * @return \WP_User|\WP_Error
	 */
	public static function find_or_create_wp_user( array $workos_user ) {
		$workos_id = $workos_user['id'] ?? '';
		$email     = $workos_user['email'] ?? '';

		if ( empty( $workos_id ) || empty( $email ) ) {
			return new \WP_Error(
				'workos_invalid_user',
				__( 'WorkOS user data is missing required fields.', 'workos' )
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
			self::link_user( $existing->ID, $workos_user );
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

		self::link_user( $user_id, $workos_user );

		return get_user_by( 'id', $user_id );
	}

	/**
	 * Link a WP user to a WorkOS user.
	 *
	 * @param int   $wp_user_id  WP user ID.
	 * @param array $workos_user WorkOS user data.
	 */
	public static function link_user( int $wp_user_id, array $workos_user ): void {
		update_user_meta( $wp_user_id, '_workos_user_id', $workos_user['id'] );
		update_user_meta( $wp_user_id, '_workos_last_synced_at', current_time( 'mysql', true ) );
		update_user_meta( $wp_user_id, '_workos_profile_hash', self::hash_profile( $workos_user ) );

		if ( ! empty( $workos_user['organization_id'] ) ) {
			update_user_meta( $wp_user_id, '_workos_org_id', $workos_user['organization_id'] );
		}
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
			self::link_user( $user_id, $result );
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
			return new \WP_Error( 'workos_sync_in_progress', __( 'A sync operation is already in progress.', 'workos' ) );
		}

		if ( ! workos()->is_enabled() ) {
			return new \WP_Error( 'workos_not_configured', __( 'WorkOS is not configured.', 'workos' ) );
		}

		$workos_id = get_user_meta( $user_id, '_workos_user_id', true );
		if ( $workos_id ) {
			return new \WP_Error( 'workos_already_linked', __( 'User is already linked to WorkOS.', 'workos' ) );
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return new \WP_Error( 'workos_user_not_found', __( 'WordPress user not found.', 'workos' ) );
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
			self::link_user( $user_id, $result );
		}

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

		// Check if profile actually changed.
		$current_hash = get_user_meta( $wp_user_id, '_workos_profile_hash', true );
		$new_hash     = self::hash_profile( $workos_user );

		if ( $current_hash === $new_hash ) {
			return;
		}

		self::update_wp_user_from_workos( $wp_user_id, $workos_user );
	}

	/**
	 * Handle user.created webhook: provision WP user.
	 *
	 * @param array $event Webhook event.
	 */
	public function handle_user_created( array $event ): void {
		self::$syncing = true;

		$workos_user = $event['data'] ?? [];
		self::find_or_create_wp_user( $workos_user );

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

		self::$syncing = false;
	}

	/**
	 * Deprovision a user based on the configured action.
	 *
	 * @param int $wp_user_id WP user ID.
	 */
	public static function deprovision_user( int $wp_user_id ): void {
		$global = \WorkOS\App::container()->get( \WorkOS\Options\Global_Options::class );
		$action = $global->get( 'deprovision_action', 'deactivate' );

		switch ( $action ) {
			case 'delete':
				$reassign = (int) $global->get( 'reassign_user', 0 );
				wp_delete_user( $wp_user_id, $reassign ? $reassign : null );
				break;

			case 'demote':
				$user = get_user_by( 'id', $wp_user_id );
				if ( $user ) {
					$user->set_role( 'subscriber' );
				}
				update_user_meta( $wp_user_id, '_workos_deactivated', true );
				break;

			case 'deactivate':
			default:
				update_user_meta( $wp_user_id, '_workos_deactivated', true );
				break;
		}
	}

	/**
	 * Look up a WP user ID by their linked WorkOS user ID.
	 *
	 * @param string $workos_id WorkOS user ID.
	 *
	 * @return int|null WP user ID or null.
	 */
	public static function get_wp_user_id_by_workos_id( string $workos_id ): ?int {
		$users = get_users(
			[
				'meta_key'   => '_workos_user_id',
				'meta_value' => $workos_id,
				'number'     => 1,
				'fields'     => 'ID',
			]
		);

		return ! empty( $users ) ? (int) $users[0] : null;
	}

	/**
	 * Generate a unique username from WorkOS user data.
	 *
	 * @param array $workos_user WorkOS user data.
	 *
	 * @return string
	 */
	private static function generate_username( array $workos_user ): string {
		$email = $workos_user['email'] ?? '';
		$base  = sanitize_user( strtok( $email, '@' ), true );

		if ( ! $base ) {
			$base = 'workos_user';
		}

		$username = $base;
		$counter  = 1;

		while ( username_exists( $username ) ) {
			$username = $base . '_' . $counter;
			++$counter;
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
