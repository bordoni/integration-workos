<?php
/**
 * Controller for multi-factor authentication.
 *
 * @package WorkOS\Mfa
 */

namespace WorkOS\Mfa;

use WorkOS\Contracts\Controller as BaseController;

/**
 * Binds the MFA settings, policy and factor service.
 *
 * Sign-in enforcement lives in `Auth\AuthKit\LoginCompleter`, and the
 * routes in `REST\Auth\Mfa`; both resolve these from the container.
 */
class Controller extends BaseController {

	/**
	 * Register feature components.
	 *
	 * @return void
	 */
	protected function doRegister(): void {
		$this->container->singleton( Settings::class );
		$this->container->singleton( Policy::class );
		$this->container->singleton( FactorService::class );
	}

	/**
	 * Unregister.
	 *
	 * @return void
	 */
	protected function doUnregister(): void {
	}
}
