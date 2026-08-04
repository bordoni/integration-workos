<?php
/**
 * Controller for shared HTTP request concerns.
 *
 * @package WorkOS\Http
 */

namespace WorkOS\Http;

use WorkOS\Config;
use WorkOS\Contracts\Controller as BaseController;

/**
 * Binds request-level services other modules resolve.
 *
 * Registered ahead of the features that consume them, since rate limiting,
 * the activity log, and the audit log all need to agree on who the caller
 * is within a single request.
 */
class Controller extends BaseController {

	/**
	 * Register HTTP services.
	 *
	 * @return void
	 */
	protected function doRegister(): void {
		$this->container->when( ClientIp::class )
						->needs( '$trusted_header' )
						->give( static fn(): string => Config::string_setting( 'client_ip_header', '' ) );

		$this->container->singleton( ClientIp::class );
	}

	/**
	 * Nothing to unhook: this module only provides container bindings.
	 *
	 * @return void
	 */
	protected function doUnregister(): void {
	}
}
