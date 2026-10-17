<?php
/**
 * Tests for the WorkOS Users admin REST API.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Admin\Users\RestApi;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Covers /wp-json/workos/v1/admin/users.
 *
 * The endpoint is a thin proxy over Api\Client::list_users(). The tests
 * intercept the outbound HTTP via the `pre_http_request` filter so they
 * can assert (a) the upstream URL + query params we forwarded, (b) the
 * response shape we return to the React client (including the
 * `dashboard_url` enrichment), and (c) graceful error envelopes when
 * upstream fails.
 *
 * @covers \WorkOS\Admin\Users\RestApi
 */
class AdminUsersRestApiTest extends WPTestCase {

	private const ROUTE_BASE = '/' . RestApi::NAMESPACE . RestApi::BASE;

	/**
	 * Captured outbound HTTP calls (URL + method + headers + body).
	 *
	 * @var array<int, array{url: string, method: string, body: string, headers: array}>
	 */
	private array $captured = [];

	/**
	 * Queued canned responses, FIFO. Falls back to an empty `{}` 200 when
	 * empty. Each entry is the array shape WP expects from a
	 * `pre_http_request` short-circuit.
	 *
	 * @var array<int, array>
	 */
	private array $responses = [];

	/**
	 * Set up — credentials, REST routes, HTTP interception.
	 */
	public function setUp(): void {
		parent::setUp();

		// Configure the active environment so workos()->is_enabled() passes.
		\WorkOS\Config::set_active_environment( 'production' );
		update_option(
			'workos_production',
			[
				'api_key'        => 'sk_test_fake',
				'client_id'      => 'client_fake',
				'environment_id' => 'environment_test',
			]
		);
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$this->captured  = [];
		$this->responses = [];

		add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );

