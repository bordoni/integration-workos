<?php
/**
 * Activity Log admin page (Usage).
 *
 * @package WorkOS\ActivityLog
 */

namespace WorkOS\ActivityLog;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the WorkOS > Usage admin page.
 */
class AdminPage {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_post_workos_clear_activity_log', [ $this, 'handle_clear_log' ] );
	}

	/**
	 * Register the submenu page.
	 */
	public function register_menu(): void {
		add_submenu_page(
			'workos',
			__( 'Usage — Activity Log', 'integration-workos' ),
			__( 'Usage', 'integration-workos' ),
			'manage_options',
			'workos-usage',
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Handle clearing the activity log.
	 */
	public function handle_clear_log(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'integration-workos' ) );
		}

		check_admin_referer( 'workos_clear_activity_log' );

		EventLogger::clear();

		wp_safe_redirect(
			add_query_arg(
				[
					'page'    => 'workos-usage',
					'cleared' => '1',
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render the page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_page = max( 1, absint( $_GET['paged'] ?? 1 ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$event_filter = sanitize_text_field( wp_unslash( $_GET['event_type'] ?? '' ) );

		$stats  = EventLogger::get_stats( 30 );
		$result = EventLogger::get_events(
			[
				'per_page'   => 20,
				'page'       => $current_page,
				'event_type' => $event_filter,
			]
		);

		$items       = $result['items'];
		$total       = $result['total'];
		$total_pages = (int) ceil( $total / 20 );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$cleared = ! empty( $_GET['cleared'] );

		$event_types = [ 'login', 'logout', 'login_failed', 'login_denied', 'user_suspended', 'onboarding_sync', 'bypass_activated' ];

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Usage — Activity Log', 'integration-workos' ); ?></h1>

			<?php if ( $cleared ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Activity log cleared.', 'integration-workos' ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! EventLogger::is_enabled() ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php
						printf(
							/* translators: %s: settings page URL */
							esc_html__( 'Activity logging is disabled. Enable it in %s.', 'integration-workos' ),
							'<a href="' . esc_url( admin_url( 'admin.php?page=workos' ) ) . '">' . esc_html__( 'Settings', 'integration-workos' ) . '</a>'
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<!-- Stats Cards -->
			<div style="display:flex;gap:16px;margin:20px 0;">
				<div class="card" style="flex:1;padding:16px;">
					<h3 style="margin:0 0 4px;"><?php echo esc_html( number_format_i18n( $stats['total_logins'] ) ); ?></h3>
					<p style="margin:0;color:#646970;"><?php esc_html_e( 'Logins (30 days)', 'integration-workos' ); ?></p>
				</div>
				<div class="card" style="flex:1;padding:16px;">
					<h3 style="margin:0 0 4px;"><?php echo esc_html( number_format_i18n( $stats['failed_logins'] ) ); ?></h3>
					<p style="margin:0;color:#646970;"><?php esc_html_e( 'Failed Logins (30 days)', 'integration-workos' ); ?></p>
				</div>
				<div class="card" style="flex:1;padding:16px;">
					<h3 style="margin:0 0 4px;"><?php echo esc_html( number_format_i18n( $stats['unique_users'] ) ); ?></h3>
					<p style="margin:0;color:#646970;"><?php esc_html_e( 'Unique Users (30 days)', 'integration-workos' ); ?></p>
				</div>
			</div>

			<!-- Filter + Clear -->
			<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
					<input type="hidden" name="page" value="workos-usage" />
					<select name="event_type">
						<option value=""><?php esc_html_e( 'All events', 'integration-workos' ); ?></option>
						<?php foreach ( $event_types as $type ) : ?>
							<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $event_filter, $type ); ?>>
								<?php echo esc_html( $type ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<?php submit_button( __( 'Filter', 'integration-workos' ), 'secondary', '', false ); ?>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
					<input type="hidden" name="action" value="workos_clear_activity_log" />
					<?php wp_nonce_field( 'workos_clear_activity_log' ); ?>
					<?php submit_button( __( 'Clear Log', 'integration-workos' ), 'delete', '', false, [ 'onclick' => 'return confirm("' . esc_js( __( 'Clear all activity log entries?', 'integration-workos' ) ) . '");' ] ); ?>
				</form>
			</div>

			<!-- Events Table -->
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width:160px;"><?php esc_html_e( 'Date', 'integration-workos' ); ?></th>
						<th style="width:130px;"><?php esc_html_e( 'Event', 'integration-workos' ); ?></th>
						<th><?php esc_html_e( 'User', 'integration-workos' ); ?></th>
						<th style="width:130px;"><?php esc_html_e( 'IP Address', 'integration-workos' ); ?></th>
						<th><?php esc_html_e( 'Details', 'integration-workos' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $items ) ) : ?>
						<tr>
							<td colspan="5"><?php esc_html_e( 'No events found.', 'integration-workos' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $items as $row ) : ?>
							<tr>
								<td><?php echo esc_html( wp_date( 'Y-m-d H:i:s', strtotime( $row['created_at'] ) ) ); ?></td>
								<td><code><?php echo esc_html( $row['event_type'] ); ?></code></td>
								<td>
									<?php echo esc_html( $row['user_email'] ? $row['user_email'] : '—' ); ?>
									<?php if ( $row['user_id'] ) : ?>
										<br><small>#<?php echo esc_html( $row['user_id'] ); ?></small>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $row['ip_address'] ? $row['ip_address'] : '—' ); ?></td>
								<td>
									<?php
									if ( ! empty( $row['metadata'] ) ) {
										$meta = json_decode( $row['metadata'], true );
										if ( is_array( $meta ) ) {
											echo '<code style="word-break:break-all;">' . esc_html( wp_json_encode( $meta ) ) . '</code>';
										}
									} else {
										echo '—';
									}
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<?php
						echo wp_kses_post(
							paginate_links(
								[
									'base'    => add_query_arg( 'paged', '%#%' ),
									'format'  => '',
									'current' => $current_page,
									'total'   => $total_pages,
								]
							)
						);
						?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
