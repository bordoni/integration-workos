<?php
/**
 * Authentication integration.
 *
 * @package WorkOS\Auth
 */

namespace WorkOS\Auth;

use WorkOS\Auth\AuthKit\LoginCompleter;
use WorkOS\Auth\AuthKit\Profile;
use WorkOS\Auth\AuthKit\ProfileRepository;
use WorkOS\Vendor\StellarWP\SuperGlobals\SuperGlobals;

defined( 'ABSPATH' ) || exit;

/**
 * Handles both AuthKit redirect and headless API authentication flows.
 */
class Login {

	/**
	 * Constructor — registers hooks.
	 */
	public function __construct() {
		// AuthKit redirect mode: intercept wp-login.php.
		add_action( 'login_init', [ $this, 'maybe_redirect_to_authkit' ] );

		// Headless mode: authenticate filter.
		add_filter( 'authenticate', [ $this, 'authenticate_headless' ], 10, 3 );

		// Register callback rewrite.
		add_action( 'init', [ self::class, 'register_rewrite' ] );
		add_action( 'template_redirect', [ $this, 'handle_callback' ] );

		// Optionally remove WP default auth handlers.
		add_action( 'init', [ $this, 'maybe_disable_password_fallback' ] );

		// Control session duration.
		add_filter( 'auth_cookie_expiration', [ $this, 'session_expiration' ], 10, 3 );

		// Logout: clear WorkOS session.
		add_action( 'wp_logout', [ $this, 'handle_logout' ] );
	}

	/**
	 * Redirect to AuthKit if in redirect mode.
	 *
	 * Runs on `login_init`. Only intercepts when:
	 * - Plugin is enabled
	 * - Login mode is "redirect"
	 * - This is not the callback return
	 * - This is not a logout action
	 * - Fallback mode is not explicitly requested via ?fallback=1
	 */
	public function maybe_redirect_to_authkit(): void {
		if ( ! workos()->is_enabled() ) {
			return;
		}

		// Login bypass: allow ?workos=0 to skip WorkOS redirect.
		if ( LoginBypass::is_active() ) {
			return;
		}

		if ( workos()->option( 'login_mode', 'redirect' ) !== 'redirect' ) {
			return;
		}

		// Allow specific wp-login.php actions to pass through.
		$action = SuperGlobals::get_var( 'action' ) ?? 'login';
		$bypass = [ 'logout', 'lostpassword', 'rp', 'resetpass', 'register', 'confirmaction', 'postpass' ];
		if ( in_array( $action, $bypass, true ) ) {
			return;
		}

		// Allow the "You have been logged out" screen to display.
		if ( ! empty( SuperGlobals::get_get_var( 'loggedout' ) ) ) {
			return;
		}

		// Allow fallback login with ?fallback=1.
		if ( ! empty( SuperGlobals::get_get_var( 'fallback' ) ) && workos()->option( 'allow_password_fallback', true ) ) {
			return;
		}

		// Build state with redirect_to.
		$redirect_to = sanitize_url( SuperGlobals::get_var( 'redirect_to' ) ?? admin_url() );
		$state       = wp_create_nonce( 'workos_auth' ) . '|' . $redirect_to;

		$args = [
			'redirect_uri' => self::get_callback_url(),
			'state'        => $state,
			'provider'     => 'authkit',
			'screen_hint'  => 'sign-in',
		];

		$org_id = \WorkOS\Config::get_organization_id();
		if ( $org_id ) {
			$args['organization_id'] = $org_id;
		}

		$auth_url = workos()->api()->get_authorization_url( $args );

		\WorkOS\Api\Client::safe_redirect( $auth_url );
	}

	/**
	 * Headless mode: authenticate via WorkOS API using email/password.
	 *
	 * Runs at priority 10, before WP's default handlers at 20.
	 *
	 * @param \WP_User|\WP_Error|null $user     Current user or error.
	 * @param string                  $username  Username or email.
	 * @param string                  $password  Password.
	 *
	 * @return \WP_User|\WP_Error|null
	 */
	public function authenticate_headless( $user, $username, $password ) {
		// Skip if already authenticated, or not in headless mode.
		if ( $user instanceof \WP_User ) {
			return $user;
		}

		if ( ! workos()->is_enabled() ) {
			return $user;
		}

		if ( workos()->option( 'login_mode', 'redirect' ) !== 'headless' ) {
			return $user;
		}

		if ( empty( $username ) || empty( $password ) ) {
			return $user;
		}

		// Determine email.
		$email = is_email( $username ) ? $username : '';
		if ( ! $email ) {
			$wp_user = get_user_by( 'login', $username );
			$email   = $wp_user ? $wp_user->user_email : '';
		}

		if ( ! $email ) {
			return $user; // Let WP handle the error.
		}

		$result = workos()->api()->authenticate_with_password( $email, $password );

		if ( is_wp_error( $result ) ) {
			// Don't block fallback — just return $user to let WP try next.
			return $user;
		}

		$workos_user = $result['user'] ?? $result;
		$wp_user     = \WorkOS\Sync\UserSync::find_or_create_wp_user( $workos_user );

		if ( is_wp_error( $wp_user ) ) {
			return $wp_user;
		}

		// Store tokens.
		self::store_tokens( $wp_user->ID, $result );

		// Entitlement gate: deny login if user lacks active org membership.
		\WorkOS\Organization\EntitlementGate::check( $wp_user->ID, $result );

		/**
		 * Fires after a user is authenticated via WorkOS.
		 *
		 * @param int   $wp_user_id  WP user ID.
		 * @param array $workos_data Full WorkOS auth response.
		 */
		do_action( 'workos_user_authenticated', $wp_user->ID, $result );

		return $wp_user;
	}

