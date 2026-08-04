<?php
/**
 * Client IP resolution.
 *
 * @package WorkOS\Http
 */

namespace WorkOS\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Works out which address a request came from.
 *
 * `REMOTE_ADDR` is set by the webserver and cannot be forged, so it is the
 * default and the only source trusted without configuration. Behind a reverse
 * proxy or CDN it is the edge node and every visitor collapses into one
 * bucket, which is why a site in that position can name the header its edge
 * sets via the `trusted_proxy_header` option.
 *
 * Naming one is an assertion that the origin only accepts proxied traffic. If
 * the origin is reachable directly, a forwarding header is attacker-controlled
 * and trusting it is worse than not: anyone can mint a fresh identity per
 * request by changing a string.
 */
class ClientIp {

	/**
	 * Returned when no usable address resolves.
	 */
	public const UNKNOWN = '0.0.0.0';

	/**
	 * Resolve the client IP for this request.
	 *
	 * @return string IPv4 or IPv6 address; {@see self::UNKNOWN} when nothing is usable.
	 */
	public function get(): string {
		$header = (string) workos()->option( 'trusted_proxy_header', '' );

		/**
		 * Filters the request header trusted to carry the real client IP.
		 *
		 * Empty (the default) means REMOTE_ADDR only. Set this *only* when a
		 * proxy in front of the site overwrites the named header on every
		 * inbound request — otherwise it is caller-supplied and worthless.
		 *
		 * @param string $header Server variable name, e.g. `HTTP_X_FORWARDED_FOR`.
		 */
		$header = (string) apply_filters( 'workos_trusted_proxy_header', $header );

		if ( '' !== $header ) {
			$forwarded = $this->from_header( $header );

			if ( '' !== $forwarded ) {
				return $forwarded;
			}
		}

		return $this->remote_addr();
	}

	/**
	 * Resolve the client IP for a stored record.
	 *
	 * A log or audit row is read as evidence, so an unresolved caller is left
	 * blank rather than written down as {@see self::UNKNOWN} — a placeholder in
	 * that column reads like a real address later.
	 *
	 * @return string Empty when nothing usable resolves.
	 */
	public function for_record(): string {
		$ip = $this->get();

		return self::UNKNOWN === $ip ? '' : $ip;
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
	 * Read a client IP from a configured forwarding header.
	 *
	 * Each hop appends the address it received from, so the left-most entry is
	 * client-supplied and carries no weight. The scan runs right to left and
	 * skips private and reserved ranges, so an internal load balancer's own
	 * address never becomes everyone's bucket. A chain that is private end to
	 * end is an internal deployment; there the right-most valid entry stands.
	 *
	 * @param string $header Server variable name.
	 *
	 * @return string Validated IP, or empty when the header is absent or unusable.
	 */
	private function from_header( string $header ): string {
		$header = strtoupper( preg_replace( '/[^A-Za-z0-9_]/', '', $header ) );

		if ( '' === $header || ! isset( $_SERVER[ $header ] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Dynamic key; sanitized on the next line.
		$raw      = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
		$fallback = '';

		foreach ( array_reverse( explode( ',', $raw ) ) as $entry ) {
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
