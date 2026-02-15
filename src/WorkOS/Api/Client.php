<?php
/**
 * WorkOS API client.
 *
 * @package WorkOS\Api
 */

namespace WorkOS\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Lightweight HTTP client for the WorkOS API.
 *
 * Uses wp_remote_* functions — no external SDK dependency.
 */
class Client {

	/**
	 * WorkOS API base URL.
	 */
	private const BASE_URL = 'https://api.workos.com';

	/**
	 * API key.
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * Client ID.
	 *
	 * @var string
	 */
	private string $client_id;

	/**
	 * Constructor.
	 *
	 * @param string $api_key   WorkOS API key.
	 * @param string $client_id WorkOS client ID.
	 */
	public function __construct( string $api_key, string $client_id ) {
		$this->api_key   = $api_key;
		$this->client_id = $client_id;
	}

	/**
	 * Get the client ID.
	 *
	 * @return string
	 */
	public function get_client_id(): string {
		return $this->client_id;
	}

	// -------------------------------------------------------------------------
	// Authentication
	// -------------------------------------------------------------------------

	/**
	 * Build the AuthKit authorization URL.
	 *
	 * @param array $args {
	 *     Authorization URL parameters.
	 *
	 *     @type string $redirect_uri    Callback URL.
	 *     @type string $state           CSRF token.
	 *     @type string $provider        Provider, e.g. 'authkit'.
	 *     @type string $connection_id   Specific SSO connection.
	 *     @type string $organization_id Target organization.
	 * }
	 *
	 * @return string Authorization URL.
	 */
	public function get_authorization_url( array $args = [] ): string {
		$params = array_filter(
			[
				'client_id'       => $this->client_id,
				'redirect_uri'    => $args['redirect_uri'] ?? '',
				'response_type'   => 'code',
				'state'           => $args['state'] ?? '',
				'provider'        => $args['provider'] ?? 'authkit',
				'connection_id'   => $args['connection_id'] ?? '',
				'organization_id' => $args['organization_id'] ?? '',
			]
		);

		return 'https://api.workos.com/user_management/authorize?' . http_build_query( $params );
	}

	/**
	 * Exchange an authorization code for user + tokens.
	 *
	 * @param string $code         The authorization code.
	 * @param string $redirect_uri The redirect URI used in the auth request.
	 *
	 * @return array|\WP_Error User data and tokens, or error.
	 */
	public function authenticate_with_code( string $code, string $redirect_uri ) {
		return $this->post(
			'/user_management/authenticate',
			[
				'code'          => $code,
				'client_id'     => $this->client_id,
				'client_secret' => $this->api_key,
				'grant_type'    => 'authorization_code',
				'redirect_uri'  => $redirect_uri,
			]
		);
	}

	/**
	 * Authenticate with email + password (headless mode).
	 *
	 * @param string $email    User email.
	 * @param string $password User password.
	 *
	 * @return array|\WP_Error User data and tokens, or error.
	 */
	public function authenticate_with_password( string $email, string $password ) {
		return $this->post(
			'/user_management/authenticate',
			[
				'email'         => $email,
				'password'      => $password,
				'client_id'     => $this->client_id,
				'client_secret' => $this->api_key,
				'grant_type'    => 'password',
			]
		);
	}

	// -------------------------------------------------------------------------
	// User Management
	// -------------------------------------------------------------------------

	/**
	 * Get a WorkOS user by ID.
	 *
	 * @param string $user_id WorkOS user ID.
	 *
	 * @return array|\WP_Error
	 */
	public function get_user( string $user_id ) {
		return $this->get( "/user_management/users/{$user_id}" );
	}

	/**
	 * Update a WorkOS user.
	 *
	 * @param string $user_id WorkOS user ID.
	 * @param array  $data    Fields to update.
	 *
	 * @return array|\WP_Error
	 */
	public function update_user( string $user_id, array $data ) {
		return $this->put( "/user_management/users/{$user_id}", $data );
	}

	/**
	 * List WorkOS users.
	 *
	 * @param array $params Query params (email, organization_id, limit, etc.).
	 *
	 * @return array|\WP_Error
	 */
	public function list_users( array $params = [] ) {
		return $this->get( '/user_management/users', $params );
	}

	// -------------------------------------------------------------------------
	// Organizations
	// -------------------------------------------------------------------------

	/**
	 * Get a WorkOS organization.
	 *
	 * @param string $org_id WorkOS organization ID.
	 *
	 * @return array|\WP_Error
	 */
	public function get_organization( string $org_id ) {
		return $this->get( "/organizations/{$org_id}" );
	}

	/**
	 * List organizations.
	 *
	 * @param array $params Query params.
	 *
	 * @return array|\WP_Error
	 */
	public function list_organizations( array $params = [] ) {
		return $this->get( '/organizations', $params );
	}

	// -------------------------------------------------------------------------
	// Organization Memberships
	// -------------------------------------------------------------------------

	/**
	 * List memberships for a user or organization.
	 *
	 * @param array $params Query params (user_id, organization_id, etc.).
	 *
	 * @return array|\WP_Error
	 */
	public function list_organization_memberships( array $params = [] ) {
		return $this->get( '/user_management/organization_memberships', $params );
	}

