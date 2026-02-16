<?php
/**
 * Main Controller
 *
 * @package WorkOS
 */

namespace WorkOS;

use WorkOS\Contracts\Controller as BaseController;
use WorkOS\Admin\Controller as AdminController;
use WorkOS\Auth\Controller as AuthController;
use WorkOS\REST\Controller as RESTController;
use WorkOS\Webhook\Controller as WebhookController;
use WorkOS\Sync\Controller as SyncController;
use WorkOS\Organization\Controller as OrganizationController;

/**
 * Main controller — registers all feature controllers.
 */
class Controller extends BaseController {

	/**
	 * Register all feature controllers.
	 *
	 * @return void
	 */
	protected function doRegister(): void {
		$this->container->register( AdminController::class );
		$this->container->register( AuthController::class );
		$this->container->register( RESTController::class );
		$this->container->register( WebhookController::class );
		$this->container->register( SyncController::class );
		$this->container->register( OrganizationController::class );
	}

	/**
	 * Unregister.
	 *
	 * @return void
	 */
	protected function doUnregister(): void {
	}
}
