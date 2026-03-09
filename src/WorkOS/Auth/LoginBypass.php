<?php
/**
 * Login bypass for emergency WP-native login access.
 *
 * @package WorkOS\Auth
 */

namespace WorkOS\Auth;

use WorkOS\ActivityLog\EventLogger;
use WorkOS\Vendor\StellarWP\SuperGlobals\SuperGlobals;

defined( 'ABSPATH' ) || exit;

/**
 * Allows bypassing WorkOS login via ?workos=0 on wp-login.php.
 *
 * Deactivated by default — must be explicitly enabled via filter or setting.
 */
class LoginBypass {

	/**
	 * Whether bypass is currently active for this request.
	 *
	 * @var bool
	 */
	private static bool $active = false;

	/**
	 * Constructor — registers hooks.
	 */
	public function __construct() {
		add_action( 'login_init', [ $this, 'maybe_activate_bypass' ], 1 );
	}

	/**
	 * Check if bypass is active for this request.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return self::$active;
	}

	/**
	 * Check for bypass query parameter and activate if allowed.
	 */
	public function maybe_activate_bypass(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$workos = SuperGlobals::get_get_var( 'workos' );
		if ( null === $workos || '0' !== $workos ) {
			return;
		}

		if ( ! self::is_bypass_enabled() ) {
			return;
		}

		/**
		 * Filter whether the current bypass attempt should be allowed.
		 *
		 * Only runs if bypass is enabled. Use for IP whitelisting, etc.
		 *
		 * @param bool $allowed Whether bypass is allowed for this request.
		 */
		$allowed = apply_filters( 'workos_bypass_check', true );

		if ( ! $allowed ) {
			return;
		}

		self::$active = true;

		EventLogger::log(
			'bypass_activated',
			[
				'metadata' => [
					'ip' => self::get_ip(),
				],
			]
		);

		/**
		 * Fires when login bypass is activated.
		 */
		do_action( 'workos_bypass_activated' );
	}

	/**
	 * Check if login bypass is enabled.
	 *
	 * @return bool
	 */
	private static function is_bypass_enabled(): bool {
		/**
		 * Filter whether login bypass is enabled.
		 *
		 * Default is false — bypass must be explicitly enabled.
		 *
		 * @param bool $enabled Whether bypass is enabled.
		 */
		return (bool) apply_filters( 'workos_bypass_enabled', false );
	}

	/**
	 * Get the client IP address.
	 *
	 * @return string
	 */
	private static function get_ip(): string {
		return SuperGlobals::get_server_var( 'REMOTE_ADDR' ) ?? '';
	}
}
