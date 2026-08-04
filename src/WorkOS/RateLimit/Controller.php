<?php
/**
 * Rate-limit module wiring.
 *
 * @package WorkOS\RateLimit
 */

namespace WorkOS\RateLimit;

use WorkOS\Config;
use WorkOS\Contracts\Controller as BaseController;

defined( 'ABSPATH' ) || exit;

/**
 * Binds the counter store.
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

		// Requester limits are class-constant defaults; operations with a
		// different posture pass their own numbers at the call site. Only the
		// per-recipient email ceilings stay operator-tunable — they are the
		// security-load-bearing ones, and mail volume is deployment-specific
		// in a way per-endpoint request budgets are not.
		$this->container->singleton( HttpRateLimit::class, HttpRateLimit::class );

		// Limits are resolved here, once, and handed in as typed arguments.
		// Keeps the limiter a thing you can construct with explicit numbers in
		// a test rather than one that reaches for configuration on its own.
		$this->container->when( OutboundEmailRateLimiter::class )
						->needs( '$per_five_minutes' )
						->give( static fn(): int => Config::int_setting( 'email_limit_5min', 3 ) );

		$this->container->when( OutboundEmailRateLimiter::class )
						->needs( '$per_hour' )
						->give( static fn(): int => Config::int_setting( 'email_limit_hour', 10 ) );

		$this->container->when( OutboundEmailRateLimiter::class )
						->needs( '$per_day' )
						->give( static fn(): int => Config::int_setting( 'email_limit_day', 20 ) );

		$this->container->singleton( OutboundEmailRateLimiter::class, OutboundEmailRateLimiter::class );
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
	 * The object cache when there is one — it is faster and its `incr` is
	 * atomic on a real backend, so limits hold under concurrency. Transients
	 * otherwise, which every install has.
	 *
	 * @return string Store class name.
	 */
	private function resolve_store(): string {
		return wp_using_ext_object_cache() ? ObjectCacheStore::class : TransientStore::class;
	}
}
