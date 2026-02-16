<?php
/**
 * Controller Contract
 *
 * @package WorkOS
 */

namespace WorkOS\Contracts;

/**
 * Base controller class for all feature controllers.
 *
 * Controllers provide lifecycle management, registration tracking, and conditional activation.
 * Always use Controllers instead of ServiceProviders for features.
 */
abstract class Controller extends ServiceProvider {

	/**
	 * Whether the controller has been registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Register the controller.
	 *
	 * This method checks if the controller is active and calls the appropriate methods.
	 *
	 * @return void
	 */
	public function register(): void {
		// Prevent duplicate registration.
		if ( $this->registered ) {
			return;
		}

		// Register as singleton automatically.
		$this->container->singleton( static::class, $this );

		// Check if controller should be active.
		if ( $this->isActive() ) {
			$this->registered = true;
			$this->doRegister();

			/**
			 * Fires when any service provider is registered.
			 *
			 * @param array $classes Array containing the class name of the registered provider.
			 */
			do_action( 'workos/container/registered_provider', [ static::class ] );

			/**
			 * Fires when a specific service provider is registered.
			 *
			 * @param array $classes Array containing the class name of the registered provider.
			 */
			do_action( 'workos/container/registered_provider:' . static::class, [ static::class ] );
		} else {
			$this->triggerNotActiveRegistered();
		}
	}

	/**
	 * Unregister the controller.
	 *
	 * @return void
	 */
	public function unregister(): void {
		if ( $this->registered ) {
			$this->registered = false;
			$this->doUnregister();
		} else {
			$this->triggerNotActiveUnregistered();
		}
	}

	/**
	 * Check if the controller has been registered.
	 *
	 * @return bool
	 */
	public function isRegistered(): bool {
		return $this->registered;
	}

	/**
	 * Check if this controller should be active.
	 *
	 * Override this method to add conditional activation logic.
	 *
	 * @return bool
	 */
	public function isActive(): bool {
		return true;
	}

	/**
	 * Register hooks and filters when the controller is active.
	 *
	 * This is only called once when the controller is registered and active.
	 *
	 * @return void
	 */
	abstract protected function doRegister(): void;

	/**
	 * Remove hooks and filters when the controller is unregistered.
	 *
	 * This is only called if the controller was previously registered.
	 *
	 * @return void
	 */
	abstract protected function doUnregister(): void;

	/**
	 * Called when register() is called but isActive() returns false.
	 *
	 * @return void
	 */
	protected function triggerNotActiveRegistered(): void {
		$this->debug( sprintf( 'Controller %s not active during registration', static::class ) );
	}

	/**
	 * Called when unregister() is called but the controller wasn't registered.
	 *
	 * @return void
	 */
	protected function triggerNotActiveUnregistered(): void {
		$this->debug( sprintf( 'Controller %s not active during unregistration', static::class ) );
	}
}
