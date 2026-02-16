<?php
/**
 * Organization Controller
 *
 * @package WorkOS\Organization
 */

namespace WorkOS\Organization;

use WorkOS\Contracts\Controller as BaseController;

/**
 * Registers organization components.
 */
class Controller extends BaseController {

	/**
	 * Register organization components.
	 *
	 * @return void
	 */
	protected function doRegister(): void {
		$this->container->singleton( Manager::class );
		$this->container->get( Manager::class );
	}

	/**
	 * Unregister.
	 *
	 * @return void
	 */
	protected function doUnregister(): void {
	}
}
