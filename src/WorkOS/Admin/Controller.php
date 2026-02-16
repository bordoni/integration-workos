<?php
/**
 * Admin Controller
 *
 * @package WorkOS\Admin
 */

namespace WorkOS\Admin;

use WorkOS\Contracts\Controller as BaseController;

/**
 * Registers admin-only components.
 */
class Controller extends BaseController {

	/**
	 * Only active in the admin context.
	 *
	 * @return bool
	 */
	public function isActive(): bool {
		return is_admin();
	}

	/**
	 * Register admin components.
	 *
	 * @return void
	 */
	protected function doRegister(): void {
		$this->container->singleton( Settings::class );
		$this->container->get( Settings::class );

		$this->container->singleton( UserList::class );
		$this->container->get( UserList::class );
	}

	/**
	 * Unregister.
	 *
	 * @return void
	 */
	protected function doUnregister(): void {
	}
}
