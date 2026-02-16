<?php
/**
 * Sync Controller
 *
 * @package WorkOS\Sync
 */

namespace WorkOS\Sync;

use WorkOS\Contracts\Controller as BaseController;

/**
 * Registers sync components.
 */
class Controller extends BaseController {

	/**
	 * Register sync components.
	 *
	 * @return void
	 */
	protected function doRegister(): void {
		$this->container->singleton( UserSync::class );
		$this->container->get( UserSync::class );

		$this->container->singleton( RoleMapper::class );
		$this->container->get( RoleMapper::class );

		$this->container->singleton( DirectorySync::class );
		$this->container->get( DirectorySync::class );

		// Audit logging is conditionally registered.
		$this->container->register( AuditLogController::class );
	}

	/**
	 * Unregister.
	 *
	 * @return void
	 */
	protected function doUnregister(): void {
	}
}
