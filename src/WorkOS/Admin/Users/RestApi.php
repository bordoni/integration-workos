<?php
/**
 * WorkOS Users admin REST API.
 *
 * @package WorkOS\Admin\Users
 */

namespace WorkOS\Admin\Users;

use WorkOS\Config;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Admin-only REST routes under /wp-json/workos/v1/admin/users.
 *
 * Thin proxy over `Api\Client::list_users()` — the WorkOS upstream already
 * paginates, filters, and authorizes. Responsibilities here are:
 *
 *   1. `manage_options` enforcement,
 *   2. parameter sanitization,
 *   3. enrichment of each user record with a `dashboard_url` so the React
 *      side doesn't reconstruct it,
 *   4. shaping upstream errors into a JSON envelope the UI can render.
 */
class RestApi {

	public const NAMESPACE = 'workos/v1';
	public const BASE      = '/admin/users';

	/**
	 * Maximum page size accepted from the client (matches WorkOS upstream limit).
	 */
	private const MAX_LIMIT = 100;

	/**
	 * Default page size when the client omits `limit`.
	 */
	private const DEFAULT_LIMIT = 25;

	/**
	 * Constructor.
	 */
	public function __construct() {
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
					'callback'            => [ $this, 'list_users' ],
					'permission_callback' => [ $this, 'permission_check' ],
					'args'                => [
						'limit'           => [
							'type'              => 'integer',
							'required'          => false,
							'default'           => self::DEFAULT_LIMIT,
							'sanitize_callback' => [ $this, 'sanitize_limit' ],
						],
						'before'          => [
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						],
						'after'           => [
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						],
						'email'           => [
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						],
						'organization_id' => [
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						],
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
				__( 'You do not have permission to manage WorkOS users.', 'integration-workos' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Clamp a `limit` value into [1, MAX_LIMIT].
	 *
	 * @param mixed $value Raw value from the request.
	 *
	 * @return int
	 */
	public function sanitize_limit( $value ): int {
		$limit = (int) $value;
		if ( $limit < 1 ) {
			return self::DEFAULT_LIMIT;
		}
		if ( $limit > self::MAX_LIMIT ) {
			return self::MAX_LIMIT;
		}
		return $limit;
	}

	/**
	 * GET /admin/users — proxy WorkOS user list with pagination + filters.
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response
	 */
	public function list_users( WP_REST_Request $request ): WP_REST_Response {
		if ( ! workos()->is_enabled() ) {
			return new WP_REST_Response(
				[
					'data'          => [],
					'list_metadata' => [
						'before' => null,
						'after'  => null,
					],
					'error'         => __( 'WorkOS is not configured. Save API credentials to enable user listing.', 'integration-workos' ),
				],
				200
			);
		}

		$params = $this->build_upstream_params( $request );

		$result = workos()->api()->list_users( $params );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				[
					'data'          => [],
					'list_metadata' => [
						'before' => null,
						'after'  => null,
					],
					'error'         => $result->get_error_message(),
				],
				200
			);
		}

		$environment_id = Config::get_environment_id();
		$users          = isset( $result['data'] ) && is_array( $result['data'] )
			? array_map(
				function ( $user ) use ( $environment_id ) {
					return $this->shape_user( is_array( $user ) ? $user : [], $environment_id );
				},
				$result['data']
			)
			: [];

		$metadata = isset( $result['list_metadata'] ) && is_array( $result['list_metadata'] )
			? $result['list_metadata']
			: [];

		return new WP_REST_Response(
			[
				'data'          => $users,
				'list_metadata' => [
					'before' => isset( $metadata['before'] ) && '' !== $metadata['before'] ? (string) $metadata['before'] : null,
					'after'  => isset( $metadata['after'] ) && '' !== $metadata['after'] ? (string) $metadata['after'] : null,
				],
			],
			200
		);
	}

	/**
	 * Build the upstream WorkOS params from the validated request.
	 *
	 * Cursor handling: WorkOS rejects requests that include both `before` and
	 * `after`, so we only forward whichever is present (preferring `after`).
	 * Empty strings are dropped entirely — `sanitize_text_field` keeps empty
	 * defaults around and the upstream treats them as set.
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return array<string, mixed>
	 */
	private function build_upstream_params( WP_REST_Request $request ): array {
		$params = [
			'limit' => (int) $request->get_param( 'limit' ),
			'order' => 'desc',
		];

		$after  = (string) $request->get_param( 'after' );
		$before = (string) $request->get_param( 'before' );
		if ( '' !== $after ) {
			$params['after'] = $after;
		} elseif ( '' !== $before ) {
			$params['before'] = $before;
		}

		$email = (string) $request->get_param( 'email' );
		if ( '' !== $email ) {
			$params['email'] = $email;
		}

		$org = (string) $request->get_param( 'organization_id' );
		if ( '' !== $org ) {
			$params['organization_id'] = $org;
		}

		return $params;
	}

	/**
	 * Shape an upstream WorkOS user object for REST output.
	 *
	 * Drops fields that aren't useful in the list view (raw metadata,
	 * profile picture URL — the list intentionally doesn't render avatars
	 * to keep the page lean) and adds a server-computed `dashboard_url`.
	 *
	 * @param array  $user           Upstream user object.
	 * @param string $environment_id Active WorkOS environment ID (for the deep link).
	 *
	 * @return array<string, mixed>
	 */
	private function shape_user( array $user, string $environment_id ): array {
		$id    = isset( $user['id'] ) ? (string) $user['id'] : '';
		$email = isset( $user['email'] ) ? (string) $user['email'] : '';

		$dashboard_url = '';
		if ( '' !== $id && '' !== $environment_id ) {
			$dashboard_url = sprintf(
				'https://dashboard.workos.com/%s/users/%s/details',
				rawurlencode( $environment_id ),
				rawurlencode( $id )
			);
		}

		// Resolve the local WP user_id (if the user is linked) so the React
		// row can wire up the "Send password reset" trigger that lives at
		// `POST /workos/v1/admin/users/{wp_user_id}/password-reset`. Empty
		// when the WorkOS user has no matching WP row yet.
		$wp_user_id = 0;
		if ( '' !== $id ) {
			$users = get_users(
				[
					'meta_key'   => '_workos_user_id',
					'meta_value' => $id,
					'number'     => 1,
					'fields'     => 'ID',
				]
			);
			if ( ! empty( $users ) ) {
				$wp_user_id = (int) $users[0];
			}
		}

		return [
			'id'              => $id,
			'wp_user_id'      => $wp_user_id,
			'email'           => $email,
			'email_verified'  => ! empty( $user['email_verified'] ),
			'first_name'      => isset( $user['first_name'] ) ? (string) $user['first_name'] : '',
			'last_name'       => isset( $user['last_name'] ) ? (string) $user['last_name'] : '',
			'last_sign_in_at' => isset( $user['last_sign_in_at'] ) ? (string) $user['last_sign_in_at'] : '',
			'created_at'      => isset( $user['created_at'] ) ? (string) $user['created_at'] : '',
			'updated_at'      => isset( $user['updated_at'] ) ? (string) $user['updated_at'] : '',
			'dashboard_url'   => $dashboard_url,
		];
	}
}
