<?php
/**
 * Webhook Controller
 *
 * @package WorkOS\Webhook
 */

namespace WorkOS\Webhook;

use WorkOS\Contracts\Controller as BaseController;

/**
 * Registers webhook components.
 */
class Controller extends BaseController {

	/**
	 * Register webhook components.
	 *
	 * @return void
	 */
	protected function doRegister(): void {
		$this->container->singleton( Receiver::class );
		$this->container->get( Receiver::class );
	}

	/**
	 * Unregister.
	 *
	 * @return void
	 */
	protected function doUnregister(): void {
	}
}
