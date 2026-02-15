<?php
/**
 * Centralized configuration with wp-config.php constant overrides.
 *
 * @package WorkOS
 */

namespace WorkOS;

defined( 'ABSPATH' ) || exit;

/**
 * Provides access to plugin settings with support for constant-based overrides.
 *
 * Constants in wp-config.php take precedence over database-stored options.
 */
class Config {

	/**
	 * Map of setting names to their PHP constant overrides.
	 */
	private const CONSTANT_MAP = [
		'api_key'        => 'WORKOS_API_KEY',
		'client_id'      => 'WORKOS_CLIENT_ID',
		'webhook_secret' => 'WORKOS_WEBHOOK_SECRET',
	];

	/**
	 * Get the WorkOS API key.
	 *
	 * @return string
	 */
	public static function get_api_key(): string {
		return self::get( 'api_key' );
	}

	/**
	 * Get the WorkOS Client ID.
	 *
	 * @return string
	 */
	public static function get_client_id(): string {
		return self::get( 'client_id' );
	}

	/**
	 * Get the webhook signing secret.
	 *
	 * @return string
	 */
	public static function get_webhook_secret(): string {
		return self::get( 'webhook_secret' );
	}

	/**
	 * Check if a setting is overridden by a constant.
	 *
	 * @param string $setting Setting name (e.g. 'api_key').
	 *
	 * @return bool
	 */
	public static function is_overridden( string $setting ): bool {
		$constant = self::CONSTANT_MAP[ $setting ] ?? '';

		return $constant && defined( $constant ) && '' !== constant( $constant );
	}

	/**
	 * Mask a secret value for UI display.
	 *
	 * Shows only the last 4 characters.
	 *
	 * @param string $value The secret value.
	 *
	 * @return string Masked string or empty if value is empty.
	 */
	public static function mask_secret( string $value ): string {
		if ( strlen( $value ) <= 4 ) {
			return $value ? str_repeat( '*', strlen( $value ) ) : '';
		}

		return str_repeat( '*', strlen( $value ) - 4 ) . substr( $value, -4 );
	}

	/**
	 * Generate a cryptographically secure random secret.
	 *
	 * @param int $length Length of the secret in bytes (hex output will be 2x).
	 *
	 * @return string Hex-encoded random string.
	 */
	public static function generate_secret( int $length = 32 ): string {
		return bin2hex( random_bytes( $length ) );
	}

	/**
	 * Get a setting value, checking constant override first, then database.
	 *
	 * @param string $setting Setting name (e.g. 'api_key').
	 *
	 * @return string
	 */
	private static function get( string $setting ): string {
		if ( self::is_overridden( $setting ) ) {
			return (string) constant( self::CONSTANT_MAP[ $setting ] );
		}

		return (string) get_option( "workos_{$setting}", '' );
	}
}
