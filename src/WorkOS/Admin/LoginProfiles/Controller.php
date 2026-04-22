<?php
/**
 * Login Profiles admin controller.
 *
 * @package WorkOS\Admin\LoginProfiles
 */

namespace WorkOS\Admin\LoginProfiles;

use WorkOS\Contracts\Controller as BaseController;

/**
 * Wires the admin Login Profile REST endpoints.
 *
 * This controller is always active — the REST routes need to be registered
 * during `rest_api_init` regardless of whether the current request is an
 * admin page load. Permission is enforced per-route via `manage_options`.
 */
class Controller extends BaseController {

	/**
	 * Register admin Login Profiles components.
	 *
	 * @return void
	 */
	protected function doRegister(): void {
		$this->container->singleton( RestApi::class );
		$this->container->get( RestApi::class );

		// AdminPage is a no-op outside wp-admin, but the React bundle it
		// enqueues is served from admin_enqueue_scripts so registering
		// unconditionally is fine.
		$this->container->singleton( AdminPage::class );
		$this->container->get( AdminPage::class )->register();
	}

	/**
	 * Unregister.
	 *
	 * @return void
	 */
	protected function doUnregister(): void {
	}
}
