<?php
/**
 * Auth Controller
 *
 * @package WorkOS\Auth
 */

namespace WorkOS\Auth;

use WorkOS\Contracts\Controller as BaseController;

/**
 * Registers authentication components.
 */
class Controller extends BaseController {

	/**
	 * Register auth components.
	 *
	 * @return void
	 */
	protected function doRegister(): void {
		$this->container->singleton( Login::class );
		$this->container->get( Login::class );

		$this->container->singleton( Redirect::class );
		$this->container->get( Redirect::class );

		$this->container->singleton( LogoutRedirect::class );
		$this->container->get( LogoutRedirect::class );

		$this->container->singleton( Registration::class );
		$this->container->get( Registration::class );

		$this->container->singleton( PasswordReset::class );
		$this->container->get( PasswordReset::class );

		$this->container->singleton( LoginBypass::class );
		$this->container->get( LoginBypass::class );
	}

	/**
	 * Unregister.
	 *
	 * @return void
	 */
	protected function doUnregister(): void {
	}
}
