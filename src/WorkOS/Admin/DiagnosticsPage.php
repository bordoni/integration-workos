<?php
/**
 * Diagnostics admin page.
 *
 * @package WorkOS\Admin
 */

namespace WorkOS\Admin;

use WorkOS\Config;
use WorkOS\Auth\Login;

defined( 'ABSPATH' ) || exit;

/**
 * Admin page to verify WorkOS configuration health.
 */
class DiagnosticsPage {

	/**
	 * Cached diagnostic results.
	 *
	 * @var array|null
	 */
	private ?array $results = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_post_workos_run_diagnostics', [ $this, 'handle_run_diagnostics' ] );
	}

	/**
	 * Register the submenu page.
	 */
	public function register_menu(): void {
		add_submenu_page(
			'workos',
			__( 'Diagnostics', 'integration-workos' ),
			__( 'Diagnostics', 'integration-workos' ),
			'manage_options',
			'workos-diagnostics',
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Handle the "Run Diagnostics" action.
	 */
	public function handle_run_diagnostics(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'integration-workos' ) );
		}

		check_admin_referer( 'workos_run_diagnostics' );

		wp_safe_redirect(
			add_query_arg(
				[
					'page' => 'workos-diagnostics',
					'ran'  => '1',
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render the diagnostics page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ran = ! empty( $_GET['ran'] );

		if ( $ran ) {
			$this->results = $this->run_all_checks();
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WorkOS Diagnostics', 'integration-workos' ); ?></h1>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:20px 0;">
				<input type="hidden" name="action" value="workos_run_diagnostics" />
				<?php wp_nonce_field( 'workos_run_diagnostics' ); ?>
				<?php submit_button( __( 'Run Diagnostics', 'integration-workos' ), 'primary', '', false ); ?>
			</form>

			<?php if ( null !== $this->results ) : ?>
				<table class="wp-list-table widefat fixed striped" style="max-width:800px;">
					<thead>
						<tr>
							<th style="width:40px;"><?php esc_html_e( 'Status', 'integration-workos' ); ?></th>
							<th style="width:200px;"><?php esc_html_e( 'Check', 'integration-workos' ); ?></th>
							<th><?php esc_html_e( 'Details', 'integration-workos' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $this->results as $check ) : ?>
							<tr>
								<td><?php echo $check['pass'] ? '&#x2705;' : '&#x274C;'; ?></td>
								<td><strong><?php echo esc_html( $check['label'] ); ?></strong></td>
								<td><?php echo wp_kses_post( $check['detail'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'Click "Run Diagnostics" to check your WorkOS configuration.', 'integration-workos' ); ?></p>
			<?php endif; ?>

			<hr style="margin-top:30px;">
			<h2><?php esc_html_e( 'Configuration Values', 'integration-workos' ); ?></h2>
			<?php $this->render_config_table(); ?>

			<h2><?php esc_html_e( 'Endpoints', 'integration-workos' ); ?></h2>
			<table class="widefat striped" style="max-width:800px;">
				<tr>
					<td><strong><?php esc_html_e( 'Callback URL', 'integration-workos' ); ?></strong></td>
					<td><code><?php echo esc_url( Login::get_callback_url() ); ?></code></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Webhook URL', 'integration-workos' ); ?></strong></td>
					<td><code><?php echo esc_url( rest_url( 'workos/v1/webhook' ) ); ?></code></td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Run all diagnostic checks.
	 *
	 * @return array
	 */
	private function run_all_checks(): array {
		$checks = [];

		// 1. PHP Requirements.
		$checks[] = $this->check_php_version();
		$checks[] = $this->check_php_extensions();

		// 2. API Connectivity.
		$checks[] = $this->check_api_connectivity();

		// 3. Database Tables.
		$checks[] = $this->check_database_tables();

		// 4. Schema Version.
		$checks[] = $this->check_schema_version();

		return $checks;
	}

	/**
	 * Check PHP version.
	 *
	 * @return array
	 */
	private function check_php_version(): array {
		$required = '8.0';
		$current  = PHP_VERSION;
		$pass     = version_compare( $current, $required, '>=' );

		return [
			'label'  => __( 'PHP Version', 'integration-workos' ),
			'pass'   => $pass,
			'detail' => sprintf( '%s (requires %s+)', esc_html( $current ), esc_html( $required ) ),
		];
	}

	/**
	 * Check required PHP extensions.
	 *
	 * @return array
	 */
	private function check_php_extensions(): array {
		$required = [ 'openssl', 'json', 'mbstring' ];
		$missing  = [];

		foreach ( $required as $ext ) {
			if ( ! extension_loaded( $ext ) ) {
				$missing[] = $ext;
			}
		}

		$pass = empty( $missing );

		if ( $pass ) {
			$detail = esc_html( implode( ', ', $required ) );
		} else {
			/* translators: %s: comma-separated list of missing PHP extensions */
			$detail = sprintf( esc_html__( 'Missing: %s', 'integration-workos' ), esc_html( implode( ', ', $missing ) ) );
		}

		return [
			'label'  => __( 'PHP Extensions', 'integration-workos' ),
			'pass'   => $pass,
			'detail' => $detail,
		];
	}

	/**
	 * Check API connectivity.
	 *
	 * @return array
	 */
	private function check_api_connectivity(): array {
		if ( ! workos()->is_enabled() ) {
			return [
				'label'  => __( 'API Connectivity', 'integration-workos' ),
				'pass'   => false,
				'detail' => esc_html__( 'Plugin not configured (missing API key, Client ID, or Environment ID).', 'integration-workos' ),
			];
		}

		$result = workos()->api()->list_organizations( [ 'limit' => 1 ] );

		if ( is_wp_error( $result ) ) {
			return [
				'label'  => __( 'API Connectivity', 'integration-workos' ),
				'pass'   => false,
				'detail' => esc_html( $result->get_error_message() ),
			];
		}

		return [
			'label'  => __( 'API Connectivity', 'integration-workos' ),
			'pass'   => true,
			'detail' => esc_html__( 'Successfully connected to WorkOS API.', 'integration-workos' ),
		];
	}

	/**
	 * Check if custom database tables exist.
	 *
	 * @return array
	 */
	private function check_database_tables(): array {
		global $wpdb;

		$tables = [
			"{$wpdb->prefix}workos_organizations",
			"{$wpdb->prefix}workos_org_memberships",
			"{$wpdb->prefix}workos_org_sites",
			"{$wpdb->prefix}workos_activity_log",
		];

		$missing = [];
		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( ! $exists ) {
				$missing[] = $table;
			}
		}

		$pass = empty( $missing );

		if ( $pass ) {
			/* translators: %d: number of database tables */
			$detail = sprintf( esc_html__( 'All %d tables exist.', 'integration-workos' ), count( $tables ) );
		} else {
			/* translators: %s: comma-separated list of missing database tables */
			$detail = sprintf( esc_html__( 'Missing: %s', 'integration-workos' ), esc_html( implode( ', ', $missing ) ) );
		}

		return [
			'label'  => __( 'Database Tables', 'integration-workos' ),
			'pass'   => $pass,
			'detail' => $detail,
		];
	}

	/**
	 * Check schema version.
	 *
	 * @return array
	 */
	private function check_schema_version(): array {
		$installed = (int) get_option( 'workos_db_version', 0 );
		$current   = 2; // Must match Schema::CURRENT_VERSION.

		$detail = sprintf(
			/* translators: 1: installed schema version, 2: current schema version */
			esc_html__( 'Installed: %1$d, Current: %2$d', 'integration-workos' ),
			$installed,
			$current
		);

		return [
			'label'  => __( 'Schema Version', 'integration-workos' ),
			'pass'   => $installed >= $current,
			'detail' => $detail,
		];
	}

	/**
	 * Render the configuration values table.
	 */
	private function render_config_table(): void {
		$settings = [
			'api_key'         => __( 'API Key', 'integration-workos' ),
			'client_id'       => __( 'Client ID', 'integration-workos' ),
			'environment_id'  => __( 'Environment ID', 'integration-workos' ),
			'organization_id' => __( 'Organization ID', 'integration-workos' ),
			'webhook_secret'  => __( 'Webhook Secret', 'integration-workos' ),
		];

		?>
		<table class="widefat striped" style="max-width:800px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Setting', 'integration-workos' ); ?></th>
					<th><?php esc_html_e( 'Value', 'integration-workos' ); ?></th>
					<th><?php esc_html_e( 'Source', 'integration-workos' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><strong><?php esc_html_e( 'Active Environment', 'integration-workos' ); ?></strong></td>
					<td><code><?php echo esc_html( Config::get_active_environment() ); ?></code></td>
					<td><?php echo Config::is_environment_overridden() ? '<em>constant</em>' : 'database'; ?></td>
				</tr>
				<?php foreach ( $settings as $key => $label ) : ?>
					<?php
					$getter = 'get_' . $key;
					$value  = Config::$getter();
					?>
					<tr>
						<td><strong><?php echo esc_html( $label ); ?></strong></td>
						<td><code><?php echo $value ? esc_html( Config::mask_secret( $value ) ) : '<em>' . esc_html__( 'not set', 'integration-workos' ) . '</em>'; ?></code></td>
						<td><?php echo Config::is_overridden( $key ) ? '<em>constant</em>' : 'database'; ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
