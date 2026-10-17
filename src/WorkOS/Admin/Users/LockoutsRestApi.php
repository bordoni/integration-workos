<?php
/**
 * Sign-in lockout REST API.
 *
 * @package WorkOS\Admin\Users
 */

namespace WorkOS\Admin\Users;

use WorkOS\RateLimit\Lockouts;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Admin routes behind the "Sign-in lockouts" lookup on the Users screen.
 *
 *   POST /workos/v1/admin/lockouts/check  { email }
 *   POST /workos/v1/admin/lockouts/clear  { email, bucket? }
 *
 * POST for both so the address travels in the body and stays out of
 * access logs. Gated by `edit_users`: clearing a lockout is an account
 * action, not a settings change.
 */
class LockoutsRestApi {

	public const NAMESPACE = 'workos/v1';
	public const BASE      = '/admin/lockouts';

	/**
	 * Lockout service.
	 *
	 * @var Lockouts
	 */
	private Lockouts $lockouts;

	/**
	 * Constructor.
	 *
	 * @param Lockouts $lockouts Lockout service.
	 */
	public function __construct( Lockouts $lockouts ) {
		$this->lockouts = $lockouts;

		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$email = [
			'type'              => 'string',
			'required'          => true,
			'sanitize_callback' => 'sanitize_text_field',
		];

		register_rest_route(
			self::NAMESPACE,
			self::BASE . '/check',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'check' ],
				'permission_callback' => [ $this, 'permission_check' ],
				'args'                => [ 'email' => $email ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			self::BASE . '/clear',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'clear' ],
				'permission_callback' => [ $this, 'permission_check' ],
				'args'                => [
					'email'  => $email,
					'bucket' => [
						'type'              => 'string',
						'required'          => false,
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					],
				],
			]
		);
	}

	/**
	 * Require `edit_users`.
	 *
	 * @return true|WP_Error
	 */
	public function permission_check() {
		if ( ! current_user_can( 'edit_users' ) ) {
			return new WP_Error(
				'workos_forbidden',
				__( 'You do not have permission to manage sign-in lockouts.', 'integration-workos' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
	}

	/**
	 * POST /admin/lockouts/check.
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function check( WP_REST_Request $request ) {
		$rows = $this->lockouts->check( (string) $request->get_param( 'email' ) );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		return new WP_REST_Response(
			[
				'buckets' => $rows,
				'locked'  => count( array_filter( array_column( $rows, 'locked' ) ) ),
			],
			200
		);
	}

	/**
	 * POST /admin/lockouts/clear.
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function clear( WP_REST_Request $request ) {
		$cleared = $this->lockouts->clear(
			(string) $request->get_param( 'email' ),
			(string) $request->get_param( 'bucket' ),
			'admin'
		);
		if ( is_wp_error( $cleared ) ) {
			return $cleared;
		}

		return new WP_REST_Response( [ 'cleared' => $cleared ], 200 );
	}
}
