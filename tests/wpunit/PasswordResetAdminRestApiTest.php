<?php
/**
 * Tests for the admin-triggered password-reset REST endpoint.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Auth\AuthKit\Profile;
use WorkOS\Auth\AuthKit\ProfileRepository;
use WorkOS\Auth\AuthKit\RateLimiter;
use WorkOS\Auth\PasswordResetAdmin\RedirectValidator;
use WorkOS\Auth\PasswordResetAdmin\RestApi;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Coverage for POST /workos/v1/admin/users/{id}/password-reset.
 *
 * The endpoint mixes capability checks, rate limiting, redirect-URL
 * validation, and an outbound WorkOS API call — so each surface gets a
 * focused test. HTTP is mocked at `pre_http_request` so no real network
 * traffic leaves the suite.
 */
class PasswordResetAdminRestApiTest extends WPTestCase {

	/**
	 * Profile repository.
	 *
	 * @var ProfileRepository
	 */
	private ProfileRepository $repository;

	/**
	 * Captured HTTP calls.
	 *
	 * @var array<int, array{url: string, method: string, body: string, headers: array}>
	 */
	private array $captured = [];

	/**
	 * Linked user (`_workos_user_id` set).
	 *
	 * @var int
	 */
	private int $linked_user_id = 0;

	/**
	 * Unlinked WP user (no WorkOS link).
	 *
	 * @var int
	 */
	private int $unlinked_user_id = 0;

	/**
	 * Admin user used as the request initiator.
	 *
	 * @var int
	 */
	private int $admin_user_id = 0;

	/**
	 * Linked user's email (kept around for `email_hint` mask assertions).
	 *
	 * @var string
	 */
	private string $linked_email = '';

	/**
	 * Boot a single profile + register routes.
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
				// EventLogger reads `enable_activity_log` via workos()->option(),
				// which lives inside the env-scoped option array.
				'enable_activity_log' => true,
			]
		);
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		// RateLimiter buckets are transient-backed and survive between
		// tests unless we explicitly clear them — burning through the
		// per-IP window once leaves later tests stuck at 429.
		$this->reset_rate_limit_buckets();

		$this->repository = new ProfileRepository();
		$this->repository->register_post_type();

		foreach ( $this->repository->all() as $existing ) {
			wp_delete_post( $existing->get_id(), true );
		}

		$saved = $this->repository->save(
			Profile::from_array(
				[
					'slug'                => 'default',
					'title'               => 'Default',
					'methods'             => [ Profile::METHOD_PASSWORD ],
					'password_reset_flow' => true,
					'post_login_redirect' => '',
				]
			)
		);
		$this->assertInstanceOf( Profile::class, $saved );

		$rest_api = new RestApi(
			$this->repository,
			new RateLimiter(),
			new RedirectValidator()
		);

		add_action( 'rest_api_init', [ $rest_api, 'register_routes' ] );
		$server = rest_get_server();
		do_action( 'rest_api_init', $server );

		add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );

		// WorkOS UserSync hooks `user_register` and can return errors from
		// nested HTTP calls; create users via wp_insert_user so a sync
		// failure surfaces here rather than corrupting the test fixture.
		// Unique per-run suffix so a leftover row from a crashed prior
		// invocation (which can persist past the WPTestCase rollback when
		// setUp itself errored) doesn't collide with this run's fixture.
		$suffix                 = uniqid( 'pra_', true );
		$this->linked_email     = 'linked-' . $suffix . '@example.test';
		$this->linked_user_id   = $this->create_user( $this->linked_email, 'subscriber' );
		$this->unlinked_user_id = $this->create_user( 'unlinked-' . $suffix . '@example.test', 'subscriber' );
		$this->admin_user_id    = $this->create_user( 'admin-' . $suffix . '@example.test', 'administrator' );

		update_user_meta( $this->linked_user_id, '_workos_user_id', 'user_linked_01' );

		$this->captured = [];
	}

	/**
	 * Test-only user factory that strips the user_register/login_redirect
	 * hooks the WorkOS plugin wires onto user creation. Returns an int so
	 * the typed properties never see a WP_Error.
	 *
	 * @param string $email Unique email.
	 * @param string $role  WordPress role slug.
	 *
	 * @return int
	 */
	private function create_user( string $email, string $role ): int {
		$user_id = wp_insert_user(
			[
				'user_login' => 'tu_' . wp_generate_password( 8, false ),
				'user_pass'  => wp_generate_password(),
				'user_email' => $email,
				'role'       => $role,
			]
		);

		$this->assertIsInt( $user_id, 'wp_insert_user must return an int user id.' );

		return $user_id;
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10 );
		$this->captured = [];

