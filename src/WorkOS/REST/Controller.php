<?php
/**
 * REST Controller
 *
 * @package WorkOS\REST
 */

namespace WorkOS\REST;

use WorkOS\Contracts\Controller as BaseController;

/**
 * Registers REST API components.
 */
class Controller extends BaseController {

	/**
	 * Register REST components.
	 *
	 * @return void
	 */
	protected function doRegister(): void {
		$this->container->singleton( TokenAuth::class );
		$this->container->get( TokenAuth::class );
	}

	/**
	 * Unregister.
	 *
	 * @return void
	 */
	protected function doUnregister(): void {
	}
}
