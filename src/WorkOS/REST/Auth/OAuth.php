<?php
/**
 * OAuth social-login AuthKit endpoints.
 *
 * @package WorkOS\REST\Auth
 */

namespace WorkOS\REST\Auth;

use WorkOS\Auth\AuthKit\Profile;
use WorkOS\Auth\Login;

defined( 'ABSPATH' ) || exit;

/**
 * GET /auth/oauth/authorize-url
 *
 * Builds a WorkOS /user_management/authorize URL for a specific social
 * provider (Google, Microsoft, GitHub, Apple). The React shell hits this
 * endpoint when the user clicks a provider button, receives the URL, and
 * does a `window.location` redirect. WorkOS hands the user back off to
 * the existing `/workos/callback` handler which exchanges the code.
 *
 * We intentionally do *not* proxy the OAuth code exchange through a REST
 * endpoint — the callback path is a full-page redirect regardless, so
 * reusing the existing handler keeps the flow identical across profiles.
 */
class OAuth extends BaseEndpoint {

	private const RATE_LIMIT_IP_ATTEMPTS = 20;
	private const RATE_LIMIT_WINDOW      = 60;

	/**
	 * Mapping of profile method constants to WorkOS provider identifiers.
	 */
	private const METHOD_TO_PROVIDER = [
		Profile::METHOD_OAUTH_GOOGLE    => 'GoogleOAuth',
		Profile::METHOD_OAUTH_MICROSOFT => 'MicrosoftOAuth',
		Profile::METHOD_OAUTH_GITHUB    => 'GitHubOAuth',
		Profile::METHOD_OAUTH_APPLE     => 'AppleOAuth',
	];

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::BASE . '/oauth/authorize-url',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'authorize_url' ],
				'permission_callback' => [ $this, 'public_permission' ],
			]
		);
	}

	/**
	 * GET /auth/oauth/authorize-url?profile=slug&provider=oauth_google
	 *
	 * @param \WP_REST_Request $request REST request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function authorize_url( \WP_REST_Request $request ) {
		$profile = $this->resolve_profile( $request );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		$method_param = sanitize_text_field( (string) $request->get_param( 'provider' ) );
		if ( '' === $method_param ) {
			return new \WP_Error(
				'workos_authkit_invalid_input',
				__( 'Provider is required.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! isset( self::METHOD_TO_PROVIDER[ $method_param ] ) ) {
			return new \WP_Error(
				'workos_authkit_unknown_provider',
				__( 'Unknown OAuth provider.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! $profile->has_method( $method_param ) ) {
			return new \WP_Error(
				'workos_authkit_method_disabled',
				__( 'This social provider is not enabled for this login.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		$rate_ok = $this->rate_limit(
			[
				[ 'oauth_url_ip', $this->rate_limiter->client_ip(), self::RATE_LIMIT_IP_ATTEMPTS, self::RATE_LIMIT_WINDOW ],
			]
		);
		if ( is_wp_error( $rate_ok ) ) {
			return $rate_ok;
		}

		$redirect_to = $this->sanitize_redirect( (string) $request->get_param( 'redirect_to' ) );
		if ( '' === $redirect_to ) {
			$redirect_to = admin_url();
		}

		// State threads a nonce + the final redirect + the profile slug so
		// the existing callback handler can restore context. We use a
		// single-use wp_create_nonce for the nonce portion; the profile
		// slug is bound into the state so an attacker can't swap profiles
		// between request and callback.
		$state = implode(
			'|',
			[
				wp_create_nonce( 'workos_auth' ),
				$redirect_to,
				$profile->get_slug(),
			]
		);

		$args = [
			'redirect_uri' => Login::get_callback_url(),
			'state'        => $state,
			'provider'     => self::METHOD_TO_PROVIDER[ $method_param ],
		];

		$organization_id = $profile->get_organization_id();
		if ( '' !== $organization_id ) {
			$args['organization_id'] = $organization_id;
		}

		$url = workos()->api()->get_authorization_url( $args );

		return new \WP_REST_Response(
			[ 'authorize_url' => (string) $url ],
			200
		);
	}
}