	/**
	 * Register the /workos/callback rewrite rule.
	 */
	public static function register_rewrite(): void {
		add_rewrite_rule(
			'^workos/callback/?$',
			'index.php?workos_callback=1',
			'top'
		);
		add_rewrite_tag( '%workos_callback%', '1' );
	}

	/**
	 * Handle the OAuth callback at /workos/callback.
	 */
	public function handle_callback(): void {
		if ( ! get_query_var( 'workos_callback' ) ) {
			return;
		}

		$code = SuperGlobals::get_get_var( 'code' ) ?? '';
		if ( empty( $code ) ) {
			// Check for error.
			$error      = SuperGlobals::get_get_var( 'error' ) ?? 'missing_code';
			$error_desc = SuperGlobals::get_get_var( 'error_description' ) ?? '';
			wp_die(
				esc_html(
					sprintf(
					/* translators: 1: error code, 2: error description */
						__( 'WorkOS authentication error: %1$s — %2$s', 'integration-workos' ),
						$error,
						$error_desc
					)
				),
				esc_html__( 'Authentication Error', 'integration-workos' ),
				[ 'response' => 403 ]
			);
		}

		// Parse state. Two formats are accepted:
		// 1. Legacy AuthKit-redirect mode: `nonce|redirect_to`, nonce minted
		// with the generic `workos_auth` action by `maybe_redirect_to_authkit`.
		// 2. Custom AuthKit OAuth: `nonce|redirect_to|profile_slug`, nonce
		// minted profile-scoped by `WorkOS\Auth\AuthKit\Nonce::mint()`.
		$state        = SuperGlobals::get_get_var( 'state' ) ?? '';
		$state_parts  = explode( '|', $state, 3 );
		$nonce        = $state_parts[0] ?? '';
		$redirect_to  = $state_parts[1] ?? admin_url();
		$profile_slug = isset( $state_parts[2] ) ? sanitize_title( $state_parts[2] ) : '';

		$nonce_ok = false;
		if ( '' !== $profile_slug ) {
			$nonce_ok = ( new \WorkOS\Auth\AuthKit\Nonce() )->verify( $nonce, $profile_slug );
		}
		if ( ! $nonce_ok ) {
			// Back-compat: legacy AuthKit-redirect callbacks carry the generic nonce.
			$nonce_ok = (bool) wp_verify_nonce( $nonce, 'workos_auth' );
		}

		if ( ! $nonce_ok ) {
			wp_die(
				esc_html__( 'Security check failed. Please try logging in again.', 'integration-workos' ),
				esc_html__( 'Authentication Error', 'integration-workos' ),
				[ 'response' => 403 ]
			);
		}

		// Exchange code for user. Route the response (success or WP_Error)
		// through LoginCompleter so the AuthKit-style flows and this OAuth
		// callback share the same `organization_selection_required`
		// recovery, MFA gating, and post-login bookkeeping.
		$result  = workos()->api()->authenticate_with_code( $code, self::get_callback_url() );
		$profile = $this->resolve_callback_profile( $profile_slug );
		$outcome = workos()->getContainer()->get( LoginCompleter::class )->complete( $result, $profile, $redirect_to );

		if ( is_wp_error( $outcome ) ) {
			wp_die(
				esc_html( $outcome->get_error_message() ),
				esc_html__( 'Authentication Error', 'integration-workos' ),
				[ 'response' => 403 ]
			);
		}

		// Step-up auth shouldn't normally surface here — the hosted UI runs
		// MFA before issuing the code — but if WorkOS does return it,
		// refuse rather than silently completing the login.
		if ( ! empty( $outcome['mfa_required'] ) ) {
			wp_die(
				esc_html__( 'This login requires multi-factor authentication, which is not supported on the hosted callback. Try signing in from the login page.', 'integration-workos' ),
				esc_html__( 'Authentication Error', 'integration-workos' ),
				[ 'response' => 403 ]
			);
		}

		wp_safe_redirect( (string) ( $outcome['redirect_to'] ?? admin_url() ) );
		exit;
	}

