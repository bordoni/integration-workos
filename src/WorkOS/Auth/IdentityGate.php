<?php
/**
 * Require a linked WorkOS identity for signed-in users.
 *
 * @package WorkOS\Auth
 */

namespace WorkOS\Auth;

use WorkOS\Auth\AuthKit\FrontendRoute;
use WorkOS\Auth\AuthKit\Profile;
use WorkOS\Auth\AuthKit\ProfileRepository;
use WorkOS\Config;
use WorkOS\Vendor\StellarWP\SuperGlobals\SuperGlobals;

defined( 'ABSPATH' ) || exit;

/**
 * When "Require Linked Identity" is on (it needs Re-link Protection), a
 * signed-in user with no linked WorkOS user can't use the site:
 *
 * - Front-end pages and wp-admin show only a "Connect your account" page.
 *   Its button signs the user out and sends them through WorkOS sign-in,
 *   which links the identity and returns them where they were going.
 * - REST requests outside the plugin's own `workos/v1` routes get a 403.
 * - admin-ajax requests get a 403 JSON error.
 *
 * Users who can `manage_options` are exempt. A failed identity check counts
 * as unlinked.
 */
class IdentityGate {

	/**
	 * Query arg of the "Connect account" action.
	 */
	public const CONNECT_ACTION = 'workos_connect';

	/**
	 * Error code returned to REST and AJAX callers.
	 */
	public const ERROR_CODE = 'workos_identity_required';

	/**
	 * REST namespace that stays reachable: sign-in and webhooks live there.
	 */
	private const REST_NAMESPACE = '/workos/v1';

	/**
	 * Login profiles.
	 *
	 * @var ProfileRepository
	 */
	private ProfileRepository $profiles;

	/**
	 * Constructor — registers hooks.
	 *
	 * @param ProfileRepository $profiles Login profiles.
	 */
	public function __construct( ProfileRepository $profiles ) {
		$this->profiles = $profiles;

		// Before anything else on template_redirect, WooCommerce's wc-ajax (priority 0) included.
		add_action( 'template_redirect', [ $this, 'gate_front_end' ], -100 );
		add_action( 'admin_init', [ $this, 'gate_admin' ], 0 );
		add_filter( 'rest_pre_dispatch', [ $this, 'gate_rest' ], 10, 3 );
	}

	/**
	 * Whether the gate is on.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return Config::is_linked_identity_required();
	}

	/**
	 * Whether a WP user is linked to a WorkOS user.
	 *
	 * Fails closed: if the check throws, the user counts as unlinked.
	 *
	 * @param int $user_id WP user ID.
	 *
	 * @return bool
	 */
	public static function is_linked( int $user_id ): bool {
		try {
			return '' !== (string) get_user_meta( $user_id, '_workos_user_id', true );
		} catch ( \Throwable $e ) {
			workos_log( 'Identity check failed for user #' . $user_id . ': ' . $e->getMessage(), 'error' );
			return false;
		}
	}

	/**
	 * Whether the gate blocks the current user.
	 *
	 * @return bool
	 */
	public function blocks_current_user(): bool {
		if ( ! is_user_logged_in() || ! self::is_enabled() ) {
			return false;
		}

		$user = wp_get_current_user();

		/**
		 * Filter whether a signed-in user may skip the linked-identity gate.
		 *
		 * @param bool     $exempt Default: the user can manage_options.
		 * @param \WP_User $user   The current user.
		 */
		$exempt = (bool) apply_filters( 'workos_identity_gate_exempt', user_can( $user, 'manage_options' ), $user );
		if ( $exempt ) {
			return false;
		}

		return ! self::is_linked( $user->ID );
	}

	// -------------------------------------------------------------------------
	// Front end and wp-admin
	// -------------------------------------------------------------------------

	/**
	 * Gate a front-end request.
	 *
	 * @return void
	 */
	public function gate_front_end(): void {
		if ( ! $this->blocks_current_user() ) {
			return;
		}

		if ( $this->is_connect_request() ) {
			$this->connect();
		}

		if ( $this->is_allowed_front_end_request() ) {
			return;
		}

		$this->respond_with_connect_page();
	}

