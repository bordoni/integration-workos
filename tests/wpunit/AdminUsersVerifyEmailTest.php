<?php
/**
 * Tests for marking a WorkOS email verified from the admin.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Admin\UserProfile;
use WorkOS\Admin\Users\RestApi;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Covers `POST /workos/v1/admin/users/{id}/verify-email` and the button on
 * the user-edit WorkOS panel.
 *
 * WorkOS is mocked at `pre_http_request`: GETs of the user return
 * `$this->workos_user`, PUTs return `$this->update_response`.
 *
 * @covers \WorkOS\Admin\Users\RestApi
 */
class AdminUsersVerifyEmailTest extends WPTestCase {

	/**
	 * Captured outbound HTTP calls.
	 *
	 * @var array<int, array{url: string, method: string, body: string}>
	 */
	private array $captured = [];

	/**
	 * What WorkOS returns for GET /user_management/users/{id}.
	 *
	 * @var array{code: int, body: array}
	 */
	private array $workos_user = [];

	/**
	 * What WorkOS returns for PUT /user_management/users/{id}.
	 *
	 * @var array{code: int, body: array}
	 */
	private array $update_response = [];

	/**
	 * Linked subscriber, the target.
	 *
	 * @var int
	 */
	private int $linked_user_id = 0;

	/**
	 * WP user with no WorkOS link.
	 *
	 * @var int
	 */
	private int $unlinked_user_id = 0;

	/**
	 * Administrator, the usual caller.
	 *
	 * @var int
	 */
	private int $admin_user_id = 0;

	/**
	 * Set up credentials, routes, users and the HTTP mock.
	 */
	public function setUp(): void {
		parent::setUp();

		\WorkOS\Config::set_active_environment( 'production' );
		update_option(
			'workos_production',
			[
				'api_key'             => 'sk_test_fake',
				'client_id'           => 'client_fake',
				'environment_id'      => 'environment_test',
				'enable_activity_log' => true,
			]
		);
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		new RestApi();
		do_action( 'rest_api_init', rest_get_server() );

		add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );

		$this->linked_user_id   = $this->create_user( 'subscriber' );
		$this->unlinked_user_id = $this->create_user( 'subscriber' );
		$this->admin_user_id    = $this->create_user( 'administrator' );
		update_user_meta( $this->linked_user_id, '_workos_user_id', 'user_verify_01' );
		// The user-edit panel caches the WorkOS user; start each test cold.
		delete_transient( 'workos_profile_user_' . md5( 'user_verify_01' ) );
		delete_transient( 'workos_profile_memberships_' . md5( 'user_verify_01' ) );

		$this->workos_user     = [
			'code' => 200,
			'body' => [
				'id'             => 'user_verify_01',
				'email'          => 'target@example.test',
				'email_verified' => false,
			],
		];
		$this->update_response = [
			'code' => 200,
			'body' => [
				'id'             => 'user_verify_01',
				'email'          => 'target@example.test',
				'email_verified' => true,
			],
		];

