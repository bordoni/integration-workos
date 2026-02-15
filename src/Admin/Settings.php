<?php
/**
 * Admin settings page.
 *
 * @package WorkOS\Admin
 */

namespace WorkOS\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the WorkOS settings page in WP Admin.
 */
class Settings {

	/**
	 * Option group name.
	 */
	private const OPTION_GROUP = 'workos_settings';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_filter( 'plugin_action_links_' . WORKOS_BASENAME, [ $this, 'action_links' ] );
	}

	/**
	 * Add the top-level menu page.
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'WorkOS Settings', 'workos' ),
			__( 'WorkOS', 'workos' ),
			'manage_options',
			'workos',
			[ $this, 'render_page' ],
			'dashicons-shield',
			81
		);
	}

	/**
	 * Register all settings.
	 */
	public function register_settings(): void {
		// --- API Credentials section ---
		add_settings_section(
			'workos_api',
			__( 'API Credentials', 'workos' ),
			function () {
				echo '<p>' . esc_html__( 'Enter your WorkOS API key and Client ID from the WorkOS dashboard.', 'workos' ) . '</p>';
			},
			'workos'
		);

		$this->add_field( 'workos_api_key', __( 'API Key', 'workos' ), 'password', 'workos_api' );
		$this->add_field( 'workos_client_id', __( 'Client ID', 'workos' ), 'text', 'workos_api' );

		// --- Authentication section ---
		add_settings_section(
			'workos_auth',
			__( 'Authentication', 'workos' ),
			function () {
				echo '<p>' . esc_html__( 'Configure how users authenticate with your site.', 'workos' ) . '</p>';
			},
			'workos'
		);

		register_setting( self::OPTION_GROUP, 'workos_login_mode', [
			'type'              => 'string',
			'default'           => 'redirect',
			'sanitize_callback' => 'sanitize_text_field',
		] );

		add_settings_field(
			'workos_login_mode',
			__( 'Login Mode', 'workos' ),
			[ $this, 'render_select' ],
			'workos',
			'workos_auth',
			[
				'name'    => 'workos_login_mode',
				'options' => [
					'redirect' => __( 'AuthKit Redirect (Recommended)', 'workos' ),
					'headless' => __( 'Headless API (Custom Form)', 'workos' ),
				],
			]
		);

		register_setting( self::OPTION_GROUP, 'workos_allow_password_fallback', [
			'type'              => 'boolean',
			'default'           => true,
			'sanitize_callback' => 'rest_sanitize_boolean',
		] );

		add_settings_field(
			'workos_allow_password_fallback',
			__( 'Password Fallback', 'workos' ),
			[ $this, 'render_checkbox' ],
			'workos',
			'workos_auth',
			[
				'name'  => 'workos_allow_password_fallback',
				'label' => __( 'Allow users to log in with WordPress password if WorkOS auth fails.', 'workos' ),
			]
		);

		// --- Webhook section ---
		add_settings_section(
			'workos_webhooks',
			__( 'Webhooks', 'workos' ),
			function () {
				$url = rest_url( 'workos/v1/webhook' );
				printf(
					'<p>%s <code>%s</code></p>',
					esc_html__( 'Set this URL as your webhook endpoint in WorkOS:', 'workos' ),
					esc_url( $url )
				);
			},
			'workos'
		);

		$this->add_field( 'workos_webhook_secret', __( 'Webhook Secret', 'workos' ), 'password', 'workos_webhooks' );

		// --- User Provisioning section ---
		add_settings_section(
			'workos_provisioning',
			__( 'User Provisioning', 'workos' ),
			function () {
				echo '<p>' . esc_html__( 'Configure how users are deprovisioned when removed from WorkOS.', 'workos' ) . '</p>';
			},
			'workos'
		);

		register_setting( self::OPTION_GROUP, 'workos_deprovision_action', [
			'type'              => 'string',
			'default'           => 'deactivate',
			'sanitize_callback' => 'sanitize_text_field',
		] );

		add_settings_field(
			'workos_deprovision_action',
			__( 'Deprovision Action', 'workos' ),
			[ $this, 'render_select' ],
			'workos',
			'workos_provisioning',
			[
				'name'    => 'workos_deprovision_action',
				'options' => [
					'deactivate' => __( 'Deactivate (mark as inactive)', 'workos' ),
					'demote'     => __( 'Demote to Subscriber role', 'workos' ),
					'delete'     => __( 'Delete user (reassign content)', 'workos' ),
				],
			]
		);

		register_setting( self::OPTION_GROUP, 'workos_reassign_user', [
			'type'              => 'integer',
			'default'           => 0,
			'sanitize_callback' => 'absint',
		] );

		add_settings_field(
			'workos_reassign_user',
			__( 'Reassign Content To', 'workos' ),
			[ $this, 'render_user_select' ],
			'workos',
			'workos_provisioning',
			[ 'name' => 'workos_reassign_user' ]
		);

		// --- Role Mapping section ---
		add_settings_section(
			'workos_roles',
			__( 'Role Mapping', 'workos' ),
			function () {
				echo '<p>' . esc_html__( 'Map WorkOS roles to WordPress roles. Users will be assigned the mapped WP role on login.', 'workos' ) . '</p>';
			},
			'workos'
		);

		register_setting( self::OPTION_GROUP, 'workos_role_map', [
			'type'              => 'array',
			'default'           => [],
			'sanitize_callback' => [ $this, 'sanitize_role_map' ],
		] );

		add_settings_field(
			'workos_role_map',
			__( 'Role Map', 'workos' ),
			[ $this, 'render_role_map' ],
			'workos',
			'workos_roles'
		);

		// --- Audit Logging section ---
		add_settings_section(
			'workos_audit',
			__( 'Audit Logging', 'workos' ),
			function () {
				echo '<p>' . esc_html__( 'Forward WordPress events to WorkOS Audit Logs.', 'workos' ) . '</p>';
			},
			'workos'
		);

		register_setting( self::OPTION_GROUP, 'workos_audit_logging_enabled', [
			'type'              => 'boolean',
			'default'           => false,
			'sanitize_callback' => 'rest_sanitize_boolean',
		] );

		add_settings_field(
			'workos_audit_logging_enabled',
			__( 'Enable Audit Logging', 'workos' ),
			[ $this, 'render_checkbox' ],
			'workos',
			'workos_audit',
			[
				'name'  => 'workos_audit_logging_enabled',
				'label' => __( 'Send login, post, and user events to WorkOS Audit Logs.', 'workos' ),
			]
		);
	}

	/**
	 * Render the settings page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php settings_errors( 'workos_messages' ); ?>

			<form action="options.php" method="post">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( 'workos' );
				submit_button( __( 'Save Settings', 'workos' ) );
				?>
			</form>

			<?php if ( workos()->is_enabled() ) : ?>
				<hr>
				<h2><?php esc_html_e( 'Status', 'workos' ); ?></h2>
				<table class="widefat striped" style="max-width:600px">
					<tr>
						<td><strong><?php esc_html_e( 'Plugin', 'workos' ); ?></strong></td>
						<td><?php esc_html_e( 'Configured', 'workos' ); ?> &#x2705;</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Webhook URL', 'workos' ); ?></strong></td>
						<td><code><?php echo esc_url( rest_url( 'workos/v1/webhook' ) ); ?></code></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Callback URL', 'workos' ); ?></strong></td>
						<td><code><?php echo esc_url( home_url( '/workos/callback' ) ); ?></code></td>
					</tr>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Add a simple text/password settings field.
	 *
	 * @param string $name    Option name.
	 * @param string $label   Field label.
	 * @param string $type    Input type.
	 * @param string $section Section ID.
	 */
	private function add_field( string $name, string $label, string $type, string $section ): void {
		register_setting( self::OPTION_GROUP, $name, [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		] );

		add_settings_field(
			$name,
			$label,
			[ $this, 'render_input' ],
			'workos',
			$section,
			[ 'name' => $name, 'type' => $type ]
		);
	}

	/**
	 * Render a text/password input.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_input( array $args ): void {
		$value = get_option( $args['name'], '' );
		printf(
			'<input type="%s" name="%s" value="%s" class="regular-text" autocomplete="off" />',
			esc_attr( $args['type'] ),
			esc_attr( $args['name'] ),
			esc_attr( $value )
		);
	}

	/**
	 * Render a select dropdown.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_select( array $args ): void {
		$value = get_option( $args['name'], '' );
		echo '<select name="' . esc_attr( $args['name'] ) . '">';
		foreach ( $args['options'] as $key => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $key ),
				selected( $value, $key, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	/**
	 * Render a checkbox.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_checkbox( array $args ): void {
		$value = get_option( $args['name'], false );
		printf(
			'<label><input type="checkbox" name="%s" value="1" %s /> %s</label>',
			esc_attr( $args['name'] ),
			checked( $value, true, false ),
			esc_html( $args['label'] )
		);
	}

	/**
	 * Render a user select for content reassignment.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_user_select( array $args ): void {
		$value = (int) get_option( $args['name'], 0 );
		wp_dropdown_users( [
			'name'             => $args['name'],
			'selected'         => $value,
			'show_option_none' => __( '— Select User —', 'workos' ),
			'role__in'         => [ 'administrator', 'editor' ],
		] );
		echo '<p class="description">' . esc_html__( 'Content from deleted users will be reassigned to this user.', 'workos' ) . '</p>';
	}

	/**
	 * Render the role mapping table.
	 */
	public function render_role_map(): void {
		$map      = \WorkOS\Sync\RoleMapper::get_role_map();
		$wp_roles = \WorkOS\Sync\RoleMapper::get_wp_roles();

		echo '<table class="widefat" style="max-width:500px"><thead><tr>';
		echo '<th>' . esc_html__( 'WorkOS Role', 'workos' ) . '</th>';
		echo '<th>' . esc_html__( 'WordPress Role', 'workos' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $map as $workos_role => $wp_role ) {
			echo '<tr><td>';
			printf(
				'<input type="text" name="workos_role_map[keys][]" value="%s" class="regular-text" />',
				esc_attr( $workos_role )
			);
			echo '</td><td>';
			echo '<select name="workos_role_map[values][]">';
			foreach ( $wp_roles as $slug => $name ) {
				printf(
					'<option value="%s" %s>%s</option>',
					esc_attr( $slug ),
					selected( $wp_role, $slug, false ),
					esc_html( $name )
				);
			}
			echo '</select>';
			echo '</td></tr>';
		}

		// Empty row for adding new mappings.
		echo '<tr><td>';
		echo '<input type="text" name="workos_role_map[keys][]" value="" class="regular-text" placeholder="' . esc_attr__( 'New WorkOS role...', 'workos' ) . '" />';
		echo '</td><td>';
		echo '<select name="workos_role_map[values][]">';
		echo '<option value="">' . esc_html__( '— Select —', 'workos' ) . '</option>';
		foreach ( $wp_roles as $slug => $name ) {
			printf( '<option value="%s">%s</option>', esc_attr( $slug ), esc_html( $name ) );
		}
		echo '</select>';
		echo '</td></tr>';

		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Map WorkOS organization roles to WordPress roles. The "member" role is the default fallback.', 'workos' ) . '</p>';
	}

	/**
	 * Sanitize the role map from the form submission.
	 *
	 * @param mixed $input Raw form input.
	 *
	 * @return array Sanitized role map.
	 */
	public function sanitize_role_map( $input ): array {
		if ( ! is_array( $input ) || empty( $input['keys'] ) || empty( $input['values'] ) ) {
			return [];
		}

		$map = [];
		foreach ( $input['keys'] as $i => $key ) {
			$key   = sanitize_text_field( $key );
			$value = sanitize_text_field( $input['values'][ $i ] ?? '' );
			if ( $key && $value ) {
				$map[ $key ] = $value;
			}
		}

		return $map;
	}

	/**
	 * Add a Settings link to the plugin row.
	 *
	 * @param array $links Existing links.
	 *
	 * @return array
	 */
	public function action_links( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=workos' ) ),
			esc_html__( 'Settings', 'workos' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}
}
