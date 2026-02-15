<?php
/**
 * Codeception global bootstrap.
 *
 * @package WorkOS\Tests
 */

use Codeception\Util\Autoload;

Autoload::addNamespace( 'WorkOS\\Tests', __DIR__ . '/_support' );
