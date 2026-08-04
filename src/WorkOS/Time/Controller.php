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
 * Bound to the interface only, so every consumer shares one instance and
 * {@see \Helper\Time} can swap it wholesale. Production always gets the real
 * clock; {@see FrozenClock} is a test concern.
 */
class Controller extends BaseController {

	/**
	 * Register the module.
	 *
	 * @return void
	 */
	protected function doRegister(): void {
		$this->container->singleton( Clock::class, SystemClock::class );
	}

	/**
	 * Unregister the module.
	 *
	 * @return void
	 */
	protected function doUnregister(): void {
	}
}
