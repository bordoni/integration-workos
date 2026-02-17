<?php
/**
 * Audit Log Controller
 *
 * @package WorkOS\Sync
 */

namespace WorkOS\Sync;

use WorkOS\Contracts\Controller as BaseController;

/**
 * Conditionally registers the audit logging component.
 */
class AuditLogController extends BaseController {

	/**
	 * Only active when audit logging is enabled.
	 *
	 * @return bool
	 */
	public function isActive(): bool {
		return (bool) \WorkOS\App::container()->get( \WorkOS\Options\Global_Options::class )->get( 'audit_logging_enabled', false );
	}

	/**
	 * Register audit log component.
	 *
	 * @return void
	 */
	protected function doRegister(): void {
		$this->container->singleton( AuditLog::class );
		$this->container->get( AuditLog::class );
	}

	/**
	 * Unregister.
	 *
	 * @return void
	 */
	protected function doUnregister(): void {
	}
}
