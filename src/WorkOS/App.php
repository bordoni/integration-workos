<?php
/**
 * App Facade for Container Access
 *
 * @package WorkOS
 */

namespace WorkOS;

use WorkOS\Vendor\lucatume\DI52\App as Di52App;

/**
 * App facade for global container access.
 *
 * This should only be used outside of Controllers.
 * Inside Controllers, use $this->container instead.
 *
 * @method static \WorkOS\Contracts\Container container()
 * @method static void setContainer(\WorkOS\Contracts\Container $container)
 * @method static void singleton(string $id, $implementation = null, ?array $afterBuildMethods = null)
 * @method static void bind(string $id, $implementation = null, ?array $afterBuildMethods = null)
 * @method static mixed get(string $id)
 * @method static mixed make(string $id, array $args = [])
 * @method static void register(string $serviceProviderClass, string ...$alias)
 * @method static callable callback(string $id, string $method = null)
 * @method static void setVar(string $key, $value)
 * @method static mixed getVar(string $key, $default = null)
 * @method static bool has(string $id)
 */
class App extends Di52App {
}
