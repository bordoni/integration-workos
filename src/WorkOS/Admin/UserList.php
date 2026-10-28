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

use WorkOS\Config;
use WorkOS\Organization\Manager;
use WorkOS\Sync\MembershipSync;
use WorkOS\Sync\RoleMapper;
use WorkOS\Sync\RoleSyncCheck;
use WorkOS\Sync\UserSync;
use WorkOS\Vendor\StellarWP\SuperGlobals\SuperGlobals;

defined( 'ABSPATH' ) || exit;

/**
 * Extends the WP Users list table with WorkOS visibility and sync controls.
 */
class UserList {

	/**
	 * Whether this request already loaded the list page's memberships.
	 *
	 * @var bool
	 */
	private bool $primed = false;

	/**
	 * Refreshes memberships and mapped roles from WorkOS.
	 *
	 * @var MembershipSync
	 */
	private MembershipSync $membership_sync;

	/**
	 * Refreshes complete user profiles and memberships.
	 *
	 * @var UserSync
	 */
	private UserSync $user_sync;

	/**
	 * Register Users-screen hooks.
	 *
	 * @param MembershipSync $membership_sync Membership refresh service.
	 * @param UserSync       $user_sync       Full user refresh service.
	 */
	public function __construct( MembershipSync $membership_sync, UserSync $user_sync ) {
		$this->membership_sync = $membership_sync;
		$this->user_sync       = $user_sync;

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
		$environment_id = Config::get_environment_id();

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
		$dashboard_url = '' !== $environment_id
			? sprintf(
				'https://dashboard.workos.com/%s/users/%s/details',
				rawurlencode( $environment_id ),
				rawurlencode( $workos_id )
			)
			: '';

		if ( '' !== $dashboard_url ) {
			$html = sprintf(
				'<code><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></code>',
				esc_url( $dashboard_url ),
				esc_html( $workos_id )
			);
		} else {
			$html = '<code>' . esc_html( $workos_id ) . '</code>';
		}

		$actions = [];

		if ( '' !== $dashboard_url ) {
			$actions['view'] = sprintf(
				'<span class="view"><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></span>',
				esc_url( $dashboard_url ),
				esc_html__( 'View in WorkOS', 'integration-workos' )
			);
		}

		if ( workos()->is_enabled() && $this->can_resync_user( $user_id ) ) {
			$current_url = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

			$resync_url = wp_nonce_url(
				add_query_arg(
					[
						'action'           => 'workos_resync_user',
						'user_id'          => $user_id,
						'_wp_http_referer' => rawurlencode( $this->users_list_url( $current_url ) ),
					],
					admin_url( 'users.php' )
				),
				'workos_resync_user_' . $user_id
			);

			$actions['resync'] = sprintf(
				'<span class="resync"><a href="%s">%s</a></span>',
				esc_url( $resync_url ),
				esc_html__( 'Re-sync', 'integration-workos' )
			);
		}

		/**
		 * Filters the row-action links rendered under the WorkOS column on
		 * `wp-admin/users.php` for a linked user.
		 *
		 * Each entry is a fully-formed `<span class="…"><a …>Label</a></span>`
		 * string keyed by action slug; the keys exist so extenders can
		 * replace or reorder a specific action without rebuilding the list.
		 * Entries are joined with ` | ` separators on render — same visual
		 * style as `user_row_actions`.
		 *
		 * @param array<string,string> $actions   Map of action slug => HTML.
		 * @param int                  $user_id   WordPress user ID.
		 * @param string               $workos_id Linked WorkOS user ID.
		 */
		$actions = (array) apply_filters( 'workos_user_list_column_actions', $actions, $user_id, $workos_id );

		$rendered = array_filter(
			array_map( 'strval', $actions ),
			static fn( string $action ): bool => '' !== $action
		);

		$html .= '<div class="row-actions">' . implode( ' | ', $rendered ) . '</div>';

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
		$this->prime_page_memberships();

		$user_orgs = Manager::get_user_orgs( $user_id );
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
					'<br><span class="workos-role-mismatch" title="%s" style="display:inline-block;margin-top:4px;padding:2px 8px;font-size:11px;font-weight:600;line-height:16px;color:#a00;background:#fde8e8;border-radius:10px;"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-1px"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> %s</span>',
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
	 * Load the memberships of every user on the current list page in one query,
	 * the first time a row needs them.
	 */
	private function prime_page_memberships(): void {
		if ( $this->primed ) {
			return;
		}
		$this->primed = true;

		global $wp_list_table;
		if ( ! $wp_list_table instanceof \WP_Users_List_Table || empty( $wp_list_table->items ) ) {
			return;
		}

		Manager::prime_user_orgs( wp_list_pluck( $wp_list_table->items, 'ID' ) );
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
			return $this->handle_bulk_refresh( $redirect_url, $user_ids, false );
		}

		if ( 'workos_bulk_sync_roles' === $action ) {
			return $this->handle_bulk_refresh( $redirect_url, $user_ids, true );
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
	 * Whether the actor can edit this user and, when applicable, change roles.
	 *
	 * @param int  $user_id    Target user ID.
	 * @param bool $roles_only Whether this is a role-only refresh.
	 *
	 * @return bool
	 */
	private function can_resync_user( int $user_id, bool $roles_only = false ): bool {
		return current_user_can( 'edit_users' ) && current_user_can( 'edit_user', $user_id )
			&& ( ( ! $roles_only && '' === Config::get_organization_id() ) || current_user_can( 'promote_user', $user_id ) );
	}

	/**
	 * Refresh selected users from WorkOS and report per-user failures.
	 *
	 * @param string $redirect_url Redirect URL.
	 * @param array  $user_ids     Selected user IDs.
	 * @param bool   $roles_only   Refresh membership without profile fields.
	 *
	 * @return string
	 */
	private function handle_bulk_refresh( string $redirect_url, array $user_ids, bool $roles_only ): string {
		$synced  = 0;
		$failed  = 0;
		$skipped = 0;
		$errors  = [];

		foreach ( $user_ids as $user_id ) {
			$user_id = absint( $user_id );

			if ( ! $this->can_resync_user( $user_id, $roles_only ) ) {
				$result = new \WP_Error( 'workos_sync_forbidden', __( 'You do not have permission to refresh this user and their role.', 'integration-workos' ) );
			} elseif ( ! get_user_meta( $user_id, '_workos_user_id', true ) ) {
				++$skipped;
				continue;
			} else {
				$result = $roles_only ? $this->membership_sync->refresh( $user_id ) : $this->user_sync->resync_from_workos( $user_id );
			}

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
				$roles_only ? 'workos_roles_synced' : 'workos_resynced' => $synced,
				$roles_only ? 'workos_roles_skipped' : 'workos_skipped' => $skipped,
				'workos_failed' => $failed,
				'workos_errors' => rawurlencode( implode( ' | ', $errors ) ),
			],
			$redirect_url
		);
	}

	/**
	 * Handle a single row-action sync request.
	 */
	public function handle_single_sync(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below via check_admin_referer().
		if ( empty( SuperGlobals::get_get_var( 'action' ) ) || 'workos_sync_user' !== SuperGlobals::get_get_var( 'action' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below via check_admin_referer().
		$user_id = absint( SuperGlobals::get_get_var( 'user_id' ) ?? 0 );
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
		if ( empty( SuperGlobals::get_get_var( 'action' ) ) || 'workos_resync_user' !== SuperGlobals::get_get_var( 'action' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below via check_admin_referer().
		$user_id = absint( SuperGlobals::get_get_var( 'user_id' ) ?? 0 );
		if ( ! $user_id ) {
			return;
		}

		// Older WordPress versions read this value inside check_admin_referer() without a type check.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Normalize navigation data before core verifies the action nonce.
		if ( isset( $_REQUEST['_wp_http_referer'] ) && ! is_string( $_REQUEST['_wp_http_referer'] ) ) {
			$_REQUEST['_wp_http_referer'] = admin_url( 'users.php' );
		}

		check_admin_referer( 'workos_resync_user_' . $user_id );

		if ( ! $this->can_resync_user( $user_id ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'integration-workos' ) );
		}

		$result = $this->user_sync->resync_from_workos( $user_id );

		$query_args = [];
		if ( is_wp_error( $result ) ) {
			$query_args['workos_failed'] = 1;
			$query_args['workos_errors'] = rawurlencode( $result->get_error_message() );
		} else {
			$query_args['workos_resynced'] = 1;
		}

		$referer = wp_get_referer();

		wp_safe_redirect( add_query_arg( $query_args, $this->users_list_url( is_string( $referer ) ? $referer : '' ) ) );
		exit;
	}

	/**
	 * Keep Users-screen filters while removing action and notification parameters.
	 *
	 * Require the current site's Users screen, then rebuild on its fixed URL so
	 * neither a supplied referer nor a browser header can change the destination.
	 *
	 * @param string $url Original list URL.
	 *
	 * @return string Users-screen URL, falling back to the unfiltered list.
	 */
	private function users_list_url( string $url ): string {
		$fallback = admin_url( 'users.php' );
		$expected = wp_parse_url( $fallback );
		$parts    = wp_parse_url( $url );

		if ( ! is_array( $parts ) || ( $parts['path'] ?? '' ) !== $expected['path'] || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return $fallback;
		}

		$origin_components = [
			'scheme',
			'host',
			'port',
		];

		foreach ( $origin_components as $component ) {
			if ( isset( $parts[ $component ] ) && ( $expected[ $component ] ?? null ) !== $parts[ $component ] ) {
				return $fallback;
			}
		}

		$remove = array_merge(
			wp_removable_query_args(),
			[
				'action',
				'action2',
				'user',
				'user_id',
				'users',
				'_wpnonce',
				'_wp_http_referer',
				'wp_http_referer',
				'redirect_to',
				'workos_synced',
				'workos_resynced',
				'workos_failed',
				'workos_skipped',
				'workos_errors',
				'workos_roles_synced',
				'workos_roles_skipped',
			]
		);

		return remove_query_arg( $remove, $fallback . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' ) );
	}

	/**
	 * Add an "Out of sync" view to the users list filter links.
	 *
	 * Reads the last role sync check (RoleSyncCheck); nothing is computed on this screen.
	 *
	 * @param array $views Existing view links.
	 *
	 * @return array Modified view links.
	 */
	public function add_out_of_sync_view( array $views ): array {
		if ( ! workos()->is_enabled() ) {
			return $views;
		}

		$result = RoleSyncCheck::result();
		$count  = $result ? $result['count'] : 0;

		if ( 0 === $count ) {
			return $views;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only.
		$current = null !== SuperGlobals::get_get_var( 'workos_sync_status' ) && 'out_of_sync' === SuperGlobals::get_get_var( 'workos_sync_status' );
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
		if ( empty( SuperGlobals::get_get_var( 'workos_sync_status' ) ) || 'out_of_sync' !== SuperGlobals::get_get_var( 'workos_sync_status' ) ) {
			return;
		}

		$result          = RoleSyncCheck::result();
		$out_of_sync_ids = $result ? $result['ids'] : [];

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
		$synced = absint( SuperGlobals::get_get_var( 'workos_synced' ) ?? 0 );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only; data is sanitized below.
		$resynced = absint( SuperGlobals::get_get_var( 'workos_resynced' ) ?? 0 );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only; data is sanitized below.
		$failed = absint( SuperGlobals::get_get_var( 'workos_failed' ) ?? 0 );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only; data is sanitized below.
		$skipped = absint( SuperGlobals::get_get_var( 'workos_skipped' ) ?? 0 );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only; data is sanitized below.
		$errors = SuperGlobals::get_get_var( 'workos_errors' ) ?? '';
		$errors = is_string( $errors ) ? wp_unslash( $errors ) : '';

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
							'integration-workos'
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
							'integration-workos'
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
					'integration-workos'
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
							'integration-workos'
						),
						$skipped
					)
				)
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only; data is sanitized below.
		$roles_synced = absint( SuperGlobals::get_get_var( 'workos_roles_synced' ) ?? 0 );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only; data is sanitized below.
		$roles_skipped = absint( SuperGlobals::get_get_var( 'workos_roles_skipped' ) ?? 0 );

		if ( $roles_synced ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of users whose roles were synced */
						_n(
							'%d user role refreshed from WorkOS.',
							'%d user roles refreshed from WorkOS.',
							$roles_synced,
							'integration-workos'
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
							'integration-workos'
						),
						$roles_skipped
					)
				)
			);
		}
	}
}
