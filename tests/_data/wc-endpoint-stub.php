<?php
/**
 * Stand-in for WooCommerce's is_wc_endpoint_url() when WooCommerce isn't loaded.
 *
 * Answers from $GLOBALS['workos_test_wc_endpoint'] ('' means "not on an endpoint"),
 * so a test can say which endpoint the request is on. Loaded only by tests that
 * need it, and only when WooCommerce itself isn't there.
 *
 * @package WorkOS\Tests
 */

$GLOBALS['workos_test_wc_endpoint_stub'] = true;
$GLOBALS['workos_test_wc_endpoint']      = '';

/**
 * Whether the request is on a WooCommerce endpoint.
 *
 * @param string|false $endpoint Endpoint slug, or false for any endpoint.
 *
 * @return bool
 */
function is_wc_endpoint_url( $endpoint = false ) {
	$current = (string) ( $GLOBALS['workos_test_wc_endpoint'] ?? '' );
	if ( false === $endpoint ) {
		return '' !== $current;
	}
	return $current === $endpoint;
}
