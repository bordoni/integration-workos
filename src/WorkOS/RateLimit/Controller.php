<?php
/**
 * Controller for the rate-limiting subsystem.
 *
 * @package WorkOS\RateLimit
 */

namespace WorkOS\RateLimit;

use WorkOS\Contracts\Controller as BaseController;

/**
 * Binds the rate limiter every other subsystem depends on.
 *
 * Registered ahead of the features that consume it (AuthKit REST,
 * change-email, admin password reset) because those resolve
 * {@see TieredRateLimiter} from the container at construction.
 */
class Controller extends BaseController {

	/**
	 * Register rate-limiting services.
	 *
	 * @return void
	 */
	protected function doRegister(): void {
		// Atomic counters when a real object cache is present, transients
		// otherwise. Consumers depend on the tiered wrapper, not on this.
		$this->container->singleton(
			RateLimiter::class,
			static function () {
				return wp_using_ext_object_cache()
					? new ObjectCacheRateLimiter()
					: new DatabaseRateLimiter();
			}
		);

		$this->container->singleton( TieredRateLimiter::class );

		// Lookup and clearing for the WP-CLI command and the Users screen.
		$this->container->singleton( Lockouts::class );
	}

	/**
	 * Nothing to unhook: this module only provides container bindings.
	 *
	 * @return void
	 */
	protected function doUnregister(): void {
	}
}
