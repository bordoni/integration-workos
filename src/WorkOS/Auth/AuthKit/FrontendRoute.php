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

	public const QUERY_VAR        = 'workos_login_profile';
	public const REWRITE          = '^workos/login/([^/]+)/?$';
	public const SIGNATURE_OPTION = 'workos_custom_paths_signature';

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
		add_action( 'init', [ self::class, 'register_rewrite' ] );
		// Priority 11 so the CPT (registered at init priority 5) and the
		// canonical rewrite (default init priority 10) are both in place.
		add_action( 'init', [ $this, 'register_custom_paths' ], 11 );
		add_filter( 'query_vars', [ $this, 'register_query_var' ] );
		add_action( 'template_redirect', [ $this, 'maybe_render' ] );

		// React to profile mutations: clear the cached signature so the
		// next request's `init` re-registers the rules and flushes once.
		add_action( 'workos_login_profile_saved', [ $this, 'invalidate_signature' ] );
		add_action( 'workos_login_profile_deleted', [ $this, 'invalidate_signature' ] );
	}

	/**
	 * Register the /workos/login/{profile} rewrite rule.
	 *
	 * Static so Plugin::activate() can call it before the DI container is
	 * built, matching the convention used by {@see \WorkOS\Auth\Login::register_rewrite()}.
	 * Called both from activation (so the rule persists after the
	 * flush_rewrite_rules() call below) and from the `init` hook on every
	 * request (so the rule is present in the rewrite table for matching).
	 *
	 * @return void
	 */
	public static function register_rewrite(): void {
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

		// Already-signed-in users don't need a login screen — bounce them
		// to where they would have ended up after signing in.
		if ( is_user_logged_in() ) {
			nocache_headers();
			wp_safe_redirect( LoginRedirector::for_visitor( $profile ), 302 );
			exit;
		}

		$context = [
			'redirect_to'      => (string) ( SuperGlobals::get_get_var( 'redirect_to' ) ?? '' ),
			'invitation_token' => (string) ( SuperGlobals::get_get_var( 'invitation_token' ) ?? '' ),
			'reset_token'      => (string) ( SuperGlobals::get_get_var( 'token' ) ?? '' ),
		];

		$this->renderer->render_full_page( $profile, $context );
	}

	/**
	 * Register one rewrite rule per profile that has a non-empty custom_path.
	 *
	 * Each rule maps the custom path to the same {@see self::QUERY_VAR}
	 * the canonical /workos/login/{slug} rule uses, so {@see maybe_render()}
	 * needs no changes to handle the new entry points.
	 *
	 * Detects path-set changes via a signature hashed across all custom
	 * paths and triggers a single soft `flush_rewrite_rules( false )` only
	 * when the signature drifts. .htaccess is left untouched.
	 *
	 * @return void
	 */
	public function register_custom_paths(): void {
		$entries = [];
		foreach ( $this->profiles->all() as $profile ) {
			$path = $profile->get_custom_path();
			if ( '' === $path ) {
				continue;
			}
			$slug             = $profile->get_slug();
			$entries[ $path ] = $slug;
			add_rewrite_rule(
				'^' . preg_quote( $path, '#' ) . '/?$',
				'index.php?' . self::QUERY_VAR . '=' . rawurlencode( $slug ),
				'top'
			);
		}

		$signature = self::compute_signature( $entries );
		$stored    = (string) get_option( self::SIGNATURE_OPTION, '' );
		if ( $signature !== $stored ) {
			flush_rewrite_rules( false );
			update_option( self::SIGNATURE_OPTION, $signature, false );
		}
	}

	/**
	 * Drop the cached path signature so the next `init` rebuilds and flushes.
	 *
	 * @return void
	 */
	public function invalidate_signature(): void {
		delete_option( self::SIGNATURE_OPTION );
	}

	/**
	 * Compute a stable hash for the path → slug map.
	 *
	 * @param array<string,string> $entries Map of custom path to profile slug.
	 *
	 * @return string Empty string when there are no entries; an md5 otherwise.
	 */
	private static function compute_signature( array $entries ): string {
		if ( empty( $entries ) ) {
			return 'empty';
		}
		ksort( $entries );
		return md5( (string) wp_json_encode( $entries ) );
	}
}
