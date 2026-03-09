<?php
/**
 * Organization entitlement gate.
 *
 * @package WorkOS\Auth
 */

namespace WorkOS\Auth;

use WorkOS\ActivityLog\EventLogger;
use WorkOS\Config;

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
	 * Call this before setting the auth cookie. Returns true if allowed,
	 * or calls wp_die() if denied.
	 *
	 * @param int   $user_id     WP user ID.
	 * @param array $workos_data WorkOS auth response data.
	 */
	public static function check( int $user_id, array $workos_data ): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		$workos_user_id = $workos_data['user']['id'] ?? '';
		$org_id         = $workos_data['organization_id'] ?? Config::get_organization_id();

		if ( empty( $workos_user_id ) || empty( $org_id ) ) {
			return;
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
				return;
			}
			self::deny( $user_id, 'filter_denied', $workos_data );
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
			return;
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
			self::deny( $user_id, 'no_active_membership', $workos_data );
		}
	}

	/**
	 * Deny login and terminate.
	 *
	 * @param int    $user_id     WP user ID.
	 * @param string $reason      Denial reason.
	 * @param array  $workos_data WorkOS auth response.
	 */
	private static function deny( int $user_id, string $reason, array $workos_data ): void {
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

		wp_die(
			esc_html__( 'Access denied. You do not have an active membership in the required organization. Please contact your administrator.', 'integration-workos' ),
			esc_html__( 'Access Denied', 'integration-workos' ),
			[ 'response' => 403 ]
		);
	}
}
