<?php
/**
 * Audit logging bridge — WP events to WorkOS Audit Logs.
 *
 * @package WorkOS\Sync
 */

namespace WorkOS\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Forwards significant WP actions to the WorkOS Audit Logs API.
 */
class AuditLog {

	/**
	 * Constructor — register event hooks.
	 */
	public function __construct() {
		if ( ! get_option( 'workos_audit_logging_enabled', false ) ) {
			return;
		}

		// Authentication events.
		add_action( 'wp_login', [ $this, 'log_login' ], 10, 2 );
		add_action( 'wp_logout', [ $this, 'log_logout' ] );
		add_action( 'wp_login_failed', [ $this, 'log_login_failed' ] );

		// Post events.
		add_action( 'save_post', [ $this, 'log_post_save' ], 10, 3 );
		add_action( 'delete_post', [ $this, 'log_post_delete' ], 10, 2 );

		// User events.
		add_action( 'user_register', [ $this, 'log_user_created' ] );
		add_action( 'delete_user', [ $this, 'log_user_deleted' ] );
		add_action( 'set_user_role', [ $this, 'log_role_change' ], 10, 3 );
	}

	/**
	 * Log a successful login.
	 *
	 * @param string   $user_login Username.
	 * @param \WP_User $user       User object.
	 */
	public function log_login( string $user_login, \WP_User $user ): void {
		$this->create_event(
			'user.logged_in',
			$user->ID,
			[
				'type' => 'session',
				'id'   => 'login',
				'name' => 'User Login',
			],
			[
				'ip_address' => $this->get_client_ip(),
			]
		);
	}

	/**
	 * Log a logout.
	 */
	public function log_logout(): void {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		$this->create_event(
			'user.logged_out',
			$user_id,
			[
				'type' => 'session',
				'id'   => 'logout',
				'name' => 'User Logout',
			]
		);
	}

	/**
	 * Log a failed login attempt.
	 *
	 * @param string $username The attempted username.
	 */
	public function log_login_failed( string $username ): void {
		// We don't have a user ID, so we need to find one.
		$user = get_user_by( 'login', $username );
		if ( ! $user ) {
			$user = get_user_by( 'email', $username );
		}
		if ( ! $user ) {
			return;
		}

		$this->create_event(
			'user.login_failed',
			$user->ID,
			[
				'type' => 'session',
				'id'   => 'login_failed',
				'name' => 'Failed Login Attempt',
			],
			[
				'ip_address' => $this->get_client_ip(),
			]
		);
	}

	/**
	 * Log a post save (create or update).
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @param bool     $update  Whether this is an update.
	 */
	public function log_post_save( int $post_id, \WP_Post $post, bool $update ): void {
		// Skip auto-saves and revisions.
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Skip non-public post types.
		if ( ! is_post_type_viewable( $post->post_type ) ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		$action = $update ? 'post.updated' : 'post.created';

		$this->create_event(
			$action,
			$user_id,
			[
				'type' => 'post',
				'id'   => (string) $post_id,
				'name' => $post->post_title,
			],
			[
				'post_type' => $post->post_type,
			]
		);
	}

	/**
	 * Log a post deletion.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public function log_post_delete( int $post_id, \WP_Post $post ): void {
		if ( ! is_post_type_viewable( $post->post_type ) ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		$this->create_event(
			'post.deleted',
			$user_id,
			[
				'type' => 'post',
				'id'   => (string) $post_id,
				'name' => $post->post_title,
			],
			[
				'post_type' => $post->post_type,
			]
		);
	}

	/**
	 * Log a new user registration.
	 *
	 * @param int $user_id New user ID.
	 */
	public function log_user_created( int $user_id ): void {
		$actor_id = get_current_user_id();
		if ( ! $actor_id ) {
			$actor_id = $user_id;
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return;
		}

		$this->create_event(
			'user.created',
			$actor_id,
			[
				'type' => 'user',
				'id'   => (string) $user_id,
				'name' => $user->display_name,
			]
		);
	}

	/**
	 * Log a user deletion.
	 *
	 * @param int $user_id Deleted user ID.
	 */
	public function log_user_deleted( int $user_id ): void {
		$actor_id = get_current_user_id();
		if ( ! $actor_id ) {
			return;
		}

		$user = get_user_by( 'id', $user_id );
		$name = $user ? $user->display_name : "User #{$user_id}";

		$this->create_event(
			'user.deleted',
			$actor_id,
			[
				'type' => 'user',
				'id'   => (string) $user_id,
				'name' => $name,
			]
		);
	}

	/**
	 * Log a role change.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $role     New role.
	 * @param array  $old_roles Previous roles.
	 */
	public function log_role_change( int $user_id, string $role, array $old_roles ): void {
		$actor_id = get_current_user_id();
		if ( ! $actor_id ) {
			return;
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return;
		}

		$this->create_event(
			'user.role_changed',
			$actor_id,
			[
				'type' => 'user',
				'id'   => (string) $user_id,
				'name' => $user->display_name,
			],
			[
				'old_roles' => implode( ', ', $old_roles ),
				'new_role'  => $role,
			]
		);
	}

	/**
	 * Create and send an audit event to WorkOS.
	 *
	 * @param string $action   Action type (e.g. 'user.logged_in').
	 * @param int    $user_id  WP user ID (actor).
	 * @param array  $target   Target object with type, id, name.
	 * @param array  $metadata Additional metadata.
	 */
	private function create_event( string $action, int $user_id, array $target, array $metadata = [] ): void {
		$workos_id = get_user_meta( $user_id, '_workos_user_id', true );
		if ( ! $workos_id ) {
			return;
		}

		$org_id = get_user_meta( $user_id, '_workos_org_id', true );
		if ( ! $org_id ) {
			return;
		}

		$event = [
			'action'      => [
				'type' => $action,
				'name' => ucwords( str_replace( [ '.', '_' ], ' ', $action ) ),
			],
			'actor'       => [
				'type' => 'user',
				'id'   => $workos_id,
			],
			'targets'     => [ $target ],
			'context'     => [
				'location' => $this->get_client_ip(),
			],
			'occurred_at' => gmdate( 'c' ),
		];

		if ( ! empty( $metadata ) ) {
			$event['metadata'] = $metadata;
		}

		// Fire asynchronously to avoid slowing down the user action.
		$this->send_event_async( $org_id, $event );
	}

	/**
	 * Send an audit event asynchronously using wp_remote_post in a non-blocking way.
	 *
	 * @param string $org_id WorkOS organization ID.
	 * @param array  $event  Audit event data.
	 */
	private function send_event_async( string $org_id, array $event ): void {
		workos()->api()->create_audit_event( $org_id, $event );
	}

	/**
	 * Get the client IP address.
	 *
	 * @return string
	 */
	private function get_client_ip(): string {
		$ip           = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ) );
		$validated_ip = filter_var( $ip, FILTER_VALIDATE_IP );
		return $validated_ip ? $validated_ip : '0.0.0.0';
	}
}