	/**
	 * Resolve a Profile for the OAuth callback.
	 *
	 * The state carries the originating profile slug for Custom AuthKit
	 * flows. Legacy AuthKit-redirect callbacks have no slug — fall through
	 * to the default profile so LoginCompleter still has profile-scoped
	 * settings (org pin, MFA enforcement, post-login redirect) to evaluate.
	 *
	 * @param string $slug Profile slug from state, may be empty.
	 *
	 * @return Profile
	 */
	private function resolve_callback_profile( string $slug ): Profile {
		$repository = workos()->getContainer()->get( ProfileRepository::class );
		$profile    = '' !== $slug ? $repository->find_by_slug( $slug ) : null;

		if ( ! $profile ) {
			$profile = $repository->find_by_slug( Profile::DEFAULT_SLUG );
		}

		if ( ! $profile ) {
			// Last-resort hydration so we never call into LoginCompleter
			// with null. ProfileRepository auto-creates the default in
			// normal installs; this guards against an admin that deleted it.
			$repository->ensure_default();
			$profile = $repository->find_by_slug( Profile::DEFAULT_SLUG );
		}

		return $profile;
	}

	/**
	 * Optionally disable WP's default password authentication.
	 */
	public function maybe_disable_password_fallback(): void {
		if ( ! workos()->is_enabled() ) {
			return;
		}

		if ( workos()->option( 'allow_password_fallback', true ) ) {
			return;
		}

		remove_filter( 'authenticate', 'wp_authenticate_username_password', 20 );
		remove_filter( 'authenticate', 'wp_authenticate_email_password', 20 );
	}

	/**
	 * Align WP session duration with WorkOS tokens.
	 *
	 * @param int  $expiration Current expiration.
	 * @param int  $user_id    User ID.
	 * @param bool $remember   Remember flag.
	 *
	 * @return int
	 */
	public function session_expiration( int $expiration, int $user_id, bool $remember ): int {
		if ( ! get_user_meta( $user_id, '_workos_user_id', true ) ) {
			return $expiration;
		}

		// 24 hours for WorkOS users; 14 days if "remember me".
		return $remember ? 14 * DAY_IN_SECONDS : DAY_IN_SECONDS;
	}

	/**
	 * Handle logout — clear local tokens and revoke WorkOS session.
	 *
	 * @param int $user_id User ID passed by the wp_logout action.
	 */
	public function handle_logout( int $user_id ): void {
		$access_token = get_user_meta( $user_id, '_workos_access_token', true );

		// Clean up stored tokens.
		delete_user_meta( $user_id, '_workos_access_token' );
		delete_user_meta( $user_id, '_workos_refresh_token' );
		delete_user_meta( $user_id, '_workos_session_id' );

		// Revoke the WorkOS session server-side so WordPress retains full
		// control of the logout redirect (no browser detour to WorkOS).
		$session_id = $access_token ? self::extract_session_id( $access_token ) : '';
		if ( $session_id ) {
			workos()->api()->revoke_session( $session_id );
		}
	}

	/**
	 * Get the callback URL.
	 *
	 * @return string
	 */
	public static function get_callback_url(): string {
		return home_url( '/workos/callback' );
	}

	/**
	 * Extract the session ID (sid claim) from a WorkOS access token JWT.
	 *
	 * Decodes the payload without signature verification — we only need
	 * the sid claim for logout, not cryptographic validation.
	 *
	 * @param string $token JWT access token.
	 *
	 * @return string Session ID, or empty string on failure.
	 */
	private static function extract_session_id( string $token ): string {
		$parts = explode( '.', $token );
		if ( count( $parts ) < 2 ) {
			return '';
		}

		$payload = json_decode(
			base64_decode( strtr( $parts[1], '-_', '+/' ) ),
			true
		);

		return $payload['sid'] ?? '';
	}


	/**
	 * Store WorkOS tokens in usermeta (encrypted if possible).
	 *
	 * @param int   $user_id WP user ID.
	 * @param array $result  WorkOS auth response.
	 */
	public static function store_tokens( int $user_id, array $result ): void {
		if ( ! empty( $result['access_token'] ) ) {
			update_user_meta( $user_id, '_workos_access_token', $result['access_token'] );

			// Store session ID from JWT for logout revocation.
			$session_id = self::extract_session_id( $result['access_token'] );
			if ( $session_id ) {
				update_user_meta( $user_id, '_workos_session_id', $session_id );
			}
		}
		if ( ! empty( $result['refresh_token'] ) ) {
			update_user_meta( $user_id, '_workos_refresh_token', $result['refresh_token'] );
		}

		// Store org membership info.
		if ( ! empty( $result['organization_id'] ) ) {
			update_user_meta( $user_id, '_workos_org_id', $result['organization_id'] );
		}
	}
}
