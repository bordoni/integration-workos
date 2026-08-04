<?php
/**
 * Controller for shared email-address services.
 *
 * @package WorkOS\Email
 */

namespace WorkOS\Email;

use WorkOS\Contracts\Controller as BaseController;

/**
 * Binds address helpers used across features.
 *
 * Registered early: the auth endpoints resolve the canonicalizer at
 * construction to key their rate limits on a mailbox rather than on the
 * particular string a caller typed.
 */
class Controller extends BaseController {

	/**
	 * Register email services.
	 *
	 * @return void
	 */
	protected function doRegister(): void {
		$this->container->singleton( AddressCanonicalizer::class );
	}

	/**
	 * Nothing to unhook: this module only provides container bindings.
	 *
	 * @return void
	 */
	protected function doUnregister(): void {
	}
}
