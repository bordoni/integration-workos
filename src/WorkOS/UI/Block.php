<?php
/**
 * Gutenberg block registration.
 *
 * @package WorkOS\UI
 */

namespace WorkOS\UI;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the workos/login-button dynamic block.
 */
class Block {

	/**
	 * Constructor — registers the block on init.
	 */
	public function __construct() {
		add_action( 'init', [ $this, 'register' ] );
	}

	/**
	 * Register the block type.
	 */
	public function register(): void {
		register_block_type(
			WORKOS_DIR . 'build/js/login-button',
			[
				'render_callback' => [ Renderer::class, 'render' ],
			]
		);
	}
}