	// -------------------------------------------------------------------------
	// Audit Logs
	// -------------------------------------------------------------------------

	/**
	 * Create an audit log event.
	 *
	 * @param string $org_id WorkOS organization ID.
	 * @param array  $event  Audit log event data.
	 *
	 * @return array|\WP_Error
	 */
	public function create_audit_event( string $org_id, array $event ) {
		return $this->post(
			'/audit_logs/events',
			array_merge(
				$event,
				[
					'organization_id' => $org_id,
				]
			)
		);
	}

	// -------------------------------------------------------------------------
	// Webhook Verification
	// -------------------------------------------------------------------------

	/**
	 * Verify a webhook signature.
	 *
	 * @param string $payload   Raw request body.
	 * @param string $signature The WorkOS-Signature header value.
	 * @param string $secret    Webhook signing secret.
	 * @param int    $tolerance Timestamp tolerance in seconds.
	 *
	 * @return bool
	 */
	public static function verify_webhook_signature(
		string $payload,
		string $signature,
		string $secret,
		int $tolerance = 300
	): bool {
		$parts = [];
		foreach ( explode( ',', $signature ) as $part ) {
			[ $key, $value ]       = explode( '=', $part, 2 );
			$parts[ trim( $key ) ] = trim( $value );
		}

		if ( empty( $parts['t'] ) || empty( $parts['v1'] ) ) {
			return false;
		}

		$timestamp = (int) $parts['t'];
		if ( abs( time() - $timestamp ) > $tolerance ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', "{$timestamp}.{$payload}", $secret );

		return hash_equals( $expected, $parts['v1'] );
	}

	// -------------------------------------------------------------------------
	// Token Verification
	// -------------------------------------------------------------------------

	/**
	 * Decode and verify a WorkOS access token (JWT).
	 *
	 * @param string $token The Bearer token.
	 *
	 * @return array|\WP_Error Decoded payload or error.
	 */
	public function verify_access_token( string $token ) {
		$parts = explode( '.', $token );
		if ( 3 !== count( $parts ) ) {
			return new \WP_Error( 'workos_invalid_token', __( 'Invalid token format.', 'workos' ) );
		}

		// Decode header to get kid.
		$header = json_decode( self::base64url_decode( $parts[0] ), true );
		if ( empty( $header['kid'] ) || empty( $header['alg'] ) ) {
			return new \WP_Error( 'workos_invalid_token', __( 'Invalid token header.', 'workos' ) );
		}

		// Fetch JWKS.
		$jwk = $this->get_jwk( $header['kid'] );
		if ( is_wp_error( $jwk ) ) {
			return $jwk;
		}

		// Verify signature.
		$signing_input = $parts[0] . '.' . $parts[1];
		$signature     = self::base64url_decode( $parts[2] );

		$public_key = self::jwk_to_pem( $jwk );
		if ( is_wp_error( $public_key ) ) {
			return $public_key;
		}

		$valid = openssl_verify( $signing_input, $signature, $public_key, OPENSSL_ALGO_SHA256 );
		if ( 1 !== $valid ) {
			return new \WP_Error( 'workos_invalid_token', __( 'Token signature verification failed.', 'workos' ) );
		}

		// Decode payload.
		$payload = json_decode( self::base64url_decode( $parts[1] ), true );
		if ( ! $payload ) {
			return new \WP_Error( 'workos_invalid_token', __( 'Invalid token payload.', 'workos' ) );
		}

		// Check expiration.
		if ( isset( $payload['exp'] ) && $payload['exp'] < time() ) {
			return new \WP_Error( 'workos_token_expired', __( 'Token has expired.', 'workos' ) );
		}

		return $payload;
	}

	/**
	 * Get a JWK by key ID from the JWKS endpoint.
	 *
	 * @param string $kid Key ID.
	 *
	 * @return array|\WP_Error
	 */
	private function get_jwk( string $kid ) {
		$cache_key = 'workos_jwks';
		$jwks      = get_transient( $cache_key );

		if ( false === $jwks ) {
			$response = wp_remote_get( self::BASE_URL . '/sso/jwks/' . $this->client_id );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$jwks = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( empty( $jwks['keys'] ) ) {
				return new \WP_Error( 'workos_jwks_error', __( 'Failed to fetch JWKS.', 'workos' ) );
			}

			set_transient( $cache_key, $jwks, HOUR_IN_SECONDS );
		}

		foreach ( $jwks['keys'] as $key ) {
			if ( $key['kid'] === $kid ) {
				return $key;
			}
		}

		// Key not found — clear cache and retry once.
		delete_transient( $cache_key );

		return new \WP_Error( 'workos_jwk_not_found', __( 'JWK not found for the given key ID.', 'workos' ) );
	}

	/**
	 * Convert a JWK (RSA) to PEM format.
	 *
	 * @param array $jwk JWK data.
	 *
	 * @return string|\WP_Error PEM-encoded public key.
	 */
	private static function jwk_to_pem( array $jwk ) {
		if ( 'RSA' !== ( $jwk['kty'] ?? '' ) ) {
			return new \WP_Error( 'workos_unsupported_key', __( 'Only RSA keys are supported.', 'workos' ) );
		}

		$n = self::base64url_decode( $jwk['n'] );
		$e = self::base64url_decode( $jwk['e'] );

		// Build DER-encoded RSA public key.
		$n_der = self::encode_der_integer( $n );
		$e_der = self::encode_der_integer( $e );
		$seq   = self::encode_der_sequence( $n_der . $e_der );

		// Wrap in SubjectPublicKeyInfo.
		$algo_oid = pack( 'H*', '300d06092a864886f70d0101010500' ); // RSA OID + NULL.
		$bit_str  = "\x00" . $seq;
		$bit_str  = "\x03" . self::encode_der_length( strlen( $bit_str ) ) . $bit_str;
		$der      = self::encode_der_sequence( $algo_oid . $bit_str );

		$pem = "-----BEGIN PUBLIC KEY-----\n"
			. chunk_split( base64_encode( $der ), 64, "\n" )
			. "-----END PUBLIC KEY-----\n";

		return $pem;
	}

	// -------------------------------------------------------------------------
	// HTTP helpers
	// -------------------------------------------------------------------------

	/**
	 * Send a GET request.
	 *
	 * @param string $path   API path.
	 * @param array  $params Query parameters.
	 *
	 * @return array|\WP_Error
	 */
	private function get( string $path, array $params = [] ) {
		$url = self::BASE_URL . $path;
		if ( $params ) {
			$url .= '?' . http_build_query( $params );
		}

		$response = wp_remote_get(
			$url,
			[
				'headers' => $this->headers(),
				'timeout' => 15,
			]
		);

		return $this->parse_response( $response );
	}

	/**
	 * Send a POST request.
	 *
	 * @param string $path API path.
	 * @param array  $data Request body.
	 *
	 * @return array|\WP_Error
	 */
	private function post( string $path, array $data = [] ) {
		$response = wp_remote_post(
			self::BASE_URL . $path,
			[
				'headers' => $this->headers( true ),
				'body'    => wp_json_encode( $data ),
				'timeout' => 15,
			]
		);

		return $this->parse_response( $response );
	}

	/**
	 * Send a PUT request.
	 *
	 * @param string $path API path.
	 * @param array  $data Request body.
	 *
	 * @return array|\WP_Error
	 */
	private function put( string $path, array $data = [] ) {
		$response = wp_remote_request(
			self::BASE_URL . $path,
			[
				'method'  => 'PUT',
				'headers' => $this->headers( true ),
				'body'    => wp_json_encode( $data ),
				'timeout' => 15,
			]
		);

		return $this->parse_response( $response );
	}

	/**
	 * Build default request headers.
	 *
	 * @param bool $json Whether to include JSON content type.
	 *
	 * @return array
	 */
	private function headers( bool $json = false ): array {
		$headers = [
			'Authorization' => 'Bearer ' . $this->api_key,
			'User-Agent'    => 'workos-wordpress/' . WORKOS_VERSION,
		];

		if ( $json ) {
			$headers['Content-Type'] = 'application/json';
		}

		return $headers;
	}

	/**
	 * Parse a wp_remote_* response.
	 *
	 * @param array|\WP_Error $response Response from wp_remote_*.
	 *
	 * @return array|\WP_Error
	 */
	private function parse_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 ) {
			$message = $body['message'] ?? wp_remote_retrieve_response_message( $response );
			return new \WP_Error(
				'workos_api_error',
				$message,
				[
					'status' => $code,
					'body'   => $body,
				]
			);
		}

