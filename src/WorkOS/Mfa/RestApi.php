<?php
/**
 * MFA account REST routes.
 *
 * @package WorkOS\Mfa
 */

namespace WorkOS\Mfa;

use WorkOS\RateLimit\TieredRateLimiter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * A signed-in user's MFA: status, enroll, confirm, remove.
 *
 * Cookie-authenticated with the core `wp_rest` nonce (`X-WP-Nonce`), like
 * the other `/users/{id}/…` routes, so the account panel, the `[workos:mfa]`
 * shortcode and any third-party UI can use them without a Login Profile.
 *
 *  - GET    /users/{id}/mfa                       — status and factors.
 *  - POST   /users/{id}/mfa/enroll                — create a TOTP factor (QR + secret).
 *  - POST   /users/{id}/mfa/confirm               — confirm it with the first code.
 *  - DELETE /users/{id}/mfa/factors/{factor_id}   — remove a factor, with a code from it.
 *
 * Enrolling and removing are the account owner's alone. Admins can read
 * the status; their tools (reset, require) are in the admin routes.
 */
class RestApi {

	/**
	 * REST namespace.
	 */
	public const NAMESPACE = 'workos/v1';

	/**
	 * Factor service.
	 *
	 * @var FactorService
	 */
	private FactorService $factors;

	/**
	 * Rate limiter.
	 *
	 * @var TieredRateLimiter
	 */
	private TieredRateLimiter $rate_limiter;

