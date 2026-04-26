<?php
/**
 * [workos:login] shortcode.
 *
 * @package WorkOS\Auth\AuthKit
 */

namespace WorkOS\Auth\AuthKit;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the AuthKit React shell inline at the shortcode location.
 *
 * The tag uses the `workos:` colon namespace so all plugin-provided
 * shortcodes share a single, scannable prefix.
 */
class Shortcode {

	public const TAG = 'workos:login';

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

		// Already-signed-in visitors don't need a login form on this page.
		// Render a friendly "you're signed in, continue here" message
		// instead — we can't redirect from inside the_content (headers
		// are already sent), so an inline message is the best we can do.
		if ( is_user_logged_in() ) {
			return $this->render_already_signed_in( $profile );
		}

		return $this->renderer->render_mount(
			$profile,
			[ 'redirect_to' => (string) $atts['redirect_to'] ]
		);
	}

	/**
	 * Render the inline "you're already signed in" callout.
	 *
	 * @param Profile $profile Active profile.
	 *
	 * @return string Safe HTML.
	 */
	private function render_already_signed_in( Profile $profile ): string {
		$dest = LoginRedirector::for_visitor( $profile );
		$user = wp_get_current_user();

		return sprintf(
			'<div class="workos-authkit-signed-in-notice"><p>%s</p><p><a class="button button-primary" href="%s">%s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %s: display name. */
					__( 'You\'re already signed in as %s.', 'integration-workos' ),
					$user->display_name
				)
			),
			esc_url( $dest ),
			esc_html__( 'Continue', 'integration-workos' )
		);
	}
}
