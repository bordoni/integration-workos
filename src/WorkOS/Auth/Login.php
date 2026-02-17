<?php
/**
 * Authentication integration.
 *
 * @package WorkOS\Auth
 */

namespace WorkOS\Auth;

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
		add_action( 'init', [ $this, 'register_rewrite' ] );
		add_action( 'template_redirect', [ $this, 'handle_callback' ] );

		// Optionally remove WP default auth handlers.
		add_action( 'init', [ $this, 'maybe_disable_password_fallback' ] );

		// Control session duration.
		add_filter( 'auth_cookie_expiration', [ $this, 'session_expiration' ], 10, 3 );

		// Logout: clear WorkOS session.
		add_action( 'wp_logout', [ $this, 'handle_logout' ] );

		// Login redirect.
		add_filter( 'login_redirect', [ $this, 'login_redirect' ], 10, 3 );
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

		if ( workos()->option( 'login_mode', 'redirect' ) !== 'redirect' ) {
			return;
		}

		// Allow specific wp-login.php actions to pass through.
		$action = sanitize_text_field( wp_unslash( $_REQUEST['action'] ?? 'login' ) );
		$bypass = [ 'logout', 'lostpassword', 'rp', 'resetpass', 'register', 'confirmaction', 'postpass' ];
		if ( in_array( $action, $bypass, true ) ) {
			return;
		}

		// Allow fallback login with ?fallback=1.
		if ( ! empty( $_GET['fallback'] ) && workos()->option( 'allow_password_fallback', true ) ) {
			return;
		}

		// Build state with redirect_to.
		$redirect_to = sanitize_url( wp_unslash( $_REQUEST['redirect_to'] ?? admin_url() ) );
		$state       = wp_create_nonce( 'workos_auth' ) . '|' . $redirect_to;

		$args = [
			'redirect_uri' => self::get_callback_url(),
			'state'        => $state,
			'provider'     => 'authkit',
		];

		$org_id = \WorkOS\Config::get_organization_id();
		if ( $org_id ) {
			$args['organization_id'] = $org_id;
		}

		$auth_url = workos()->api()->get_authorization_url( $args );

		wp_redirect( $auth_url );
		exit;
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
	public function register_rewrite(): void {
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

		$code = sanitize_text_field( wp_unslash( $_GET['code'] ?? '' ) );
		if ( empty( $code ) ) {
			// Check for error.
			$error      = sanitize_text_field( wp_unslash( $_GET['error'] ?? 'missing_code' ) );
			$error_desc = sanitize_text_field( wp_unslash( $_GET['error_description'] ?? '' ) );
			wp_die(
				esc_html(
					sprintf(
					/* translators: 1: error code, 2: error description */
						__( 'WorkOS authentication error: %1$s — %2$s', 'workos' ),
						$error,
						$error_desc
					)
				),
				esc_html__( 'Authentication Error', 'workos' ),
				[ 'response' => 403 ]
			);
		}

		// Parse state.
		$state       = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );
		$state_parts = explode( '|', $state, 2 );
		$nonce       = $state_parts[0] ?? '';
		$redirect_to = $state_parts[1] ?? admin_url();

		if ( ! wp_verify_nonce( $nonce, 'workos_auth' ) ) {
			wp_die(
				esc_html__( 'Security check failed. Please try logging in again.', 'workos' ),
				esc_html__( 'Authentication Error', 'workos' ),
				[ 'response' => 403 ]
			);
		}

		// Exchange code for user.
		$result = workos()->api()->authenticate_with_code( $code, self::get_callback_url() );

		if ( is_wp_error( $result ) ) {
			wp_die(
				esc_html( $result->get_error_message() ),
				esc_html__( 'Authentication Error', 'workos' ),
				[ 'response' => 403 ]
			);
		}

		$workos_user = $result['user'] ?? $result;
		$wp_user     = \WorkOS\Sync\UserSync::find_or_create_wp_user( $workos_user );

		if ( is_wp_error( $wp_user ) ) {
			wp_die(
				esc_html( $wp_user->get_error_message() ),
				esc_html__( 'Authentication Error', 'workos' ),
				[ 'response' => 500 ]
			);
		}

		// Store WorkOS tokens.
		self::store_tokens( $wp_user->ID, $result );

		/** This action is documented in Auth/Login.php */
		do_action( 'workos_user_authenticated', $wp_user->ID, $result );

		// Set WP auth cookie.
		wp_set_auth_cookie( $wp_user->ID, true );
		wp_set_current_user( $wp_user->ID );

		/**
		 * Fires on WP login.
		 *
		 * @param string   $user_login Username.
		 * @param \WP_User $wp_user    User object.
		 */
		do_action( 'wp_login', $wp_user->user_login, $wp_user );

		wp_safe_redirect( $redirect_to );
		exit;
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
	 * Handle logout — optionally redirect to WorkOS logout.
	 */
	public function handle_logout(): void {
		$user_id = get_current_user_id();

		// Clean up stored tokens.
		delete_user_meta( $user_id, '_workos_access_token' );
		delete_user_meta( $user_id, '_workos_refresh_token' );
	}

	/**
	 * Control login redirect.
	 *
	 * @param string   $redirect_to           Default redirect.
	 * @param string   $requested_redirect_to Requested redirect.
	 * @param \WP_User $user                  User.
	 *
	 * @return string
	 */
	public function login_redirect( string $redirect_to, string $requested_redirect_to, $user ): string {
		if ( ! $user instanceof \WP_User ) {
			return $redirect_to;
		}

		if ( ! get_user_meta( $user->ID, '_workos_user_id', true ) ) {
			return $redirect_to;
		}

		return $requested_redirect_to ? $requested_redirect_to : admin_url();
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
	 * Store WorkOS tokens in usermeta (encrypted if possible).
	 *
	 * @param int   $user_id WP user ID.
	 * @param array $result  WorkOS auth response.
	 */
	private static function store_tokens( int $user_id, array $result ): void {
		if ( ! empty( $result['access_token'] ) ) {
			update_user_meta( $user_id, '_workos_access_token', $result['access_token'] );
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
