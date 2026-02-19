<?php
/**
 * Login button shortcode.
 *
 * @package WorkOS\UI
 */

namespace WorkOS\UI;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the [workos_login] shortcode.
 */
class Shortcode {

	/**
	 * Constructor — registers the shortcode.
	 */
	public function __construct() {
		add_shortcode( 'workos_login', [ $this, 'handle' ] );
	}

	/**
	 * Handle the shortcode.
	 *
	 * @param array|string $atts Shortcode attributes.
	 *
	 * @return string HTML output.
	 */
	public function handle( $atts ): string {
		$atts = shortcode_atts( Renderer::DEFAULTS, (array) $atts, 'workos_login' );

		return Renderer::render( $atts );
	}
}
