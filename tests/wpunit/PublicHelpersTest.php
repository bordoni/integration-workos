<?php
/**
 * Tests for the public account helpers: valid access token, password
 * reset and logout URL.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Api\Client;
use WorkOS\Auth\AuthKit\Profile;
use WorkOS\Auth\AuthKit\ProfileRepository;
use WorkOS\User;
use WP_Error;

/**
 * Coverage for `workos_get_valid_access_token()`, `workos_send_password_reset()`
 * and `workos_get_logout_url()` (plus their `WorkOS\User` counterparts).
 *
 * HTTP is mocked at `pre_http_request`; each test queues the responses it
 * expects and asserts on the captured calls.
 */
class PublicHelpersTest extends WPTestCase {

	/**
	 * Captured outbound HTTP calls.
	 *
	 * @var array<int, array{url: string, method: string, body: string}>
	 */
	private array $captured = [];

	/**
	 * Queued HTTP responses keyed by URL fragment.
	 *
	 * @var array<string, array{code: int, body: array}>
	 */
	private array $responses = [];

	/**
	 * Profile repository.
	 *
	 * @var ProfileRepository
	 */
	private ProfileRepository $repository;

	/**
	 * Set up: configure the plugin, seed the default profile, mock HTTP.
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

		$this->reset_rate_limit_buckets();

		$this->repository = new ProfileRepository();
		$this->repository->register_post_type();
		foreach ( $this->repository->all() as $existing ) {
			wp_delete_post( $existing->get_id(), true );
		}
		$this->repository->save(
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

		$this->captured  = [];
		$this->responses = [];
		add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10 );

		foreach ( $this->repository->all() as $existing ) {
			wp_delete_post( $existing->get_id(), true );
		}

		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}workos_activity_log" );

		$this->reset_rate_limit_buckets();
		wp_set_current_user( 0 );

		delete_option( 'workos_production' );
		\WorkOS\Config::set_active_environment( 'staging' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		parent::tearDown();
	}

	/**
	 * HTTP interceptor: records the call and answers with the queued
	 * response for the first matching URL fragment, or `{}` with a 200.
	 *
	 * @param mixed  $preempt Filter input.
	 * @param array  $args    Request args.
	 * @param string $url     Target URL.
	 *
	 * @return array
	 */
	public function intercept_http( $preempt, array $args, string $url ): array {
		$this->captured[] = [
			'url'    => $url,
			'method' => $args['method'] ?? 'GET',
			'body'   => (string) ( $args['body'] ?? '' ),
		];

		foreach ( $this->responses as $fragment => $response ) {
			if ( str_contains( $url, $fragment ) ) {
				return [
					'response' => [
						'code'    => $response['code'],
						'message' => 200 === $response['code'] ? 'OK' : 'Error',
					],
					'body'     => (string) wp_json_encode( $response['body'] ),
				];
			}
		}

		return [
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'body'     => '{}',
		];
	}

	// ------------------------------------------------------------------
	// workos_get_valid_access_token()
	// ------------------------------------------------------------------

	/**
	 * A token that is still good for a while comes back as stored, with
	 * no call to WorkOS.
	 */
	public function test_valid_access_token_returns_stored_token_when_fresh(): void {
		$token   = $this->build_jwt( [ 'sid' => 'session_01', 'exp' => time() + 600 ] );
		$user_id = $this->create_linked_user( $token, 'rt_01' );

		$this->assertSame( $token, workos_get_valid_access_token( $user_id ) );
		$this->assertSame( $token, User::get_valid_access_token( $user_id ) );
		$this->assertEmpty( $this->captured, 'A fresh token must not trigger a refresh.' );
	}

	/**
	 * Omitting the user id targets the current user.
	 */
	public function test_valid_access_token_defaults_to_current_user(): void {
		$token   = $this->build_jwt( [ 'sid' => 'session_01', 'exp' => time() + 600 ] );
		$user_id = $this->create_linked_user( $token, 'rt_01' );
		wp_set_current_user( $user_id );

		$this->assertSame( $token, workos_get_valid_access_token() );
	}

