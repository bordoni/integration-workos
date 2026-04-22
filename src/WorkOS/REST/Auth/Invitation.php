<?php
/**
 * Invitation AuthKit endpoints.
 *
 * @package WorkOS\REST\Auth
 */

namespace WorkOS\REST\Auth;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

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
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function lookup( WP_REST_Request $request ) {
		$token = (string) $request['token'];
		if ( '' === $token ) {
			return new WP_Error(
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

		return new WP_REST_Response(
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
	 * Hands the invitation token + new password to WorkOS, which:
	 *   - Validates the token (rejects consumed, expired, or unknown tokens)
	 *   - Creates or identifies the invited user on the invitation's
	 *     authoritative email (the caller cannot substitute an arbitrary email)
	 *   - Sets the password and marks the email verified per WorkOS policy
	 *   - Returns a session
	 *
	 * Single atomic call — we intentionally do NOT call create_user or
	 * authenticate_with_password from the public endpoint. Doing so
	 * previously let an anonymous caller force `email_verified=true` on an
	 * arbitrary email and then authenticate with a chosen password, which
	 * combined with email-based auto-linking allowed account takeover of
	 * existing WP admins who happened to receive an invitation to their
	 * address.
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function accept( WP_REST_Request $request ) {
		$profile = $this->resolve_profile( $request );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		$nonce_ok = $this->verify_nonce( $request, $profile );
		if ( is_wp_error( $nonce_ok ) ) {
			return $nonce_ok;
		}

		if ( ! $profile->is_invite_flow_enabled() ) {
			return new WP_Error(
				'workos_authkit_invitation_disabled',
				__( 'Invitation acceptance is not enabled for this login.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		$token    = (string) $request->get_param( 'invitation_token' );
		$password = (string) $request->get_param( 'password' );

		if ( '' === $token || '' === $password ) {
			return new WP_Error(
				'workos_authkit_invalid_input',
				__( 'Invitation token and password are required.', 'integration-workos' ),
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

		$workos_response = workos()->api()->authenticate_with_invitation(
			$token,
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

		return new WP_REST_Response( $result, 200 );
	}
}
