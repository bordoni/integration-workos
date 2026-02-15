<?php
/**
 * Users list table enhancements.
 *
 * Adds a WorkOS status column, per-row sync action, and bulk sync action
 * to the WordPress Users list (users.php).
 *
 * @package WorkOS\Admin
 */

namespace WorkOS\Admin;

use WorkOS\Sync\UserSync;

defined( 'ABSPATH' ) || exit;

/**
 * Extends the WP Users list table with WorkOS visibility and sync controls.
 */
class UserList {

	/**
	 * Constructor — registers all hooks.
	 */
	public function __construct() {
		add_filter( 'manage_users_columns', [ $this, 'add_column' ] );
		add_filter( 'manage_users_custom_column', [ $this, 'render_column' ], 10, 3 );
		add_filter( 'user_row_actions', [ $this, 'add_row_action' ], 10, 2 );
		add_filter( 'bulk_actions-users', [ $this, 'add_bulk_action' ] );
		add_filter( 'handle_bulk_actions-users', [ $this, 'handle_bulk_action' ], 10, 3 );
		add_action( 'admin_init', [ $this, 'handle_single_sync' ] );
		add_action( 'admin_notices', [ $this, 'render_notices' ] );
	}

	/**
	 * Add the "WorkOS" column header.
	 *
	 * @param array $columns Existing column headers.
	 *
	 * @return array
	 */
	public function add_column( array $columns ): array {
		$columns['workos'] = __( 'WorkOS', 'workos' );
		return $columns;
	}

	/**
	 * Render the WorkOS column for each user row.
	 *
	 * @param string $output      Custom column output (empty for custom columns).
	 * @param string $column_name Column identifier.
	 * @param int    $user_id     User ID.
	 *
	 * @return string Column HTML.
	 */
	public function render_column( string $output, string $column_name, int $user_id ): string {
		if ( 'workos' !== $column_name ) {
			return $output;
		}

		$workos_id = get_user_meta( $user_id, '_workos_user_id', true );

		if ( $workos_id ) {
			return sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
				esc_url( 'https://dashboard.workos.com/users/' . $workos_id ),
				esc_html( $workos_id )
			);
		}

		return '&mdash;';
	}

	/**
	 * Add a "Sync to WorkOS" row action for unlinked users.
	 *
	 * @param array    $actions Existing row actions.
	 * @param \WP_User $user    User object.
	 *
	 * @return array
	 */
	public function add_row_action( array $actions, \WP_User $user ): array {
		if ( ! current_user_can( 'edit_users' ) ) {
			return $actions;
		}

		if ( ! workos()->is_enabled() ) {
			return $actions;
		}

		$workos_id = get_user_meta( $user->ID, '_workos_user_id', true );
		if ( $workos_id ) {
			return $actions;
		}

		$url = wp_nonce_url(
			add_query_arg(
				[
					'action'  => 'workos_sync_user',
					'user_id' => $user->ID,
				],
				admin_url( 'users.php' )
			),
			'workos_sync_user_' . $user->ID
		);

		$actions['workos_sync'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			esc_html__( 'Sync to WorkOS', 'workos' )
		);

		return $actions;
	}

	/**
	 * Register the bulk "Sync to WorkOS" action in the dropdown.
	 *
	 * @param array $actions Existing bulk actions.
	 *
	 * @return array
	 */
	public function add_bulk_action( array $actions ): array {
		if ( ! workos()->is_enabled() ) {
			return $actions;
		}

		$actions['workos_bulk_sync'] = __( 'Sync to WorkOS', 'workos' );
		return $actions;
	}

	/**
	 * Handle the bulk "Sync to WorkOS" action.
	 *
	 * @param string $redirect_url Redirect URL.
	 * @param string $action       The bulk action being processed.
	 * @param array  $user_ids     Selected user IDs.
	 *
	 * @return string Updated redirect URL with result query args.
	 */
	public function handle_bulk_action( string $redirect_url, string $action, array $user_ids ): string {
		if ( 'workos_bulk_sync' !== $action ) {
			return $redirect_url;
		}

		if ( ! current_user_can( 'edit_users' ) ) {
			return $redirect_url;
		}

		$synced  = 0;
		$failed  = 0;
		$skipped = 0;
		$errors  = [];

		foreach ( $user_ids as $user_id ) {
			$user_id   = absint( $user_id );
			$workos_id = get_user_meta( $user_id, '_workos_user_id', true );

			if ( $workos_id ) {
				++$skipped;
				continue;
			}

			$result = UserSync::sync_existing_user( $user_id );

			if ( is_wp_error( $result ) ) {
				++$failed;
				$errors[] = sprintf(
					/* translators: 1: user ID, 2: error message */
					__( 'User #%1$d: %2$s', 'workos' ),
					$user_id,
					$result->get_error_message()
				);
			} else {
				++$synced;
			}
		}

		return add_query_arg(
			[
				'workos_synced'  => $synced,
				'workos_failed'  => $failed,
				'workos_skipped' => $skipped,
				'workos_errors'  => rawurlencode( implode( ' | ', $errors ) ),
			],
			$redirect_url
		);
	}

	/**
	 * Handle a single row-action sync request.
	 */
	public function handle_single_sync(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below via check_admin_referer().
		if ( empty( $_GET['action'] ) || 'workos_sync_user' !== $_GET['action'] ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below via check_admin_referer().
		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
		if ( ! $user_id ) {
			return;
		}

		check_admin_referer( 'workos_sync_user_' . $user_id );

		if ( ! current_user_can( 'edit_users' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'workos' ) );
		}

		$result = UserSync::sync_existing_user( $user_id );

		$query_args = [];
		if ( is_wp_error( $result ) ) {
			$query_args['workos_failed'] = 1;
			$query_args['workos_errors'] = rawurlencode( $result->get_error_message() );
		} else {
			$query_args['workos_synced'] = 1;
		}

		wp_safe_redirect( add_query_arg( $query_args, admin_url( 'users.php' ) ) );
		exit;
	}

	/**
	 * Render admin notices from sync operations.
	 */
	public function render_notices(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'users' !== $screen->id ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only; data is sanitized below.
		$synced  = isset( $_GET['workos_synced'] ) ? absint( $_GET['workos_synced'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only; data is sanitized below.
		$failed  = isset( $_GET['workos_failed'] ) ? absint( $_GET['workos_failed'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only; data is sanitized below.
		$skipped = isset( $_GET['workos_skipped'] ) ? absint( $_GET['workos_skipped'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only; data is sanitized below.
		$errors  = isset( $_GET['workos_errors'] ) ? sanitize_text_field( wp_unslash( $_GET['workos_errors'] ) ) : '';

		if ( $synced ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of users synced */
						_n(
							'%d user synced to WorkOS.',
							'%d users synced to WorkOS.',
							$synced,
							'workos'
						),
						$synced
					)
				)
			);
		}

		if ( $failed ) {
			$message = sprintf(
				/* translators: %d: number of users that failed to sync */
				_n(
					'%d user failed to sync to WorkOS.',
					'%d users failed to sync to WorkOS.',
					$failed,
					'workos'
				),
				$failed
			);

			if ( $errors ) {
				$message .= ' ' . $errors;
			}

			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html( $message )
			);
		}

		if ( $skipped ) {
			printf(
				'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of users skipped */
						_n(
							'%d user skipped (already linked to WorkOS).',
							'%d users skipped (already linked to WorkOS).',
							$skipped,
							'workos'
						),
						$skipped
					)
				)
			);
		}
	}
}
