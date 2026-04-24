<?php
/**
 * AuthKit Controller.
 *
 * @package WorkOS\Auth\AuthKit
 */

namespace WorkOS\Auth\AuthKit;

use WorkOS\Contracts\Controller as BaseController;

/**
 * Registers AuthKit infrastructure: Login Profile CPT, repository, and
 * (later) the rendering/takeover/routing components that drive the custom
 * React login experience.
 *
 * Only the data-layer wiring is active in this controller today; the render
 * and REST controllers register themselves independently.
 */
class Controller extends BaseController {

	/**
	 * Register the AuthKit module.
	 *
	 * @return void
	 */
	protected function doRegister(): void {
		$this->container->singleton( ProfileRepository::class );
		$this->container->singleton( ProfileRouter::class );
		$this->container->singleton( RateLimiter::class );
		$this->container->singleton( Nonce::class );
		$this->container->singleton( Radar::class );
		$this->container->singleton( Renderer::class );
		$this->container->singleton( LoginTakeover::class );
		$this->container->singleton( Shortcode::class );
		$this->container->singleton( FrontendRoute::class );
		$this->container->singleton( ModeSyncer::class );

		// Register the CPT early — `init` priority 5 so other components that
		// query the type on `init` (priority 10+) see a registered post type.
		add_action( 'init', [ $this, 'register_post_type' ], 5 );

		// Activate the wp-login.php takeover, the shortcode, and the
		// dedicated /workos/login/{profile} route. Each no-ops when the
		// resolved profile is in legacy AuthKit-redirect mode.
		$this->container->get( LoginTakeover::class )->register();
		$this->container->get( Shortcode::class )->register();
		$this->container->get( FrontendRoute::class )->register();
		$this->container->get( ModeSyncer::class )->register();
	}

	/**
	 * Unregister the AuthKit module.
	 *
	 * @return void
	 */
	protected function doUnregister(): void {
		remove_action( 'init', [ $this, 'register_post_type' ], 5 );

		$this->container->get( LoginTakeover::class )->unregister();
	}

	/**
	 * Register the Login Profile custom post type.
	 *
	 * Public wrapper so the hook target resolves cleanly.
	 *
	 * @return void
	 */
	public function register_post_type(): void {
		$this->container->get( ProfileRepository::class )->register_post_type();
	}
}