		$this->captured = [];
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10 );
		remove_role( 'workos_test_support' );

		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}workos_activity_log" );

		wp_set_current_user( 0 );
		delete_option( 'workos_production' );
		delete_option( 'workos_active_environment' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		parent::tearDown();
	}

	/**
	 * Insert a user.
	 *
	 * @param string $role Role slug.
	 *
	 * @return int
	 */
	private function create_user( string $role ): int {
		$user_id = wp_insert_user(
			[
				'user_login' => 've_' . wp_generate_password( 8, false ),
				'user_pass'  => wp_generate_password(),
				'user_email' => 've-' . uniqid( '', true ) . '@example.test',
				'role'       => $role,
			]
		);

		$this->assertIsInt( $user_id );

		return $user_id;
	}

	/**
	 * Route WorkOS calls to the canned responses.
	 *
	 * @param false|array $preempt Filter input.
	 * @param array       $args    Request args.
	 * @param string      $url     Request URL.
	 *
	 * @return array
	 */
	public function intercept_http( $preempt, array $args, string $url ): array {
		$method           = $args['method'] ?? 'GET';
		$this->captured[] = [
			'url'    => $url,
			'method' => $method,
			'body'   => (string) ( $args['body'] ?? '' ),
		];

		$canned = [
			'code' => 200,
			'body' => [],
		];
		if ( str_contains( $url, '/user_management/users/user_verify_01' ) ) {
			$canned = 'PUT' === $method ? $this->update_response : $this->workos_user;
		}

		return [
			'response' => [
				'code'    => $canned['code'],
				'message' => 'OK',
			],
			'body'     => wp_json_encode( $canned['body'] ),
		];
	}

	/**
	 * POST to the endpoint.
	 *
	 * @param int $user_id WP user ID in the route.
	 *
	 * @return WP_REST_Response
	 */
	private function dispatch( int $user_id ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/workos/v1/admin/users/' . $user_id . '/verify-email' );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Captured PUTs to WorkOS.
	 *
	 * @return array<int, array{url: string, method: string, body: string}>
	 */
	private function updates(): array {
		return array_values(
			array_filter(
				$this->captured,
				static fn( array $call ): bool => 'PUT' === $call['method']
			)
		);
	}

	/**
	 * Activity-log rows for the event.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function log_rows(): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}workos_activity_log WHERE event_type = %s",
				'email.admin_verified'
			),
			ARRAY_A
		);
	}

	// ---------------------------------------------------------------------
	// Capability.
	// ---------------------------------------------------------------------

	public function test_logged_out_is_forbidden(): void {
		$this->assertSame( 403, $this->dispatch( $this->linked_user_id )->get_status() );
		$this->assertSame( [], $this->captured );
	}

	public function test_editor_is_forbidden(): void {
		wp_set_current_user( $this->create_user( 'editor' ) );
		$this->captured = [];

		$this->assertSame( 403, $this->dispatch( $this->linked_user_id )->get_status() );
		$this->assertSame( [], $this->captured );
	}

	public function test_user_cannot_verify_their_own_email(): void {
		wp_set_current_user( $this->linked_user_id );

		$this->assertSame( 403, $this->dispatch( $this->linked_user_id )->get_status() );
		$this->assertSame( [], $this->captured );
	}

	public function test_role_granted_workos_verify_email_can_verify(): void {
		add_role( 'workos_test_support', 'WorkOS Support', [ 'read' => true, 'workos_verify_email' => true ] );
		wp_set_current_user( $this->create_user( 'workos_test_support' ) );
		$this->captured = [];

		$this->assertSame( 200, $this->dispatch( $this->linked_user_id )->get_status() );
		$this->assertCount( 1, $this->updates() );
	}

	// ---------------------------------------------------------------------
	// Target checks.
	// ---------------------------------------------------------------------

	public function test_unknown_user_is_404(): void {
		wp_set_current_user( $this->admin_user_id );

		$this->assertSame( 404, $this->dispatch( 999999 )->get_status() );
	}

	public function test_unlinked_user_is_409(): void {
		wp_set_current_user( $this->admin_user_id );

		$response = $this->dispatch( $this->unlinked_user_id );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'workos_user_not_linked', $response->get_data()['code'] ?? '' );
		$this->assertSame( [], $this->captured );
	}

	// ---------------------------------------------------------------------
	// Behavior.
	// ---------------------------------------------------------------------

	public function test_already_verified_is_a_no_op(): void {
		wp_set_current_user( $this->admin_user_id );
		$this->workos_user['body']['email_verified'] = true;

		$response = $this->dispatch( $this->linked_user_id );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['email_verified'] );
		$this->assertTrue( $response->get_data()['already_verified'] );
		$this->assertSame( [], $this->updates(), 'No update when WorkOS already has it verified.' );
		$this->assertSame( [], $this->log_rows() );
	}

	public function test_marks_the_email_verified_and_logs_who_did_it(): void {
		wp_set_current_user( $this->admin_user_id );
		set_transient( 'workos_profile_user_' . md5( 'user_verify_01' ), [ 'email_verified' => false ], 300 );

		$response = $this->dispatch( $this->linked_user_id );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			[
				'ok'               => true,
				'email_verified'   => true,
				'already_verified' => false,
			],
			$response->get_data()
		);

		$updates = $this->updates();
		$this->assertCount( 1, $updates );
		$this->assertStringContainsString( '/user_management/users/user_verify_01', $updates[0]['url'] );
		$this->assertSame( [ 'email_verified' => true ], json_decode( $updates[0]['body'], true ) );

		$rows = $this->log_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( (string) $this->linked_user_id, (string) $rows[0]['user_id'] );
		$this->assertSame( 'user_verify_01', $rows[0]['workos_user_id'] );
		$metadata = json_decode( (string) $rows[0]['metadata'], true );
		$this->assertSame( $this->admin_user_id, $metadata['initiator_id'] ?? null );
		$this->assertSame( 'target@example.test', $metadata['email'] ?? null );

		// The user-edit panel's cached copy is dropped so it shows the change.
		$this->assertFalse( get_transient( 'workos_profile_user_' . md5( 'user_verify_01' ) ) );
	}

	public function test_workos_error_on_update_is_reported(): void {
		wp_set_current_user( $this->admin_user_id );
		$this->update_response = [
			'code' => 422,
			'body' => [ 'message' => 'Email verification could not be updated.' ],
		];

		$response = $this->dispatch( $this->linked_user_id );

		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( 'workos_api_error', $response->get_data()['code'] ?? '' );
		$this->assertStringContainsString( 'could not be updated', (string) ( $response->get_data()['message'] ?? '' ) );
		$this->assertSame( [], $this->log_rows() );
	}

	public function test_workos_error_on_lookup_is_reported(): void {
		wp_set_current_user( $this->admin_user_id );
		$this->workos_user = [
			'code' => 404,
			'body' => [ 'message' => 'User not found.' ],
		];

		$response = $this->dispatch( $this->linked_user_id );

		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( [], $this->updates() );
		$this->assertSame( [], $this->log_rows() );
	}

	// ---------------------------------------------------------------------
	// User-edit panel button.
	// ---------------------------------------------------------------------

	/**
	 * Render the WorkOS panel for the linked user.
	 *
	 * @return string
	 */
	private function render_panel(): string {
		ob_start();
		( new UserProfile() )->render( get_userdata( $this->linked_user_id ) );
		return (string) ob_get_clean();
	}

	public function test_panel_shows_the_button_when_unverified(): void {
		wp_set_current_user( $this->admin_user_id );

		$html = $this->render_panel();

		$this->assertStringContainsString( 'workos-verify-email-trigger', $html );
		$this->assertStringContainsString( 'data-user-id="' . $this->linked_user_id . '"', $html );
	}

	public function test_panel_hides_the_button_when_verified(): void {
		wp_set_current_user( $this->admin_user_id );
		$this->workos_user['body']['email_verified'] = true;

		$html = $this->render_panel();

		$this->assertStringContainsString( 'target@example.test', $html );
		$this->assertStringNotContainsString( 'workos-verify-email-trigger', $html );
	}

	public function test_panel_hides_the_button_without_the_capability(): void {
		// Keeps edit_users (so the panel renders) but loses the action.
		get_role( 'administrator' )->add_cap( 'workos_verify_email', false );

		try {
			wp_set_current_user( $this->admin_user_id );
			$html = $this->render_panel();
		} finally {
			get_role( 'administrator' )->remove_cap( 'workos_verify_email' );
		}

		$this->assertStringContainsString( 'target@example.test', $html );
		$this->assertStringNotContainsString( 'workos-verify-email-trigger', $html );
	}
}
