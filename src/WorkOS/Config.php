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
 * Supports per-environment (production/staging) credential storage.
 */
class Config {

	/**
	 * Map of setting names to their generic PHP constant overrides.
	 */
	private const CONSTANT_MAP = [
		'api_key'         => 'WORKOS_API_KEY',
		'client_id'       => 'WORKOS_CLIENT_ID',
		'webhook_secret'  => 'WORKOS_WEBHOOK_SECRET',
		'organization_id' => 'WORKOS_ORGANIZATION_ID',
		'environment_id'  => 'WORKOS_ENVIRONMENT_ID',
	];

	/**
	 * Get the active environment.
	 *
	 * @return string 'production' or 'staging'.
	 */
	public static function get_active_environment(): string {
		if ( defined( 'WORKOS_ENVIRONMENT' ) && in_array( constant( 'WORKOS_ENVIRONMENT' ), [ 'production', 'staging' ], true ) ) {
			return constant( 'WORKOS_ENVIRONMENT' );
		}

		$env = get_option( 'workos_active_environment', 'staging' );

		return in_array( $env, [ 'production', 'staging' ], true ) ? $env : 'staging';
	}

	/**
	 * Set the active environment.
	 *
	 * @param string $env 'production' or 'staging'.
	 */
	public static function set_active_environment( string $env ): void {
		if ( ! in_array( $env, [ 'production', 'staging' ], true ) ) {
			return;
		}

		update_option( 'workos_active_environment', $env );
	}

	/**
	 * Check if the environment is locked via constant.
	 *
	 * @return bool
	 */
	public static function is_environment_overridden(): bool {
		return defined( 'WORKOS_ENVIRONMENT' );
	}

	/**
	 * Get the available environments.
	 *
	 * @return array<string, string>
	 */
	public static function get_environments(): array {
		return [
			'production' => 'Production',
			'staging'    => 'Staging',
		];
	}

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
	 * Get the WorkOS Organization ID.
	 *
	 * @return string
	 */
	public static function get_organization_id(): string {
		return self::get( 'organization_id' );
	}

	/**
	 * Get the WorkOS Environment ID.
	 *
	 * @return string
	 */
	public static function get_environment_id(): string {
		return self::get( 'environment_id' );
	}

	/**
	 * Check if a setting is overridden by a constant.
	 *
	 * @param string $setting Setting name (e.g. 'api_key').
	 *
	 * @return bool
	 */
	public static function is_overridden( string $setting ): bool {
		$env       = self::get_active_environment();
		$env_const = 'WORKOS_' . strtoupper( $env ) . '_' . strtoupper( $setting );

		if ( defined( $env_const ) && '' !== constant( $env_const ) ) {
			return true;
		}

		$generic_const = self::CONSTANT_MAP[ $setting ] ?? '';

		return $generic_const && defined( $generic_const ) && '' !== constant( $generic_const );
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
	 * Get a site key for use in WorkOS metadata.
	 *
	 * Returns the site URL without protocol, e.g. "example.com" or "sub.example.com/path".
	 *
	 * @return string
	 */
	public static function get_site_key(): string {
		return preg_replace( '#^https?://#', '', untrailingslashit( home_url() ) );
	}

	/**
	 * Get a setting value with environment-aware precedence:
	 *
	 * 1. Env-specific constant: WORKOS_{ENV}_{SETTING}
	 * 2. Generic constant: WORKOS_{SETTING}
	 * 3. Database option: workos_{env}_{setting}
	 *
	 * @param string $setting Setting name (e.g. 'api_key').
	 *
	 * @return string
	 */
	private static function get( string $setting ): string {
		$env = self::get_active_environment();

		// 1. Env-specific constant: WORKOS_{ENV}_{SETTING}.
		$env_const = 'WORKOS_' . strtoupper( $env ) . '_' . strtoupper( $setting );
		if ( defined( $env_const ) && '' !== constant( $env_const ) ) {
			return (string) constant( $env_const );
		}

		// 2. Generic constant: WORKOS_{SETTING}.
		$generic_const = self::CONSTANT_MAP[ $setting ] ?? '';
		if ( $generic_const && defined( $generic_const ) && '' !== constant( $generic_const ) ) {
			return (string) constant( $generic_const );
		}

		// 3. Database: serialized array option.
		return (string) self::get_env_options( $env )->get( $setting, '' );
	}

	/**
	 * Get the Options instance for a given environment.
	 *
	 * @param string $env 'production' or 'staging'.
	 *
	 * @return Options\Options
	 */
	private static function get_env_options( string $env ): Options\Options {
		$class = 'staging' === $env ? Options\Staging::class : Options\Production::class;
		return App::container()->get( $class );
	}
}
