<?php
/**
 * Controller for the admin-triggered password-reset subsystem.
 *
 * @package WorkOS\Auth\PasswordResetAdmin
 */

namespace WorkOS\Auth\PasswordResetAdmin;

use WorkOS\Contracts\Controller as BaseController;

/**
 * Wires up the admin REST endpoint, the Users-list row action, the
 * user-edit screen panel button, the public shortcode, and the shared
 * JS/CSS assets. Each surface is independently registerable so they can
 * be unit-tested in isolation.
 */
class Controller extends BaseController {

	/**
	 * Register feature components.
	 *
	 * @return void
	 */
	protected function doRegister(): void {
		$this->container->singleton( RedirectValidator::class );
		$this->container->singleton( RestApi::class );
		$this->container->singleton( Assets::class );
		$this->container->singleton( RowActions::class );
		$this->container->singleton( UserProfilePanel::class );
		$this->container->singleton( Shortcode::class );

		$this->container->get( RestApi::class )->register();
		$this->container->get( Assets::class )->register();
		$this->container->get( RowActions::class )->register();
		$this->container->get( UserProfilePanel::class )->register();
		$this->container->get( Shortcode::class )->register();
	}

	/**
	 * Unregister.
	 *
	 * @return void
	 */
	protected function doUnregister(): void {
	}
}
