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
	 * Page slug for the Organization tab.
	 */
	private const ORG_PAGE = 'workos-organization';

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
		add_action( 'admin_init', [ $this, 'handle_activate_environment' ] );
		add_action( 'admin_post_workos_create_org', [ $this, 'handle_create_org' ] );
		add_action( 'admin_post_workos_sync_roles_to_workos', [ $this, 'handle_sync_roles_to_workos' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_filter( 'plugin_action_links_' . WORKOS_BASENAME, [ $this, 'action_links' ] );

		// Flush organizations cache when environment credentials change.
		foreach ( [ 'production', 'staging' ] as $env ) {
			add_action( "update_option_workos_{$env}", [ $this, 'flush_organizations_cache' ] );
		}

		// Admin notice for users upgrading who are missing environment_id.
		add_action( 'admin_notices', [ $this, 'maybe_show_environment_id_notice' ] );
	}

	/**
	 * Show admin notice when api_key + client_id are set but environment_id is missing.
	 */
	public function maybe_show_environment_id_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! empty( Config::get_api_key() ) && ! empty( Config::get_client_id() ) && empty( Config::get_environment_id() ) ) {
			printf(
				'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'WorkOS now requires an Environment ID to be fully configured.', 'integration-workos' ),
				esc_url( admin_url( 'admin.php?page=workos' ) ),
				esc_html__( 'Update your settings.', 'integration-workos' )
			);
		}
	}

	/**
	 * Get the current tab from the query string.
	 *
	 * @return string Current tab slug.
	 */
	private function get_current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab navigation, no data modification.
		$tab = sanitize_text_field( wp_unslash( $_GET['tab'] ?? 'settings' ) );

		if ( ! in_array( $tab, [ 'settings', 'organization', 'users' ], true ) ) {
			return 'settings';
		}

		// Force redirect to settings if preconditions not met.
		if ( 'organization' === $tab && ! $this->is_editing_env_configured() ) {
			return 'settings';
		}

		if ( 'users' === $tab && ! $this->has_editing_env_org() ) {
			return 'settings';
		}

		return $tab;
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

		return in_array( $env, [ 'production', 'staging' ], true ) ? $env : 'staging';
	}

	/**
	 * Get the array-style option name for an environment setting.
	 *
	 * Uses the editing environment, not the active environment,
	 * so settings can be saved for either env without switching the active one.
	 *
	 * @param string $setting Setting name (e.g. 'api_key').
	 *
	 * @return string Option name (e.g. 'workos_production[api_key]').
	 */
	private function env_option( string $setting ): string {
		return sprintf( 'workos_%s[%s]', $this->get_editing_environment(), $setting );
	}

	/**
	 * Check if the editing environment has api_key + client_id + environment_id configured.
	 *
	 * @return bool
	 */
	private function is_editing_env_configured(): bool {
		$env     = $this->get_editing_environment();
		$options = get_option( "workos_{$env}", [] );

		return ! empty( $options['api_key'] )
			&& ! empty( $options['client_id'] )
			&& ! empty( $options['environment_id'] );
	}

	/**
	 * Check if the editing environment has an organization_id set.
	 *
	 * @return bool
	 */
	private function has_editing_env_org(): bool {
		if ( ! $this->is_editing_env_configured() ) {
			return false;
		}

		$env     = $this->get_editing_environment();
		$options = get_option( "workos_{$env}", [] );

		return ! empty( $options['organization_id'] );
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
			__( 'WorkOS Settings', 'integration-workos' ),
			__( 'WorkOS', 'integration-workos' ),
			'manage_options',
			'workos',
			[ $this, 'render_page' ],
			'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAiIGhlaWdodD0iMjAiIHZpZXdCb3g9IjAgMCA1OSA1MSIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cGF0aCBkPSJNMCAyNS40MDAyQzAgMjYuNTEzOSAwLjI5MzA3OSAyNy42Mjc2IDAuODU5Njk5IDI4LjU4NUwxMS4xMzcgNDYuMzg0N0MxMi4xOTIxIDQ4LjIwMTggMTMuNzk0MyA0OS42ODY3IDE1Ljc4NzIgNTAuMzUxQzE5LjcxNDUgNTEuNjYwMSAyMy43Nzg1IDQ5Ljk3OTggMjUuNzEyOCA0Ni42MTkyTDI4LjE5NDIgNDIuMzIwN0wxOC40MDU0IDI1LjQwMDJMMjguNzQxMyA3LjQ4MzI5TDMxLjIyMjcgMy4xODQ4QzMxLjk2NTIgMS44OTUyNSAzMi45NjE3IDAuODQwMTYxIDM0LjEzNCAwSDMzLjA1OTRIMTguMTkwNUMxNS4zOTY0IDAgMTIuODE3MyAxLjQ4NDk0IDExLjQzMDEgMy45MDc3MkwwLjg1OTY5OSAyMi4yMTU0QzAuMjkzMDc5IDIzLjE3MjggMCAyNC4yODY1IDAgMjUuNDAwMloiIGZpbGw9ImJsYWNrIi8+PHBhdGggZD0iTTU4LjYxNTEgMjUuNDAwMUM1OC42MTUxIDI0LjI4NjQgNTguMzIyIDIzLjE3MjcgNTcuNzU1NCAyMi4yMTUzTDQ3LjM0MTMgNC4xODExOEM0NS40MDcgMC44NDAwNzggNDEuMzQzIC0wLjg0MDI0MyAzNy40MTU3IDAuNDQ5MzA2QzM1LjQyMjggMS4xMTM2MiAzMy44MjA2IDIuNTk4NTUgMzIuNzY1NSA0LjQxNTY1TDMwLjQyMDkgOC40NjAxNEw0MC4yMDk3IDI1LjQwMDFMMjkuODczOCA0My4zMTdMMjcuMzkyNCA0Ny42MTU1QzI2LjY0OTkgNDguODg1NiAyNS42NTM1IDQ5Ljk2MDIgMjQuNDgxMSA1MC44MDAzSDI1LjU1NThINDAuNDI0N0M0My4yMTg3IDUwLjgwMDMgNDUuNzk3OCA0OS4zMTU0IDQ3LjE4NSA0Ni44OTI2TDU3Ljc1NTQgMjguNTg0OUM1OC4zMjIgMjcuNjI3NSA1OC42MTUxIDI2LjUxMzggNTguNjE1MSAyNS40MDAxWiIgZmlsbD0iYmxhY2siLz48L3N2Zz4=',
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

		if ( in_array( $current_tab, [ 'settings', 'organization' ], true ) ) {
			add_thickbox();
		}

		if ( 'users' === $current_tab ) {
			$this->enqueue_role_mapping_assets();
			$this->enqueue_redirect_urls_assets();
			$this->enqueue_logout_redirect_urls_assets();
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

		$wp_roles     = \WorkOS\Sync\RoleMapper::get_wp_roles();
		$workos_roles = $this->get_workos_org_roles();
		wp_add_inline_script(
			'workos-role-mapping',
			'window.workosRoleMapping = ' . wp_json_encode(
				[
					'wpRoles'     => $wp_roles,
					'workosRoles' => $workos_roles,
					'env'         => $this->get_editing_environment(),
				]
			) . ';',
			'before'
		);
	}

	/**
	 * Enqueue redirect-urls script and styles on the Users tab.
	 */
	private function enqueue_redirect_urls_assets(): void {
		$asset_file = WORKOS_DIR . 'build/redirect-urls.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = include $asset_file;

		wp_enqueue_script(
			'workos-redirect-urls',
			WORKOS_URL . 'build/redirect-urls.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_enqueue_style(
			'workos-redirect-urls',
			WORKOS_URL . 'build/redirect-urls.css',
			[],
			$asset['version']
		);

		$wp_roles  = \WorkOS\Sync\RoleMapper::get_wp_roles();
		$all_roles = array_merge(
			[ '__default__' => __( 'Default (all roles)', 'integration-workos' ) ],
			$wp_roles
		);
		wp_add_inline_script(
			'workos-redirect-urls',
			'window.workosRedirectUrls = ' . wp_json_encode(
				[
					'wpRoles' => $all_roles,
					'env'     => $this->get_editing_environment(),
				]
			) . ';',
			'before'
		);
	}

	/**
	 * Enqueue logout-redirect-urls script and styles on the Users tab.
	 */
	private function enqueue_logout_redirect_urls_assets(): void {
		$asset_file = WORKOS_DIR . 'build/logout-redirect-urls.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = include $asset_file;

		wp_enqueue_script(
			'workos-logout-redirect-urls',
			WORKOS_URL . 'build/logout-redirect-urls.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_enqueue_style(
			'workos-logout-redirect-urls',
			WORKOS_URL . 'build/logout-redirect-urls.css',
			[],
			$asset['version']
		);

		$wp_roles  = \WorkOS\Sync\RoleMapper::get_wp_roles();
		$all_roles = array_merge(
			[ '__default__' => __( 'Default (all roles)', 'integration-workos' ) ],
			$wp_roles
		);
		wp_add_inline_script(
			'workos-logout-redirect-urls',
			'window.workosLogoutRedirectUrls = ' . wp_json_encode(
				[
					'wpRoles' => $all_roles,
					'env'     => $this->get_editing_environment(),
				]
			) . ';',
			'before'
		);
	}

	/**
	 * Register all settings.
	 *
	 * Registers all options for all tabs, then delegates section/field
	 * registration to per-tab methods based on the current tab.
	 */
	public function register_settings(): void {
		$this->register_all_options();

		$current_tab = $this->get_current_tab();

		switch ( $current_tab ) {
			case 'organization':
				$this->register_organization_fields();
				break;
			case 'users':
				$this->register_users_fields();
				break;
			default:
				$this->register_settings_fields();
				break;
		}
	}

	/**
	 * Register all option names with the Settings API.
	 */
	private function register_all_options(): void {
		// Per-environment options (2 serialized array rows).
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

		// Global settings (kept as safety stub — no fields write here anymore).
		register_setting(
			self::OPTION_GROUP,
			'workos_global',
			[
				'type'              => 'array',
				'default'           => [],
				'sanitize_callback' => function ( $input ) {
					return is_array( $input ) ? $input : [];
				},
			]
		);

		// Active environment stays standalone (read before container boots).
		register_setting(
			self::OPTION_GROUP,
			'workos_active_environment',
			[
				'type'              => 'string',
				'default'           => 'staging',
				'sanitize_callback' => function ( $value ) {
					return in_array( $value, [ 'production', 'staging' ], true ) ? $value : 'staging';
				},
			]
		);
	}

	/**
	 * Register sections and fields for the Settings tab.
	 */
	private function register_settings_fields(): void {
		$editing_env = $this->get_editing_environment();
		$env_label   = Config::get_environments()[ $editing_env ] ?? 'Staging';

		// --- API Credentials section (always shown) ---
		add_settings_section(
			'workos_api',
			/* translators: %s: environment name (Production or Staging) */
			sprintf( __( 'API Credentials (%s)', 'integration-workos' ), $env_label ),
			function () {
				printf(
					'<p>%s <a href="%s" target="_blank">%s</a></p>',
					esc_html__( 'Enter your API key and Client ID from the', 'integration-workos' ),
					'https://dashboard.workos.com/api-keys',
					esc_html__( 'WorkOS Dashboard &rarr; API Keys', 'integration-workos' )
				);
			},
			'workos'
		);

		$this->add_field(
			$this->env_option( 'api_key' ),
			__( 'API Key', 'integration-workos' ),
			'password',
			'workos_api',
			__( 'Found under API Keys in the WorkOS Dashboard. Starts with "sk_".', 'integration-workos' )
		);
		$this->add_field(
			$this->env_option( 'client_id' ),
			__( 'Client ID', 'integration-workos' ),
			'text',
			'workos_api',
			__( 'Found under API Keys in the WorkOS Dashboard. Starts with "client_".', 'integration-workos' )
		);
		$this->add_field(
			$this->env_option( 'environment_id' ),
			__( 'Environment ID', 'integration-workos' ),
			'text',
			'workos_api',
			__( 'Found in the WorkOS Dashboard URL after /environment_. Starts with "environment_".', 'integration-workos' )
		);

		// --- Progressive disclosure: only show remaining sections when configured ---
		if ( ! $this->is_editing_env_configured() ) {
			add_settings_section(
				'workos_configure_notice',
				'',
				function () {
					printf(
						'<div class="notice notice-info inline"><p>%s</p></div>',
						esc_html__( 'Configure your API Key, Client ID, and Environment ID above, then save to unlock additional settings.', 'integration-workos' )
					);
				},
				'workos'
			);
			return;
		}

		// --- Webhook section ---
		add_settings_section(
			'workos_webhooks',
			__( 'Webhooks', 'integration-workos' ),
			function () {
				$url = rest_url( 'workos/v1/webhook' );
				echo '<p>' . esc_html__( 'Webhooks allow WorkOS to notify your site when users, organizations, or memberships change.', 'integration-workos' ) . '</p>';
				echo '<ol>';
				printf(
					'<li>%s <a href="%s" target="_blank">%s</a></li>',
					esc_html__( 'Go to', 'integration-workos' ),
					'https://dashboard.workos.com/webhooks',
					esc_html__( 'WorkOS Dashboard &rarr; Webhooks', 'integration-workos' )
				);
				printf(
					'<li>%s <code>%s</code></li>',
					esc_html__( 'Create a new endpoint with this URL:', 'integration-workos' ),
					esc_url( $url )
				);
				printf(
					'<li>%s <a href="#TB_inline?width=480&height=500&inlineId=workos-webhook-events" class="thickbox">%s</a></li>',
					esc_html__( 'Subscribe to the required events &mdash;', 'integration-workos' ),
					esc_html__( 'View required events', 'integration-workos' )
				);
				echo '<li>' . esc_html__( 'Copy the signing secret that WorkOS generates and paste it below.', 'integration-workos' ) . '</li>';
				echo '</ol>';

				$this->render_webhook_events_modal();
			},
			'workos'
		);

		$this->add_field(
			$this->env_option( 'webhook_secret' ),
			__( 'Webhook Secret', 'integration-workos' ),
			'password',
			'workos_webhooks',
			__( 'The signing secret from your WorkOS webhook endpoint.', 'integration-workos' )
		);

		// --- Authentication section ---
		add_settings_section(
			'workos_auth',
			__( 'Authentication', 'integration-workos' ),
			function () {
				echo '<p>' . esc_html__( 'Configure how users authenticate with your site.', 'integration-workos' ) . '</p>';
			},
			'workos'
		);

		add_settings_field(
			'workos_env_login_mode',
			__( 'Login Mode', 'integration-workos' ),
			[ $this, 'render_select' ],
			'workos',
			'workos_auth',
			[
				'name'    => $this->env_option( 'login_mode' ),
				'options' => [
					'redirect' => __( 'AuthKit Redirect (Recommended)', 'integration-workos' ),
					'headless' => __( 'Headless API (Custom Form)', 'integration-workos' ),
				],
			]
		);

		add_settings_field(
			'workos_env_allow_password_fallback',
			__( 'Password Fallback', 'integration-workos' ),
			[ $this, 'render_checkbox' ],
			'workos',
			'workos_auth',
			[
				'name'  => $this->env_option( 'allow_password_fallback' ),
				'label' => __( 'Allow users to log in with WordPress password if WorkOS auth fails.', 'integration-workos' ),
			]
		);

		// --- Audit Logging section ---
		add_settings_section(
			'workos_audit',
			__( 'Audit Logging', 'integration-workos' ),
			function () {
				echo '<p>' . esc_html__( 'Forward WordPress events to WorkOS Audit Logs.', 'integration-workos' ) . '</p>';
			},
			'workos'
		);

		add_settings_field(
			'workos_env_audit_logging_enabled',
			__( 'Enable Audit Logging', 'integration-workos' ),
			[ $this, 'render_checkbox' ],
			'workos',
			'workos_audit',
			[
				'name'  => $this->env_option( 'audit_logging_enabled' ),
				'label' => __( 'Send login, post, and user events to WorkOS Audit Logs.', 'integration-workos' ),
			]
		);
	}

	/**
	 * Register sections and fields for the Organization tab.
	 */
	private function register_organization_fields(): void {
		// --- Organization selection ---
		add_settings_section(
			'workos_organization',
			__( 'Organization', 'integration-workos' ),
			function () {
				echo '<p>' . esc_html__( 'Select the WorkOS organization this site belongs to.', 'integration-workos' ) . '</p>';
			},
			self::ORG_PAGE
		);

		add_settings_field(
			'workos_env_organization_id',
			__( 'Organization', 'integration-workos' ),
			[ $this, 'render_organization_select' ],
			self::ORG_PAGE,
			'workos_organization'
		);
	}

	/**
	 * Register sections and fields for the Users tab.
	 */
	private function register_users_fields(): void {
		// --- User Provisioning section ---
		add_settings_section(
			'workos_provisioning',
			__( 'User Provisioning', 'integration-workos' ),
			function () {
				echo '<p>' . esc_html__( 'Configure how users are deprovisioned when removed from WorkOS.', 'integration-workos' ) . '</p>';
			},
			self::USERS_PAGE
		);

		add_settings_field(
			'workos_env_deprovision_action',
			__( 'Deprovision Action', 'integration-workos' ),
			[ $this, 'render_select' ],
			self::USERS_PAGE,
			'workos_provisioning',
			[
				'name'    => $this->env_option( 'deprovision_action' ),
				'options' => [
					'deactivate' => __( 'Deactivate (mark as inactive)', 'integration-workos' ),
					'demote'     => __( 'Demote to Subscriber role', 'integration-workos' ),
					'delete'     => __( 'Delete user (reassign content)', 'integration-workos' ),
				],
			]
		);

		add_settings_field(
			'workos_env_reassign_user',
			__( 'Reassign Content To', 'integration-workos' ),
			[ $this, 'render_user_select' ],
			self::USERS_PAGE,
			'workos_provisioning',
			[ 'name' => $this->env_option( 'reassign_user' ) ]
		);

		// --- Login Redirects section ---
		add_settings_section(
			'workos_redirects',
			__( 'Login Redirects', 'integration-workos' ),
			function () {
				echo '<p>' . esc_html__( 'Redirect users to a specific URL after login, based on their WordPress role.', 'integration-workos' ) . '</p>';
				$this->render_redirect_urls();
			},
			self::USERS_PAGE
		);

		// --- Logout Redirects section ---
		add_settings_section(
			'workos_logout_redirects',
			__( 'Logout Redirects', 'integration-workos' ),
			function () {
				echo '<p>' . esc_html__( 'Redirect users to a specific URL after logout, based on their WordPress role.', 'integration-workos' ) . '</p>';
				$this->render_logout_redirect_urls();
			},
			self::USERS_PAGE
		);

		// --- Role Mapping section ---
		add_settings_section(
			'workos_roles',
			__( 'Role Mapping', 'integration-workos' ),
			function () {
				echo '<p>' . esc_html__( 'Map WorkOS roles to WordPress roles. Users will be assigned the mapped WP role on login.', 'integration-workos' ) . '</p>';
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

		$current_tab   = $this->get_current_tab();
		$editing_env   = $this->get_editing_environment();
		$is_configured = $this->is_editing_env_configured();
		$has_org       = $this->has_editing_env_org();

		$tabs = [
			'settings'     => __( 'Settings', 'integration-workos' ),
			'organization' => __( 'Organization', 'integration-workos' ),
			'users'        => __( 'Users', 'integration-workos' ),
		];

		$tab_disabled = [
			'settings'     => false,
			'organization' => ! $is_configured,
			'users'        => ! $has_org,
		];

		$tab_tooltips = [
			'organization' => __( 'Configure API credentials first', 'integration-workos' ),
			'users'        => __( 'Select an organization first', 'integration-workos' ),
		];

		switch ( $current_tab ) {
			case 'organization':
				$page_slug = self::ORG_PAGE;
				break;
			case 'users':
				$page_slug = self::USERS_PAGE;
				break;
			default:
				$page_slug = 'workos';
				break;
		}

		?>
		<div class="wrap">
			<h1 style="display: inline-block; margin-right: 16px;"><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php $this->render_environment_picker(); ?>

			<?php settings_errors( 'workos_messages' ); ?>

			<nav class="nav-tab-wrapper">
				<?php
				foreach ( $tabs as $slug => $label ) :
					$disabled = $tab_disabled[ $slug ];
					$tab_url  = add_query_arg(
						[
							'tab' => $slug,
							'env' => $editing_env,
						],
						admin_url( 'admin.php?page=workos' )
					);

					if ( $disabled ) :
						$tooltip = $tab_tooltips[ $slug ] ?? '';
						?>
						<span class="nav-tab nav-tab-disabled"
							style="opacity: 0.5; cursor: not-allowed;"
							title="<?php echo esc_attr( $tooltip ); ?>">
							<?php echo esc_html( $label ); ?>
						</span>
					<?php else : ?>
						<a href="<?php echo esc_url( $tab_url ); ?>"
							class="nav-tab <?php echo $slug === $current_tab ? 'nav-tab-active' : ''; ?>">
							<?php echo esc_html( $label ); ?>
						</a>
					<?php endif; ?>
				<?php endforeach; ?>
			</nav>

			<form action="options.php" method="post">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( $page_slug );
				submit_button( __( 'Save Settings', 'integration-workos' ) );
				?>
			</form>

			<?php if ( 'settings' === $current_tab && workos()->is_enabled() ) : ?>
				<hr>
				<h2><?php esc_html_e( 'Status', 'integration-workos' ); ?></h2>
				<table class="widefat striped" style="max-width:600px">
					<tr>
						<td><strong><?php esc_html_e( 'Plugin', 'integration-workos' ); ?></strong></td>
						<td><?php esc_html_e( 'Configured', 'integration-workos' ); ?> &#x2705;</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Webhook URL', 'integration-workos' ); ?></strong></td>
						<td><code><?php echo esc_url( rest_url( 'workos/v1/webhook' ) ); ?></code></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Callback URL', 'integration-workos' ); ?></strong></td>
						<td><code><?php echo esc_url( home_url( '/workos/callback' ) ); ?></code></td>
					</tr>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the environment picker inline with the page title.
	 *
	 * Shows env buttons, active badge, and Activate button.
	 */
	private function render_environment_picker(): void {
		$editing      = $this->get_editing_environment();
		$active       = Config::get_active_environment();
		$environments = Config::get_environments();
		$current_tab  = $this->get_current_tab();
		$is_locked    = Config::is_environment_overridden();

		?>
		<div style="display: inline-block; vertical-align: middle; margin-bottom: 10px;">
			<?php
			foreach ( $environments as $slug => $label ) :
				$url        = add_query_arg(
					[
						'tab' => $current_tab,
						'env' => $slug,
					],
					admin_url( 'admin.php?page=workos' )
				);
				$is_viewing = $slug === $editing;
				$is_active  = $slug === $active;
				?>
				<a
					href="<?php echo esc_url( $url ); ?>"
					style="
						display: inline-block;
						padding: 6px 16px;
						margin-right: 4px;
						border: 1px solid <?php echo $is_viewing ? 'transparent' : '#8c8f94'; ?>;
						border-radius: 4px;
						font-weight: 600;
						font-size: 13px;
						text-decoration: none;
						color: <?php echo $is_viewing ? '#fff' : '#50575e'; ?>;
						background: 
						<?php
						if ( $is_viewing ) {
							echo 'staging' === $slug ? '#dba617' : '#00a32a';
						} else {
							echo '#f0f0f1';
						}
						?>
						;
					"
				>
					<?php echo esc_html( $label ); ?>
					<?php if ( $is_active ) : ?>
						<span style="margin-left: 4px; font-size: 11px; opacity: 0.9;">&#x2713;active</span>
					<?php endif; ?>
					<?php if ( $is_locked && $is_active ) : ?>
						<span style="margin-left: 2px; font-size: 11px;">&#x1F512;</span>
					<?php endif; ?>
				</a>
			<?php endforeach; ?>

			<?php
			if ( ! $is_locked && $editing !== $active ) :
				$activate_url = wp_nonce_url(
					add_query_arg(
						[
							'action' => 'workos_activate_env',
							'env'    => $editing,
						],
						admin_url( 'admin.php?page=workos' )
					),
					'workos_activate_env'
				);
				$confirm_msg  = 'production' === $editing
					? esc_attr__( 'Are you sure you want to activate the Production environment? This will affect all users.', 'integration-workos' )
					: '';
				?>
				<a
					href="<?php echo esc_url( $activate_url ); ?>"
					class="button button-secondary"
					style="margin-left: 8px; vertical-align: middle;"
					<?php if ( $confirm_msg ) : ?>
						onclick="return confirm('<?php echo $confirm_msg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>');"
					<?php endif; ?>
				>
					<?php
					/* translators: %s: environment name (Production or Staging) */
					printf( esc_html__( 'Activate %s', 'integration-workos' ), esc_html( $environments[ $editing ] ?? $editing ) );
					?>
					<?php if ( 'production' === $editing ) : ?>
						&#x26A0;
					<?php endif; ?>
				</a>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handle the environment activation action (admin_init).
	 */
	public function handle_activate_environment(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is verified below.
		if ( empty( $_GET['action'] ) || 'workos_activate_env' !== $_GET['action'] ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'integration-workos' ) );
		}

		check_admin_referer( 'workos_activate_env' );

		$env = sanitize_text_field( wp_unslash( $_GET['env'] ?? '' ) );

		if ( ! in_array( $env, [ 'production', 'staging' ], true ) ) {
			wp_die( esc_html__( 'Invalid environment.', 'integration-workos' ) );
		}

		if ( Config::is_environment_overridden() ) {
			wp_die( esc_html__( 'Environment is locked via constant.', 'integration-workos' ) );
		}

		Config::set_active_environment( $env );

		$env_label = Config::get_environments()[ $env ] ?? $env;
		add_settings_error(
			'workos_messages',
			'workos_env_activated',
			/* translators: %s: environment name */
			sprintf( __( '%s environment is now active.', 'integration-workos' ), $env_label ),
			'success'
		);

		wp_safe_redirect(
			add_query_arg(
				[
					'page' => 'workos',
					'env'  => $env,
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle organization creation (admin_post action).
	 */
	public function handle_create_org(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'integration-workos' ) );
		}

		check_admin_referer( 'workos_create_org' );

		$name = sanitize_text_field( wp_unslash( $_POST['org_name'] ?? '' ) );
		$env  = sanitize_text_field( wp_unslash( $_POST['editing_env'] ?? '' ) );

		if ( ! in_array( $env, [ 'production', 'staging' ], true ) ) {
			$env = Config::get_active_environment();
		}

		$redirect_url = add_query_arg(
			[
				'page' => 'workos',
				'tab'  => 'organization',
				'env'  => $env,
			],
			admin_url( 'admin.php' )
		);

		if ( empty( $name ) ) {
			add_settings_error(
				'workos_messages',
				'workos_create_org_error',
				__( 'Organization name is required.', 'integration-workos' ),
				'error'
			);
			set_transient( 'settings_errors', get_settings_errors(), 30 );
			wp_safe_redirect( add_query_arg( 'settings-updated', 'false', $redirect_url ) );
			exit;
		}

		$result = workos()->api()->create_organization( [ 'name' => $name ] );

		if ( is_wp_error( $result ) ) {
			add_settings_error(
				'workos_messages',
				'workos_create_org_error',
				/* translators: %s: error message from API */
				sprintf( __( 'Failed to create organization: %s', 'integration-workos' ), $result->get_error_message() ),
				'error'
			);
			set_transient( 'settings_errors', get_settings_errors(), 30 );
			wp_safe_redirect( add_query_arg( 'settings-updated', 'false', $redirect_url ) );
			exit;
		}

		$org_id = $result['id'] ?? '';

		if ( ! empty( $org_id ) ) {
			// Save org_id to the editing env options.
			$option_name = "workos_{$env}";
			$options     = get_option( $option_name, [] );
			if ( ! is_array( $options ) ) {
				$options = [];
			}
			$options['organization_id'] = $org_id;
			update_option( $option_name, $options );

			$this->flush_organizations_cache();
		}

		add_settings_error(
			'workos_messages',
			'workos_create_org_success',
			/* translators: %s: organization name */
			sprintf( __( 'Organization "%s" created and selected.', 'integration-workos' ), $name ),
			'success'
		);
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( add_query_arg( 'settings-updated', 'true', $redirect_url ) );
		exit;
	}

	/**
	 * Handle bulk sync of WP roles to WorkOS (admin_post action).
	 */
	public function handle_sync_roles_to_workos(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'integration-workos' ) );
		}

		check_admin_referer( 'workos_sync_roles_to_workos' );

		$env = sanitize_text_field( wp_unslash( $_POST['editing_env'] ?? Config::get_active_environment() ) );
		if ( ! in_array( $env, [ 'production', 'staging' ], true ) ) {
			$env = Config::get_active_environment();
		}

		$redirect_url = add_query_arg(
			[
				'page' => 'workos',
				'tab'  => 'users',
				'env'  => $env,
			],
			admin_url( 'admin.php' )
		);

		$counts = \WorkOS\Sync\RoleMapper::push_all_roles_to_workos();

		if ( $counts['synced'] > 0 || ( 0 === $counts['failed'] ) ) {
			add_settings_error(
				'workos_messages',
				'workos_sync_roles_success',
				sprintf(
					/* translators: 1: synced count, 2: skipped count, 3: failed count */
					__( 'Role sync complete — %1$d updated, %2$d skipped, %3$d failed.', 'integration-workos' ),
					$counts['synced'],
					$counts['skipped'],
					$counts['failed']
				),
				'success'
			);
		} else {
			add_settings_error(
				'workos_messages',
				'workos_sync_roles_error',
				sprintf(
					/* translators: 1: synced count, 2: skipped count, 3: failed count */
					__( 'Role sync completed with errors — %1$d updated, %2$d skipped, %3$d failed.', 'integration-workos' ),
					$counts['synced'],
					$counts['skipped'],
					$counts['failed']
				),
				'error'
			);
		}

		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( add_query_arg( 'settings-updated', 'true', $redirect_url ) );
		exit;
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
			'<input type="hidden" name="%s" value="0" />',
			esc_attr( $args['name'] )
		);
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
				'show_option_none' => __( '— Select User —', 'integration-workos' ),
				'role__in'         => [ 'administrator', 'editor' ],
			]
		);
		echo '<p class="description">' . esc_html__( 'Content from deleted users will be reassigned to this user.', 'integration-workos' ) . '</p>';
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
			echo '<p class="description">' . esc_html__( 'Configure API credentials and save to select an organization.', 'integration-workos' ) . '</p>';
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
					esc_html__( 'Could not fetch organizations:', 'integration-workos' ),
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
				esc_html__( 'Set via WORKOS_ORGANIZATION_ID constant.', 'integration-workos' )
			);
			return;
		}

		echo '<select name="' . esc_attr( $option_name ) . '">';
		printf( '<option value="">%s</option>', esc_html__( '— Select Organization —', 'integration-workos' ) );
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
		printf(
			'<p><a href="#TB_inline?width=400&height=250&inlineId=workos-create-org-modal" class="thickbox">%s</a></p>',
			esc_html__( 'Create new organization', 'integration-workos' )
		);

		$this->render_create_org_modal();
	}

	/**
	 * Render the hidden Thickbox modal for creating an organization.
	 */
	private function render_create_org_modal(): void {
		$editing_env = $this->get_editing_environment();
		?>
		<div id="workos-create-org-modal" style="display:none">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'workos_create_org' ); ?>
				<input type="hidden" name="action" value="workos_create_org" />
				<input type="hidden" name="editing_env" value="<?php echo esc_attr( $editing_env ); ?>" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="workos_org_name"><?php esc_html_e( 'Organization Name', 'integration-workos' ); ?></label>
						</th>
						<td>
							<input type="text" id="workos_org_name" name="org_name" class="regular-text" required />
							<p class="description"><?php esc_html_e( 'Name for the new WorkOS organization.', 'integration-workos' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Create Organization', 'integration-workos' ), 'secondary' ); ?>
			</form>
		</div>
		<?php
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

		$event_groups[ __( 'User Management', 'integration-workos' ) ] = [
			'user.created',
			'user.updated',
			'user.deleted',
		];

		$event_groups[ __( 'Directory Sync', 'integration-workos' ) ] = [
			'dsync.user.created',
			'dsync.user.updated',
			'dsync.user.deleted',
			'dsync.group.user_added',
			'dsync.group.user_removed',
		];

		$event_groups[ __( 'Organizations', 'integration-workos' ) ] = [
			'organization.created',
			'organization.updated',
			'organization_membership.created',
			'organization_membership.updated',
			'organization_membership.deleted',
		];

		$event_groups[ __( 'Connections', 'integration-workos' ) ] = [
			'connection.activated',
			'connection.deactivated',
		];

		$event_groups[ __( 'Authentication', 'integration-workos' ) ] = [
			'authentication.email_verification_succeeded',
		];

		echo '<div id="workos-webhook-events" style="display:none">';
		echo '<p>' . esc_html__( 'Subscribe to the following events when creating your webhook endpoint in the WorkOS Dashboard.', 'integration-workos' ) . '</p>';

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
	 * Fetch WorkOS organization roles for the editing environment.
	 *
	 * Calls the WorkOS API and caches the result in a 5-minute transient.
	 * Returns a slug => name map, or empty array on error.
	 *
	 * @return array<string, string> WorkOS role slug => role name.
	 */
	private function get_workos_org_roles(): array {
		$env     = $this->get_editing_environment();
		$options = get_option( "workos_{$env}", [] );
		$org_id  = $options['organization_id'] ?? '';

		if ( empty( $org_id ) ) {
			return [];
		}

		$cache_key = 'workos_org_roles_' . $org_id;
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : [];
		}

		if ( ! workos()->is_enabled() ) {
			return [];
		}

		$result = workos()->api()->list_organization_roles( $org_id );

		if ( is_wp_error( $result ) || empty( $result['data'] ) ) {
			// Cache empty result briefly to avoid repeated failing calls.
			set_transient( $cache_key, [], MINUTE_IN_SECONDS );
			return [];
		}

		$roles = [];
		foreach ( $result['data'] as $role ) {
			$slug = $role['slug'] ?? '';
			$name = $role['name'] ?? $slug;
			if ( $slug ) {
				$roles[ $slug ] = $name;
			}
		}

		set_transient( $cache_key, $roles, 5 * MINUTE_IN_SECONDS );

		return $roles;
	}

	/**
	 * Render the role mapping table.
	 */
	public function render_role_map(): void {
		$map          = \WorkOS\Sync\RoleMapper::get_role_map();
		$wp_roles     = \WorkOS\Sync\RoleMapper::get_wp_roles();
		$workos_roles = $this->get_workos_org_roles();
		$env          = $this->get_editing_environment();
		$has_dropdown = ! empty( $workos_roles );

		// Out-of-sync warning banner.
		$out_of_sync = \WorkOS\Sync\RoleMapper::get_out_of_sync_users();
		if ( ! empty( $out_of_sync ) ) {
			$count = count( $out_of_sync );
			printf(
				'<div class="notice notice-warning inline" id="workos-role-sync-warning"><p>%s <a href="%s">%s</a></p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of out-of-sync users */
						_n(
							'%d user has a WordPress role that doesn\'t match their WorkOS role mapping.',
							'%d users have a WordPress role that doesn\'t match their WorkOS role mapping.',
							$count,
							'integration-workos'
						),
						$count
					)
				),
				esc_url( admin_url( 'users.php?workos_sync_status=out_of_sync' ) ),
				esc_html__( 'View affected users', 'integration-workos' )
			);
		}

		echo '<table id="workos-role-map-table" class="widefat"><thead><tr>';
		echo '<th>' . esc_html__( 'WorkOS Role', 'integration-workos' ) . '</th>';
		echo '<th>' . esc_html__( 'WordPress Role', 'integration-workos' ) . '</th>';
		echo '<th class="workos-role-map-actions"></th>';
		echo '</tr></thead><tbody>';

		foreach ( $map as $workos_role => $wp_role ) {
			echo '<tr class="workos-role-map-row"><td>';
			if ( $has_dropdown ) {
				printf( '<select name="workos_%s[role_map][keys][]">', esc_attr( $env ) );
				echo '<option value="">' . esc_html__( '— No Role —', 'integration-workos' ) . '</option>';
				foreach ( $workos_roles as $slug => $name ) {
					printf(
						'<option value="%s" %s>%s</option>',
						esc_attr( $slug ),
						selected( $workos_role, $slug, false ),
						esc_html( $name )
					);
				}
				echo '</select>';
			} else {
				printf(
					'<input type="text" name="workos_%s[role_map][keys][]" value="%s" class="regular-text" />',
					esc_attr( $env ),
					esc_attr( $workos_role )
				);
			}
			echo '</td><td>';
			printf( '<select name="workos_%s[role_map][values][]">', esc_attr( $env ) );
			echo '<option value="">' . esc_html__( '— No Role —', 'integration-workos' ) . '</option>';
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
		if ( $has_dropdown ) {
			printf( '<select name="workos_%s[role_map][keys][]">', esc_attr( $env ) );
			echo '<option value="">' . esc_html__( '— No Role —', 'integration-workos' ) . '</option>';
			foreach ( $workos_roles as $slug => $name ) {
				printf( '<option value="%s">%s</option>', esc_attr( $slug ), esc_html( $name ) );
			}
			echo '</select>';
		} else {
			printf(
				'<input type="text" name="workos_%s[role_map][keys][]" value="" class="regular-text" placeholder="%s" />',
				esc_attr( $env ),
				esc_attr__( 'New WorkOS role...', 'integration-workos' )
			);
		}
		echo '</td><td>';
		printf( '<select name="workos_%s[role_map][values][]">', esc_attr( $env ) );
		echo '<option value="">' . esc_html__( '— No Role —', 'integration-workos' ) . '</option>';
		foreach ( $wp_roles as $slug => $name ) {
			printf( '<option value="%s">%s</option>', esc_attr( $slug ), esc_html( $name ) );
		}
		echo '</select>';
		echo '</td></tr>';

		echo '</tbody></table>';

		// Add Mapping button — hidden until JS loads.
		echo '<button type="button" id="workos-role-map-add" class="button" style="display:none">';
		echo '<span class="dashicons dashicons-plus-alt2"></span> ';
		echo esc_html__( 'Add Mapping', 'integration-workos' );
		echo '</button>';

		// Sync Roles to WorkOS button — only when plugin is enabled and org is configured.
		if ( workos()->is_enabled() && ! empty( Config::get_organization_id() ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline; margin-left:8px">';
			wp_nonce_field( 'workos_sync_roles_to_workos' );
			echo '<input type="hidden" name="action" value="workos_sync_roles_to_workos" />';
			echo '<input type="hidden" name="editing_env" value="' . esc_attr( $this->get_editing_environment() ) . '" />';
			echo '<button type="submit" class="button">';
			echo '<span class="dashicons dashicons-update" style="vertical-align:middle; margin-right:2px"></span> ';
			echo esc_html__( 'Sync Roles to WorkOS', 'integration-workos' );
			echo '</button>';
			echo '</form>';
		}

		echo '<p class="description" style="margin-top:8px">' . esc_html__( 'The "member" role is used as the default fallback.', 'integration-workos' ) . '</p>';
	}

	/**
	 * Render the redirect URLs table for the Users tab.
	 */
	public function render_redirect_urls(): void {
		$env      = $this->get_editing_environment();
		$options  = get_option( "workos_{$env}", [] );
		$raw_map  = is_array( $options['redirect_urls'] ?? null ) ? $options['redirect_urls'] : [];
		$wp_roles = \WorkOS\Sync\RoleMapper::get_wp_roles();

		// Prepend the default fallback option.
		$all_roles = array_merge(
			[ '__default__' => __( 'Default (all roles)', 'integration-workos' ) ],
			$wp_roles
		);

		// Legacy global setting — used as default when normalizing old-format entries.
		$global_first_login = ! empty( $options['redirect_first_login_only'] );

		// Normalize entries: legacy string values become arrays.
		$map = [];
		foreach ( $raw_map as $role => $entry ) {
			if ( ! isset( $all_roles[ $role ] ) ) {
				continue;
			}
			if ( is_string( $entry ) ) {
				$map[ $role ] = [
					'url'              => $entry,
					'first_login_only' => $global_first_login,
				];
			} elseif ( is_array( $entry ) ) {
				$map[ $role ] = $entry;
			}
		}

		echo '<table id="workos-redirect-urls-table" class="widefat"><thead><tr>';
		echo '<th>' . esc_html__( 'WordPress Role', 'integration-workos' ) . '</th>';
		echo '<th>' . esc_html__( 'Redirect URL', 'integration-workos' ) . '</th>';
		echo '<th class="workos-redirect-url-first-login">' . esc_html__( 'First Login Only', 'integration-workos' ) . '</th>';
		echo '<th class="workos-redirect-url-actions"></th>';
		echo '</tr></thead><tbody>';

		$i = 0;
		foreach ( $map as $role => $entry ) {
			$url         = $entry['url'] ?? '';
			$first_login = ! empty( $entry['first_login_only'] );

			echo '<tr class="workos-redirect-url-row"><td>';
			printf( '<select name="workos_%s[redirect_urls][keys][%d]">', esc_attr( $env ), absint( $i ) );
			foreach ( $all_roles as $slug => $name ) {
				printf(
					'<option value="%s" %s>%s</option>',
					esc_attr( $slug ),
					selected( $role, $slug, false ),
					esc_html( $name )
				);
			}
			echo '</select>';
			echo '</td><td>';
			printf(
				'<input type="text" name="workos_%s[redirect_urls][values][%d]" value="%s" class="regular-text" placeholder="%s" />',
				esc_attr( $env ),
				absint( $i ),
				esc_attr( $url ),
				esc_attr__( '/welcome', 'integration-workos' )
			);
			echo '</td><td class="workos-redirect-url-first-login">';
			printf(
				'<input type="hidden" name="workos_%s[redirect_urls][first_login][%d]" value="0" />',
				esc_attr( $env ),
				absint( $i )
			);
			printf(
				'<input type="checkbox" name="workos_%s[redirect_urls][first_login][%d]" value="1" %s />',
				esc_attr( $env ),
				absint( $i ),
				checked( $first_login, true, false )
			);
			echo '</td></tr>';
			++$i;
		}

		// Empty row for adding new redirects (no-JS fallback).
		echo '<tr class="workos-redirect-url-row"><td>';
		printf( '<select name="workos_%s[redirect_urls][keys][%d]">', esc_attr( $env ), absint( $i ) );
		echo '<option value="">' . esc_html__( '— Select Role —', 'integration-workos' ) . '</option>';
		foreach ( $all_roles as $slug => $name ) {
			printf( '<option value="%s">%s</option>', esc_attr( $slug ), esc_html( $name ) );
		}
		echo '</select>';
		echo '</td><td>';
		printf(
			'<input type="text" name="workos_%s[redirect_urls][values][%d]" value="" class="regular-text" placeholder="%s" />',
			esc_attr( $env ),
			absint( $i ),
			esc_attr__( '/welcome', 'integration-workos' )
		);
		echo '</td><td class="workos-redirect-url-first-login">';
		printf(
			'<input type="hidden" name="workos_%s[redirect_urls][first_login][%d]" value="0" />',
			esc_attr( $env ),
			absint( $i )
		);
		printf(
			'<input type="checkbox" name="workos_%s[redirect_urls][first_login][%d]" value="1" />',
			esc_attr( $env ),
			absint( $i )
		);
		echo '</td></tr>';

		echo '</tbody></table>';

		// Add Redirect button — hidden until JS loads.
		echo '<button type="button" id="workos-redirect-urls-add" class="button" style="display:none">';
		echo '<span class="dashicons dashicons-plus-alt2"></span> ';
		echo esc_html__( 'Add Redirect', 'integration-workos' );
		echo '</button>';

		echo '<p class="description" style="margin-top:8px">' . esc_html__( 'Enter a URL or path (e.g. /welcome) to redirect users to after login, per role. Use "Default (all roles)" as a fallback for roles without a specific redirect.', 'integration-workos' ) . '</p>';
	}

	/**
	 * Render the logout redirect URLs table for the Users tab.
	 */
	public function render_logout_redirect_urls(): void {
		$env      = $this->get_editing_environment();
		$options  = get_option( "workos_{$env}", [] );
		$raw_map  = is_array( $options['logout_redirect_urls'] ?? null ) ? $options['logout_redirect_urls'] : [];
		$wp_roles = \WorkOS\Sync\RoleMapper::get_wp_roles();

		// Prepend the default fallback option.
		$all_roles = array_merge(
			[ '__default__' => __( 'Default (all roles)', 'integration-workos' ) ],
			$wp_roles
		);

		// Filter to only valid roles (including __default__).
		$map = [];
		foreach ( $raw_map as $role => $url ) {
			if ( ! isset( $all_roles[ $role ] ) ) {
				continue;
			}
			if ( is_string( $url ) ) {
				$map[ $role ] = $url;
			}
		}

		echo '<table id="workos-logout-redirect-urls-table" class="widefat"><thead><tr>';
		echo '<th>' . esc_html__( 'WordPress Role', 'integration-workos' ) . '</th>';
		echo '<th>' . esc_html__( 'Redirect URL', 'integration-workos' ) . '</th>';
		echo '<th class="workos-logout-redirect-url-actions"></th>';
		echo '</tr></thead><tbody>';

		$i = 0;
		foreach ( $map as $role => $url ) {
			echo '<tr class="workos-logout-redirect-url-row"><td>';
			printf( '<select name="workos_%s[logout_redirect_urls][keys][%d]">', esc_attr( $env ), absint( $i ) );
			foreach ( $all_roles as $slug => $name ) {
				printf(
					'<option value="%s" %s>%s</option>',
					esc_attr( $slug ),
					selected( $role, $slug, false ),
					esc_html( $name )
				);
			}
			echo '</select>';
			echo '</td><td>';
			printf(
				'<input type="text" name="workos_%s[logout_redirect_urls][values][%d]" value="%s" class="regular-text" placeholder="%s" />',
				esc_attr( $env ),
				absint( $i ),
				esc_attr( $url ),
				esc_attr__( '/goodbye', 'integration-workos' )
			);
			echo '</td></tr>';
			++$i;
		}

		// Empty row for adding new redirects (no-JS fallback).
		echo '<tr class="workos-logout-redirect-url-row"><td>';
		printf( '<select name="workos_%s[logout_redirect_urls][keys][%d]">', esc_attr( $env ), absint( $i ) );
		echo '<option value="">' . esc_html__( '— Select Role —', 'integration-workos' ) . '</option>';
		foreach ( $all_roles as $slug => $name ) {
			printf( '<option value="%s">%s</option>', esc_attr( $slug ), esc_html( $name ) );
		}
		echo '</select>';
		echo '</td><td>';
		printf(
			'<input type="text" name="workos_%s[logout_redirect_urls][values][%d]" value="" class="regular-text" placeholder="%s" />',
			esc_attr( $env ),
			absint( $i ),
			esc_attr__( '/goodbye', 'integration-workos' )
		);
		echo '</td></tr>';

		echo '</tbody></table>';

		// Add Redirect button — hidden until JS loads.
		echo '<button type="button" id="workos-logout-redirect-urls-add" class="button" style="display:none">';
		echo '<span class="dashicons dashicons-plus-alt2"></span> ';
		echo esc_html__( 'Add Redirect', 'integration-workos' );
		echo '</button>';

		echo '<p class="description" style="margin-top:8px">' . esc_html__( 'Enter a URL or path (e.g. /goodbye) to redirect users to after logout, per role. Use "Default (all roles)" as a fallback for roles without a specific redirect.', 'integration-workos' ) . '</p>';
	}

	/**
	 * Sanitize the redirect URLs map from the form submission.
	 *
	 * @param mixed $input Raw form input.
	 *
	 * @return array Sanitized redirect URL map.
	 */
	public function sanitize_redirect_urls( $input ): array {
		if ( ! is_array( $input ) || empty( $input['keys'] ) || empty( $input['values'] ) ) {
			if ( is_array( $input ) && ! isset( $input['keys'] ) ) {
				// Existing flat or structured map from DB — pass through.
				$sanitized = [];
				foreach ( $input as $key => $value ) {
					$key = sanitize_text_field( $key );
					if ( ! $key ) {
						continue;
					}
					if ( is_array( $value ) ) {
						$url = sanitize_text_field( $value['url'] ?? '' );
						if ( $url ) {
							$sanitized[ $key ] = [
								'url'              => $url,
								'first_login_only' => rest_sanitize_boolean( $value['first_login_only'] ?? false ),
							];
						}
					} else {
						$value = sanitize_text_field( $value );
						if ( $value ) {
							$sanitized[ $key ] = [
								'url'              => $value,
								'first_login_only' => false,
							];
						}
					}
				}
				return $sanitized;
			}
			return [];
		}

		$map = [];
		foreach ( $input['keys'] as $i => $key ) {
			$key   = sanitize_text_field( $key );
			$value = sanitize_text_field( $input['values'][ $i ] ?? '' );
			if ( $key && $value ) {
				$map[ $key ] = [
					'url'              => $value,
					'first_login_only' => rest_sanitize_boolean( $input['first_login'][ $i ] ?? false ),
				];
			}
		}

		return $map;
	}

	/**
	 * Sanitize the logout redirect URLs map from the form submission.
	 *
	 * @param mixed $input Raw form input.
	 *
	 * @return array Sanitized logout redirect URL map (role → URL string).
	 */
	public function sanitize_logout_redirect_urls( $input ): array {
		if ( ! is_array( $input ) || empty( $input['keys'] ) || empty( $input['values'] ) ) {
			// Existing flat map from DB — pass through.
			if ( is_array( $input ) && ! isset( $input['keys'] ) ) {
				$sanitized = [];
				foreach ( $input as $key => $value ) {
					$key   = sanitize_text_field( $key );
					$value = sanitize_text_field( $value );
					if ( $key && $value ) {
						$sanitized[ $key ] = $value;
					}
				}
				return $sanitized;
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
	 * Sanitize an environment options array (production or staging).
	 *
	 * Uses merge semantics: reads existing option from DB, sanitizes each
	 * submitted key by type, then merges so non-submitted keys (from other tabs)
	 * are preserved.
	 *
	 * @param mixed $input Raw form input.
	 *
	 * @return array Sanitized options.
	 */
	public function sanitize_environment_options( $input ): array {
		// Determine which option is being saved from the current filter name.
		$option_name = str_replace( 'sanitize_option_', '', current_filter() );
		$existing    = get_option( $option_name, [] );

		if ( ! is_array( $existing ) ) {
			$existing = [];
		}

		if ( ! is_array( $input ) || empty( $input ) ) {
			return $existing;
		}

		$sanitized = [];

		// Text fields.
		$text_keys = [ 'api_key', 'client_id', 'webhook_secret', 'organization_id', 'environment_id', 'login_mode', 'deprovision_action' ];
		foreach ( $text_keys as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$sanitized[ $key ] = sanitize_text_field( $input[ $key ] );
			}
		}

		// Boolean fields.
		$bool_keys = [ 'allow_password_fallback', 'audit_logging_enabled' ];
		foreach ( $bool_keys as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$sanitized[ $key ] = rest_sanitize_boolean( $input[ $key ] );
			}
		}

		// Integer fields.
		if ( isset( $input['reassign_user'] ) ) {
			$sanitized['reassign_user'] = absint( $input['reassign_user'] );
		}

		// Role map.
		if ( isset( $input['role_map'] ) ) {
			$sanitized['role_map'] = $this->sanitize_role_map( $input['role_map'] );
		}

		// Redirect URLs.
		if ( isset( $input['redirect_urls'] ) ) {
			$sanitized['redirect_urls'] = $this->sanitize_redirect_urls( $input['redirect_urls'] );
		}

		// Logout Redirect URLs.
		if ( isset( $input['logout_redirect_urls'] ) ) {
			$sanitized['logout_redirect_urls'] = $this->sanitize_logout_redirect_urls( $input['logout_redirect_urls'] );
		}

		return array_merge( $existing, $sanitized );
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
			esc_html__( 'Settings', 'integration-workos' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}
}
