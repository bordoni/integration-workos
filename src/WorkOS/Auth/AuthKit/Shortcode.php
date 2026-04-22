<?php
/**
 * [workos_login] shortcode (Custom AuthKit).
 *
 * @package WorkOS\Auth\AuthKit
 */

namespace WorkOS\Auth\AuthKit;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the AuthKit React shell inline at the shortcode location.
 *
 * This is the Custom-AuthKit shortcode (a new, differentiated short tag
 * `[workos_login_v2]`) — the legacy `[workos_login]` shortcode keeps
 * behaving as it always did (button-to-AuthKit-redirect) for back-compat.
 */
class Shortcode {

	public const TAG = 'workos_login_v2';

	/**
	 * Profile router.
	 *
	 * @var ProfileRouter
	 */
	private ProfileRouter $router;

	/**
	 * Renderer.
	 *
	 * @var Renderer
	 */
	private Renderer $renderer;

	/**
	 * Constructor.
	 *
	 * @param ProfileRouter $router   Profile router.
	 * @param Renderer      $renderer Renderer.
	 */
	public function __construct( ProfileRouter $router, Renderer $renderer ) {
		$this->router   = $router;
		$this->renderer = $renderer;
	}

	/**
	 * Register the shortcode.
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( self::TAG, [ $this, 'render' ] );
	}

	/**
	 * Shortcode callback.
	 *
	 * @param array $atts Shortcode attributes.
	 *
	 * @return string
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts(
			[
				'profile'     => '',
				'redirect_to' => '',
			],
			is_array( $atts ) ? $atts : [],
			self::TAG
		);

		$profile = $this->router->resolve(
			[
				'explicit_slug' => (string) $atts['profile'],
				'redirect_to'   => (string) $atts['redirect_to'],
				'user_id'       => get_current_user_id(),
			]
		);

		if ( ! $profile->is_custom_mode() ) {
			// Legacy redirect-mode profiles aren't served from the shortcode —
			// render an inert placeholder so authors notice.
			return '';
		}

		return $this->renderer->render_mount(
			$profile,
			[ 'redirect_to' => (string) $atts['redirect_to'] ]
		);
	}
}
