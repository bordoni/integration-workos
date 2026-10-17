<?php
/**
 * Suite-level bootstrap for wpunit tests.
 *
 * Runs after WordPress is loaded by WPLoader.
 *
 * @package WorkOS\Tests
 */

// Fixtures use reserved domains (example.com, *.test) on purpose, which
// workos_is_deliverable_email() rejects. Treat them as deliverable for the
// suite; DeliverableEmailTest removes this filter to test the real rules.
add_filter( 'workos_is_deliverable_email', '__return_true' );