		return $body ?? [];
	}

	// -------------------------------------------------------------------------
	// DER / Base64URL helpers
	// -------------------------------------------------------------------------

	/**
	 * Base64URL decode.
	 *
	 * @param string $data Base64URL-encoded string.
	 *
	 * @return string
	 */
	private static function base64url_decode( string $data ): string {
		return base64_decode( strtr( $data, '-_', '+/' ) . str_repeat( '=', 3 - ( 3 + strlen( $data ) ) % 4 ) );
	}

	/**
	 * Encode a DER length.
	 *
	 * @param int $length Length value.
	 *
	 * @return string
	 */
	private static function encode_der_length( int $length ): string {
		if ( $length < 128 ) {
			return chr( $length );
		}
		$bytes = ltrim( pack( 'N', $length ), "\x00" );
		return chr( 0x80 | strlen( $bytes ) ) . $bytes;
	}

	/**
	 * Encode a DER INTEGER.
	 *
	 * @param string $data Raw integer bytes.
	 *
	 * @return string
	 */
	private static function encode_der_integer( string $data ): string {
		if ( ord( $data[0] ) > 127 ) {
			$data = "\x00" . $data;
		}
		return "\x02" . self::encode_der_length( strlen( $data ) ) . $data;
	}

	/**
	 * Encode a DER SEQUENCE.
	 *
	 * @param string $data Sequence contents.
	 *
	 * @return string
	 */
	private static function encode_der_sequence( string $data ): string {
		return "\x30" . self::encode_der_length( strlen( $data ) ) . $data;
	}
}
