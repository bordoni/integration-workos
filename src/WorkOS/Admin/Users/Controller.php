<?php
/**
 * Users admin controller.
 *
 * @package WorkOS\Admin\Users
 */

namespace WorkOS\Admin\Users;

use WorkOS\Contracts\Controller as BaseController;

/**
 * Wires the WorkOS Users admin page (React list of WorkOS users) and its
 * supporting REST endpoint. Always active — the REST routes need to be
 * registered during `rest_api_init` regardless of admin context, and the
 * AdminPage is gated internally via the `admin_menu` / `admin_enqueue_scripts`
 * hooks plus a `manage_options` capability check.
 */
class Controller extends BaseController {

	/**
	 * Register the components.
	 *
	 * @return void
	 */
	protected function doRegister(): void {
		$this->container->singleton( RestApi::class );
		$this->container->get( RestApi::class );

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
