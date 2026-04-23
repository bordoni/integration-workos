<?php
/**
 * Login Profile admin REST API.
 *
 * @package WorkOS\Admin\LoginProfiles
 */

namespace WorkOS\Admin\LoginProfiles;

use WorkOS\Auth\AuthKit\Profile;
use WorkOS\Auth\AuthKit\ProfileRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Admin-only REST routes under /wp-json/workos/v1/admin/profiles.
 *
 * Every route requires `manage_options`. Routes are intentionally kept
 * narrow — they serve the admin React Profile Editor and nothing else.
 * Public authentication flows live under /wp-json/workos/v1/auth/*.
 */
class RestApi {

	public const NAMESPACE = 'workos/v1';
	public const BASE      = '/admin/profiles';

	/**
	 * Repository.
	 *
	 * @var ProfileRepository
	 */
	private ProfileRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param ProfileRepository $repository Profile repository.
	 */
	public function __construct( ProfileRepository $repository ) {
		$this->repository = $repository;
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::BASE,
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'list_profiles' ],
					'permission_callback' => [ $this, 'permission_check' ],
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'create_profile' ],
					'permission_callback' => [ $this, 'permission_check' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			self::BASE . '/(?P<id>\d+)',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_profile' ],
					'permission_callback' => [ $this, 'permission_check' ],
					'args'                => [
						'id' => [ 'sanitize_callback' => 'absint' ],
					],
				],
				[
					'methods'             => 'PUT',
					'callback'            => [ $this, 'update_profile' ],
					'permission_callback' => [ $this, 'permission_check' ],
					'args'                => [
						'id' => [ 'sanitize_callback' => 'absint' ],
					],
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this, 'delete_profile' ],
					'permission_callback' => [ $this, 'permission_check' ],
					'args'                => [
						'id' => [ 'sanitize_callback' => 'absint' ],
					],
				],
			]
		);
	}

	/**
	 * Permission check — admin capability required.
	 *
	 * @return true|WP_Error
	 */
	public function permission_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'workos_forbidden',
				__( 'You do not have permission to manage Login Profiles.', 'integration-workos' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * GET /admin/profiles — list all profiles.
	 *
	 * @return WP_REST_Response
	 */
	public function list_profiles(): WP_REST_Response {
		$profiles = array_map(
			static fn( Profile $profile ): array => $profile->to_array(),
			$this->repository->all()
		);

		return new WP_REST_Response( [ 'profiles' => $profiles ], 200 );
	}

	/**
	 * GET /admin/profiles/{id} — fetch a single profile.
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_profile( WP_REST_Request $request ) {
		$profile = $this->repository->find_by_id( (int) $request['id'] );
		if ( ! $profile ) {
			return new WP_Error(
				'workos_profile_not_found',
				__( 'Profile not found.', 'integration-workos' ),
				[ 'status' => 404 ]
			);
		}

		return new WP_REST_Response( $profile->to_array(), 200 );
	}

	/**
	 * POST /admin/profiles — create a new profile.
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_profile( WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();
		// Force a clean insert — ignore any client-supplied ID.
		$params['id'] = 0;

		$profile = Profile::from_array( $params );

		$saved = $this->repository->save( $profile );
		if ( is_wp_error( $saved ) ) {
			return $this->error_with_status( $saved, 400 );
		}

		return new WP_REST_Response( $saved->to_array(), 201 );
	}

	/**
	 * PUT /admin/profiles/{id} — update an existing profile.
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_profile( WP_REST_Request $request ) {
		$id       = (int) $request['id'];
		$existing = $this->repository->find_by_id( $id );
		if ( ! $existing ) {
			return new WP_Error(
				'workos_profile_not_found',
				__( 'Profile not found.', 'integration-workos' ),
				[ 'status' => 404 ]
			);
		}

		$params = (array) $request->get_json_params();

		// Merge into the existing payload so partial updates are safe — the
		// React editor only sends fields the user touched.
		$merged       = array_replace_recursive( $existing->to_array(), $params );
		$merged['id'] = $id;

		// Protect the reserved default slug from being renamed out from under
		// the wp-login.php takeover.
		if ( Profile::DEFAULT_SLUG === $existing->get_slug() ) {
			$merged['slug'] = Profile::DEFAULT_SLUG;
		}

		$profile = Profile::from_array( $merged );

		$saved = $this->repository->save( $profile );
		if ( is_wp_error( $saved ) ) {
			return $this->error_with_status( $saved, 400 );
		}

		return new WP_REST_Response( $saved->to_array(), 200 );
	}

	/**
	 * DELETE /admin/profiles/{id} — delete a profile.
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_profile( WP_REST_Request $request ) {
		$id     = (int) $request['id'];
		$result = $this->repository->delete( $id );
		if ( is_wp_error( $result ) ) {
			$status = 'workos_profile_not_found' === $result->get_error_code() ? 404 : 400;
			return $this->error_with_status( $result, $status );
		}

		return new WP_REST_Response(
			[
				'deleted' => true,
				'id'      => $id,
			],
			200
		);
	}

	/**
	 * Attach an HTTP status to a WP_Error returned by the repository.
	 *
	 * @param WP_Error $error   Error from the repository.
	 * @param int      $default_status HTTP status to set when the error has no status data.
	 *
	 * @return WP_Error
	 */
	private function error_with_status( WP_Error $error, int $default_status ): WP_Error {
		$data = $error->get_error_data();
		if ( ! is_array( $data ) || ! isset( $data['status'] ) ) {
			$error->add_data( [ 'status' => $default_status ] );
		}

		return $error;
	}
}