		foreach ( $this->repository->all() as $existing ) {
			wp_delete_post( $existing->get_id(), true );
		}

		// Clean activity log so per-test row counts stay isolated.
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}workos_activity_log" );

		wp_set_current_user( 0 );

		delete_option( 'workos_enable_activity_log' );
		delete_option( 'workos_production' );
		\WorkOS\Config::set_active_environment( 'staging' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		parent::tearDown();
	}

	/**
	 * HTTP interceptor — captures the WorkOS send_password_reset call.
	 *
	 * @param mixed  $preempt Filter input.
	 * @param array  $args    Request args.
	 * @param string $url     Target URL.
	 *
	 * @return array
	 */
	public function intercept_http( $preempt, array $args, string $url ): array {
		$this->captured[] = [
			'url'     => $url,
			'method'  => $args['method'] ?? 'GET',
			'body'    => (string) ( $args['body'] ?? '' ),
			'headers' => $args['headers'] ?? [],
		];

		return [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'body'     => '{}',
		];
	}

	/**
	 * Dispatch a POST against the admin endpoint with the WP REST nonce.
	 */
	private function dispatch( int $target_id, array $body = [] ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/workos/v1/admin/users/' . $target_id . '/password-reset' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * A subscriber acting on another user is forbidden.
	 */
	public function test_forbidden_without_edit_user_on_target(): void {
		wp_set_current_user( $this->unlinked_user_id );

		$response = $this->dispatch( $this->linked_user_id );

		$this->assertSame( 403, $response->get_status() );
		$this->assertEmpty( $this->captured, 'No HTTP call should be made when denied.' );
	}

	/**
	 * 404 when the target user does not exist.
	 */
	public function test_returns_404_for_unknown_user(): void {
		wp_set_current_user( $this->admin_user_id );

		$response = $this->dispatch( 999999 );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * 409 when the WP user has no linked `_workos_user_id` meta.
	 */
	public function test_returns_409_when_user_not_linked(): void {
		wp_set_current_user( $this->admin_user_id );

		$response = $this->dispatch( $this->unlinked_user_id );

		$this->assertSame( 409, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'workos_user_not_linked', $data['code'] ?? '' );
	}

	/**
	 * Happy path — an admin triggering on a linked user. The WorkOS API is
	 * called, the response masks the email, and the reset URL points at
	 * the AuthKit frontend route with `redirect_to` appended.
	 */
	public function test_admin_can_send_reset_for_linked_user(): void {
		wp_set_current_user( $this->admin_user_id );

		$response = $this->dispatch(
			$this->linked_user_id,
			[ 'redirect_url' => home_url( '/welcome' ) ]
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['ok'] ?? false );

		// Email hint never leaks the full address.
		$this->assertStringContainsString( '@', (string) ( $data['email_hint'] ?? '' ) );
		$this->assertStringNotContainsString( $this->linked_email, (string) $data['email_hint'] );
		$this->assertStringContainsString( '•', (string) $data['email_hint'] );

		// Validated redirect_url is echoed back.
		$this->assertSame( home_url( '/welcome' ), $data['redirect_url'] ?? '' );

		// WorkOS API got the send call with the right URL shape.
		$send = $this->find_send_password_reset_call();
		$this->assertNotNull( $send );
		$body = json_decode( $send['body'], true );
		$this->assertSame( $this->linked_email, $body['email'] ?? null );
		$this->assertStringContainsString( '/workos/login/default/', (string) ( $body['password_reset_url'] ?? '' ) );
		$this->assertStringContainsString( 'redirect_to=', (string) ( $body['password_reset_url'] ?? '' ) );
	}

	/**
	 * Self-service mode — a logged-in subscriber triggering on their own
	 * user ID. `edit_user($self)` is true by default, so the same endpoint
	 * handles self-service without a separate route.
	 */
	public function test_self_service_path_succeeds(): void {
		// Make the linked user (subscriber by default) the request initiator.
		wp_set_current_user( $this->linked_user_id );

		$response = $this->dispatch( $this->linked_user_id );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotNull( $this->find_send_password_reset_call() );
	}

	/**
	 * Off-site redirect_url falls back to the profile default + reaches
	 * WorkOS with a same-host URL.
	 */
	public function test_off_site_redirect_url_falls_back_to_safe_default(): void {
		wp_set_current_user( $this->admin_user_id );

		$response = $this->dispatch(
			$this->linked_user_id,
			[ 'redirect_url' => 'https://evil.example/landing' ]
		);

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( home_url( '/' ), $data['redirect_url'] ?? null );

		$send = $this->find_send_password_reset_call();
		$this->assertNotNull( $send );
		$body = json_decode( $send['body'], true );
		$this->assertStringNotContainsString( 'evil.example', (string) ( $body['password_reset_url'] ?? '' ) );
	}

	/**
	 * 400 when the resolved profile has password reset disabled.
	 */
	public function test_returns_400_when_password_reset_disabled(): void {
		// Replace the default profile with one that has reset disabled.
		foreach ( $this->repository->all() as $existing ) {
			wp_delete_post( $existing->get_id(), true );
		}
		$this->repository->save(
			Profile::from_array(
				[
					'slug'                => 'default',
					'methods'             => [ Profile::METHOD_PASSWORD ],
					'password_reset_flow' => false,
				]
			)
		);

		wp_set_current_user( $this->admin_user_id );

		$response = $this->dispatch( $this->linked_user_id );

		$this->assertSame( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'workos_reset_disabled', $data['code'] ?? '' );
	}

	/**
	 * After the per-target rate-limit window is consumed the endpoint
	 * surfaces 429.
	 */
	public function test_rate_limit_fires_after_threshold(): void {
		wp_set_current_user( $this->admin_user_id );

		// Per-target threshold is 5 attempts. Six rapid calls = first 5
		// succeed, sixth gets the limit.
		$last = null;
		for ( $i = 0; $i < 6; $i++ ) {
			$last = $this->dispatch( $this->linked_user_id );
		}

		$this->assertNotNull( $last );
		$this->assertSame( 429, $last->get_status() );
	}

	/**
	 * Successful sends create a `password_reset.admin_sent` activity log
	 * entry with the initiator + target user metadata.
	 */
	public function test_logs_activity_event_on_success(): void {
		global $wpdb;

		wp_set_current_user( $this->admin_user_id );

		$response = $this->dispatch(
			$this->linked_user_id,
			[ 'redirect_url' => home_url( '/welcome' ) ]
		);
		$this->assertSame( 200, $response->get_status() );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}workos_activity_log WHERE event_type = %s",
				'password_reset.admin_sent'
			),
			ARRAY_A
		);

		$this->assertCount( 1, $rows );
		$this->assertSame( (string) $this->linked_user_id, (string) $rows[0]['user_id'] );
		$this->assertSame( $this->linked_email, $rows[0]['user_email'] );
		$this->assertSame( 'user_linked_01', $rows[0]['workos_user_id'] );

		$metadata = json_decode( (string) $rows[0]['metadata'], true );
		$this->assertSame( 'default', $metadata['profile'] ?? null );
		$this->assertSame( home_url( '/welcome' ), $metadata['redirect_url'] ?? null );
		$this->assertSame( $this->admin_user_id, $metadata['initiator_id'] ?? null );
		$this->assertFalse( $metadata['self_service'] ?? null );
	}

	/**
	 * Self-service activity log entries record `self_service => true`.
	 */
	public function test_log_marks_self_service_attempts(): void {
		global $wpdb;

		wp_set_current_user( $this->linked_user_id );

		$response = $this->dispatch( $this->linked_user_id );
		$this->assertSame( 200, $response->get_status() );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}workos_activity_log WHERE event_type = %s",
				'password_reset.admin_sent'
			),
			ARRAY_A
		);

		$this->assertCount( 1, $rows );
		$metadata = json_decode( (string) $rows[0]['metadata'], true );
		$this->assertTrue( $metadata['self_service'] ?? null );
	}

	/**
	 * Helper — locate the captured outbound call to send_password_reset.
	 *
	 * @return array|null
	 */
	private function find_send_password_reset_call(): ?array {
		foreach ( $this->captured as $call ) {
			if ( str_contains( $call['url'], '/user_management/password_reset/send' ) ) {
				return $call;
			}
		}
		return null;
	}

	/**
	 * Clear the RateLimiter's transient buckets so the per-IP window
	 * doesn't carry state across tests in the same process.
	 *
	 * @return void
	 */
	private function reset_rate_limit_buckets(): void {
		global $wpdb;
		$wpdb->query(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_workos_rl_%' OR option_name LIKE '_transient_timeout_workos_rl_%'"
		);

		if ( wp_using_ext_object_cache() ) {
			wp_cache_flush();
		}
	}
}
