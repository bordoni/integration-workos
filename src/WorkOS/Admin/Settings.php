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
	 * Page slug for the Users tab.
	 */
	private const USERS_PAGE = 'workos-users';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_filter( 'plugin_action_links_' . WORKOS_BASENAME, [ $this, 'action_links' ] );
	}

	/**
	 * Get the current tab from the query string.
	 *
	 * @return string Current tab slug.
	 */
	private function get_current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab navigation, no data modification.
		$tab = sanitize_text_field( wp_unslash( $_GET['tab'] ?? 'general' ) );
		return in_array( $tab, [ 'general', 'users' ], true ) ? $tab : 'general';
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
	 * Enqueue assets for the settings page.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'toplevel_page_workos' !== $hook_suffix ) {
			return;
		}

		$current_tab = $this->get_current_tab();

		if ( 'general' === $current_tab ) {
			add_thickbox();
		}

		if ( 'users' === $current_tab ) {
			$this->enqueue_role_mapping_assets();
		}
	}

	/**
	 * Enqueue role-mapping script and styles on the Users tab.
	 */
	private function enqueue_role_mapping_assets(): void {
		$asset_file = WORKOS_DIR . 'build/role-mapping.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = include $asset_file;

		wp_enqueue_script(
			'workos-role-mapping',
			WORKOS_URL . 'build/role-mapping.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_enqueue_style(
			'workos-role-mapping',
			WORKOS_URL . 'build/role-mapping.css',
			[],
			$asset['version']
		);

		$wp_roles = \WorkOS\Sync\RoleMapper::get_wp_roles();
		wp_add_inline_script(
			'workos-role-mapping',
			'window.workosRoleMapping = ' . wp_json_encode( [ 'wpRoles' => $wp_roles ] ) . ';',
			'before'
		);
	}

	/**
	 * Register all settings.
	 *
	 * Registers all options for both tabs, then delegates section/field
	 * registration to per-tab methods based on the current tab.
	 */
	public function register_settings(): void {
		// Register all options regardless of active tab so saving
		// on one tab does not blank out the other tab's values.
		$this->register_all_options();

		$current_tab = $this->get_current_tab();

		if ( 'users' === $current_tab ) {
			$this->register_users_fields();
		} else {
			$this->register_general_fields();
		}
	}

	/**
	 * Register all option names with the Settings API.
	 */
	private function register_all_options(): void {
		register_setting(
			self::OPTION_GROUP,
			'workos_api_key',
			[
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			]
		);

		register_setting(
			self::OPTION_GROUP,
			'workos_client_id',
			[
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			]
		);

		register_setting(
			self::OPTION_GROUP,
			'workos_login_mode',
			[
				'type'              => 'string',
				'default'           => 'redirect',
				'sanitize_callback' => 'sanitize_text_field',
			]
		);

		register_setting(
			self::OPTION_GROUP,
			'workos_allow_password_fallback',
			[
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
			]
		);

		register_setting(
			self::OPTION_GROUP,
			'workos_webhook_secret',
			[
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			]
		);

		register_setting(
			self::OPTION_GROUP,
			'workos_deprovision_action',
			[
				'type'              => 'string',
				'default'           => 'deactivate',
				'sanitize_callback' => 'sanitize_text_field',
			]
		);

		register_setting(
			self::OPTION_GROUP,
			'workos_reassign_user',
			[
				'type'              => 'integer',
				'default'           => 0,
				'sanitize_callback' => 'absint',
			]
		);

		register_setting(
			self::OPTION_GROUP,
			'workos_role_map',
			[
				'type'              => 'array',
				'default'           => [],
				'sanitize_callback' => [ $this, 'sanitize_role_map' ],
			]
		);

		register_setting(
			self::OPTION_GROUP,
			'workos_audit_logging_enabled',
			[
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			]
		);
	}

	/**
	 * Register sections and fields for the General tab.
	 */
	private function register_general_fields(): void {
		// --- API Credentials section ---
		add_settings_section(
			'workos_api',
			__( 'API Credentials', 'workos' ),
			function () {
				printf(
					'<p>%s <a href="%s" target="_blank">%s</a></p>',
					esc_html__( 'Enter your API key and Client ID from the', 'workos' ),
					'https://dashboard.workos.com/api-keys',
					esc_html__( 'WorkOS Dashboard &rarr; API Keys', 'workos' )
				);
			},
			'workos'
		);

		$this->add_field(
			'workos_api_key',
			__( 'API Key', 'workos' ),
			'password',
			'workos_api',
			__( 'Found under API Keys in the WorkOS Dashboard. Starts with "sk_".', 'workos' )
		);
		$this->add_field(
			'workos_client_id',
			__( 'Client ID', 'workos' ),
			'text',
			'workos_api',
			__( 'Found under API Keys in the WorkOS Dashboard. Starts with "client_".', 'workos' )
		);

		// --- Authentication section ---
		add_settings_section(
			'workos_auth',
			__( 'Authentication', 'workos' ),
			function () {
				echo '<p>' . esc_html__( 'Configure how users authenticate with your site.', 'workos' ) . '</p>';
			},
			'workos'
		);

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
				echo '<p>' . esc_html__( 'Webhooks allow WorkOS to notify your site when users, organizations, or memberships change.', 'workos' ) . '</p>';
				echo '<ol>';
				printf(
					'<li>%s <a href="%s" target="_blank">%s</a></li>',
					esc_html__( 'Go to', 'workos' ),
					'https://dashboard.workos.com/webhooks',
					esc_html__( 'WorkOS Dashboard &rarr; Webhooks', 'workos' )
				);
				printf(
					'<li>%s <code>%s</code></li>',
					esc_html__( 'Create a new endpoint with this URL:', 'workos' ),
					esc_url( $url )
				);
				printf(
					'<li>%s <a href="#TB_inline?width=480&height=500&inlineId=workos-webhook-events" class="thickbox">%s</a></li>',
					esc_html__( 'Subscribe to the required events &mdash;', 'workos' ),
					esc_html__( 'View required events', 'workos' )
				);
				echo '<li>' . esc_html__( 'Copy the signing secret that WorkOS generates and paste it below.', 'workos' ) . '</li>';
				echo '</ol>';

				$this->render_webhook_events_modal();
			},
			'workos'
		);

		$this->add_field(
			'workos_webhook_secret',
			__( 'Webhook Secret', 'workos' ),
			'password',
			'workos_webhooks',
			__( 'The signing secret from your WorkOS webhook endpoint.', 'workos' )
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
	 * Register sections and fields for the Users tab.
	 */
	private function register_users_fields(): void {
		// --- User Provisioning section ---
		add_settings_section(
			'workos_provisioning',
			__( 'User Provisioning', 'workos' ),
			function () {
				echo '<p>' . esc_html__( 'Configure how users are deprovisioned when removed from WorkOS.', 'workos' ) . '</p>';
			},
			self::USERS_PAGE
		);

		add_settings_field(
			'workos_deprovision_action',
			__( 'Deprovision Action', 'workos' ),
			[ $this, 'render_select' ],
			self::USERS_PAGE,
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

		add_settings_field(
			'workos_reassign_user',
			__( 'Reassign Content To', 'workos' ),
			[ $this, 'render_user_select' ],
			self::USERS_PAGE,
			'workos_provisioning',
			[ 'name' => 'workos_reassign_user' ]
		);

		// --- Role Mapping section ---
		add_settings_section(
			'workos_roles',
			__( 'Role Mapping', 'workos' ),
			function () {
				echo '<p>' . esc_html__( 'Map WorkOS roles to WordPress roles. Users will be assigned the mapped WP role on login.', 'workos' ) . '</p>';
				$this->render_role_map();
			},
			self::USERS_PAGE
		);
	}

	/**
	 * Render the settings page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$current_tab = $this->get_current_tab();
		$tabs        = [
			'general' => __( 'General', 'workos' ),
			'users'   => __( 'Users', 'workos' ),
		];
		$page_slug   = 'general' === $current_tab ? 'workos' : self::USERS_PAGE;

		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php settings_errors( 'workos_messages' ); ?>

			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'tab', $slug, admin_url( 'admin.php?page=workos' ) ) ); ?>"
						class="nav-tab <?php echo $slug === $current_tab ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<form action="options.php" method="post">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( $page_slug );
				submit_button( __( 'Save Settings', 'workos' ) );
				?>
			</form>

			<?php if ( 'general' === $current_tab && workos()->is_enabled() ) : ?>
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
	 * @param string $name        Option name.
	 * @param string $label       Field label.
	 * @param string $type        Input type.
	 * @param string $section     Section ID.
	 * @param string $description Optional help text shown below the field.
	 * @param string $page        Page slug for add_settings_field.
	 */
	private function add_field( string $name, string $label, string $type, string $section, string $description = '', string $page = 'workos' ): void {
		add_settings_field(
			$name,
			$label,
			[ $this, 'render_input' ],
			$page,
			$section,
			[
				'name'        => $name,
				'type'        => $type,
				'description' => $description,
			]
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

		if ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . esc_html( $args['description'] ) . '</p>';
		}
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
		wp_dropdown_users(
			[
				'name'             => $args['name'],
				'selected'         => $value,
				'show_option_none' => __( '— Select User —', 'workos' ),
				'role__in'         => [ 'administrator', 'editor' ],
			]
		);
		echo '<p class="description">' . esc_html__( 'Content from deleted users will be reassigned to this user.', 'workos' ) . '</p>';
	}

	/**
	 * Render the hidden Thickbox modal listing required webhook events.
	 */
	private function render_webhook_events_modal(): void {
		$event_groups = [];

		$event_groups[ __( 'User Management', 'workos' ) ] = [
			'user.created',
			'user.updated',
			'user.deleted',
		];

		$event_groups[ __( 'Directory Sync', 'workos' ) ] = [
			'dsync.user.created',
			'dsync.user.updated',
			'dsync.user.deleted',
			'dsync.group.user_added',
			'dsync.group.user_removed',
		];

		$event_groups[ __( 'Organizations', 'workos' ) ] = [
			'organization.created',
			'organization.updated',
			'organization_membership.created',
			'organization_membership.updated',
			'organization_membership.deleted',
		];

		$event_groups[ __( 'Connections', 'workos' ) ] = [
			'connection.activated',
			'connection.deactivated',
		];

		$event_groups[ __( 'Authentication', 'workos' ) ] = [
			'authentication.email_verification_succeeded',
		];

		echo '<div id="workos-webhook-events" style="display:none">';
		echo '<p>' . esc_html__( 'Subscribe to the following events when creating your webhook endpoint in the WorkOS Dashboard.', 'workos' ) . '</p>';

		foreach ( $event_groups as $label => $events ) {
			echo '<h4 style="margin-bottom:4px">' . esc_html( $label ) . '</h4>';
			echo '<ul style="margin-top:0">';
			foreach ( $events as $event ) {
				echo '<li><code>' . esc_html( $event ) . '</code></li>';
			}
			echo '</ul>';
		}

		echo '</div>';
	}

	/**
	 * Render the role mapping table.
	 */
	public function render_role_map(): void {
		$map      = \WorkOS\Sync\RoleMapper::get_role_map();
		$wp_roles = \WorkOS\Sync\RoleMapper::get_wp_roles();

		echo '<table id="workos-role-map-table" class="widefat"><thead><tr>';
		echo '<th>' . esc_html__( 'WorkOS Role', 'workos' ) . '</th>';
		echo '<th>' . esc_html__( 'WordPress Role', 'workos' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $map as $workos_role => $wp_role ) {
			echo '<tr class="workos-role-map-row"><td>';
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

		// Empty row for no-JS fallback.
		echo '<tr class="workos-role-map-row"><td>';
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

		// Add Mapping button — hidden until JS loads.
		echo '<button type="button" id="workos-role-map-add" class="button" style="display:none">';
		echo '<span class="dashicons dashicons-plus-alt2"></span> ';
		echo esc_html__( 'Add Mapping', 'workos' );
		echo '</button>';

		echo '<p class="description" style="margin-top:8px">' . esc_html__( 'The "member" role is used as the default fallback.', 'workos' ) . '</p>';
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
