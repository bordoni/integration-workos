<?php
/**
 * Onboarding wizard admin page.
 *
 * @package WorkOS\Admin
 */

namespace WorkOS\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Admin page to batch-sync existing WP users into WorkOS.
 */
class OnboardingPage {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Register the submenu page.
	 */
	public function register_menu(): void {
		add_submenu_page(
			'workos',
			__( 'Onboarding', 'integration-workos' ),
			__( 'Onboarding', 'integration-workos' ),
			'manage_options',
			'workos-onboarding',
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Enqueue assets on the onboarding page.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'workos_page_workos-onboarding' !== $hook_suffix ) {
			return;
		}

		$asset_file = WORKOS_DIR . 'build/onboarding.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = include $asset_file;

		wp_enqueue_script(
			'workos-onboarding',
			WORKOS_URL . 'build/onboarding.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_localize_script(
			'workos-onboarding',
			'workosOnboarding',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'workos_onboarding' ),
			]
		);
	}

	/**
	 * Render the onboarding page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! workos()->is_enabled() ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Onboarding', 'integration-workos' ) . '</h1>';
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Please configure your WorkOS API credentials first.', 'integration-workos' ) . '</p></div></div>';
			return;
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Onboarding — Sync Users to WorkOS', 'integration-workos' ); ?></h1>

			<p class="description">
				<?php esc_html_e( 'Sync existing WordPress users to WorkOS. Users already linked (with a WorkOS User ID) are excluded.', 'integration-workos' ); ?>
			</p>

			<div id="workos-onboarding-app">
				<div class="workos-onboarding-actions">
					<button type="button" class="button button-primary" id="workos-sync-all-btn">
						<?php esc_html_e( 'Sync All Unlinked Users', 'integration-workos' ); ?>
					</button>
					<button type="button" class="button" id="workos-refresh-btn">
						<?php esc_html_e( 'Refresh List', 'integration-workos' ); ?>
					</button>
				</div>

				<!-- Progress bar (hidden by default) -->
				<div id="workos-onboarding-progress" class="workos-progress-container">
					<div class="workos-progress-bar-bg">
						<div id="workos-progress-bar" class="workos-progress-bar-fill"></div>
					</div>
					<p id="workos-progress-text" class="workos-progress-text"></p>
				</div>

				<table class="wp-list-table widefat fixed striped" id="workos-users-table">
					<thead>
						<tr>
							<th class="workos-onboarding-col-name"><?php esc_html_e( 'Display Name', 'integration-workos' ); ?></th>
							<th class="workos-onboarding-col-email"><?php esc_html_e( 'Email', 'integration-workos' ); ?></th>
							<th class="workos-onboarding-col-role"><?php esc_html_e( 'WP Role', 'integration-workos' ); ?></th>
							<th class="workos-onboarding-col-status"><?php esc_html_e( 'Status', 'integration-workos' ); ?></th>
							<th class="workos-onboarding-col-actions"><?php esc_html_e( 'Actions', 'integration-workos' ); ?></th>
						</tr>
					</thead>
					<tbody id="workos-users-tbody">
						<tr>
							<td colspan="5"><?php esc_html_e( 'Loading...', 'integration-workos' ); ?></td>
						</tr>
					</tbody>
				</table>

				<div id="workos-users-pagination" class="workos-onboarding-pagination"></div>
			</div>
		</div>
		<?php
	}
}