	/**
	 * Constructor.
	 *
	 * @param FactorService     $factors      Factor service.
	 * @param TieredRateLimiter $rate_limiter Rate limiter.
	 */
	public function __construct( FactorService $factors, TieredRateLimiter $rate_limiter ) {
		$this->factors      = $factors;
		$this->rate_limiter = $rate_limiter;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$id = [ 'id' => [ 'sanitize_callback' => 'absint' ] ];

		register_rest_route(
			self::NAMESPACE,
			'/users/(?P<id>\d+)/mfa',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'status' ],
				'permission_callback' => [ $this, 'can_view' ],
				'args'                => $id,
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/users/(?P<id>\d+)/mfa/enroll',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'enroll' ],
				'permission_callback' => [ $this, 'can_manage_own' ],
				'args'                => $id,
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/users/(?P<id>\d+)/mfa/confirm',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'confirm' ],
				'permission_callback' => [ $this, 'can_manage_own' ],
				'args'                => $id + [
					'factor_id'    => [ 'sanitize_callback' => 'sanitize_text_field' ],
					'challenge_id' => [ 'sanitize_callback' => 'sanitize_text_field' ],
					'code'         => [ 'sanitize_callback' => 'sanitize_text_field' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/users/(?P<id>\d+)/mfa/factors/(?P<factor_id>[A-Za-z0-9_]+)',
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'remove' ],
				'permission_callback' => [ $this, 'can_manage_own' ],
				'args'                => $id + [
					'code' => [ 'sanitize_callback' => 'sanitize_text_field' ],
				],
			]
		);
	}

	/**
	 * The account owner, or someone who may edit the user.
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return true|WP_Error
	 */
	public function can_view( WP_REST_Request $request ) {
		$user_id = (int) $request->get_param( 'id' );

		if ( $user_id > 0 && get_current_user_id() === $user_id ) {
			return $this->can_manage_own( $request );
		}

		return self::can_manage_user( $user_id );
	}

	/**
	 * Only the account owner manages their own factors.
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return true|WP_Error
	 */
	public function can_manage_own( WP_REST_Request $request ) {
		$user_id = (int) $request->get_param( 'id' );

		if ( get_current_user_id() <= 0 ) {
			return $this->error( 'workos_mfa_not_logged_in', __( 'You must be signed in to manage two-step verification.', 'integration-workos' ), 401 );
		}

		/**
		 * Filters whether a user may manage their own MFA factors.
		 *
		 * @param bool $can     Default: true for the account owner.
		 * @param int  $user_id WP user ID.
		 */
		$can = get_current_user_id() === $user_id && (bool) apply_filters( 'workos_mfa_can_manage', true, $user_id );

		return $can ? true : $this->error( 'workos_mfa_forbidden', __( 'You can’t manage two-step verification for this account.', 'integration-workos' ), 403 );
	}

	/**
	 * Whether the current user may administer another user's MFA.
	 *
	 * @param int $user_id Target WP user ID.
	 *
	 * @return true|WP_Error
	 */
	public static function can_manage_user( int $user_id ) {
		$can = $user_id > 0 && current_user_can( 'edit_user', $user_id );

		/**
		 * Filters whether the current user may administer a user's MFA
		 * (read the status, reset factors, set the require flag).
		 *
		 * @param bool $can     Default: `edit_user` on the target.
		 * @param int  $user_id Target WP user ID.
		 */
		$can = (bool) apply_filters( 'workos_mfa_can_manage_user', $can, $user_id );

		return $can ? true : new WP_Error(
			'workos_mfa_forbidden',
			__( 'You can’t manage two-step verification for this account.', 'integration-workos' ),
			[ 'status' => rest_authorization_required_code() ]
		);
	}

	/**
	 * GET /users/{id}/mfa
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function status( WP_REST_Request $request ) {
		$status = $this->factors->status( (int) $request->get_param( 'id' ) );

		return is_wp_error( $status ) ? $status : new WP_REST_Response( $status, 200 );
	}

	/**
	 * POST /users/{id}/mfa/enroll
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function enroll( WP_REST_Request $request ) {
		$user_id = (int) $request->get_param( 'id' );

		$limited = $this->rate_limiter->attempt( 'mfa_enroll_user', (string) $user_id, TieredRateLimiter::REQUEST_SUBJECT );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$result = $this->factors->start_enrollment( $user_id );

		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 201 );
	}

	/**
	 * POST /users/{id}/mfa/confirm
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function confirm( WP_REST_Request $request ) {
		$user_id = (int) $request->get_param( 'id' );

		$limited = $this->rate_limiter->attempt( 'mfa_confirm_user', (string) $user_id, TieredRateLimiter::REQUEST_SUBJECT );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$result = $this->factors->confirm_enrollment(
			$user_id,
			(string) $request->get_param( 'factor_id' ),
			(string) $request->get_param( 'code' ),
			(string) $request->get_param( 'challenge_id' )
		);

		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
	}

	/**
	 * DELETE /users/{id}/mfa/factors/{factor_id}
	 *
	 * Needs a current code from the factor being removed, so a session
	 * someone else got hold of can't quietly turn MFA off.
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function remove( WP_REST_Request $request ) {
		$user_id   = (int) $request->get_param( 'id' );
		$factor_id = (string) $request->get_param( 'factor_id' );

		$limited = $this->rate_limiter->attempt( 'mfa_delete_user', (string) $user_id, TieredRateLimiter::REQUEST_SUBJECT );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$factors = $this->factors->list_factors( $user_id );
		if ( is_wp_error( $factors ) ) {
			return $factors;
		}
		if ( ! in_array( $factor_id, array_column( $factors, 'id' ), true ) ) {
			return $this->error( 'workos_mfa_factor_not_found', __( 'Factor not found.', 'integration-workos' ), 404 );
		}

		$valid = $this->factors->verify_code( $factor_id, (string) $request->get_param( 'code' ) );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$result = $this->factors->delete_factor( $user_id, $factor_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$status = $this->factors->status( $user_id );

		return new WP_REST_Response( is_wp_error( $status ) ? [ 'deleted' => true ] : $status, 200 );
	}

	/**
	 * Build an error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 *
	 * @return WP_Error
	 */
	private function error( string $code, string $message, int $status ): WP_Error {
		return new WP_Error( $code, $message, [ 'status' => $status ] );
	}
}
