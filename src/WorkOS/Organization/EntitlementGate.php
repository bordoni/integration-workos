<?php
/**
 * Organization entitlement gate.
 *
 * @package WorkOS\Organization
 */

namespace WorkOS\Organization;

use WorkOS\ActivityLog\EventLogger;
use WorkOS\Config;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Denies login if user lacks active organization membership.
 */
class EntitlementGate {

	/**
	 * Check if the entitlement gate is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		if ( defined( 'WORKOS_ENTITLEMENT_GATE_ENABLED' ) ) {
			$enabled = (bool) WORKOS_ENTITLEMENT_GATE_ENABLED;
		} else {
			$enabled = (bool) workos()->option( 'entitlement_gate_enabled', false );
		}

		/**
		 * Filter whether the entitlement gate is enabled.
		 *
		 * @param bool $enabled Whether the gate is enabled.
		 */
		return (bool) apply_filters( 'workos_entitlement_gate_enabled', $enabled );
	}

	/**
	 * Check entitlements for a user after WorkOS authentication.
	 *
	 * Call this before setting the auth cookie. Returns when the user is
	 * allowed, or calls wp_die() when denied. Use {@see self::evaluate()}
	 * from REST contexts where a wp_die() page would be inappropriate.
	 *
	 * @param int   $user_id     WP user ID.
	 * @param array $workos_data WorkOS auth response data.
	 */
	public static function check( int $user_id, array $workos_data ): void {
		$result = self::evaluate( $user_id, $workos_data );
		if ( is_wp_error( $result ) ) {
			self::deny_with_wp_die( $user_id, (string) $result->get_error_code(), $workos_data );
		}
	}

	/**
	 * Non-terminating entitlement check — returns true or WP_Error.
	 *
	 * Fires the same activity log and `workos_login_denied` action as
	 * {@see self::check()} but leaves the HTTP response to the caller.
	 * Used by the AuthKit REST endpoints so denials surface as JSON
	 * instead of the wp_die() 403 page.
	 *
	 * @param int   $user_id     WP user ID.
	 * @param array $workos_data WorkOS auth response data.
	 *
	 * @return true|WP_Error True when allowed; WP_Error(403) when denied.
	 */
	public static function evaluate( int $user_id, array $workos_data ) {
		if ( ! self::is_enabled() ) {
			return true;
		}

		$workos_user_id = $workos_data['user']['id'] ?? '';
		$org_id         = $workos_data['organization_id'] ?? Config::get_organization_id();

		if ( empty( $workos_user_id ) || empty( $org_id ) ) {
			return true;
		}

		/**
		 * Filter whether the user passes the entitlement check.
		 *
		 * @param bool  $allowed     Whether the user is allowed.
		 * @param int   $user_id     WP user ID.
		 * @param array $workos_data WorkOS auth response.
		 */
		$allowed = apply_filters( 'workos_entitlement_check', null, $user_id, $workos_data );

		// If a filter explicitly set a value, use it.
		if ( null !== $allowed ) {
			if ( $allowed ) {
				return true;
			}
			self::log_denial( $user_id, 'filter_denied', $workos_data );
			return self::denial_error( 'filter_denied' );
		}

		// Check organization membership via API.
		$result = workos()->api()->list_organization_memberships(
			[
				'user_id'         => $workos_user_id,
				'organization_id' => $org_id,
			]
		);

		if ( is_wp_error( $result ) ) {
			// On API error, don't block login — fail open.
			return true;
		}

		$memberships = $result['data'] ?? [];
		$has_active  = false;

		foreach ( $memberships as $membership ) {
			if ( ( $membership['status'] ?? '' ) === 'active' ) {
				$has_active = true;
				break;
			}
		}

		if ( ! $has_active ) {
			self::log_denial( $user_id, 'no_active_membership', $workos_data );
			return self::denial_error( 'no_active_membership' );
		}

		return true;
	}

	/**
	 * Build the WP_Error returned from evaluate() on denial.
	 *
	 * @param string $reason Denial reason.
	 *
	 * @return WP_Error
	 */
	private static function denial_error( string $reason ): WP_Error {
		return new WP_Error(
			'workos_entitlement_denied',
			__( 'Access denied. You do not have an active membership in the required organization.', 'integration-workos' ),
			[
				'status' => 403,
				'reason' => $reason,
			]
		);
	}

	/**
	 * Fire activity log + `workos_login_denied` action for a denial.
	 *
	 * @param int    $user_id     WP user ID.
	 * @param string $reason      Denial reason.
	 * @param array  $workos_data WorkOS auth response.
	 *
	 * @return void
	 */
	private static function log_denial( int $user_id, string $reason, array $workos_data ): void {
		EventLogger::log(
			'login_denied',
			[
				'user_id'        => $user_id,
				'workos_user_id' => $workos_data['user']['id'] ?? '',
				'metadata'       => [ 'reason' => $reason ],
			]
		);

		/**
		 * Fires when a login is denied by the entitlement gate.
		 *
		 * @param int    $user_id     WP user ID.
		 * @param string $reason      Denial reason.
		 * @param array  $workos_data WorkOS auth response.
		 */
		do_action( 'workos_login_denied', $user_id, $reason, $workos_data );
	}

	/**
	 * Terminal denial via wp_die — used by the legacy redirect flow.
	 *
	 * Preserves the original `check()` behavior: activity-log the denial
	 * and short-circuit the request with a 403 page.
	 *
	 * @param int    $user_id     WP user ID.
	 * @param string $reason_or_error_code Original reason or error code string.
	 * @param array  $workos_data WorkOS auth response.
	 */
	private static function deny_with_wp_die( int $user_id, string $reason_or_error_code, array $workos_data ): void {
		// evaluate() already logged; re-derive the reason from the error code
		// so this path can also be used directly.
		$reason = 'workos_entitlement_denied' === $reason_or_error_code
			? ( $workos_data['__denial_reason'] ?? 'no_active_membership' )
			: $reason_or_error_code;

		if ( 'workos_entitlement_denied' !== $reason_or_error_code ) {
			self::log_denial( $user_id, $reason, $workos_data );
		}

		wp_die(
			esc_html__( 'Access denied. You do not have an active membership in the required organization. Please contact your administrator.', 'integration-workos' ),
			esc_html__( 'Access Denied', 'integration-workos' ),
			[ 'response' => 403 ]
		);
	}
}
