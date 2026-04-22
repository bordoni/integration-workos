<?php
/**
 * Dedicated /workos/login/{profile} frontend route.
 *
 * @package WorkOS\Auth\AuthKit
 */

namespace WorkOS\Auth\AuthKit;

use WorkOS\Vendor\StellarWP\SuperGlobals\SuperGlobals;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes a full-bleed login page at /workos/login/{profile-slug}.
 *
 * Use case: marketing/onboarding flows that want the AuthKit UI at a
 * clean URL without dropping a shortcode onto a page. The rewrite rule
 * parses the profile slug out of the URL; the template_redirect hook
 * short-circuits theme rendering and emits the React shell.
 */
class FrontendRoute {

	public const QUERY_VAR = 'workos_login_profile';
	public const REWRITE   = '^workos/login/([^/]+)/?$';

	/**
	 * Profile repository.
	 *
	 * @var ProfileRepository
	 */
	private ProfileRepository $profiles;

	/**
	 * Renderer.
	 *
	 * @var Renderer
	 */
	private Renderer $renderer;

	/**
	 * Constructor.
	 *
	 * @param ProfileRepository $profiles Profile repository.
	 * @param Renderer          $renderer Renderer.
	 */
	public function __construct( ProfileRepository $profiles, Renderer $renderer ) {
		$this->profiles = $profiles;
		$this->renderer = $renderer;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', [ $this, 'register_rewrite' ] );
		add_filter( 'query_vars', [ $this, 'register_query_var' ] );
		add_action( 'template_redirect', [ $this, 'maybe_render' ] );
	}

	/**
	 * Register the rewrite rule.
	 *
	 * @return void
	 */
	public function register_rewrite(): void {
		add_rewrite_rule(
			self::REWRITE,
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);
	}

	/**
	 * Expose our query var to WP_Query.
	 *
	 * @param array $vars Existing query vars.
	 *
	 * @return array
	 */
	public function register_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Render the AuthKit shell when our query var is populated.
	 *
	 * @return void
	 */
	public function maybe_render(): void {
		$slug = (string) get_query_var( self::QUERY_VAR );
		if ( '' === $slug ) {
			return;
		}

		$profile = $this->profiles->find_by_slug( $slug );
		if ( ! $profile ) {
			status_header( 404 );
			nocache_headers();
			wp_die(
				esc_html__( 'Login profile not found.', 'integration-workos' ),
				esc_html__( 'Not Found', 'integration-workos' ),
				[ 'response' => 404 ]
			);
		}

		if ( ! $profile->is_custom_mode() ) {
			// Redirect-mode profiles hand off to the legacy callback flow.
			status_header( 301 );
			wp_safe_redirect( wp_login_url( (string) ( SuperGlobals::get_get_var( 'redirect_to' ) ?? '' ) ) );
			exit;
		}

		$context = [
			'redirect_to'      => (string) ( SuperGlobals::get_get_var( 'redirect_to' ) ?? '' ),
			'invitation_token' => (string) ( SuperGlobals::get_get_var( 'invitation_token' ) ?? '' ),
			'reset_token'      => (string) ( SuperGlobals::get_get_var( 'token' ) ?? '' ),
		];

		$this->renderer->render_full_page( $profile, $context );
	}
}
