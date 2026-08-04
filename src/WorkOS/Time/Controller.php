<?php
/**
 * Time module wiring.
 *
 * @package WorkOS\Time
 */

namespace WorkOS\Time;

use WorkOS\Contracts\Controller as BaseController;

defined( 'ABSPATH' ) || exit;

/**
 * Binds the time source.
 *
 * Always the real clock. {@see FrozenClock} exists for tests, which build
 * their subjects directly rather than resolving them here, so there is no
 * environment in which the container hands out a clock that doesn't move.
 */
class Controller extends BaseController {

	/**
	 * Register the module.
	 *
	 * @return void
	 */
	protected function doRegister(): void {
		$this->container->singleton( Clock::class, SystemClock::class );
		$this->container->singleton( SystemClock::class );
	}

	/**
	 * Unregister the module.
	 *
	 * @return void
	 */
	protected function doUnregister(): void {
	}
}
