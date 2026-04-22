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

	/**
	 * Get the WorkOS API base URL.
	 *
	 * @return string
	 */
	public static function get_base_url(): string {
		return self::BASE_URL;
	}

	/**
	 * Perform a safe redirect to a WorkOS URL.
	 *
	 * Temporarily allows the WorkOS API host in wp_safe_redirect(),
	 * then removes the filter after the redirect call.
	 *
	 * @param string $url WorkOS URL to redirect to.
	 */
	public static function safe_redirect( string $url ): void {
		$allowed_host = wp_parse_url( self::BASE_URL, PHP_URL_HOST );

		$filter = static function ( array $hosts ) use ( $allowed_host ): array {
			$hosts[] = $allowed_host;
			return $hosts;
		};

		add_filter( 'allowed_redirect_hosts', $filter );
		wp_safe_redirect( $url );
		remove_filter( 'allowed_redirect_hosts', $filter );
		exit;
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
	 *     @type string $screen_hint     AuthKit screen: 'sign-in' or 'sign-up'.
	 *     @type string $login_hint      Pre-fill the email field.
	 *     @type string $domain_hint     Route to a specific organization domain.
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
				'screen_hint'     => $args['screen_hint'] ?? '',
				'login_hint'      => $args['login_hint'] ?? '',
				'domain_hint'     => $args['domain_hint'] ?? '',
			]
		);

		return self::BASE_URL . '/user_management/authorize?' . http_build_query( $params );
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
	 * @param string      $email              User email.
	 * @param string      $password           User password.
	 * @param string|null $radar_action_token Optional Radar action token from the browser SDK.
	 *
	 * @return array|\WP_Error User data and tokens, or error.
	 */
	public function authenticate_with_password( string $email, string $password, ?string $radar_action_token = null ) {
		return $this->post(
			'/user_management/authenticate',
			[
				'email'         => $email,
				'password'      => $password,
				'client_id'     => $this->client_id,
				'client_secret' => $this->api_key,
				'grant_type'    => 'password',
			],
			$this->radar_headers( $radar_action_token )
		);
	}

	/**
	 * Send a magic authentication code to the given email.
	 *
	 * Enumeration-safe — WorkOS returns success even for unknown emails.
	 *
	 * @param string      $email              Email address to send the code to.
	 * @param string|null $radar_action_token Optional Radar action token.
	 *
	 * @return array|\WP_Error
	 */
	public function send_magic_auth_code( string $email, ?string $radar_action_token = null ) {
		return $this->post(
			'/user_management/magic_auth/send',
			[ 'email' => $email ],
			$this->radar_headers( $radar_action_token )
		);
	}

	/**
	 * Authenticate a user with a magic auth code.
	 *
	 * @param string      $email              Email address.
	 * @param string      $code               One-time code delivered via email.
	 * @param string|null $pending_auth_token Pending authentication token from a prior incomplete auth.
	 * @param string|null $radar_action_token Optional Radar action token.
	 *
	 * @return array|\WP_Error
	 */
	public function authenticate_with_magic_auth(
		string $email,
		string $code,
		?string $pending_auth_token = null,
		?string $radar_action_token = null
	) {
		$body = [
			'email'         => $email,
			'code'          => $code,
			'client_id'     => $this->client_id,
			'client_secret' => $this->api_key,
			'grant_type'    => 'urn:workos:oauth:grant-type:magic-auth:code',
		];

		if ( null !== $pending_auth_token && '' !== $pending_auth_token ) {
			$body['pending_authentication_token'] = $pending_auth_token;
		}

		return $this->post(
			'/user_management/authenticate',
			$body,
			$this->radar_headers( $radar_action_token )
		);
	}

	/**
	 * Complete a pending authentication with a TOTP code.
	 *
	 * @param string      $pending_auth_token Pending authentication token returned from prior auth.
	 * @param string      $authentication_challenge_id Challenge ID.
	 * @param string      $code               TOTP code.
	 * @param string|null $radar_action_token Optional Radar action token.
	 *
	 * @return array|\WP_Error
	 */
	public function authenticate_with_totp(
		string $pending_auth_token,
		string $authentication_challenge_id,
		string $code,
		?string $radar_action_token = null
	) {
		return $this->post(
			'/user_management/authenticate',
			[
				'client_id'                    => $this->client_id,
				'client_secret'                => $this->api_key,
				'grant_type'                   => 'urn:workos:oauth:grant-type:mfa-totp',
				'pending_authentication_token' => $pending_auth_token,
				'authentication_challenge_id'  => $authentication_challenge_id,
				'code'                         => $code,
			],
			$this->radar_headers( $radar_action_token )
		);
	}

	/**
	 * Exchange a refresh token for a new access token.
	 *
	 * Used by both the server-side token refresh in REST\TokenAuth and by the
	 * React shell's proactive session refresh.
	 *
	 * @param string $refresh_token Refresh token.
	 *
	 * @return array|\WP_Error New tokens and user, or error.
	 */
	public function authenticate_with_refresh_token( string $refresh_token ) {
		return $this->post(
			'/user_management/authenticate',
			[
				'client_id'     => $this->client_id,
				'client_secret' => $this->api_key,
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh_token,
			]
		);
	}

	/**
	 * Alias for {@see self::authenticate_with_refresh_token()}.
	 *
	 * @param string $refresh_token Refresh token.
	 *
	 * @return array|\WP_Error
	 */
	public function refresh_session( string $refresh_token ) {
		return $this->authenticate_with_refresh_token( $refresh_token );
	}

	/**
	 * Accept a WorkOS invitation and authenticate the invited user in one
	 * atomic call.
	 *
	 * WorkOS validates the invitation token, identifies or creates the user
	 * on the invitation's original email (the caller CANNOT substitute an
	 * arbitrary email), sets their password, and returns a session. This
	 * is the only supported path for accepting invitations — it replaces
	 * a prior create_user + authenticate_with_password pair that let the
	 * caller override the email and force `email_verified = true`.
	 *
	 * @param string      $invitation_token   Invitation token from the email link.
	 * @param string      $password           Password the new user is setting.
	 * @param string|null $radar_action_token Optional Radar action token.
	 *
	 * @return array|\WP_Error Session + user, or error.
	 */
	public function authenticate_with_invitation(
		string $invitation_token,
		string $password,
		?string $radar_action_token = null
	) {
		return $this->post(
			'/user_management/authenticate',
			[
				'client_id'        => $this->client_id,
				'client_secret'    => $this->api_key,
				'grant_type'       => 'urn:workos:oauth:grant-type:invitation_token',
				'invitation_token' => $invitation_token,
				'password'         => $password,
			],
			$this->radar_headers( $radar_action_token )
		);
	}

	// -------------------------------------------------------------------------
	// Password reset
	// -------------------------------------------------------------------------

	/**
	 * Send a password reset email.
	 *
	 * Enumeration-safe — WorkOS returns success even for unknown emails.
	 *
	 * @param string      $email              Email address.
	 * @param string      $password_reset_url URL the reset email should link to.
	 * @param string|null $radar_action_token Optional Radar action token.
	 *
	 * @return array|\WP_Error
	 */
	public function send_password_reset(
		string $email,
		string $password_reset_url,
		?string $radar_action_token = null
	) {
		return $this->post(
			'/user_management/password_reset/send',
			[
				'email'              => $email,
				'password_reset_url' => $password_reset_url,
			],
			$this->radar_headers( $radar_action_token )
		);
	}

	/**
	 * Complete a password reset with a token and new password.
	 *
	 * @param string      $token              Password reset token from the reset email.
	 * @param string      $new_password       New password.
	 * @param string|null $radar_action_token Optional Radar action token.
	 *
	 * @return array|\WP_Error
	 */
	public function reset_password(
		string $token,
		string $new_password,
		?string $radar_action_token = null
	) {
		return $this->post(
			'/user_management/password_reset/confirm',
			[
				'token'        => $token,
				'new_password' => $new_password,
			],
			$this->radar_headers( $radar_action_token )
		);
	}

	// -------------------------------------------------------------------------
	// Email verification
	// -------------------------------------------------------------------------

	/**
	 * Send a verification email to a user.
	 *
	 * @param string $user_id WorkOS user ID.
	 *
	 * @return array|\WP_Error
	 */
	public function send_verification_email( string $user_id ) {
		return $this->post( "/user_management/users/{$user_id}/email_verification/send" );
	}

	/**
	 * Verify a user's email with a code.
	 *
	 * @param string      $user_id            WorkOS user ID.
	 * @param string      $code               Verification code from the email.
	 * @param string|null $radar_action_token Optional Radar action token.
	 *
	 * @return array|\WP_Error
	 */
	public function verify_email(
		string $user_id,
		string $code,
		?string $radar_action_token = null
	) {
		return $this->post(
			"/user_management/users/{$user_id}/email_verification/confirm",
			[ 'code' => $code ],
			$this->radar_headers( $radar_action_token )
		);
	}

	// -------------------------------------------------------------------------
	// Invitations
	// -------------------------------------------------------------------------

	/**
	 * Look up an invitation by its token.
	 *
	 * @param string $token Invitation token.
	 *
	 * @return array|\WP_Error
	 */
	public function get_invitation_by_token( string $token ) {
		return $this->get( "/user_management/invitations/by_token/{$token}" );
	}

	// -------------------------------------------------------------------------
	// Authentication factors (MFA)
	// -------------------------------------------------------------------------

	/**
	 * List a user's enrolled authentication factors.
	 *
	 * @param string $user_id WorkOS user ID.
	 *
	 * @return array|\WP_Error
	 */
	public function list_auth_factors( string $user_id ) {
		return $this->get( "/user_management/users/{$user_id}/auth_factors" );
	}

	/**
	 * Enroll a TOTP authentication factor.
	 *
	 * @param string $user_id    WorkOS user ID.
	 * @param string $totp_issuer Human-readable issuer displayed by the authenticator (e.g. site name).
	 * @param string $totp_user  Human-readable account label (usually the user's email).
	 *
	 * @return array|\WP_Error Factor data including `totp.qr_code` and `totp.secret`.
	 */
	public function enroll_totp_factor( string $user_id, string $totp_issuer, string $totp_user ) {
		return $this->post(
			"/user_management/users/{$user_id}/auth_factors",
			[
				'type'        => 'totp',
				'totp_issuer' => $totp_issuer,
				'totp_user'   => $totp_user,
			]
		);
	}

	/**
	 * Enroll an SMS authentication factor.
	 *
	 * @param string $user_id      WorkOS user ID.
	 * @param string $phone_number E.164-formatted phone number.
	 *
	 * @return array|\WP_Error
	 */
	public function enroll_sms_factor( string $user_id, string $phone_number ) {
		return $this->post(
			"/user_management/users/{$user_id}/auth_factors",
			[
				'type'         => 'sms',
				'phone_number' => $phone_number,
			]
		);
	}

	/**
	 * Delete (un-enroll) an authentication factor.
	 *
	 * @param string $factor_id Factor ID.
	 *
	 * @return array|\WP_Error
	 */
	public function delete_auth_factor( string $factor_id ) {
		return $this->delete( "/user_management/auth_factors/{$factor_id}" );
	}

	/**
	 * Issue a challenge for an enrolled authentication factor.
	 *
	 * For SMS factors, this triggers the text message; for TOTP, it just
	 * returns a challenge record to verify against.
	 *
	 * @param string      $factor_id    Factor ID.
	 * @param string|null $sms_template Optional SMS template override.
	 *
	 * @return array|\WP_Error Challenge data.
	 */
	public function challenge_auth_factor( string $factor_id, ?string $sms_template = null ) {
		$body = [];
		if ( null !== $sms_template && '' !== $sms_template ) {
			$body['sms_template'] = $sms_template;
		}

		return $this->post( "/user_management/auth_factors/{$factor_id}/challenge", $body );
	}

	/**
	 * Verify an authentication challenge with a user-provided code.
	 *
	 * @param string      $challenge_id       Challenge ID.
	 * @param string      $code               Code entered by the user.
	 * @param string|null $radar_action_token Optional Radar action token.
	 *
	 * @return array|\WP_Error
	 */
	public function verify_auth_challenge(
		string $challenge_id,
		string $code,
		?string $radar_action_token = null
	) {
		return $this->post(
			"/user_management/auth_challenges/{$challenge_id}/verify",
			[ 'code' => $code ],
			$this->radar_headers( $radar_action_token )
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
	 * Create a WorkOS user.
	 *
	 * @param array       $data               User data (email required; first_name, last_name, email_verified, password optional).
	 * @param string|null $radar_action_token Optional Radar action token for signup-form submissions.
	 *
	 * @return array|\WP_Error
	 */
	public function create_user( array $data, ?string $radar_action_token = null ) {
		return $this->post(
			'/user_management/users',
			$data,
			$this->radar_headers( $radar_action_token )
		);
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

	/**
	 * Create a new organization.
	 *
	 * @param array $data Organization data (name required).
	 *
	 * @return array|\WP_Error
	 */
	public function create_organization( array $data ) {
		return $this->post( '/organizations', $data );
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

	/**
	 * Create an organization membership.
	 *
	 * @param string $user_id         WorkOS user ID.
	 * @param string $organization_id WorkOS organization ID.
	 * @param string $role_slug       Role slug (default 'member').
	 *
	 * @return array|\WP_Error
	 */
	public function create_organization_membership( string $user_id, string $organization_id, string $role_slug = 'member' ) {
		return $this->post(
			'/user_management/organization_memberships',
			[
				'user_id'         => $user_id,
				'organization_id' => $organization_id,
				'role_slug'       => $role_slug,
			]
		);
	}

	/**
	 * Update an organization membership (e.g. change role).
	 *
	 * @param string $membership_id WorkOS membership ID.
	 * @param array  $data          Fields to update (e.g. role_slug).
	 *
	 * @return array|\WP_Error
	 */
	public function update_organization_membership( string $membership_id, array $data ) {
		return $this->put( "/user_management/organization_memberships/{$membership_id}", $data );
	}

	// -------------------------------------------------------------------------
	// Organization Roles
	// -------------------------------------------------------------------------

	/**
	 * List roles for an organization.
	 *
	 * @param string $organization_id WorkOS organization ID.
	 *
	 * @return array|\WP_Error
	 */
	public function list_organization_roles( string $organization_id ) {
		return $this->get( "/organizations/{$organization_id}/roles" );
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
	// Events
	// -------------------------------------------------------------------------

	/**
	 * List events.
	 *
	 * @param array $params Query params (events[], organization_id, after, range_start, limit).
	 *
	 * @return array|\WP_Error
	 */
	public function list_events( array $params = [] ) {
		return $this->get( '/events', $params );
	}

	// -------------------------------------------------------------------------
	// Sessions
	// -------------------------------------------------------------------------

	/**
	 * Revoke a WorkOS session server-side.
	 *
	 * @param string $session_id WorkOS session ID.
	 *
	 * @return array|\WP_Error
	 */
	public function revoke_session( string $session_id ) {
		return $this->post( "/user_management/sessions/{$session_id}/revoke" );
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
			return new \WP_Error( 'workos_invalid_token', __( 'Invalid token format.', 'integration-workos' ) );
		}

		// Decode header to get kid.
		$header = json_decode( self::base64url_decode( $parts[0] ), true );
		if ( empty( $header['kid'] ) || empty( $header['alg'] ) ) {
			return new \WP_Error( 'workos_invalid_token', __( 'Invalid token header.', 'integration-workos' ) );
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
			return new \WP_Error( 'workos_invalid_token', __( 'Token signature verification failed.', 'integration-workos' ) );
		}

		// Decode payload.
		$payload = json_decode( self::base64url_decode( $parts[1] ), true );
		if ( ! $payload ) {
			return new \WP_Error( 'workos_invalid_token', __( 'Invalid token payload.', 'integration-workos' ) );
		}

		// Check expiration.
		if ( isset( $payload['exp'] ) && $payload['exp'] < time() ) {
			return new \WP_Error( 'workos_token_expired', __( 'Token has expired.', 'integration-workos' ) );
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
				return new \WP_Error( 'workos_jwks_error', __( 'Failed to fetch JWKS.', 'integration-workos' ) );
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

		return new \WP_Error( 'workos_jwk_not_found', __( 'JWK not found for the given key ID.', 'integration-workos' ) );
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
			return new \WP_Error( 'workos_unsupported_key', __( 'Only RSA keys are supported.', 'integration-workos' ) );
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
	 * @param string $path          API path.
	 * @param array  $params        Query parameters.
	 * @param array  $extra_headers Additional headers to merge in.
	 *
	 * @return array|\WP_Error
	 */
	private function get( string $path, array $params = [], array $extra_headers = [] ) {
		$url = self::BASE_URL . $path;
		if ( $params ) {
			$url .= '?' . http_build_query( $params );
		}

		$response = wp_remote_get(
			$url,
			[
				'headers' => $this->headers( false, $extra_headers ),
				'timeout' => 15,
			]
		);

		return $this->parse_response( $response );
	}

	/**
	 * Send a POST request.
	 *
	 * @param string $path          API path.
	 * @param array  $data          Request body.
	 * @param array  $extra_headers Additional headers to merge in.
	 *
	 * @return array|\WP_Error
	 */
	private function post( string $path, array $data = [], array $extra_headers = [] ) {
		$response = wp_remote_post(
			self::BASE_URL . $path,
			[
				'headers' => $this->headers( true, $extra_headers ),
				'body'    => wp_json_encode( $data ),
				'timeout' => 15,
			]
		);

		return $this->parse_response( $response );
	}

	/**
	 * Send a PUT request.
	 *
	 * @param string $path          API path.
	 * @param array  $data          Request body.
	 * @param array  $extra_headers Additional headers to merge in.
	 *
	 * @return array|\WP_Error
	 */
	private function put( string $path, array $data = [], array $extra_headers = [] ) {
		$response = wp_remote_request(
			self::BASE_URL . $path,
			[
				'method'  => 'PUT',
				'headers' => $this->headers( true, $extra_headers ),
				'body'    => wp_json_encode( $data ),
				'timeout' => 15,
			]
		);

		return $this->parse_response( $response );
	}

	/**
	 * Send a DELETE request.
	 *
	 * @param string $path          API path.
	 * @param array  $extra_headers Additional headers to merge in.
	 *
	 * @return array|\WP_Error
	 */
	private function delete( string $path, array $extra_headers = [] ) {
		$response = wp_remote_request(
			self::BASE_URL . $path,
			[
				'method'  => 'DELETE',
				'headers' => $this->headers( false, $extra_headers ),
				'timeout' => 15,
			]
		);

		return $this->parse_response( $response );
	}

	/**
	 * Build default request headers.
	 *
	 * @param bool  $json          Whether to include JSON content type.
	 * @param array $extra_headers Additional headers to merge in. Extra headers override defaults.
	 *
	 * @return array
	 */
	private function headers( bool $json = false, array $extra_headers = [] ): array {
		$headers = [
			'Authorization' => 'Bearer ' . $this->api_key,
			'User-Agent'    => 'workos-wordpress/' . WORKOS_VERSION,
		];

		if ( $json ) {
			$headers['Content-Type'] = 'application/json';
		}

		foreach ( $extra_headers as $name => $value ) {
			if ( '' === (string) $value ) {
				continue;
			}
			$headers[ $name ] = $value;
		}

		return $headers;
	}

	/**
	 * Build the `x-workos-radar-action-token` header array from a token.
	 *
	 * Returns an empty array when the token is empty so callers can always
	 * pass the result as the `extra_headers` argument.
	 *
	 * @param string|null $radar_action_token Radar action token from the browser SDK.
	 *
	 * @return array
	 */
	private function radar_headers( ?string $radar_action_token ): array {
		if ( null === $radar_action_token || '' === $radar_action_token ) {
			return [];
		}

		return [ 'x-workos-radar-action-token' => $radar_action_token ];
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
