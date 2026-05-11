<?php
/**
 * Centralized configuration with wp-config.php constant overrides.
 *
 * @package WorkOS
 */

namespace WorkOS;

use WorkOS\Options\Global_Options;

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
	 * Map of boolean setting names to their generic PHP constant overrides.
	 * Env-specific form: WORKOS_{ENV}_{SETTING} (e.g. WORKOS_STAGING_ALLOW_PASSWORD_FALLBACK).
	 */
	private const BOOL_CONSTANT_MAP = [
		'allow_password_fallback'                 => 'WORKOS_ALLOW_PASSWORD_FALLBACK',
		'wp_password_fallback_email_confirmation' => 'WORKOS_WP_PASSWORD_FALLBACK_EMAIL_CONFIRMATION',
	];

	/**
	 * Map of array setting names to their generic PHP constant overrides.
	 * Env-specific form: WORKOS_{ENV}_{SETTING} (e.g. WORKOS_STAGING_REDIRECT_URLS).
	 */
	private const ARRAY_CONSTANT_MAP = [
		'redirect_urls' => 'WORKOS_REDIRECT_URLS',
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

		$env = get_option( 'workos_active_environment', '' );

		// Back-compat: fall back to the legacy location for installs that
		// predate the migration in Schema::maybe_upgrade().
		if ( ! in_array( $env, [ 'production', 'staging' ], true ) ) {
			$env = App::container()->get( Global_Options::class )->get( 'active_environment', '' );
		}

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
	 * Options key used to store the last-seen constants hash.
	 */
	private const CONSTANTS_HASH_OPTION = 'workos_constants_hash';

	/**
	 * Seed the database from any defined wp-config.php constants.
	 *
	 * Skipped entirely when the hash of all constant values matches what was
	 * stored on the previous run — so the steady-state cost is one autoloaded
	 * get_option() call per request with no further DB activity.
	 *
	 * @return void
	 */
	public static function sync_constants_to_db(): void {
		$hash = self::constants_hash();

		if ( get_option( self::CONSTANTS_HASH_OPTION ) === $hash ) {
			return;
		}

		$env       = self::get_active_environment();
		$class     = 'staging' === $env ? Options\Staging::class : Options\Production::class;
		$env_upper = strtoupper( $env );

		$options = App::container()->get( $class );

		foreach ( self::CONSTANT_MAP as $key => $generic ) {
			$env_const = 'WORKOS_' . $env_upper . '_' . strtoupper( $key );

			if ( defined( $env_const ) && '' !== constant( $env_const ) ) {
				$value = (string) constant( $env_const );
			} elseif ( defined( $generic ) && '' !== constant( $generic ) ) {
				$value = (string) constant( $generic );
			} else {
				continue;
			}

			if ( $value !== (string) $options->get( $key ) ) {
				$options->set( $key, $value );
			}
		}

		$stored_all = $options->all();
		foreach ( self::BOOL_CONSTANT_MAP as $key => $generic ) {
			$env_const = 'WORKOS_' . $env_upper . '_' . strtoupper( $key );

			if ( defined( $env_const ) ) {
				$value = (bool) constant( $env_const );
			} elseif ( defined( $generic ) ) {
				$value = (bool) constant( $generic );
			} else {
				continue;
			}

			$stored = array_key_exists( $key, $stored_all ) ? (bool) $stored_all[ $key ] : null;
			if ( $value !== $stored ) {
				$options->set( $key, $value );
			}
		}

		foreach ( self::ARRAY_CONSTANT_MAP as $key => $generic ) {
			$env_const = 'WORKOS_' . $env_upper . '_' . strtoupper( $key );

			if ( defined( $env_const ) && is_array( constant( $env_const ) ) ) {
				$value = constant( $env_const );
			} elseif ( defined( $generic ) && is_array( constant( $generic ) ) ) {
				$value = constant( $generic );
			} else {
				continue;
			}

			if ( $value !== $options->get( $key ) ) {
				$options->set( $key, $value );
			}
		}

		update_option( self::CONSTANTS_HASH_OPTION, $hash );

		// Clear the in-memory cache so any same-request reads (e.g. settings
		// page render after save) pick up the freshly written DB values.
		$options->reset();
	}

	/**
	 * Build an md5 hash of the active environment's WORKOS constant values.
	 *
	 * @return string
	 */
	private static function constants_hash(): string {
		$env       = self::get_active_environment();
		$env_upper = strtoupper( $env );
		$values    = [];

		foreach ( self::CONSTANT_MAP as $key => $generic ) {
			$env_const = 'WORKOS_' . $env_upper . '_' . strtoupper( $key );
			$values[]  = defined( $env_const ) ? (string) constant( $env_const ) : '';
			$values[]  = defined( $generic ) ? (string) constant( $generic ) : '';
		}

		foreach ( self::BOOL_CONSTANT_MAP as $key => $generic ) {
			$env_const = 'WORKOS_' . $env_upper . '_' . strtoupper( $key );
			$values[]  = defined( $env_const ) ? ( constant( $env_const ) ? '1' : '0' ) : '';
			$values[]  = defined( $generic ) ? ( constant( $generic ) ? '1' : '0' ) : '';
		}

		foreach ( self::ARRAY_CONSTANT_MAP as $key => $generic ) {
			$env_const = 'WORKOS_' . $env_upper . '_' . strtoupper( $key );
			$values[]  = defined( $env_const ) && is_array( constant( $env_const ) ) ? md5( wp_json_encode( constant( $env_const ) ) ) : '';
			$values[]  = defined( $generic ) && is_array( constant( $generic ) ) ? md5( wp_json_encode( constant( $generic ) ) ) : '';
		}

		return md5( implode( '|', $values ) );
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
