<?php
/**
 * CLI Controller
 *
 * @package WorkOS\CLI
 */

namespace WorkOS\CLI;

use WorkOS\Contracts\Controller as BaseController;

/**
 * Registers WP-CLI commands when running under `wp`.
 */
class Controller extends BaseController {

	/**
	 * Only active when WP-CLI is available.
	 *
	 * @return bool
	 */
	public function isActive(): bool {
		return defined( 'WP_CLI' ) && WP_CLI;
	}

	/**
	 * Register CLI commands.
	 *
	 * @return void
	 */
	protected function doRegister(): void {
		$this->container->singleton( UserCommand::class );
		$this->container->singleton( SyncCommand::class );

		\WP_CLI::add_command( 'workos status', StatusCommand::class );
		\WP_CLI::add_command( 'workos user', $this->container->get( UserCommand::class ) );
		\WP_CLI::add_command( 'workos org', OrgCommand::class );
		\WP_CLI::add_command( 'workos sync', $this->container->get( SyncCommand::class ) );
	}

	/**
	 * Unregister.
	 *
	 * @return void
	 */
	protected function doUnregister(): void {
	}
}
