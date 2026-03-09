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

use WorkOS\Sync\RoleMapper;
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
		add_filter( 'bulk_actions-users', [ $this, 'add_bulk_action' ] );
		add_filter( 'handle_bulk_actions-users', [ $this, 'handle_bulk_action' ], 10, 3 );
		add_action( 'admin_init', [ $this, 'handle_single_sync' ] );
		add_action( 'admin_init', [ $this, 'handle_single_resync' ] );
		add_action( 'admin_notices', [ $this, 'render_notices' ] );
		add_filter( 'views_users', [ $this, 'add_out_of_sync_view' ] );
		add_action( 'pre_get_users', [ $this, 'filter_out_of_sync_users' ] );
	}

	/**
	 * Add the "WorkOS" column header.
	 *
	 * @param array $columns Existing column headers.
	 *
	 * @return array
	 */
	public function add_column( array $columns ): array {
		$columns['workos'] = __( 'WorkOS', 'integration-workos' );
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

		$workos_id      = get_user_meta( $user_id, '_workos_user_id', true );
		$environment_id = \WorkOS\Config::get_environment_id();

		if ( $workos_id ) {
			return $this->render_linked_column( $user_id, $workos_id, $environment_id );
		}

		return $this->render_unlinked_column( $user_id );
	}

	/**
	 * Render column content for a user linked to WorkOS.
	 *
	 * @param int    $user_id        WordPress user ID.
	 * @param string $workos_id      WorkOS user ID.
	 * @param string $environment_id WorkOS environment ID.
	 *
	 * @return string Column HTML.
	 */
	private function render_linked_column( int $user_id, string $workos_id, string $environment_id ): string {
		if ( ! $environment_id ) {
			return '<code>' . esc_html( $workos_id ) . '</code>';
		}

		$dashboard_url = sprintf(
			'https://dashboard.workos.com/%s/users/%s/details',
			rawurlencode( $environment_id ),
			rawurlencode( $workos_id )
		);

		$html = sprintf(
			'<code><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></code>',
			esc_url( $dashboard_url ),
			esc_html( $workos_id )
		);

		$html .= '<div class="row-actions">';
		$html .= sprintf(
			'<span class="view"><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></span>',
			esc_url( $dashboard_url ),
			esc_html__( 'View in WorkOS', 'integration-workos' )
		);

		if ( workos()->is_enabled() && current_user_can( 'edit_users' ) ) {
			$resync_url = wp_nonce_url(
				add_query_arg(
					[
						'action'  => 'workos_resync_user',
						'user_id' => $user_id,
					],
					admin_url( 'users.php' )
				),
				'workos_resync_user_' . $user_id
			);

			$html .= sprintf(
				' | <span class="resync"><a href="%s">%s</a></span>',
				esc_url( $resync_url ),
				esc_html__( 'Re-sync', 'integration-workos' )
			);
		}

		$html .= '</div>';

		// Role mismatch indicator.
		$html .= $this->render_role_mismatch_indicator( $user_id );

		return $html;
	}

	/**
	 * Render a role mismatch indicator if the user's WP role doesn't match the WorkOS mapping.
	 *
	 * @param int $user_id WordPress user ID.
	 *
	 * @return string HTML indicator or empty string.
	 */
	private function render_role_mismatch_indicator( int $user_id ): string {
		$user_orgs = \WorkOS\Organization\Manager::get_user_orgs( $user_id );
		if ( empty( $user_orgs ) ) {
			return '';
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return '';
		}

		$role_map       = RoleMapper::get_role_map();
		$actual_wp_role = ! empty( $user->roles ) ? $user->roles[0] : '';

		foreach ( $user_orgs as $org ) {
			$workos_role = $org->workos_role ?? '';
			if ( empty( $workos_role ) ) {
				continue;
			}

			$expected = $role_map[ $workos_role ] ?? $role_map['member'] ?? 'subscriber';
			if ( $expected !== $actual_wp_role ) {
				return sprintf(
					'<br><span class="workos-role-mismatch" title="%s">&#x26A0; %s</span>',
					esc_attr(
						sprintf(
							/* translators: 1: expected WP role, 2: actual WP role */
							__( 'Expected: %1$s, Actual: %2$s', 'integration-workos' ),
							$expected,
							$actual_wp_role
						)
					),
					esc_html__( 'Role mismatch', 'integration-workos' )
				);
			}
		}

		return '';
	}

	/**
	 * Render column content for a user not linked to WorkOS.
	 *
	 * @param int $user_id WordPress user ID.
	 *
	 * @return string Column HTML.
	 */
	private function render_unlinked_column( int $user_id ): string {
		$html = '&mdash;';

		if ( workos()->is_enabled() && current_user_can( 'edit_users' ) ) {
			$url = wp_nonce_url(
				add_query_arg(
					[
						'action'  => 'workos_sync_user',
						'user_id' => $user_id,
					],
					admin_url( 'users.php' )
				),
				'workos_sync_user_' . $user_id
			);

			$html .= '<div class="row-actions">';
			$html .= sprintf(
				'<span class="sync"><a href="%s">%s</a></span>',
				esc_url( $url ),
				esc_html__( 'Sync to WorkOS', 'integration-workos' )
			);
			$html .= '</div>';
		}

		return $html;
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

		$actions['workos_bulk_sync']       = __( 'Sync to WorkOS', 'integration-workos' );
		$actions['workos_bulk_resync']     = __( 'Re-sync from WorkOS', 'integration-workos' );
		$actions['workos_bulk_sync_roles'] = __( 'Sync Roles from WorkOS', 'integration-workos' );
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
		if ( ! in_array( $action, [ 'workos_bulk_sync', 'workos_bulk_resync', 'workos_bulk_sync_roles' ], true ) ) {
			return $redirect_url;
		}

		if ( ! current_user_can( 'edit_users' ) ) {
			return $redirect_url;
		}

		if ( 'workos_bulk_resync' === $action ) {
			return $this->handle_bulk_resync( $redirect_url, $user_ids );
		}

		if ( 'workos_bulk_sync_roles' === $action ) {
			return $this->handle_bulk_sync_roles( $redirect_url, $user_ids );
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
					__( 'User #%1$d: %2$s', 'integration-workos' ),
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
	 * Handle the bulk "Re-sync from WorkOS" action.
	 *
	 * @param string $redirect_url Redirect URL.
	 * @param array  $user_ids     Selected user IDs.
	 *
	 * @return string Updated redirect URL with result query args.
	 */
	private function handle_bulk_resync( string $redirect_url, array $user_ids ): string {
		$resynced = 0;
		$failed   = 0;
		$skipped  = 0;
		$errors   = [];

		foreach ( $user_ids as $user_id ) {
			$user_id   = absint( $user_id );
			$workos_id = get_user_meta( $user_id, '_workos_user_id', true );

			if ( ! $workos_id ) {
				++$skipped;
				continue;
			}

			$result = UserSync::resync_from_workos( $user_id );

			if ( is_wp_error( $result ) ) {
				++$failed;
				$errors[] = sprintf(
					/* translators: 1: user ID, 2: error message */
					__( 'User #%1$d: %2$s', 'integration-workos' ),
					$user_id,
					$result->get_error_message()
				);
			} else {
				++$resynced;
			}
		}

		return add_query_arg(
			[
				'workos_resynced' => $resynced,
				'workos_failed'   => $failed,
				'workos_skipped'  => $skipped,
				'workos_errors'   => rawurlencode( implode( ' | ', $errors ) ),
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
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'integration-workos' ) );
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
	 * Handle a single row-action re-sync request.
	 */
	public function handle_single_resync(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below via check_admin_referer().
		if ( empty( $_GET['action'] ) || 'workos_resync_user' !== $_GET['action'] ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below via check_admin_referer().
		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
		if ( ! $user_id ) {
			return;
		}

		check_admin_referer( 'workos_resync_user_' . $user_id );

		if ( ! current_user_can( 'edit_users' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'integration-workos' ) );
		}

		$result = UserSync::resync_from_workos( $user_id );

		$query_args = [];
		if ( is_wp_error( $result ) ) {
			$query_args['workos_failed'] = 1;
			$query_args['workos_errors'] = rawurlencode( $result->get_error_message() );
		} else {
			$query_args['workos_resynced'] = 1;
		}

		wp_safe_redirect( add_query_arg( $query_args, admin_url( 'users.php' ) ) );
		exit;
	}

	/**
	 * Handle the bulk "Sync Roles from WorkOS" action.
	 *
	 * Re-applies the role mapping for selected users based on their WorkOS role.
	 *
	 * @param string $redirect_url Redirect URL.
	 * @param array  $user_ids     Selected user IDs.
	 *
	 * @return string Updated redirect URL with result query args.
	 */
	private function handle_bulk_sync_roles( string $redirect_url, array $user_ids ): string {
		$synced  = 0;
		$skipped = 0;

		$role_mapper = new RoleMapper();

		foreach ( $user_ids as $user_id ) {
			$user_id   = absint( $user_id );
			$user_orgs = \WorkOS\Organization\Manager::get_user_orgs( $user_id );

			if ( empty( $user_orgs ) ) {
				++$skipped;
				continue;
			}

			$applied = false;
			foreach ( $user_orgs as $org ) {
				$workos_role = $org->workos_role ?? '';
				if ( ! empty( $workos_role ) ) {
					$role_mapper->apply_role( $user_id, $workos_role );
					$applied = true;
					break;
				}
			}

			if ( $applied ) {
				++$synced;
			} else {
				++$skipped;
			}
		}

		return add_query_arg(
			[
				'workos_roles_synced'  => $synced,
				'workos_roles_skipped' => $skipped,
			],
			$redirect_url
		);
	}

	/**
	 * Add an "Out of sync" view to the users list filter links.
	 *
	 * @param array $views Existing view links.
	 *
	 * @return array Modified view links.
	 */
	public function add_out_of_sync_view( array $views ): array {
		if ( ! workos()->is_enabled() ) {
			return $views;
		}

		$out_of_sync_ids = RoleMapper::get_out_of_sync_user_ids();
		$count           = count( $out_of_sync_ids );

		if ( 0 === $count ) {
			return $views;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only.
		$current = isset( $_GET['workos_sync_status'] ) && 'out_of_sync' === $_GET['workos_sync_status'];
		$class   = $current ? ' class="current"' : '';

		$views['workos_out_of_sync'] = sprintf(
			'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
			esc_url( admin_url( 'users.php?workos_sync_status=out_of_sync' ) ),
			$class,
			esc_html__( 'Out of sync', 'integration-workos' ),
			$count
		);

		return $views;
	}

	/**
	 * Filter the users query when the out-of-sync view is active.
	 *
	 * @param \WP_User_Query $query The user query.
	 */
	public function filter_out_of_sync_users( \WP_User_Query $query ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Filter param, no data modification.
		if ( empty( $_GET['workos_sync_status'] ) || 'out_of_sync' !== $_GET['workos_sync_status'] ) {
			return;
		}

		$out_of_sync_ids = RoleMapper::get_out_of_sync_user_ids();

		if ( empty( $out_of_sync_ids ) ) {
			// No results — use impossible ID to return empty set.
			$query->set( 'include', [ 0 ] );
			return;
		}

		$query->set( 'include', $out_of_sync_ids );
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
		$synced = isset( $_GET['workos_synced'] ) ? absint( $_GET['workos_synced'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only; data is sanitized below.
		$resynced = isset( $_GET['workos_resynced'] ) ? absint( $_GET['workos_resynced'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only; data is sanitized below.
		$failed = isset( $_GET['workos_failed'] ) ? absint( $_GET['workos_failed'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only; data is sanitized below.
		$skipped = isset( $_GET['workos_skipped'] ) ? absint( $_GET['workos_skipped'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only; data is sanitized below.
		$errors = isset( $_GET['workos_errors'] ) ? sanitize_text_field( wp_unslash( $_GET['workos_errors'] ) ) : '';

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

		if ( $resynced ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of users re-synced */
						_n(
							'%d user re-synced from WorkOS.',
							'%d users re-synced from WorkOS.',
							$resynced,
							'workos'
						),
						$resynced
					)
				)
			);
		}

		if ( $failed ) {
			$message = sprintf(
				/* translators: %d: number of users that failed to sync */
				_n(
					'%d user failed to sync.',
					'%d users failed to sync.',
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
							'%d user skipped (not applicable).',
							'%d users skipped (not applicable).',
							$skipped,
							'workos'
						),
						$skipped
					)
				)
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only; data is sanitized below.
		$roles_synced = isset( $_GET['workos_roles_synced'] ) ? absint( $_GET['workos_roles_synced'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only; data is sanitized below.
		$roles_skipped = isset( $_GET['workos_roles_skipped'] ) ? absint( $_GET['workos_roles_skipped'] ) : 0;

		if ( $roles_synced ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of users whose roles were synced */
						_n(
							'%d user role synced from WorkOS mapping.',
							'%d user roles synced from WorkOS mapping.',
							$roles_synced,
							'workos'
						),
						$roles_synced
					)
				)
			);
		}

		if ( $roles_skipped ) {
			printf(
				'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of users skipped during role sync */
						_n(
							'%d user skipped (no WorkOS role found).',
							'%d users skipped (no WorkOS role found).',
							$roles_skipped,
							'workos'
						),
						$roles_skipped
					)
				)
			);
		}
	}
}
