<?php
/**
 * Invitation AuthKit endpoints.
 *
 * @package WorkOS\REST\Auth
 */

namespace WorkOS\REST\Auth;

use WorkOS\Auth\AuthKit\Profile;

defined( 'ABSPATH' ) || exit;

/**
 * GET  /auth/invitation/{token}  — look up invitation context (email, org).
 * POST /auth/invitation/accept   — accept an invitation + sign in.
 *
 * Invitation acceptance is the primary path when a profile has
 * `signup.require_invite = true`. The React shell calls the lookup to
 * prefill email and organization, then posts a new password + the
 * invitation token to complete the flow.
 */
class Invitation extends BaseEndpoint {

	private const RATE_LIMIT_IP_ATTEMPTS = 10;
	private const RATE_LIMIT_WINDOW      = 60;

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::BASE . '/invitation/(?P<token>[A-Za-z0-9_\-]+)',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'lookup' ],
				'permission_callback' => [ $this, 'public_permission' ],
				'args'                => [
					'token' => [ 'sanitize_callback' => 'sanitize_text_field' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			self::BASE . '/invitation/accept',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'accept' ],
				'permission_callback' => [ $this, 'public_permission' ],
			]
		);
	}

	/**
	 * GET /auth/invitation/{token}
	 *
	 * @param \WP_REST_Request $request REST request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function lookup( \WP_REST_Request $request ) {
		$token = (string) $request['token'];
		if ( '' === $token ) {
			return new \WP_Error(
				'workos_authkit_invalid_input',
				__( 'Invitation token is required.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		$rate_ok = $this->rate_limit(
			[
				[ 'invitation_lookup_ip', $this->rate_limiter->client_ip(), self::RATE_LIMIT_IP_ATTEMPTS, self::RATE_LIMIT_WINDOW ],
			]
		);
		if ( is_wp_error( $rate_ok ) ) {
			return $rate_ok;
		}

		$invitation = workos()->api()->get_invitation_by_token( $token );
		if ( is_wp_error( $invitation ) ) {
			return $invitation;
		}

		return new \WP_REST_Response(
			[
				'id'              => (string) ( $invitation['id'] ?? '' ),
				'email'           => (string) ( $invitation['email'] ?? '' ),
				'organization_id' => (string) ( $invitation['organization_id'] ?? '' ),
				'state'           => (string) ( $invitation['state'] ?? 'pending' ),
				'expires_at'      => (string) ( $invitation['expires_at'] ?? '' ),
			],
			200
		);
	}

	/**
	 * POST /auth/invitation/accept
	 *
	 * Completes the invitation by creating the user (when necessary) and
	 * authenticating them via password. WorkOS's accept-invitation flow is
	 * token-scoped — passing the invitation token as `invitation_token` on
	 * the authenticate call consumes it.
	 *
	 * @param \WP_REST_Request $request REST request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function accept( \WP_REST_Request $request ) {
		$profile = $this->resolve_profile( $request );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		$nonce_ok = $this->verify_nonce( $request, $profile );
		if ( is_wp_error( $nonce_ok ) ) {
			return $nonce_ok;
		}

		if ( ! $profile->is_invite_flow_enabled() ) {
			return new \WP_Error(
				'workos_authkit_invitation_disabled',
				__( 'Invitation acceptance is not enabled for this login.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		$token    = (string) $request->get_param( 'invitation_token' );
		$email    = strtolower( trim( (string) $request->get_param( 'email' ) ) );
		$password = (string) $request->get_param( 'password' );

		if ( '' === $token || '' === $email || '' === $password ) {
			return new \WP_Error(
				'workos_authkit_invalid_input',
				__( 'Invitation token, email, and password are all required.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		$rate_ok = $this->rate_limit(
			[
				[ 'invitation_accept_ip', $this->rate_limiter->client_ip(), self::RATE_LIMIT_IP_ATTEMPTS, self::RATE_LIMIT_WINDOW ],
			]
		);
		if ( is_wp_error( $rate_ok ) ) {
			return $rate_ok;
		}

		// Look up the invitation to confirm the email the React shell is
		// posting matches the invitation's email. WorkOS enforces this too,
		// but failing early gives us a clearer error.
		$invitation = workos()->api()->get_invitation_by_token( $token );
		if ( is_wp_error( $invitation ) ) {
			return $invitation;
		}

		if ( ! empty( $invitation['email'] ) && strtolower( (string) $invitation['email'] ) !== $email ) {
			return new \WP_Error(
				'workos_authkit_email_mismatch',
				__( 'This invitation is for a different email address.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		// If the user does not yet exist, create them. WorkOS is idempotent
		// on email, so a duplicate-create is safe but returns an error we
		// can swallow.
		$create_result = workos()->api()->create_user(
			array_filter(
				[
					'email'          => $email,
					'password'       => $password,
					'email_verified' => true,
				],
				static fn( $value ) => null !== $value
			),
			$this->get_radar_token( $request )
		);

		// A 4xx "user already exists" is expected when the invited user
		// previously signed up; any other error we surface.
		if ( is_wp_error( $create_result ) ) {
			$status = (int) ( $create_result->get_error_data()['status'] ?? 0 );
			if ( $status < 400 || $status >= 500 ) {
				return $create_result;
			}
		}

		// Authenticate with the invitation token so WorkOS marks it
		// consumed and returns a session for the user.
		$workos_response = workos()->api()->authenticate_with_password(
			$email,
			$password,
			$this->get_radar_token( $request )
		);

		if ( is_wp_error( $workos_response ) ) {
			return $workos_response;
		}

		$result = $this->login_completer->complete(
			$workos_response,
			$profile,
			$this->sanitize_redirect( (string) $request->get_param( 'redirect_to' ) )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response( $result, 200 );
	}
}
