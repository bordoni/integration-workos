<?php
/**
 * Controller for the WorkOS-verified email-change subsystem.
 *
 * @package WorkOS\Auth\ChangeEmail
 */

namespace WorkOS\Auth\ChangeEmail;

use WorkOS\Auth\PasswordResetAdmin\RedirectValidator;
use WorkOS\Contracts\Controller as BaseController;
use WorkOS\Email\Mailer;

/**
 * Wires the change-email REST endpoints, the Users-list row action, the
 * user-edit panel, the self-service shortcode, the frontend confirm
 * route, and the shared JS/CSS assets. Each surface is independently
 * registerable so it can be unit-tested in isolation.
 *
 * Verification is owned by this plugin (not WorkOS): a hashed token is
 * stored as user_meta with an expiry, emailed to the new address, then
 * consumed by the REST `confirm` endpoint, which commits the change in
 * WorkOS before mirroring it into WordPress. See {@see PendingChange}
 * and {@see RestApi} for the lifecycle details.
 */
class Controller extends BaseController {

	/**
	 * Register feature components.
	 *
	 * @return void
	 */
	protected function doRegister(): void {
		$this->container->singleton( TokenFactory::class );
		$this->container->singleton( PendingChange::class );
		$this->container->singleton( ConflictResolver::class );
		$this->container->singleton( Mailer::class );
		$this->container->singleton( Notifier::class );
		$this->container->singleton( RedirectValidator::class );
		$this->container->singleton( RestApi::class );
		$this->container->singleton( Assets::class );
		$this->container->singleton( RowActions::class );
		$this->container->singleton( UserProfilePanel::class );
		$this->container->singleton( Shortcode::class );
		$this->container->singleton( FrontendConfirmRoute::class );

		$this->container->get( RestApi::class )->register();
		$this->container->get( Assets::class )->register();
		$this->container->get( RowActions::class )->register();
		$this->container->get( UserProfilePanel::class )->register();
		$this->container->get( Shortcode::class )->register();
		$this->container->get( FrontendConfirmRoute::class )->register();
	}

	/**
	 * Unregister.
	 *
	 * @return void
	 */
	protected function doUnregister(): void {
	}

	/**
	 * Master feature switch — applies the `change_email_enabled` option
	 * filtered through `workos/change_email/enabled`.
	 *
	 * @return bool
	 */
	public function isActive(): bool {
		$enabled = (bool) workos()->option( 'change_email_enabled', true );

		/**
		 * Filter whether the change-email feature is active.
		 *
		 * @param bool $enabled Whether the change-email feature is active.
		 */
		return (bool) apply_filters( 'workos/change_email/enabled', $enabled );
	}
}
