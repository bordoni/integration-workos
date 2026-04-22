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
	}

	/**
	 * Unregister.
	 *
	 * @return void
	 */
	protected function doUnregister(): void {
	}
}
