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
	 * @return Container
	 */
	protected static function getContainer(): Container {
		return static::$container;
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
