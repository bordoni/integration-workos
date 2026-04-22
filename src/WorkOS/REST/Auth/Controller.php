<?php
/**
 * REST Auth (Custom AuthKit) controller.
 *
 * @package WorkOS\REST\Auth
 */

namespace WorkOS\REST\Auth;

use WorkOS\Auth\AuthKit\LoginCompleter;
use WorkOS\Contracts\Controller as BaseController;

/**
 * Registers the public /wp-json/workos/v1/auth/* REST namespace.
 *
 * This controller is always active — anonymous visitors need to be able to
 * authenticate, so the routes cannot be gated on `is_admin()`. Permission
 * is enforced per-route via nonce + rate limit (see {@see BaseEndpoint}).
 */
class Controller extends BaseController {

	/**
	 * Register AuthKit REST endpoints.
	 *
	 * @return void
	 */
	protected function doRegister(): void {
		$this->container->singleton( LoginCompleter::class );

		$this->container->singleton( Password::class );
		$this->container->singleton( MagicCode::class );
		$this->container->singleton( Session::class );
		$this->container->singleton( Signup::class );
		$this->container->singleton( Invitation::class );
		$this->container->singleton( OAuth::class );
		$this->container->singleton( Mfa::class );

		// Resolve each once so the container instantiates them now; the
		// actual route registration happens below on `rest_api_init`.
		$this->container->get( Password::class );
		$this->container->get( MagicCode::class );
		$this->container->get( Session::class );
		$this->container->get( Signup::class );
		$this->container->get( Invitation::class );
		$this->container->get( OAuth::class );
		$this->container->get( Mfa::class );

		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Unregister.
	 *
	 * @return void
	 */
	protected function doUnregister(): void {
		remove_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register all AuthKit REST routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		foreach ( $this->endpoints() as $endpoint ) {
			$endpoint->register_routes();
		}
	}

	/**
	 * The set of endpoints that should register routes.
	 *
	 * Separated into a method so future phases can extend it (Signup,
	 * Invitation, OAuth, MFA, Passkey) without reworking this controller.
	 *
	 * @return BaseEndpoint[]
	 */
	private function endpoints(): array {
		return [
			$this->container->get( Password::class ),
			$this->container->get( MagicCode::class ),
			$this->container->get( Session::class ),
			$this->container->get( Signup::class ),
			$this->container->get( Invitation::class ),
			$this->container->get( OAuth::class ),
			$this->container->get( Mfa::class ),
		];
	}
}