	/**
	 * Gate a wp-admin, admin-post or admin-ajax request.
	 *
	 * @return void
	 */
	public function gate_admin(): void {
		if ( ! $this->blocks_current_user() ) {
			return;
		}

		if ( wp_doing_ajax() ) {
			$action = sanitize_key( (string) SuperGlobals::get_var( 'action', '' ) );
			if ( $this->is_allowed_ajax_action( $action ) ) {
				return;
			}

			wp_send_json_error(
				[
					'code'    => self::ERROR_CODE,
					'message' => $this->message(),
				],
				403
			);
		}

		$this->respond_with_connect_page();
	}

	/**
	 * Whether a front-end request goes through for a blocked user.
	 *
	 * The WorkOS callback and the login route stay reachable so the user can
	 * finish signing in.
	 *
	 * @return bool
	 */
	public function is_allowed_front_end_request(): bool {
		$allowed = (bool) get_query_var( 'workos_callback' ) || '' !== (string) get_query_var( FrontendRoute::QUERY_VAR );

		/**
		 * Filter whether a front-end request goes through for a user the gate blocks.
		 *
		 * @param bool $allowed Default: true for the WorkOS callback and login routes.
		 */
		return (bool) apply_filters( 'workos_identity_gate_allow_request', $allowed );
	}

	/**
	 * Whether an admin-ajax action goes through for a blocked user.
	 *
	 * @param string $action AJAX action.
	 *
	 * @return bool
	 */
	public function is_allowed_ajax_action( string $action ): bool {
		/**
		 * Filter the admin-ajax actions a user the gate blocks may still call.
		 *
		 * @param string[] $actions Default: heartbeat (keeps the session alive, returns no data).
		 */
		$actions = (array) apply_filters( 'workos_identity_gate_ajax_actions', [ 'heartbeat' ] );

		return in_array( $action, $actions, true );
	}

	/**
	 * Send the "Connect your account" page and stop.
	 *
	 * @return void
	 */
	private function respond_with_connect_page(): void {
		status_header( 403 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );

		echo $this->connect_page_html( $this->current_url() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the template.
		exit;
	}

	/**
	 * Render the "Connect your account" page.
	 *
	 * @param string $return_to Where the user goes after connecting.
	 *
	 * @return string HTML.
	 */
	public function connect_page_html( string $return_to ): string {
		$site_name   = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$site_icon   = get_site_icon_url( 96 );
		$heading     = $this->heading();
		$message     = $this->message();
		$connect_url = $this->connect_url( $return_to );
		$logout_url  = wp_logout_url( home_url( '/' ) );
		$button      = __( 'Connect account', 'integration-workos' );

		ob_start();
		include workos()->getDir() . 'templates/identity-gate/connect-page.php';

		return (string) ob_get_clean();
	}

	/**
	 * Page heading.
	 *
	 * @return string
	 */
	private function heading(): string {
		/**
		 * Filter the heading of the "Connect your account" page.
		 *
		 * @param string $heading Heading text.
		 */
		return (string) apply_filters( 'workos_identity_gate_heading', __( 'Connect your account', 'integration-workos' ) );
	}

	/**
	 * Page and error message.
	 *
	 * @return string
	 */
	private function message(): string {
		/**
		 * Filter the message shown on the "Connect your account" page and
		 * returned with REST and AJAX errors.
		 *
		 * @param string $message Message text.
		 */
		return (string) apply_filters(
			'workos_identity_gate_message',
			__( 'Your account isn\'t connected to a sign-in yet. Sign in to connect it and continue.', 'integration-workos' )
		);
	}

	// -------------------------------------------------------------------------
	// Connect
	// -------------------------------------------------------------------------

	/**
	 * URL of the "Connect account" button.
	 *
	 * @param string $return_to Where the user goes after connecting.
	 *
	 * @return string
	 */
	public function connect_url( string $return_to ): string {
		return add_query_arg(
			[
				self::CONNECT_ACTION => '1',
				'redirect_to'        => rawurlencode( $this->safe_return_to( $return_to ) ),
				'_wpnonce'           => wp_create_nonce( self::CONNECT_ACTION ),
			],
			home_url( '/' )
		);
	}

	/**
	 * Whether this request is the "Connect account" action.
	 *
	 * @return bool
	 */
	private function is_connect_request(): bool {
		return '1' === (string) SuperGlobals::get_get_var( self::CONNECT_ACTION, '' );
	}

	/**
	 * Sign the user out and send them to WorkOS sign-in.
	 *
	 * The login route sends signed-in users away, so the unlinked session
	 * has to end first. This doesn't go through wp-login.php?action=logout,
	 * so role-based logout redirects can't drop the return address.
	 *
	 * @return void
	 */
	private function connect(): void {
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::CONNECT_ACTION ) ) {
			return;
		}

