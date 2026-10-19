<?php
/**
 * WorkOS Users admin REST API.
 *
 * @package WorkOS\Admin\Users
 */

namespace WorkOS\Admin\Users;

use WorkOS\ActivityLog\EventLogger;
use WorkOS\Capabilities;
use WorkOS\Config;
use WorkOS\Sync\UserLinks;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Admin-only REST routes under /wp-json/workos/v1/admin/users: the user
 * list, and `POST {id}/verify-email` to mark a WorkOS email verified.
 *
 * Thin proxy over `Api\Client::list_users()` — the WorkOS upstream already
 * paginates, filters, and authorizes. Responsibilities here are:
 *
 *   1. `workos_view_users` enforcement (maps to `manage_options`),
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

		register_rest_route(
			self::NAMESPACE,
			self::BASE . '/(?P<id>\d+)/verify-email',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'verify_email' ],
				'permission_callback' => [ $this, 'verify_email_permission_check' ],
				'args'                => [
					'id' => [ 'sanitize_callback' => 'absint' ],
				],
			]
		);
	}

	/**
	 * Permission check for verify-email — `workos_verify_email` (maps to
	 * `edit_users`, so nobody can verify their own email by default).
	 *
	 * @return true|WP_Error
	 */
	public function verify_email_permission_check() {
		if ( ! current_user_can( Capabilities::VERIFY_EMAIL ) ) {
			return new WP_Error(
				'workos_forbidden',
				__( 'You do not have permission to mark this email verified.', 'integration-workos' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * POST /admin/users/{id}/verify-email — mark the linked WorkOS user's
	 * email verified.
	 *
	 * For support cases where the sign-in code never arrives and the person
	 * has been confirmed another way. A no-op when WorkOS already has the
	 * email verified.
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function verify_email( WP_REST_Request $request ) {
		$user = get_userdata( absint( $request['id'] ) );
		if ( ! $user instanceof \WP_User ) {
			return new WP_Error(
				'workos_user_not_found',
				__( 'User not found.', 'integration-workos' ),
				[ 'status' => 404 ]
			);
		}

		$workos_user_id = (string) get_user_meta( $user->ID, '_workos_user_id', true );
		if ( '' === $workos_user_id ) {
			return new WP_Error(
				'workos_user_not_linked',
				__( 'This user is not linked to a WorkOS account.', 'integration-workos' ),
				[ 'status' => 409 ]
			);
		}

		$workos_user = workos()->api()->get_user( $workos_user_id );
		if ( is_wp_error( $workos_user ) ) {
			return $this->upstream_error( $workos_user );
		}

		if ( ! empty( $workos_user['email_verified'] ) ) {
			return new WP_REST_Response(
				[
					'ok'               => true,
					'email_verified'   => true,
					'already_verified' => true,
				],
				200
			);
		}

		$result = workos()->api()->update_user( $workos_user_id, [ 'email_verified' => true ] );
		if ( is_wp_error( $result ) ) {
			return $this->upstream_error( $result );
		}

		// The user-edit panel caches the WorkOS user for a few minutes.
		delete_transient( 'workos_profile_user_' . md5( $workos_user_id ) );

		EventLogger::log(
			'email.admin_verified',
			[
				'user_id'        => $user->ID,
				'workos_user_id' => $workos_user_id,
				'metadata'       => [
					'email'        => (string) ( $workos_user['email'] ?? '' ),
					'initiator_id' => get_current_user_id(),
				],
			]
		);

		return new WP_REST_Response(
			[
				'ok'               => true,
				'email_verified'   => true,
				'already_verified' => false,
			],
			200
		);
	}

	/**
	 * Wrap a WorkOS API failure as a 502 so the client doesn't read the
	 * upstream status (a WorkOS 401, say) as its own.
	 *
	 * @param WP_Error $error Error from the API client.
	 *
	 * @return WP_Error
	 */
	private function upstream_error( WP_Error $error ): WP_Error {
		return new WP_Error(
			'workos_api_error',
			$error->get_error_message(),
			[ 'status' => 502 ]
		);
	}

	/**
	 * Permission check — `workos_view_users` (maps to `manage_options`).
	 *
	 * @return true|WP_Error
	 */
	public function permission_check() {
		if ( ! current_user_can( Capabilities::VIEW_USERS ) ) {
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
		$rows           = isset( $result['data'] ) && is_array( $result['data'] ) ? array_filter( $result['data'], 'is_array' ) : [];

		// The linked WP users for the whole page at once, not one lookup per row.
		$wp_user_ids = UserLinks::find_many( array_map( 'strval', array_column( $rows, 'id' ) ) );

		$users = array_values(
			array_map(
				function ( array $user ) use ( $environment_id, $wp_user_ids ) {
					return $this->shape_user( $user, $environment_id, $wp_user_ids );
				},
				$rows
			)
		);

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
	 * @param array              $user           Upstream user object.
	 * @param string             $environment_id Active WorkOS environment ID (for the deep link).
	 * @param array<string, int> $wp_user_ids    Linked WP user IDs by WorkOS user ID (UserLinks::find_many()).
	 *
	 * @return array<string, mixed>
	 */
	private function shape_user( array $user, string $environment_id, array $wp_user_ids ): array {
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
		$wp_user_id = '' !== $id ? (int) ( $wp_user_ids[ $id ] ?? 0 ) : 0;

		return [
			'id'                      => $id,
			'wp_user_id'              => $wp_user_id,
			// What the caller may do with this row, so the screen only shows
			// actions they can use. A row with no linked WP user has none.
			'can_send_password_reset' => $wp_user_id > 0 && current_user_can( Capabilities::SEND_PASSWORD_RESET, $wp_user_id ),
			'can_change_email'        => $wp_user_id > 0 && current_user_can( Capabilities::CHANGE_EMAIL, $wp_user_id ),
			'can_verify_email'        => $wp_user_id > 0 && current_user_can( Capabilities::VERIFY_EMAIL ),
			'email'                   => $email,
			'email_verified'          => ! empty( $user['email_verified'] ),
			'first_name'              => isset( $user['first_name'] ) ? (string) $user['first_name'] : '',
			'last_name'               => isset( $user['last_name'] ) ? (string) $user['last_name'] : '',
			'last_sign_in_at'         => isset( $user['last_sign_in_at'] ) ? (string) $user['last_sign_in_at'] : '',
			'created_at'              => isset( $user['created_at'] ) ? (string) $user['created_at'] : '',
			'updated_at'              => isset( $user['updated_at'] ) ? (string) $user['updated_at'] : '',
			'dashboard_url'           => $dashboard_url,
		];
	}
}
