<?php
/**
 * UI Controller — registers shortcode, widget, block, AJAX, and frontend assets.
 *
 * @package WorkOS\UI
 */

namespace WorkOS\UI;

use WorkOS\Contracts\Controller as BaseController;

defined( 'ABSPATH' ) || exit;

/**
 * Feature controller for the login button UI components.
 */
class Controller extends BaseController {

	/**
	 * Register all UI components.
	 *
	 * @return void
	 */
	protected function doRegister(): void {
		$this->container->singleton( Shortcode::class );
		$this->container->get( Shortcode::class );

		$this->container->singleton( Block::class );
		$this->container->get( Block::class );

		$this->container->singleton( Ajax::class );
		$this->container->get( Ajax::class );

		add_action( 'widgets_init', [ $this, 'register_widget' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'maybe_enqueue_frontend_assets' ] );
	}

	/**
	 * Unregister.
	 *
	 * @return void
	 */
	protected function doUnregister(): void {
	}

	/**
	 * Register the classic widget only when the block editor is unavailable.
	 */
	public function register_widget(): void {
		if ( function_exists( 'register_block_type' ) ) {
			return;
		}

		register_widget( Widget::class );
	}

	/**
	 * Conditionally enqueue frontend assets.
	 *
	 * Only loads CSS/JS when the login button is actually used on the page.
	 */
	public function maybe_enqueue_frontend_assets(): void {
		global $post;

		$has_shortcode = $post instanceof \WP_Post && has_shortcode( $post->post_content, 'workos_login' );
		$has_block     = $post instanceof \WP_Post && has_block( 'workos/login-button', $post );
		$has_widget    = is_active_widget( false, false, 'workos_login_button' );

		if ( ! $has_shortcode && ! $has_block && ! $has_widget ) {
			return;
		}

		$this->enqueue_frontend_assets();
	}

	/**
	 * Enqueue the frontend CSS and JS.
	 */
	private function enqueue_frontend_assets(): void {
		wp_enqueue_style(
			'workos-login-button',
			WORKOS_URL . 'src/css/login-button.css',
			[],
			WORKOS_VERSION
		);

		wp_enqueue_script(
			'workos-login-button-frontend',
			WORKOS_URL . 'src/js/login-button-frontend.js',
			[],
			WORKOS_VERSION,
			true
		);

		wp_localize_script(
			'workos-login-button-frontend',
			'workosLoginButton',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'workos_login_button' ),
				'i18n'    => [
					'error' => __( 'An error occurred. Please try again.', 'integration-workos' ),
				],
			]
		);
	}
}
