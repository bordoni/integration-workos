<?php
/**
 * Controller for multi-factor authentication.
 *
 * @package WorkOS\Mfa
 */

namespace WorkOS\Mfa;

use WorkOS\Contracts\Controller as BaseController;

/**
 * Binds the MFA settings, policy and factor service, the guards that keep
 * password-only sign-ins and outside Bearer tokens from skipping MFA, the
 * emails and sign-outs around a change, and the account panel (REST,
 * assets, `[workos:mfa]`, profile screen).
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
		$this->container->singleton( CodeGuard::class );
		$this->container->singleton( FactorService::class );
		$this->container->singleton( PasswordGuard::class );
		$this->container->singleton( VerifiedSessions::class );
		$this->container->singleton( AccountChanges::class );
		$this->container->singleton( RestApi::class );
		$this->container->singleton( Assets::class );
		$this->container->singleton( Shortcode::class );
		$this->container->singleton( UserProfilePanel::class );
		$this->container->singleton( Admin\UserEditPanel::class );

		$this->container->get( PasswordGuard::class )->register();
		$this->container->get( VerifiedSessions::class )->register();
		$this->container->get( AccountChanges::class )->register();
		$this->container->get( RestApi::class )->register();
		$this->container->get( Assets::class )->register();
		$this->container->get( Shortcode::class )->register();
		$this->container->get( UserProfilePanel::class )->register();
		$this->container->get( Admin\UserEditPanel::class )->register();
	}

	/**
	 * Unregister.
	 *
	 * @return void
	 */
	protected function doUnregister(): void {
	}
}
