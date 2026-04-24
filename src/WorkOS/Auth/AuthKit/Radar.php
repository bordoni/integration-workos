<?php
/**
 * Radar anti-fraud integration.
 *
 * @package WorkOS\Auth\AuthKit
 */

namespace WorkOS\Auth\AuthKit;

use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Helper for WorkOS Radar, the anti-fraud/bot-detection product.
 *
 * Because we're replacing AuthKit's hosted page with our own React shell,
 * we lose AuthKit's built-in fraud checks. Radar fills that gap: the
 * browser SDK fingerprints the device and produces an *action token*; we
 * forward that token on every auth call we proxy to WorkOS; WorkOS scores
 * the risk and can deny, step up, or allow.
 *
 * This helper covers the WordPress-side concerns:
 * - extracting the action token from REST requests,
 * - deciding whether Radar is enabled based on configuration,
 * - exposing the public site key to the React shell.
 *
 * Admin setting key: `radar_site_key` (stored in the plugin's environment
 * options). When empty, Radar is disabled and the React shell ships without
 * the SDK.
 */
class Radar {

	/**
	 * Request header that carries the Radar action token.
	 */
	public const REQUEST_HEADER = 'X-WorkOS-Radar-Action-Token';

	/**
	 * Maximum accepted length for a Radar action token.
	 *
	 * Real tokens produced by the WorkOS Radar SDK are a few hundred
	 * bytes. Cap well above that (2 KB) so a hostile client can't push a
	 * multi-megabyte header value and force the plugin to forward it on
	 * every outbound WorkOS call.
	 */
	public const MAX_TOKEN_LENGTH = 2048;

	/**
	 * Plugin option key for the Radar public site key.
	 */
	public const SITE_KEY_OPTION = 'radar_site_key';

	/**
	 * Constant override for the Radar site key (parallels other WorkOS_*_KEY constants).
	 */
	public const SITE_KEY_CONSTANT = 'WORKOS_RADAR_SITE_KEY';

	/**
	 * Whether Radar is enabled.
	 *
	 * Radar is considered enabled if a site key is configured, either via
	 * constant or the plugin option.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return '' !== $this->get_site_key();
	}

	/**
	 * Get the public Radar site key.
	 *
	 * Constant beats option, matching the rest of the plugin's Config
	 * resolution order. Returns an empty string when unset.
	 *
	 * @return string
	 */
	public function get_site_key(): string {
		if ( defined( self::SITE_KEY_CONSTANT ) ) {
			$value = constant( self::SITE_KEY_CONSTANT );
			if ( is_string( $value ) && '' !== $value ) {
				return $value;
			}
		}

		$value = workos()->option( self::SITE_KEY_OPTION, '' );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Extract the Radar action token from a REST request.
	 *
	 * Returns null when absent so callers can pass it through to the API
	 * client, which already treats null as "omit the header".
	 *
	 * @param WP_REST_Request $request REST request object.
	 *
	 * @return string|null
	 */
	public function extract_from_request( WP_REST_Request $request ): ?string {
		$token = $request->get_header( self::REQUEST_HEADER );

		if ( ! is_string( $token ) ) {
			return null;
		}

		$trimmed = trim( $token );
		if ( '' === $trimmed ) {
			return null;
		}

		// Silently drop absurdly large values rather than forwarding them
		// to WorkOS. A hostile client cannot inflate every outbound auth
		// call by stuffing megabytes into the header.
		if ( strlen( $trimmed ) > self::MAX_TOKEN_LENGTH ) {
			return null;
		}

		return $trimmed;
	}
}