	/**
	 * A WP user with no WorkOS link gets an error and no HTTP call.
	 */
	public function test_valid_access_token_errors_for_unlinked_user(): void {
		$user_id = $this->create_plain_user();

		$result = workos_get_valid_access_token( $user_id );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'workos_user_not_linked', $result->get_error_code() );
		$this->assertEmpty( $this->captured );
	}

	/**
	 * No logged-in user and no id: an error, not someone else's token.
	 */
	public function test_valid_access_token_errors_without_a_user(): void {
		wp_set_current_user( 0 );

		$result = workos_get_valid_access_token();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'workos_user_not_linked', $result->get_error_code() );
	}

	/**
	 * A linked user who signed out (no tokens stored) has no session.
	 */
	public function test_valid_access_token_errors_when_signed_out(): void {
		$user_id = $this->create_plain_user();
		update_user_meta( $user_id, User::META_WORKOS_ID, 'user_signed_out' );

		$result = workos_get_valid_access_token( $user_id );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'workos_no_session', $result->get_error_code() );
		$this->assertEmpty( $this->captured );
	}

	/**
	 * An expired token is exchanged with the stored refresh token, and the
	 * rotated pair is stored the same way the login and session-refresh
	 * flows store it.
	 */
	public function test_valid_access_token_refreshes_expired_token(): void {
		$expired = $this->build_jwt( [ 'sid' => 'session_01', 'exp' => time() - 60 ] );
		$user_id = $this->create_linked_user( $expired, 'rt_original' );

		$fresh = $this->build_jwt( [ 'sid' => 'session_01', 'exp' => time() + 300 ] );
		$this->responses['/user_management/authenticate'] = [
			'code' => 200,
			'body' => [
				'access_token'  => $fresh,
				'refresh_token' => 'rt_rotated',
				'user'          => [ 'id' => 'user_linked' ],
			],
		];

		$result = workos_get_valid_access_token( $user_id );

		$this->assertSame( $fresh, $result );
		$this->assertSame( $fresh, get_user_meta( $user_id, User::META_ACCESS_TOKEN, true ) );
		$this->assertSame( 'rt_rotated', get_user_meta( $user_id, User::META_REFRESH_TOKEN, true ) );
		$this->assertSame( 'session_01', get_user_meta( $user_id, User::META_SESSION_ID, true ) );

		$this->assertCount( 1, $this->captured );
		$this->assertStringStartsWith( Client::get_base_url() . '/user_management/authenticate', $this->captured[0]['url'] );
		$body = json_decode( $this->captured[0]['body'], true );
		$this->assertSame( 'refresh_token', $body['grant_type'] ?? null );
		$this->assertSame( 'rt_original', $body['refresh_token'] ?? null );
	}

	/**
	 * A token inside the leeway window (about to expire) is refreshed too.
	 */
	public function test_valid_access_token_refreshes_token_about_to_expire(): void {
		$expiring = $this->build_jwt( [ 'sid' => 'session_01', 'exp' => time() + 10 ] );
		$user_id  = $this->create_linked_user( $expiring, 'rt_original' );

		$fresh = $this->build_jwt( [ 'sid' => 'session_01', 'exp' => time() + 300 ] );
		$this->responses['/user_management/authenticate'] = [
			'code' => 200,
			'body' => [
				'access_token'  => $fresh,
				'refresh_token' => 'rt_rotated',
			],
		];

		$this->assertSame( $fresh, workos_get_valid_access_token( $user_id ) );
		$this->assertCount( 1, $this->captured );
	}

	/**
	 * When WorkOS rejects the refresh, the caller gets the error and the
	 * stored tokens are left alone.
	 */
	public function test_valid_access_token_returns_error_when_refresh_fails(): void {
		$expired = $this->build_jwt( [ 'sid' => 'session_01', 'exp' => time() - 60 ] );
		$user_id = $this->create_linked_user( $expired, 'rt_revoked' );

		$this->responses['/user_management/authenticate'] = [
			'code' => 400,
			'body' => [
				'error'             => 'invalid_grant',
				'error_description' => 'Refresh token is invalid.',
			],
		];

		$result = workos_get_valid_access_token( $user_id );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'workos_api_error', $result->get_error_code() );
		$this->assertSame( $expired, get_user_meta( $user_id, User::META_ACCESS_TOKEN, true ) );
		$this->assertSame( 'rt_revoked', get_user_meta( $user_id, User::META_REFRESH_TOKEN, true ) );
	}

	/**
	 * If another request rotated the tokens while this refresh was in
	 * flight (so WorkOS refused the spent refresh token), the fresh token
	 * that request stored is returned instead of the error.
	 */
	public function test_valid_access_token_uses_token_rotated_by_concurrent_refresh(): void {
		$expired = $this->build_jwt( [ 'sid' => 'session_01', 'exp' => time() - 60 ] );
		$user_id = $this->create_linked_user( $expired, 'rt_spent' );
		$fresh   = $this->build_jwt( [ 'sid' => 'session_01', 'exp' => time() + 300 ] );

		$this->responses['/user_management/authenticate'] = [
			'code' => 400,
			'body' => [ 'error' => 'invalid_grant' ],
		];
		$rotate = static function ( $preempt ) use ( $user_id, $fresh ) {
			update_user_meta( $user_id, User::META_ACCESS_TOKEN, $fresh );
			return $preempt;
		};
		add_filter( 'pre_http_request', $rotate, 5 );

		$result = workos_get_valid_access_token( $user_id );

		remove_filter( 'pre_http_request', $rotate, 5 );
		$this->assertSame( $fresh, $result );
	}

	/**
	 * A refresh response without an access token is treated as a failure.
	 */
	public function test_valid_access_token_errors_on_empty_refresh_response(): void {
		$expired = $this->build_jwt( [ 'sid' => 'session_01', 'exp' => time() - 60 ] );
		$user_id = $this->create_linked_user( $expired, 'rt_original' );

		$result = workos_get_valid_access_token( $user_id );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'workos_refresh_failed', $result->get_error_code() );
		$this->assertSame( $expired, get_user_meta( $user_id, User::META_ACCESS_TOKEN, true ) );
	}

	/**
	 * An expired token with no refresh token to exchange is an error.
	 */
	public function test_valid_access_token_errors_when_expired_without_refresh_token(): void {
		$expired = $this->build_jwt( [ 'sid' => 'session_01', 'exp' => time() - 60 ] );
		$user_id = $this->create_linked_user( $expired, '' );

		$result = workos_get_valid_access_token( $user_id );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'workos_no_session', $result->get_error_code() );
		$this->assertEmpty( $this->captured );
	}

	/**
	 * The existing `workos_get_access_token()` keeps returning the stored
	 * token as-is, expired or not, without calling WorkOS.
	 */
	public function test_existing_get_access_token_is_unchanged(): void {
		$expired = $this->build_jwt( [ 'sid' => 'session_01', 'exp' => time() - 60 ] );
		$user_id = $this->create_linked_user( $expired, 'rt_original' );

		$this->assertSame( $expired, workos_get_access_token( $user_id ) );
		$this->assertEmpty( $this->captured );
	}

	// ------------------------------------------------------------------
	// workos_send_password_reset()
	// ------------------------------------------------------------------

	/**
	 * Happy path: the reset goes out through the same call the admin
	 * endpoint makes, and the activity log gets the same row.
	 */
	public function test_send_password_reset_sends_and_logs(): void {
		global $wpdb;

		$user_id = $this->create_linked_user( '', '' );
		$email   = get_userdata( $user_id )->user_email;
		wp_set_current_user( $user_id );

		$result = workos_send_password_reset( $user_id, [ 'redirect_url' => home_url( '/account' ) ] );

		$this->assertTrue( $result );

		$send = $this->find_call( '/user_management/password_reset/send' );
		$this->assertNotNull( $send );
		$body = json_decode( $send['body'], true );
		$this->assertSame( $email, $body['email'] ?? null );
		$this->assertStringContainsString( '/workos/login/default/', (string) ( $body['password_reset_url'] ?? '' ) );
		$this->assertStringContainsString( 'redirect_to=', (string) ( $body['password_reset_url'] ?? '' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}workos_activity_log WHERE event_type = %s",
				'password_reset.admin_sent'
			),
			ARRAY_A
		);
		$this->assertCount( 1, $rows );
		$metadata = json_decode( (string) $rows[0]['metadata'], true );
		$this->assertSame( home_url( '/account' ), $metadata['redirect_url'] ?? null );
		$this->assertTrue( $metadata['self_service'] ?? null );
	}

	/**
	 * A user with no WorkOS link gets the endpoint's error, and nothing is sent.
	 */
	public function test_send_password_reset_errors_for_unlinked_user(): void {
		$user_id = $this->create_plain_user();

		$result = workos_send_password_reset( $user_id );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'workos_user_not_linked', $result->get_error_code() );
		$this->assertNull( $this->find_call( '/user_management/password_reset/send' ) );
	}

	/**
	 * An unknown WP user id is an error.
	 */
	public function test_send_password_reset_errors_for_unknown_user(): void {
		$result = workos_send_password_reset( 999999 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'workos_user_not_found', $result->get_error_code() );
	}

	/**
	 * An off-site redirect_url is replaced by RedirectValidator's fallback.
	 */
	public function test_send_password_reset_validates_redirect_url(): void {
		$user_id = $this->create_linked_user( '', '' );

		$result = workos_send_password_reset( $user_id, [ 'redirect_url' => 'https://evil.example/landing' ] );

		$this->assertTrue( $result );
		$send = $this->find_call( '/user_management/password_reset/send' );
		$this->assertNotNull( $send );
		$body = json_decode( $send['body'], true );
		$this->assertStringNotContainsString( 'evil.example', (string) ( $body['password_reset_url'] ?? '' ) );
	}

	/**
	 * An unknown profile slug is rejected the same way the endpoint rejects it.
	 */
	public function test_send_password_reset_rejects_unknown_profile(): void {
		$user_id = $this->create_linked_user( '', '' );

		$result = workos_send_password_reset( $user_id, [ 'profile' => 'no-such-profile' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'workos_profile_not_found', $result->get_error_code() );
	}

	/**
	 * The helper shares the endpoint's per-user bucket: after 5 sends in a
	 * minute the sixth is refused.
	 */
	public function test_send_password_reset_is_rate_limited(): void {
		$user_id = $this->create_linked_user( '', '' );

		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertTrue( workos_send_password_reset( $user_id ) );
		}

		$result = workos_send_password_reset( $user_id );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'workos_rate_limited', $result->get_error_code() );
	}

	// ------------------------------------------------------------------
	// workos_get_logout_url()
	// ------------------------------------------------------------------

	/**
	 * With a WorkOS session, the URL is the WorkOS logout endpoint for it.
	 */
	public function test_logout_url_uses_workos_session(): void {
		$token   = $this->build_jwt( [ 'sid' => 'session_abc', 'exp' => time() + 600 ] );
		$user_id = $this->create_linked_user( $token, 'rt_01' );

		$url = workos_get_logout_url( $user_id, home_url( '/bye' ) );

		$this->assertStringStartsWith( Client::get_base_url() . '/user_management/sessions/logout?', $url );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame( 'session_abc', $query['session_id'] ?? null );
		$this->assertSame( home_url( '/bye' ), $query['return_to'] ?? null );
		$this->assertSame( $url, User::get_logout_url( $user_id, home_url( '/bye' ) ) );
		$this->assertEmpty( $this->captured, 'Building the URL must not call WorkOS.' );
	}

	/**
	 * Without return_to, only the session id is sent.
	 */
	public function test_logout_url_omits_empty_return_to(): void {
		$token   = $this->build_jwt( [ 'sid' => 'session_abc', 'exp' => time() + 600 ] );
		$user_id = $this->create_linked_user( $token, 'rt_01' );
		wp_set_current_user( $user_id );

		$url = workos_get_logout_url();

		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame( [ 'session_id' => 'session_abc' ], $query );
	}

	/**
	 * A user with no WorkOS link gets the WordPress logout URL.
	 */
	public function test_logout_url_falls_back_for_unlinked_user(): void {
		$user_id = $this->create_plain_user();

		$this->assertSame( wp_logout_url( home_url( '/bye' ) ), workos_get_logout_url( $user_id, home_url( '/bye' ) ) );
		$this->assertSame( wp_logout_url(), workos_get_logout_url( $user_id ) );
	}

	/**
	 * A linked user with no current session also gets the WordPress logout URL.
	 */
	public function test_logout_url_falls_back_when_signed_out(): void {
		$user_id = $this->create_plain_user();
		update_user_meta( $user_id, User::META_WORKOS_ID, 'user_signed_out' );

		$this->assertSame( wp_logout_url(), workos_get_logout_url( $user_id ) );
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Create a linked WP user, optionally with stored tokens. The session id
	 * is stored through Login::store_tokens() just like a real sign-in.
	 *
	 * @param string $access_token  Access token to store, or ''.
	 * @param string $refresh_token Refresh token to store, or ''.
	 *
	 * @return int
	 */
	private function create_linked_user( string $access_token, string $refresh_token ): int {
		$user_id = $this->create_plain_user();
		update_user_meta( $user_id, User::META_WORKOS_ID, 'user_linked' );

		\WorkOS\Auth\Login::store_tokens(
			$user_id,
			[
				'access_token'  => $access_token,
				'refresh_token' => $refresh_token,
			]
		);

		return $user_id;
	}

	/**
	 * Create a WP user with no WorkOS link. Calls made by the plugin's own
	 * user_register hooks are dropped so tests only see what they trigger.
	 *
	 * @return int
	 */
	private function create_plain_user(): int {
		$user_id = self::factory()->user->create(
			[ 'user_email' => 'helpers-' . wp_generate_password( 8, false ) . '@example.test' ]
		);

		$this->captured = [];

		return $user_id;
	}

	/**
	 * Build an unsigned JWT with the given payload.
	 *
	 * @param array $payload Claims.
	 *
	 * @return string
	 */
	private function build_jwt( array $payload ): string {
		$header = rtrim( strtr( base64_encode( '{"alg":"RS256","typ":"JWT","kid":"k1"}' ), '+/', '-_' ), '=' );
		$body   = rtrim( strtr( base64_encode( (string) wp_json_encode( $payload ) ), '+/', '-_' ), '=' );

		return "{$header}.{$body}.sig";
	}

	/**
	 * Find the first captured call whose URL contains a fragment.
	 *
	 * @param string $fragment URL fragment.
	 *
	 * @return array|null
	 */
	private function find_call( string $fragment ): ?array {
		foreach ( $this->captured as $call ) {
			if ( str_contains( $call['url'], $fragment ) ) {
				return $call;
			}
		}

		return null;
	}

	/**
	 * Clear rate-limit buckets so per-user windows don't leak across tests.
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
