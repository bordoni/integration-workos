<?php
/**
 * Client IP resolution.
 *
 * @package WorkOS\Http
 */

namespace WorkOS\Http;

use WorkOS\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Works out who a request came from, for rate limiting and logging.
 *
 * `REMOTE_ADDR` is set by the webserver and cannot be forged, but behind a
 * CDN it is the CDN's edge — every visitor collapses into one bucket. The
 * proxy's own header (`CF-Connecting-IP` on Cloudflare) has the real client
 * and is authoritative *there*, because the edge overwrites whatever the
 * client sent.
 *
 * That last part only holds if the request actually arrived through the
 * proxy. If the origin is reachable directly, a forwarded-for header is
 * attacker-controlled and trusting it is strictly worse than not: anyone
 * can mint a fresh rate-limit bucket per request just by changing a string.
 *
 * So the header is never auto-detected. An operator names it explicitly via
 * `WORKOS_CLIENT_IP_HEADER` (constant or environment variable), asserting
 * that their origin only accepts proxied traffic. Unset, which is the
 * default, means `REMOTE_ADDR` and nothing else.
 */
class ClientIp {

	/**
	 * Constant / environment variable naming the trusted header.
	 */
	public const HEADER_SETTING = 'WORKOS_CLIENT_IP_HEADER';

	/**
	 * Returned when no usable address can be determined.
	 */
	public const UNKNOWN = '0.0.0.0';

	/**
	 * Resolve the client IP for this request.
	 *
	 * @return string IPv4 or IPv6 address; {@see self::UNKNOWN} when nothing is usable.
	 */
	public function get(): string {
		$header = Config::string_setting( 'client_ip_header', '' );

		if ( '' !== $header ) {
			$forwarded = $this->from_header( $header );

			if ( '' !== $forwarded ) {
				return $forwarded;
			}
		}

		return $this->remote_addr();
	}

	/**
	 * The address the webserver saw. Unforgeable, but possibly a proxy.
	 *
	 * @return string
	 */
	public function remote_addr(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] )
			? filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ), FILTER_VALIDATE_IP )
			: false;

		return is_string( $remote ) && '' !== $remote ? $remote : self::UNKNOWN;
	}

	/**
	 * Pull a validated address out of a named request header.
	 *
	 * When the header carries a list, the *right-most public* entry wins.
	 * `X-Forwarded-For` appends on each hop, so everything to the right of
	 * the client was written by infrastructure and everything to the left is
	 * whatever the client chose to send — reading from the front hands the
	 * bucket key back to the attacker. Private and reserved entries are
	 * skipped on the way left so internal hops don't shadow the client; if
	 * the whole list is private (a local proxy in development), the
	 * right-most valid entry is still used.
	 *
	 * Returns empty rather than a fallback so the caller can decide.
	 *
	 * @param string $header Header name, e.g. `CF-Connecting-IP`.
	 *
	 * @return string Empty when absent or not an address.
	 */
	private function from_header( string $header ): string {
		$key = 'HTTP_' . strtoupper( str_replace( '-', '_', $header ) );

		if ( empty( $_SERVER[ $key ] ) ) {
			return '';
		}

		$value    = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
		$entries  = array_reverse( explode( ',', $value ) );
		$fallback = '';

		foreach ( $entries as $entry ) {
			$candidate = filter_var( trim( $entry ), FILTER_VALIDATE_IP );

			if ( ! is_string( $candidate ) ) {
				continue;
			}

			if ( '' === $fallback ) {
				$fallback = $candidate;
			}

			if ( false !== filter_var( $candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return $candidate;
			}
		}

		return $fallback;
	}
}
