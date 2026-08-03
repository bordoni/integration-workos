<?php
/**
 * Controller for the attempt-lock subsystem.
 *
 * @package WorkOS\Lock
 */

namespace WorkOS\Lock;

use WorkOS\Contracts\Controller as BaseController;
use WorkOS\Lock\Admin\RowActions;
use WorkOS\Lock\Admin\UnlockHandler;
use WorkOS\Lock\Admin\UserProfilePanel;
use WorkOS\Lock\Store\OptionStore;
use WorkOS\Lock\Store\ResolvingStore;
use WorkOS\Lock\Store\UserMetaStore;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the lock registry, its storage backends, the scheduled cleanup and the
 * operator surfaces.
 *
 * The locks themselves attach nothing — they are called directly by the flows
 * they guard. What is registered here is everything around them: the shared
 * store, the sweep that keeps option rows from accumulating, and the admin
 * screens for inspecting and clearing a lock.
 *
 * See `docs/locks.md` for the calling convention and for the recipe on adding
 * a lock of your own.
 */
class Controller extends BaseController {

	/**
	 * Register feature components.
	 *
	 * @return void
	 */
	protected function doRegister(): void {
		$this->container->singleton( UserMetaStore::class );
		$this->container->singleton( OptionStore::class );
		$this->container->singleton( ResolvingStore::class );
		$this->container->singleton( Registry::class );
		$this->container->singleton( GarbageCollector::class );
		$this->container->singleton( ActivityLogger::class );
		$this->container->singleton( UnlockHandler::class );
		$this->container->singleton( RowActions::class );
		$this->container->singleton( UserProfilePanel::class );

		$this->container->get( GarbageCollector::class )->register();
		$this->container->get( ActivityLogger::class )->register();
		$this->container->get( UnlockHandler::class )->register();
		$this->container->get( RowActions::class )->register();
		$this->container->get( UserProfilePanel::class )->register();
	}

	/**
	 * Unregister.
	 *
	 * @return void
	 */
	protected function doUnregister(): void {
		$this->container->get( GarbageCollector::class )->unregister();
	}

	/**
	 * Master feature switch.
	 *
	 * Disabling this removes the durable layer but leaves the burst limiter in
	 * place, so endpoints are still throttled — just not across cache flushes
	 * or on an escalating schedule.
	 *
	 * @return bool
	 */
	public function isActive(): bool {
		$enabled = (bool) workos()->option( 'locks_enabled', true );

		/**
		 * Filters whether the attempt-lock subsystem is active.
		 *
		 * @param bool $enabled Whether locks are enforced.
		 */
		return (bool) apply_filters( 'workos_locks_enabled', $enabled );
	}
}
