<?php
/**
 * Onboarding AJAX handlers.
 *
 * @package WorkOS\Admin
 */

namespace WorkOS\Admin;

use WorkOS\ActivityLog\EventLogger;
use WorkOS\Config;
use WorkOS\Sync\RoleMapper;
use WorkOS\Vendor\StellarWP\SuperGlobals\SuperGlobals;

defined( 'ABSPATH' ) || exit;

/**
 * Handles AJAX requests for the onboarding wizard.
 */
class OnboardingAjax {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_ajax_workos_onboarding_get_users', [ $this, 'get_users' ] );
		add_action( 'wp_ajax_workos_onboarding_sync_user', [ $this, 'sync_user' ] );
		add_action( 'wp_ajax_workos_onboarding_sync_batch', [ $this, 'sync_batch' ] );
	}

	/**
	 * Get paginated list of unlinked WP users.
	 */
	public function get_users(): void {
		check_ajax_referer( 'workos_onboarding', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$page     = max( 1, absint( SuperGlobals::get_post_var( 'page' ) ?? 1 ) );
		$per_page = 20;

		$args = [
			'number'     => $per_page,
			'paged'      => $page,
			'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[
					'key'     => '_workos_user_id',
					'compare' => 'NOT EXISTS',
				],
			],
			'orderby'    => 'display_name',
			'order'      => 'ASC',
		];

		$query = new \WP_User_Query( $args );
		$users = [];

		foreach ( $query->get_results() as $user ) {
			$roles   = $user->roles;
			$users[] = [
				'id'           => $user->ID,
				'display_name' => $user->display_name,
				'email'        => $user->user_email,
				'role'         => ! empty( $roles ) ? reset( $roles ) : '',
			];
		}

		wp_send_json_success(
			[
				'users'       => $users,
				'total'       => (int) $query->get_total(),
				'total_pages' => (int) ceil( $query->get_total() / $per_page ),
				'page'        => $page,
			]
		);
	}

	/**
	 * Sync a single WP user to WorkOS.
	 */
	public function sync_user(): void {
		check_ajax_referer( 'workos_onboarding', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$user_id = absint( SuperGlobals::get_post_var( 'user_id' ) ?? 0 );

		if ( ! $user_id ) {
			wp_send_json_error( [ 'message' => 'Invalid user ID.' ] );
		}

		$result = $this->sync_single_user( $user_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Sync a batch of users to WorkOS.
	 */
	public function sync_batch(): void {
		check_ajax_referer( 'workos_onboarding', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Nonce verified above; values are cast to int via absint().
		$user_ids = array_map( 'absint', (array) ( $_POST['user_ids'] ?? [] ) );
		$user_ids = array_filter( $user_ids );

		if ( empty( $user_ids ) ) {
			wp_send_json_error( [ 'message' => 'No user IDs provided.' ] );
		}

		$results = [];
		foreach ( $user_ids as $user_id ) {
			$result    = $this->sync_single_user( $user_id );
			$results[] = [
				'user_id' => $user_id,
				'success' => ! is_wp_error( $result ),
				'message' => is_wp_error( $result ) ? $result->get_error_message() : 'Synced',
				'data'    => is_wp_error( $result ) ? null : $result,
			];
		}

		wp_send_json_success( [ 'results' => $results ] );
	}

	/**
	 * Sync a single WP user to WorkOS.
	 *
	 * @param int $user_id WP user ID.
	 *
	 * @return array|\WP_Error
	 */
	private function sync_single_user( int $user_id ) {
		$wp_user = get_userdata( $user_id );

		if ( ! $wp_user ) {
			return new \WP_Error( 'invalid_user', 'User not found.' );
		}

		// Already linked?
		$existing_workos_id = get_user_meta( $user_id, '_workos_user_id', true );
		if ( $existing_workos_id ) {
			return new \WP_Error( 'already_linked', 'User is already linked to WorkOS.' );
		}

		$email = $wp_user->user_email;
		$api   = workos()->api();

		// Check if user exists in WorkOS by email.
		$search = $api->list_users( [ 'email' => $email ] );
		if ( is_wp_error( $search ) ) {
			return $search;
		}

		$workos_users = $search['data'] ?? [];
		$workos_user  = ! empty( $workos_users ) ? $workos_users[0] : null;

		// Create in WorkOS if not found.
		if ( ! $workos_user ) {
			$create_result = $api->create_user(
				[
					'email'          => $email,
					'first_name'     => $wp_user->first_name,
					'last_name'      => $wp_user->last_name,
					'email_verified' => true,
				]
			);

			if ( is_wp_error( $create_result ) ) {
				return $create_result;
			}

			$workos_user = $create_result;
		}

		$workos_user_id = $workos_user['id'] ?? '';

		if ( empty( $workos_user_id ) ) {
			return new \WP_Error( 'workos_error', 'Failed to get WorkOS user ID.' );
		}

		// Link the user.
		update_user_meta( $user_id, '_workos_user_id', $workos_user_id );
		update_user_meta( $user_id, '_workos_last_synced_at', current_time( 'mysql', true ) );

		// Ensure organization membership.
		$org_id = Config::get_organization_id();
		if ( $org_id ) {
			$wp_role     = ! empty( $wp_user->roles ) ? reset( $wp_user->roles ) : '';
			$role_mapper = new RoleMapper();
			$role_slug   = $wp_role ? $role_mapper->reverse_map_role( $wp_role ) : 'member';

			$api->create_organization_membership( $workos_user_id, $org_id, $role_slug ? $role_slug : 'member' );
		}

		// Log the sync event.
		EventLogger::log(
			'onboarding_sync',
			[
				'user_id'        => $user_id,
				'workos_user_id' => $workos_user_id,
				'metadata'       => [
					'email'  => $email,
					'action' => ! empty( $workos_users ) ? 'linked' : 'created',
				],
			]
		);

		return [
			'workos_user_id' => $workos_user_id,
			'action'         => ! empty( $workos_users ) ? 'linked' : 'created',
		];
	}
}
