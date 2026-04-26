<?php
/**
 * Service Provider Contract
 *
 * @package WorkOS
 */

namespace WorkOS\Contracts;

use WorkOS\Vendor\lucatume\DI52\ServiceProvider as Di52ServiceProvider;

/**
 * Base service provider class.
 *
 * Note: Prefer using Controller instead of ServiceProvider.
 * Controllers provide better lifecycle management and registration tracking.
 *
 * @property Container $container The plugin's typed container subclass — the
 *                                parent declares `$container` as the bare di52
 *                                Container, but `Plugin::initializeContainer()`
 *                                always constructs the contract subclass.
 */
abstract class ServiceProvider extends Di52ServiceProvider {

	/**
	 * Binds and sets up implementations.
	 *
	 * @return void
	 */
	abstract public function register(): void;

	/**
	 * Get the container instance.
	 *
	 * Instance method (not static) because the parent
	 * `Di52ServiceProvider` stores the container as a non-static
	 * `$container` property — `static::$container` would fatal at
	 * runtime if anyone called the previous static signature.
	 *
	 * @return Container
	 */
	protected function getContainer(): Container {
		return $this->container;
	}

	/**
	 * Log a debug message.
	 *
	 * @param string $message The message to log.
	 *
	 * @return void
	 */
	protected function debug( string $message ): void {
		workos_log( $message, 'debug' );
	}

	/**
	 * Log a warning message.
	 *
	 * @param string $message The message to log.
	 *
	 * @return void
	 */
	protected function warning( string $message ): void {
		workos_log( $message, 'warning' );
	}

	/**
	 * Log an error message.
	 *
	 * @param string $message The message to log.
	 *
	 * @return void
	 */
	protected function error( string $message ): void {
		workos_log( $message, 'error' );
	}
}
