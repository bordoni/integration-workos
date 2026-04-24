<?php
/**
 * Main Controller
 *
 * @package WorkOS
 */

namespace WorkOS;

use WorkOS\Contracts\Controller as BaseController;
use WorkOS\Admin\AdminBar;
use WorkOS\Admin\Controller as AdminController;
use WorkOS\Admin\LoginProfiles\Controller as LoginProfilesAdminController;
use WorkOS\Auth\AuthKit\Controller as AuthKitController;
use WorkOS\Auth\Controller as AuthController;
use WorkOS\REST\Controller as RESTController;
use WorkOS\Webhook\Controller as WebhookController;
use WorkOS\Sync\Controller as SyncController;
use WorkOS\Organization\Controller as OrganizationController;
use WorkOS\CLI\Controller as CLIController;
use WorkOS\UI\Controller as UIController;
use WorkOS\ActivityLog\Controller as ActivityLogController;

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
		$this->container->register( AuthKitController::class );
		$this->container->register( LoginProfilesAdminController::class );
		$this->container->register( AuthController::class );
		$this->container->register( RESTController::class );
		$this->container->register( WebhookController::class );
		$this->container->register( SyncController::class );
		$this->container->register( OrganizationController::class );
		$this->container->register( CLIController::class );
		$this->container->register( UIController::class );
		$this->container->register( ActivityLogController::class );

		// Admin bar shows on both admin and frontend, so register at the main level.
		$this->container->singleton( AdminBar::class );
		$this->container->get( AdminBar::class );
	}

	/**
	 * Unregister.
	 *
	 * @return void
	 */
	protected function doUnregister(): void {
	}
}