		// Raw, then validated: SuperGlobals HTML-escapes values, which breaks URLs with a query string.
		$return_to = $this->safe_return_to( isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '' );

		wp_logout();

		nocache_headers();
		wp_safe_redirect( $this->sign_in_url( $return_to ) );
		exit;
	}

	/**
	 * WorkOS sign-in URL for the configured login profile.
	 *
	 * @param string $return_to Where the user goes after signing in.
	 *
	 * @return string
	 */
	public function sign_in_url( string $return_to ): string {
		$slug    = sanitize_title( (string) workos()->option( 'identity_gate_profile', Profile::DEFAULT_SLUG ) );
		$profile = $this->profiles->find_by_slug( '' !== $slug ? $slug : Profile::DEFAULT_SLUG );

		if ( $profile && $profile->is_custom_mode() ) {
			// url_for_profile() appends values verbatim; encode so the return address keeps its own query string.
			return FrontendRoute::url_for_profile( $profile, [ 'redirect_to' => rawurlencode( $return_to ) ] );
		}

		return wp_login_url( $return_to );
	}

	/**
	 * Keep a return address on this site.
	 *
	 * @param string $url Requested URL.
	 *
	 * @return string
	 */
	public function safe_return_to( string $url ): string {
		return wp_validate_redirect( $url, home_url( '/' ) );
	}

	/**
	 * The URL of the current request.
	 *
	 * @return string
	 */
	private function current_url(): string {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';

		return $this->safe_return_to( ( is_ssl() ? 'https://' : 'http://' ) . $host . $uri );
	}

	// -------------------------------------------------------------------------
	// REST
	// -------------------------------------------------------------------------

	/**
	 * Refuse REST requests from a blocked user, outside workos/v1.
	 *
	 * @param mixed            $result  Response so far; anything but null short-circuits.
	 * @param \WP_REST_Server  $server  Server.
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return mixed
	 */
	public function gate_rest( $result, $server, $request ) {
		if ( null !== $result || ! $request instanceof \WP_REST_Request ) {
			return $result;
		}

		if ( ! $this->blocks_current_user() || $this->is_allowed_rest_route( $request->get_route() ) ) {
			return $result;
		}

		return new \WP_Error( self::ERROR_CODE, $this->message(), [ 'status' => 403 ] );
	}

	/**
	 * Whether a REST route goes through for a blocked user.
	 *
	 * @param string $route REST route, e.g. /wp/v2/users/me.
	 *
	 * @return bool
	 */
	public function is_allowed_rest_route( string $route ): bool {
		$allowed = self::REST_NAMESPACE === $route || 0 === strpos( $route, self::REST_NAMESPACE . '/' );

		/**
		 * Filter whether a REST route goes through for a user the gate blocks.
		 *
		 * @param bool   $allowed Default: true for the plugin's own workos/v1 routes.
		 * @param string $route   REST route.
		 */
		return (bool) apply_filters( 'workos_identity_gate_allow_rest_route', $allowed, $route );
	}
}
