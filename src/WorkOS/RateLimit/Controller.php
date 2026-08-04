<?php
/**
 * Rate-limit module wiring.
 *
 * @package WorkOS\RateLimit
 */

namespace WorkOS\RateLimit;

use WorkOS\Contracts\Controller as BaseController;

defined( 'ABSPATH' ) || exit;

/**
 * Binds the counter and its storage.
 */
class Controller extends BaseController {

	/**
	 * Register the module.
	 *
	 * @return void
	 */
	protected function doRegister(): void {
		$this->container->singleton( CounterStore::class, $this->resolve_store() );
		$this->container->singleton( RateLimiter::class );
	}

	/**
	 * Unregister the module.
	 *
	 * @return void
	 */
	protected function doUnregister(): void {
	}

	/**
	 * Which store backs the counters.
	 *
	 * The object cache when there is one: `wp_cache_incr` is a single atomic
	 * operation on Redis or Memcached, so a burst cannot race the counter.
	 * Transients otherwise, which every install has but which read, modify and
	 * write.
	 *
	 * Worth knowing: WordPress reports an object cache as present based on a
	 * drop-in existing, not on the backend answering. A drop-in whose backend is
	 * unreachable can degrade to per-request memory while still answering every
	 * call, at which point every request starts from zero.
	 *
	 * @return string Store class name.
	 */
	private function resolve_store(): string {
		return wp_using_ext_object_cache() ? ObjectCacheStore::class : TransientStore::class;
	}
}