		// Register REST routes against the live REST server.
		new RestApi();
		$server = rest_get_server();
		do_action( 'rest_api_init', $server );
	}

	/**
	 * Tear down — detach interceptor, clear options, reset user.
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10 );
		$this->captured  = [];
		$this->responses = [];

		wp_set_current_user( 0 );
		delete_option( 'workos_production' );
		// Clear the env selector outright rather than rewriting to 'staging' —
		// other tests in the suite assume `workos_active_environment` is
		// absent and `is_enabled()` defaults to false.
		delete_option( 'workos_active_environment' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		parent::tearDown();
	}

	/**
	 * Intercept any outbound HTTP and return queued responses in order.
	 *
	 * @param false|array $preempt Response override.
	 * @param array       $args    Request args.
	 * @param string      $url     Request URL.
	 */
	public function intercept_http( $preempt, array $args, string $url ): array {
		$this->captured[] = [
			'url'     => $url,
			'method'  => $args['method'] ?? 'GET',
			'body'    => $args['body'] ?? '',
			'headers' => $args['headers'] ?? [],
		];

		if ( ! empty( $this->responses ) ) {
			return count( $this->responses ) > 1
				? array_shift( $this->responses )
				: $this->responses[0];
		}

		return [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'body'     => wp_json_encode(
				[
					'data'          => [],
					'list_metadata' => [
						'before' => null,
						'after'  => null,
					],
				]
			),
		];
	}

	private function queue_response( int $status, array $body ): void {
		$this->responses[] = [
			'response' => [ 'code' => $status, 'message' => 'OK' ],
			'body'     => wp_json_encode( $body ),
		];
	}

	private function become_admin(): int {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );
		// Factory-driven user creation triggers WorkOS user-sync which fires
		// outbound HTTP through `pre_http_request`. That noise pollutes
		// assertions on what the endpoint forwarded, so reset the capture
		// buffer after the fixture is in place.
		$this->captured = [];
		return $user_id;
	}

	/**
	 * Dispatch a GET against the REST server. Query params must be passed
	 * via `set_query_params()` — `WP_REST_Request` does not parse `?` from
	 * the URL the way `wp_remote_get()` does.
	 *
	 * @param array<string, mixed> $query Optional query parameters.
	 */
	private function dispatch( array $query = [] ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', self::ROUTE_BASE );
		if ( $query ) {
			$request->set_query_params( $query );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function last_request(): array {
		$this->assertNotEmpty( $this->captured, 'No HTTP request was captured.' );
		return $this->captured[ count( $this->captured ) - 1 ];
	}

	// ---------------------------------------------------------------------
	// Authorization.
	// ---------------------------------------------------------------------

	public function test_anonymous_caller_is_forbidden(): void {
		$response = $this->dispatch();

		$this->assertSame( 403, $response->get_status() );
		// No upstream call should have been made.
		$this->assertSame( [], $this->captured );
	}

	public function test_subscriber_is_forbidden(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		// Drop the user_register HTTP push UserSync makes for the fixture.
		$this->captured = [];

		$response = $this->dispatch();

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( [], $this->captured );
	}

	// ---------------------------------------------------------------------
	// Happy path: pagination + enrichment.
	// ---------------------------------------------------------------------

	public function test_list_returns_shaped_users_with_dashboard_url(): void {
		$this->become_admin();

		$this->queue_response(
			200,
			[
				'data'          => [
					[
						'id'              => 'user_01HXXX',
						'email'           => 'jane@example.com',
						'email_verified'  => true,
						'first_name'      => 'Jane',
						'last_name'       => 'Doe',
						'last_sign_in_at' => '2026-05-10T08:00:00Z',
						'created_at'      => '2025-12-01T08:00:00Z',
						'updated_at'      => '2026-05-10T08:00:00Z',
						'profile_picture_url' => 'https://example.com/jane.png',
					],
				],
				'list_metadata' => [
					'before' => null,
					'after'  => 'user_01HXXX',
				],
			]
		);

		$response = $this->dispatch();
		$this->assertSame( 200, $response->get_status() );

		$body = $response->get_data();
		$this->assertCount( 1, $body['data'] );

		$user = $body['data'][0];
		$this->assertSame( 'user_01HXXX', $user['id'] );
		$this->assertSame( 'jane@example.com', $user['email'] );
		$this->assertTrue( $user['email_verified'] );
		$this->assertSame( 'Jane', $user['first_name'] );
		$this->assertSame(
			'https://dashboard.workos.com/environment_test/users/user_01HXXX/details',
			$user['dashboard_url']
		);
		// Profile picture was intentionally dropped from the shape.
		$this->assertArrayNotHasKey( 'profile_picture_url', $user );

		// list_metadata is forwarded as-is.
		$this->assertNull( $body['list_metadata']['before'] );
		$this->assertSame( 'user_01HXXX', $body['list_metadata']['after'] );

		// Upstream got the default limit.
		$req = $this->last_request();
		$this->assertStringContainsString( '/user_management/users', $req['url'] );
		$this->assertStringContainsString( 'limit=25', $req['url'] );
	}

	public function test_list_resolves_linked_wp_users_in_one_lookup(): void {
		global $wpdb;

		$this->become_admin();
		$alice = self::factory()->user->create( [ 'user_login' => 'alice_rest' ] );
		$bob   = self::factory()->user->create( [ 'user_login' => 'bob_rest' ] );
		update_user_meta( $alice, '_workos_user_id', 'user_alice' );
		update_user_meta( $bob, '_workos_user_id', 'user_bob' );
		$this->captured  = [];
		$this->responses = [];

		$this->queue_response(
			200,
			[
				'data'          => [
					[ 'id' => 'user_alice', 'email' => 'alice@example.com' ],
					[ 'id' => 'user_nobody', 'email' => 'nobody@example.com' ],
					[ 'id' => 'user_bob', 'email' => 'bob@example.com' ],
				],
				'list_metadata' => [ 'before' => null, 'after' => null ],
			]
		);

		wp_cache_flush();
		$before   = $wpdb->num_queries;
		$response = $this->dispatch( [ 'limit' => 3 ] );
		$queries  = $wpdb->num_queries - $before;

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			[ $alice, 0, $bob ],
			array_column( $response->get_data()['data'], 'wp_user_id' )
		);
		// The page's links in one query plus one for their meta, not a meta scan per row.
		$this->assertLessThanOrEqual( 6, $queries );
	}

	// ---------------------------------------------------------------------
	// Plugin capabilities.
	// ---------------------------------------------------------------------

	public function test_role_with_only_view_users_can_list(): void {
		add_role( 'workos_test_support', 'WorkOS Support', [ 'read' => true, 'workos_view_users' => true ] );

		try {
			wp_set_current_user( self::factory()->user->create( [ 'role' => 'workos_test_support' ] ) );
			$this->captured = [];

			$response = $this->dispatch();

			$this->assertSame( 200, $response->get_status() );
			$this->assertFalse( current_user_can( 'manage_options' ) );
		} finally {
			remove_role( 'workos_test_support' );
		}
	}

	public function test_editor_is_still_forbidden(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->captured = [];

		$this->assertSame( 403, $this->dispatch()->get_status() );
		$this->assertSame( [], $this->captured );
	}

	public function test_rows_carry_the_callers_action_permissions(): void {
		$this->become_admin();
		$alice = self::factory()->user->create( [ 'user_login' => 'alice_caps' ] );
		update_user_meta( $alice, '_workos_user_id', 'user_alice_caps' );
		$this->captured = [];

		$this->queue_response(
			200,
			[
				'data'          => [
					[ 'id' => 'user_alice_caps', 'email' => 'alice@example.com' ],
					[ 'id' => 'user_nobody_caps', 'email' => 'nobody@example.com' ],
				],
				'list_metadata' => [ 'before' => null, 'after' => null ],
			]
		);

		$rows = $this->dispatch()->get_data()['data'];

		$this->assertTrue( $rows[0]['can_send_password_reset'] );
		$this->assertTrue( $rows[0]['can_change_email'] );
		// No linked WP user, so there is nothing to act on.
		$this->assertFalse( $rows[1]['can_send_password_reset'] );
		$this->assertFalse( $rows[1]['can_change_email'] );
	}

	public function test_rows_hide_actions_the_caller_lacks(): void {
		$alice = self::factory()->user->create( [ 'user_login' => 'alice_view_only' ] );
		update_user_meta( $alice, '_workos_user_id', 'user_alice_view' );
		add_role(
			'workos_test_support',
			'WorkOS Support',
			[
				'read'                       => true,
				'workos_view_users'          => true,
				'workos_send_password_reset' => true,
			]
		);

		try {
			wp_set_current_user( self::factory()->user->create( [ 'role' => 'workos_test_support' ] ) );
			$this->captured = [];

			$this->queue_response(
				200,
				[
					'data'          => [ [ 'id' => 'user_alice_view', 'email' => 'alice@example.com' ] ],
					'list_metadata' => [ 'before' => null, 'after' => null ],
				]
			);

			$row = $this->dispatch()->get_data()['data'][0];

			$this->assertSame( $alice, $row['wp_user_id'] );
			$this->assertTrue( $row['can_send_password_reset'] );
			$this->assertFalse( $row['can_change_email'] );
		} finally {
			remove_role( 'workos_test_support' );
		}
	}

	// ---------------------------------------------------------------------
	// Parameter forwarding.
	// ---------------------------------------------------------------------

	public function test_limit_is_clamped_and_forwarded(): void {
		$this->become_admin();

		$response = $this->dispatch( [ 'limit' => 500 ] );
		$this->assertSame( 200, $response->get_status() );

		$req = $this->last_request();
		$this->assertStringContainsString( 'limit=100', $req['url'] );
	}

	public function test_email_filter_is_forwarded(): void {
		$this->become_admin();

		$this->dispatch( [ 'email' => 'jane@example.com' ] );

		$req = $this->last_request();
		$this->assertStringContainsString( 'email=jane', $req['url'] );
		$this->assertStringContainsString( '%40example.com', $req['url'] );
	}

	public function test_after_cursor_is_forwarded(): void {
		$this->become_admin();

		$this->dispatch( [ 'after' => 'user_cursor_xyz' ] );

		$req = $this->last_request();
		$this->assertStringContainsString( 'after=user_cursor_xyz', $req['url'] );
		$this->assertStringNotContainsString( 'before=', $req['url'] );
	}

	public function test_after_wins_when_both_cursors_supplied(): void {
		$this->become_admin();

		$this->dispatch( [ 'after' => 'A', 'before' => 'B' ] );

		$req = $this->last_request();
		$this->assertStringContainsString( 'after=A', $req['url'] );
		$this->assertStringNotContainsString( 'before=B', $req['url'] );
	}

	// ---------------------------------------------------------------------
	// Error handling.
	// ---------------------------------------------------------------------

	public function test_upstream_error_is_surfaced_in_envelope(): void {
		$this->become_admin();

		// 401 from upstream → Api\Client returns WP_Error → endpoint surfaces
		// the message but still responds 200 so the React UI can render it
		// inline without choking on its own envelope.
		$this->queue_response( 401, [ 'message' => 'Unauthorized: bad API key' ] );

		$response = $this->dispatch();
		$this->assertSame( 200, $response->get_status() );

		$body = $response->get_data();
		$this->assertSame( [], $body['data'] );
		$this->assertArrayHasKey( 'error', $body );
		$this->assertNotEmpty( $body['error'] );
	}
}
