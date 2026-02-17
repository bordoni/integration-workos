<?php
/**
 * Admin bar environment indicator.
 *
 * @package WorkOS\Admin
 */

namespace WorkOS\Admin;

use WorkOS\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a WorkOS environment badge to the WordPress admin bar.
 */
class AdminBar {

	/**
	 * Constructor — register hooks.
	 */
	public function __construct() {
		add_action( 'admin_bar_menu', [ $this, 'add_environment_indicator' ], 100 );
		add_action( 'wp_head', [ $this, 'render_inline_styles' ] );
		add_action( 'admin_head', [ $this, 'render_inline_styles' ] );
	}

	/**
	 * Add the environment indicator node to the admin bar.
	 *
	 * @param \WP_Admin_Bar $admin_bar The admin bar instance.
	 */
	public function add_environment_indicator( \WP_Admin_Bar $admin_bar ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$env   = Config::get_active_environment();
		$label = Config::get_environments()[ $env ] ?? $env;

		$admin_bar->add_node( [
			'id'     => 'workos-environment',
			'parent' => 'top-secondary',
			'title'  => sprintf( 'WorkOS: %s', esc_html( $label ) ),
			'href'   => esc_url( admin_url( 'admin.php?page=workos' ) ),
			'meta'   => [
				'class' => 'workos-env-' . $env,
			],
		] );
	}

	/**
	 * Output inline styles for the environment badge.
	 */
	public function render_inline_styles(): void {
		if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>
		<style>
			#wp-admin-bar-workos-environment .ab-item {
				font-weight: 600 !important;
				font-size: 11px !important;
			}
			#wp-admin-bar-workos-environment.workos-env-production .ab-item {
				background: #00a32a !important;
				color: #fff !important;
			}
			#wp-admin-bar-workos-environment.workos-env-staging .ab-item {
				background: #dba617 !important;
				color: #fff !important;
			}
		</style>
		<?php
	}
}
