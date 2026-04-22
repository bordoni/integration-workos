<?php
/**
 * wp-login.php takeover for the AuthKit React shell.
 *
 * @package WorkOS\Auth\AuthKit
 */

namespace WorkOS\Auth\AuthKit;

use WorkOS\Auth\LoginBypass;
use WorkOS\Vendor\StellarWP\SuperGlobals\SuperGlobals;

defined( 'ABSPATH' ) || exit;

/**
 * Intercepts wp-login.php `action=login` when the default Login Profile
 * is in custom (React) mode. Other actions — logout, register, lost
 * password, reset password, confirmaction, postpass — are intentionally
 * left untouched so the legacy WP and WooCommerce flows keep working.
 *
 * Related safety nets that are kept out of our way:
 *
 *  - `?workos=0`   — LoginBypass escape hatch
 *  - `?fallback=1` — standard fallback to WP password auth
 *  - `action=logout` and friends fall through to wp-login.php
 */
class LoginTakeover {

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
	 * Profile repository.
	 *
	 * @var ProfileRepository
	 */
	private ProfileRepository $profiles;

	/**
	 * Constructor.
	 *
	 * @param ProfileRouter     $router   Profile router.
	 * @param Renderer          $renderer Renderer.
	 * @param ProfileRepository $profiles Profile repository (for direct lookups).
	 */
	public function __construct( ProfileRouter $router, Renderer $renderer, ProfileRepository $profiles ) {
		$this->router   = $router;
		$this->renderer = $renderer;
		$this->profiles = $profiles;
	}

	/**
	 * Register the takeover on `login_init`.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'login_init', [ $this, 'maybe_takeover' ], 5 );
	}

	/**
	 * Unregister (used by tests).
	 *
	 * @return void
	 */
	public function unregister(): void {
		remove_action( 'login_init', [ $this, 'maybe_takeover' ], 5 );
	}

	/**
	 * Hook target. Decides whether to hand the page over to React.
	 *
	 * @return void
	 */
	public function maybe_takeover(): void {
		if ( ! $this->should_takeover() ) {
			return;
		}

		$profile = $this->resolve_profile();

		// AuthKit-redirect profiles still use the legacy Login::maybe_redirect_to_authkit()
		// path — we deliberately pass them through rather than duplicating behavior.
		if ( ! $profile->is_custom_mode() ) {
			return;
		}

		$context = $this->build_context();

		$this->renderer->render_full_page( $profile, $context );
	}

	/**
	 * Whether this request is a candidate for takeover.
	 *
	 * @return bool
	 */
	private function should_takeover(): bool {
		if ( ! workos()->is_enabled() ) {
			return false;
		}

		// Don't fight the "you've been logged out" screen — users need to see it.
		if ( ! empty( SuperGlobals::get_get_var( 'loggedout' ) ) ) {
			return false;
		}

		// Plugin-level escape hatch.
		if ( class_exists( LoginBypass::class ) && LoginBypass::is_active() ) {
			return false;
		}

		// Classic-fallback opt-in (`?fallback=1`).
		if ( ! empty( SuperGlobals::get_get_var( 'fallback' ) )
			&& workos()->option( 'allow_password_fallback', true ) ) {
			return false;
		}

		$action = SuperGlobals::get_var( 'action' ) ?? 'login';
		if ( 'login' !== $action ) {
			return false;
		}

		return true;
	}

	/**
	 * Resolve the profile for this request.
	 *
	 * @return Profile
	 */
	private function resolve_profile(): Profile {
		$explicit_slug = sanitize_title(
			(string) ( SuperGlobals::get_get_var( 'workos_profile' ) ?? '' )
		);

		$context = [
			'explicit_slug' => $explicit_slug,
			'redirect_to'   => (string) ( SuperGlobals::get_get_var( 'redirect_to' ) ?? '' ),
			'referrer'      => (string) ( SuperGlobals::get_server_var( 'HTTP_REFERER' ) ?? '' ),
			'user_id'       => get_current_user_id(),
		];

		return $this->router->resolve( $context );
	}

	/**
	 * Build the renderer context from current-request state.
	 *
	 * @return array
	 */
	private function build_context(): array {
		$redirect_to = (string) ( SuperGlobals::get_get_var( 'redirect_to' ) ?? '' );

		$context = [
			'redirect_to'      => $redirect_to,
			'invitation_token' => (string) ( SuperGlobals::get_get_var( 'invitation_token' ) ?? '' ),
			'reset_token'      => (string) ( SuperGlobals::get_get_var( 'token' ) ?? '' ),
		];

		// When the reset-password email links back here, boot straight into
		// the reset-confirm flow.
		$workos_action = (string) ( SuperGlobals::get_get_var( 'workos_action' ) ?? '' );
		if ( 'reset-password' === $workos_action && '' !== $context['reset_token'] ) {
			$context['initial_step'] = 'reset_confirm';
		}

		return $context;
	}
}
