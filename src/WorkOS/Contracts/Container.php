<?php
/**
 * Container Contract
 *
 * @package WorkOS
 */

namespace WorkOS\Contracts;

use WorkOS\Vendor\lucatume\DI52\Container as Di52Container;
use WorkOS\Vendor\StellarWP\ContainerContract\ContainerInterface;

/**
 * Container class extending Di52 with ContainerInterface.
 */
class Container extends Di52Container implements ContainerInterface {
}
