<?php
/**
 * Symbol stubs for static analysis.
 *
 * Loaded by phpstan.neon.dist via `scanFiles`. Never executed at runtime —
 * only parsed by PHPStan to make symbols defined inside `Plugin::init()`
 * (and similar deferred-define paths) discoverable to the analyzer.
 *
 * @phpstan-ignore-file
 */

// Defined at runtime by `WorkOS\Plugin::init()`.
define( 'WORKOS_VERSION', '0.0.0' );
define( 'WORKOS_FILE', '' );
define( 'WORKOS_DIR', '' );
define( 'WORKOS_URL', '' );
define( 'WORKOS_BASENAME', '' );

// Action Scheduler (optional; WooCommerce and many plugins ship it). Guarded by
// RoleSyncCheck::has_action_scheduler() before every call.

/**
 * @param string $hook  Hook.
 * @param array  $args  Arguments.
 * @param string $group Group.
 * @return int
 */
function as_enqueue_async_action( $hook, $args = array(), $group = '' ) {}

/**
 * @param int    $timestamp           First run.
 * @param int    $interval_in_seconds Interval.
 * @param string $hook                Hook.
 * @param array  $args                Arguments.
 * @param string $group               Group.
 * @return int
 */
function as_schedule_recurring_action( $timestamp, $interval_in_seconds, $hook, $args = array(), $group = '' ) {}

/**
 * @param string $hook  Hook.
 * @param array  $args  Arguments.
 * @param string $group Group.
 * @return void
 */
function as_unschedule_all_actions( $hook, $args = array(), $group = '' ) {}
