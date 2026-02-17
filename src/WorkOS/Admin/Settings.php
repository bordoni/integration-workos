<?php
/**
 * Admin settings page.
 *
 * @package WorkOS\Admin
 */

namespace WorkOS\Admin;

use WorkOS\Config;

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

		// Flush organizations cache when environment credentials change.
		foreach ( [ 'production', 'staging' ] as $env ) {
			add_action( "update_option_workos_{$env}", [ $this, 'flush_organizations_cache' ] );
		}
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
	 * Get the environment currently being edited on the settings page.
	 *
	 * This is driven by the ?env= query param and is independent of
	 * the active environment used by the rest of the plugin.
	 *
	 * @return string 'production' or 'staging'.
	 */
	private function get_editing_environment(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation param, no data modification.
		$env = sanitize_text_field( wp_unslash( $_GET['env'] ?? Config::get_active_environment() ) );

		return in_array( $env, [ 'production', 'staging' ], true ) ? $env : 'production';
	}

	/**
	 * Get the array-style option name for an environment credential setting.
	 *
	 * Uses the editing environment, not the active environment,
	 * so credentials can be saved for either env without switching the active one.
	 *
	 * @param string $setting Setting name (e.g. 'api_key').
	 *
	 * @return string Option name (e.g. 'workos_production[api_key]').
	 */
	private function env_option( string $setting ): string {
		return sprintf( 'workos_%s[%s]', $this->get_editing_environment(), $setting );
	}

	/**
	 * Get the array-style option name for a global setting.
	 *
	 * @param string $setting Setting name (e.g. 'login_mode').
	 *
	 * @return string Option name (e.g. 'workos_global[login_mode]').
	 */
	private function global_option( string $setting ): string {
		return "workos_global[{$setting}]";
	}

	/**
	 * Get the organizations cache transient key for the editing environment.
	 *
	 * @return string
	 */
	private function get_orgs_cache_key(): string {
		return 'workos_organizations_cache_' . $this->get_editing_environment();
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
		// Per-environment credential options (2 serialized array rows).
		foreach ( [ 'production', 'staging' ] as $env ) {
			register_setting(
				self::OPTION_GROUP,
				"workos_{$env}",
				[
					'type'              => 'array',
					'default'           => [],
					'sanitize_callback' => [ $this, 'sanitize_environment_options' ],
				]
			);
		}

		// Global settings (1 serialized array row).
		register_setting(
			self::OPTION_GROUP,
			'workos_global',
			[
				'type'              => 'array',
				'default'           => [],
				'sanitize_callback' => [ $this, 'sanitize_global_options' ],
			]
		);

		// Active environment stays standalone (read before container boots).
		register_setting(
			self::OPTION_GROUP,
			'workos_active_environment',
			[
				'type'              => 'string',
				'default'           => 'production',
				'sanitize_callback' => function ( $value ) {
					return in_array( $value, [ 'production', 'staging' ], true ) ? $value : 'production';
				},
			]
		);
	}

	/**
	 * Register sections and fields for the General tab.
	 */
	private function register_general_fields(): void {
		$editing_env = $this->get_editing_environment();
		$env_label   = Config::get_environments()[ $editing_env ] ?? 'Production';

		// --- API Credentials section ---
		add_settings_section(
			'workos_api',
			/* translators: %s: environment name (Production or Staging) */
			sprintf( __( 'API Credentials (%s)', 'workos' ), $env_label ),
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
			$this->env_option( 'api_key' ),
			__( 'API Key', 'workos' ),
			'password',
			'workos_api',
			__( 'Found under API Keys in the WorkOS Dashboard. Starts with "sk_".', 'workos' )
		);
		$this->add_field(
			$this->env_option( 'client_id' ),
			__( 'Client ID', 'workos' ),
			'text',
			'workos_api',
			__( 'Found under API Keys in the WorkOS Dashboard. Starts with "client_".', 'workos' )
		);
		$this->add_field(
			$this->env_option( 'environment_id' ),
			__( 'Environment ID', 'workos' ),
			'text',
			'workos_api',
			__( 'Found in the WorkOS Dashboard URL after /environment_. Starts with "environment_".', 'workos' )
		);

		// --- Organization section ---
		add_settings_section(
			'workos_organization',
			__( 'Organization', 'workos' ),
			function () {
				echo '<p>' . esc_html__( 'Select the WorkOS organization this site belongs to.', 'workos' ) . '</p>';
			},
			'workos'
		);

		$env = $this->get_editing_environment();
		add_settings_field(
			"workos_{$env}_organization_id",
			__( 'Organization', 'workos' ),
			[ $this, 'render_organization_select' ],
			'workos',
			'workos_organization'
		);

		// --- Active Environment section ---
		add_settings_section(
			'workos_active_env',
			__( 'Active Environment', 'workos' ),
			function () {
				echo '<p>' . esc_html__( 'Select which environment the plugin uses for authentication, webhooks, and API calls.', 'workos' ) . '</p>';
			},
			'workos'
		);

		add_settings_field(
			'workos_active_environment',
			__( 'Active Environment', 'workos' ),
			[ $this, 'render_active_environment_field' ],
			'workos',
			'workos_active_env'
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
			'workos_global_login_mode',
			__( 'Login Mode', 'workos' ),
			[ $this, 'render_select' ],
			'workos',
			'workos_auth',
			[
				'name'    => $this->global_option( 'login_mode' ),
				'options' => [
					'redirect' => __( 'AuthKit Redirect (Recommended)', 'workos' ),
					'headless' => __( 'Headless API (Custom Form)', 'workos' ),
				],
			]
		);

		add_settings_field(
			'workos_global_allow_password_fallback',
			__( 'Password Fallback', 'workos' ),
			[ $this, 'render_checkbox' ],
			'workos',
			'workos_auth',
			[
				'name'  => $this->global_option( 'allow_password_fallback' ),
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
			$this->env_option( 'webhook_secret' ),
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
			'workos_global_audit_logging_enabled',
			__( 'Enable Audit Logging', 'workos' ),
			[ $this, 'render_checkbox' ],
			'workos',
			'workos_audit',
			[
				'name'  => $this->global_option( 'audit_logging_enabled' ),
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
			'workos_global_deprovision_action',
			__( 'Deprovision Action', 'workos' ),
			[ $this, 'render_select' ],
			self::USERS_PAGE,
			'workos_provisioning',
			[
				'name'    => $this->global_option( 'deprovision_action' ),
				'options' => [
					'deactivate' => __( 'Deactivate (mark as inactive)', 'workos' ),
					'demote'     => __( 'Demote to Subscriber role', 'workos' ),
					'delete'     => __( 'Delete user (reassign content)', 'workos' ),
				],
			]
		);

		add_settings_field(
			'workos_global_reassign_user',
			__( 'Reassign Content To', 'workos' ),
			[ $this, 'render_user_select' ],
			self::USERS_PAGE,
			'workos_provisioning',
			[ 'name' => $this->global_option( 'reassign_user' ) ]
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

			<?php $this->render_environment_toggle(); ?>

			<nav class="nav-tab-wrapper">
				<?php
				$editing_env = $this->get_editing_environment();
				foreach ( $tabs as $slug => $label ) :
					$tab_url = add_query_arg(
						[ 'tab' => $slug, 'env' => $editing_env ],
						admin_url( 'admin.php?page=workos' )
					);
					?>
					<a href="<?php echo esc_url( $tab_url ); ?>"
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
	 * Render the environment view-switcher above the tab navigation.
	 *
	 * This controls which environment's credentials are displayed for editing.
	 * It does NOT change the active environment — that is a saved setting in the form.
	 */
	private function render_environment_toggle(): void {
		$editing      = $this->get_editing_environment();
		$environments = Config::get_environments();
		$current_tab  = $this->get_current_tab();
		$base_url     = add_query_arg( 'tab', $current_tab, admin_url( 'admin.php?page=workos' ) );

		?>
		<div style="margin: 12px 0 8px;">
			<?php foreach ( $environments as $slug => $label ) :
				$url       = add_query_arg( 'env', $slug, $base_url );
				$is_active = $slug === $editing;
				?>
				<a
					href="<?php echo esc_url( $url ); ?>"
					style="
						display: inline-block;
						padding: 6px 16px;
						margin-right: 4px;
						border: 1px solid <?php echo $is_active ? 'transparent' : '#8c8f94'; ?>;
						border-radius: 4px;
						font-weight: 600;
						font-size: 13px;
						text-decoration: none;
						color: <?php echo $is_active ? '#fff' : '#50575e'; ?>;
						background: <?php
						if ( $is_active ) {
							echo 'staging' === $slug ? '#dba617' : '#00a32a';
						} else {
							echo '#f0f0f1';
						}
						?>;
					"
				>
					<?php echo esc_html( $label ); ?>
				</a>
			<?php endforeach; ?>
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
		$field_id = str_replace( [ '[', ']' ], [ '_', '' ], $name );
		add_settings_field(
			$field_id,
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
	 * Render the Active Environment select (or locked indicator).
	 */
	public function render_active_environment_field(): void {
		if ( Config::is_environment_overridden() ) {
			$env   = Config::get_active_environment();
			$label = Config::get_environments()[ $env ] ?? $env;
			printf(
				'<input type="text" value="%s" class="regular-text" disabled /> <em>%s</em>',
				esc_attr( $label ),
				esc_html__( 'Locked via WORKOS_ENVIRONMENT constant.', 'workos' )
			);
			return;
		}

		$value = Config::get_active_environment();
		echo '<select name="workos_active_environment">';
		foreach ( Config::get_environments() as $slug => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $slug ),
				selected( $value, $slug, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'The plugin will use this environment for all authentication, webhooks, and API calls.', 'workos' ) . '</p>';
	}

	/**
	 * Render a text/password input.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_input( array $args ): void {
		$value = $this->get_field_value( $args['name'], '' );
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
		$value = $this->get_field_value( $args['name'], '' );
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
		$value = $this->get_field_value( $args['name'], false );
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
		$value = (int) $this->get_field_value( $args['name'], 0 );
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
	 * Get the current value for a field, supporting array-style names.
	 *
	 * @param string $name    Option name (e.g. 'workos_production[api_key]' or 'workos_active_environment').
	 * @param mixed  $default Default value.
	 *
	 * @return mixed
	 */
	private function get_field_value( string $name, $default = '' ) {
		if ( preg_match( '/^([^\[]+)\[([^\]]+)\]$/', $name, $m ) ) {
			$option = get_option( $m[1], [] );
			return $option[ $m[2] ] ?? $default;
		}
		return get_option( $name, $default );
	}

	/**
	 * Render the organization select dropdown.
	 *
	 * Shows a prompt to configure API credentials if the plugin is not enabled.
	 * When enabled, fetches orgs from WorkOS with transient caching.
	 */
	public function render_organization_select(): void {
		$option_name = $this->env_option( 'organization_id' );

		if ( ! workos()->is_enabled() ) {
			echo '<p class="description">' . esc_html__( 'Configure API credentials above and save to select an organization.', 'workos' ) . '</p>';
			return;
		}

		$is_overridden = Config::is_overridden( 'organization_id' );
		$current_value = Config::get_organization_id();

		// Fetch organizations with transient cache.
		$cache_key     = $this->get_orgs_cache_key();
		$organizations = get_transient( $cache_key );
		if ( false === $organizations ) {
			$result = workos()->api()->list_organizations();

			if ( is_wp_error( $result ) ) {
				printf(
					'<p class="description" style="color:#d63638">%s %s</p>',
					esc_html__( 'Could not fetch organizations:', 'workos' ),
					esc_html( $result->get_error_message() )
				);
				return;
			}

			$organizations = $result['data'] ?? [];
			set_transient( $cache_key, $organizations, 5 * MINUTE_IN_SECONDS );
		}

		if ( $is_overridden ) {
			// Find the org name for display.
			$org_name = $current_value;
			foreach ( $organizations as $org ) {
				if ( ( $org['id'] ?? '' ) === $current_value ) {
					$org_name = $org['name'] ?? $current_value;
					break;
				}
			}
			printf(
				'<input type="text" value="%s" class="regular-text" disabled /> <em>%s</em>',
				esc_attr( $org_name ),
				esc_html__( 'Set via WORKOS_ORGANIZATION_ID constant.', 'workos' )
			);
			return;
		}

		echo '<select name="' . esc_attr( $option_name ) . '">';
		printf( '<option value="">%s</option>', esc_html__( '— Select Organization —', 'workos' ) );
		foreach ( $organizations as $org ) {
			$org_id   = $org['id'] ?? '';
			$org_name = $org['name'] ?? $org_id;
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $org_id ),
				selected( $current_value, $org_id, false ),
				esc_html( $org_name )
			);
		}
		echo '</select>';
	}

	/**
	 * Flush the organizations transient cache.
	 */
	public function flush_organizations_cache(): void {
		foreach ( [ 'production', 'staging' ] as $env ) {
			delete_transient( 'workos_organizations_cache_' . $env );
		}
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
				'<input type="text" name="workos_global[role_map][keys][]" value="%s" class="regular-text" />',
				esc_attr( $workos_role )
			);
			echo '</td><td>';
			echo '<select name="workos_global[role_map][values][]">';
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
		echo '<input type="text" name="workos_global[role_map][keys][]" value="" class="regular-text" placeholder="' . esc_attr__( 'New WorkOS role...', 'workos' ) . '" />';
		echo '</td><td>';
		echo '<select name="workos_global[role_map][values][]">';
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
	 * Sanitize an environment options array (production or staging).
	 *
	 * @param mixed $input Raw form input.
	 *
	 * @return array Sanitized options.
	 */
	public function sanitize_environment_options( $input ): array {
		if ( ! is_array( $input ) || empty( $input ) ) {
			// Determine which option is being saved from the current filter name.
			$option_name = str_replace( 'sanitize_option_', '', current_filter() );
			return get_option( $option_name, [] );
		}

		$allowed = [ 'api_key', 'client_id', 'webhook_secret', 'organization_id', 'environment_id' ];
		$out     = [];
		foreach ( $allowed as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = sanitize_text_field( $input[ $key ] );
			}
		}
		return $out;
	}

	/**
	 * Sanitize the global options array.
	 *
	 * @param mixed $input Raw form input.
	 *
	 * @return array Sanitized options.
	 */
	public function sanitize_global_options( $input ): array {
		if ( ! is_array( $input ) || empty( $input ) ) {
			return get_option( 'workos_global', [] );
		}

		$existing = get_option( 'workos_global', [] );

		// Merge submitted keys on top of existing (preserves keys not in form).
		return array_merge( $existing, [
			'login_mode'              => sanitize_text_field( $input['login_mode'] ?? $existing['login_mode'] ?? 'redirect' ),
			'allow_password_fallback' => rest_sanitize_boolean( $input['allow_password_fallback'] ?? $existing['allow_password_fallback'] ?? true ),
			'deprovision_action'      => sanitize_text_field( $input['deprovision_action'] ?? $existing['deprovision_action'] ?? 'deactivate' ),
			'reassign_user'           => absint( $input['reassign_user'] ?? $existing['reassign_user'] ?? 0 ),
			'role_map'                => $this->sanitize_role_map( $input['role_map'] ?? $existing['role_map'] ?? [] ),
			'audit_logging_enabled'   => rest_sanitize_boolean( $input['audit_logging_enabled'] ?? $existing['audit_logging_enabled'] ?? false ),
		] );
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
			// If it's already a flat map (e.g. from existing DB), pass through.
			if ( is_array( $input ) && ! isset( $input['keys'] ) ) {
				return array_map( 'sanitize_text_field', $input );
			}
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
