<?php
/**
 * Tests for the REST Auth magic-code and session endpoints.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Auth\AuthKit\LoginCompleter;
use WorkOS\Auth\AuthKit\Nonce;
use WorkOS\Auth\AuthKit\Profile;
use WP_REST_Request;
use WP_REST_Response;
use WorkOS\Auth\AuthKit\ProfileRepository;
use WorkOS\Auth\AuthKit\Radar;
use WorkOS\Auth\AuthKit\RateLimiter;
use WorkOS\REST\Auth\MagicCode;
use WorkOS\REST\Auth\Session;

/**
 * REST dispatch coverage for /auth/magic/* and /auth/{nonce,session}/*.
 */
class AuthKitRestMagicSessionTest extends WPTestCase {

	/**
	 * Profile repository.
	 *
	 * @var ProfileRepository
	 */
	private ProfileRepository $repository;

	/**
	 * Nonce helper.
	 *
	 * @var Nonce
	 */
	private Nonce $nonce;

	/**
	 * Members profile.
	 *
	 * @var Profile
	 */
	private Profile $profile;

	/**
	 * Captured outbound HTTP requests.
	 *
	 * @var array
	 */
	private array $captured = [];

	/**
	 * Next mocked WorkOS response.
	 *
	 * @var array
	 */
	private array $next_workos = [
		'response' => [ 'code' => 200, 'message' => 'OK' ],
		'body'     => '{}',
	];

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();

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

		$this->repository = new ProfileRepository();
		$this->repository->register_post_type();
		foreach ( $this->repository->all() as $profile ) {
			wp_delete_post( $profile->get_id(), true );
		}

		$saved = $this->repository->save(
			Profile::from_array(
				[
					'slug'    => 'members',
					'title'   => 'Members',
					'methods' => [ Profile::METHOD_MAGIC_CODE ],
				]
			)
		);
		$this->assertInstanceOf( Profile::class, $saved );
		$this->profile = $saved;

		$this->nonce = new Nonce();

		$radar        = new Radar();
		$rate_limiter = new RateLimiter();
		$completer    = new LoginCompleter( false );

		$magic   = new MagicCode( $this->repository, $this->nonce, $radar, $rate_limiter, $completer );
		$session = new Session( $this->repository, $this->nonce, $radar, $rate_limiter, $completer );

		add_action( 'rest_api_init', [ $magic, 'register_routes' ] );
		add_action( 'rest_api_init', [ $session, 'register_routes' ] );

		$server = rest_get_server();
		do_action( 'rest_api_init', $server );

		add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );

		wp_set_current_user( 0 );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10 );
		$this->captured = [];

		foreach ( $this->repository->all() as $profile ) {
			wp_delete_post( $profile->get_id(), true );
		}

		wp_set_current_user( 0 );
		delete_option( 'workos_production' );
		\WorkOS\Config::set_active_environment( 'staging' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		parent::tearDown();
	}

	/**
	 * Capture HTTP.
	 */
	public function intercept_http( $preempt, array $args, string $url ): array {
		$this->captured[] = [
			'url'     => $url,
			'method'  => $args['method'] ?? 'GET',
			'body'    => $args['body'] ?? '',
			'headers' => $args['headers'] ?? [],
		];
		return $this->next_workos;
	}

	/**
	 * Helper: dispatch with nonce.
	 */
	private function dispatch_with_nonce( string $method, string $route, array $body = [], string $profile_slug = 'members' ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', $this->nonce->mint( $profile_slug ) );
		$request->set_body( wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $request );
	}

	// ------------------------------- /auth/nonce -------------------------------

	public function test_nonce_returns_nonce_and_radar_key(): void {
		$response = rest_get_server()->dispatch(
			new WP_REST_Request( 'GET', '/workos/v1/auth/nonce' )
		);

		// Missing profile => 400.
		$this->assertSame( 400, $response->get_status() );

		$request = new WP_REST_Request( 'GET', '/workos/v1/auth/nonce' );
		$request->set_param( 'profile', 'members' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertNotEmpty( $data['nonce'] );
		$this->assertArrayHasKey( 'radar_site_key', $data );
	}

	// ------------------------------- /auth/magic/send --------------------------

	public function test_magic_send_returns_200_regardless_of_email(): void {
		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/magic/send',
			[ 'profile' => 'members', 'email' => 'ghost@nowhere.test' ]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['ok'] );

		$send_calls = array_filter(
			$this->captured,
			static fn( array $c ) => str_contains( $c['url'], '/user_management/magic_auth/send' )
		);
		$this->assertNotEmpty( $send_calls );
	}

	public function test_magic_send_rejects_invalid_email(): void {
		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/magic/send',
			[ 'profile' => 'members', 'email' => 'not-an-email' ]
		);

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_magic_send_rejects_when_method_disabled(): void {
		$pw_only = $this->repository->save(
			Profile::from_array(
				[
					'slug'    => 'pwonly',
					'title'   => 'PWOnly',
					'methods' => [ Profile::METHOD_PASSWORD ],
				]
			)
		);
		$this->assertInstanceOf( Profile::class, $pw_only );

		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/magic/send',
			[ 'profile' => 'pwonly', 'email' => 'a@b.c' ],
			'pwonly'
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'workos_authkit_method_disabled', $response->get_data()['code'] );
	}

	// ------------------------------- /auth/magic/verify ------------------------

	public function test_magic_verify_completes_login_on_success(): void {
		$this->next_workos['body'] = wp_json_encode(
			[
				'access_token'  => 'eyJhbGciOi.eyJzdWIiOiJ1c2VyX20xIiwiZXhwIjo5OTk5OTk5OTk5fQ.sig',
				'refresh_token' => 'rt_magic',
				'user'          => [
					'id'             => 'user_m1',
					'email'          => 'alice@example.com',
					'email_verified' => true,
				],
			]
		);

		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/magic/verify',
			[ 'profile' => 'members', 'email' => 'alice@example.com', 'code' => '123456' ]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'alice@example.com', $response->get_data()['user']['email'] );

		$authenticate_calls = array_filter(
			$this->captured,
			static fn( array $c ) => str_contains( $c['url'], '/user_management/authenticate' )
		);
		$this->assertNotEmpty( $authenticate_calls );

		$body = json_decode( (string) array_values( $authenticate_calls )[0]['body'], true );
		$this->assertSame( 'urn:workos:oauth:grant-type:magic-auth:code', $body['grant_type'] );
		$this->assertSame( '123456', $body['code'] );
	}

	// ------------------------------- /auth/session/refresh ---------------------

	public function test_refresh_rejects_logged_out_user(): void {
		$response = rest_get_server()->dispatch(
			new WP_REST_Request( 'POST', '/workos/v1/auth/session/refresh' )
		);

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'workos_authkit_not_logged_in', $response->get_data()['code'] );
	}

	public function test_refresh_rotates_tokens_for_logged_in_user(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		update_user_meta( $user_id, '_workos_refresh_token', 'rt_original' );

		$new_jwt = 'eyJhbGciOi.' . rtrim( strtr( base64_encode( wp_json_encode( [ 'sub' => 'user_refreshed', 'exp' => time() + 600 ] ) ), '+/', '-_' ), '=' ) . '.sig';

		$this->next_workos['body'] = wp_json_encode(
			[
				'access_token'  => $new_jwt,
				'refresh_token' => 'rt_rotated',
				'user'          => [ 'id' => 'user_refreshed' ],
			]
		);

		$response = rest_get_server()->dispatch(
			new WP_REST_Request( 'POST', '/workos/v1/auth/session/refresh' )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['ok'] );
		$this->assertIsInt( $response->get_data()['expires_at'] );
		$this->assertSame( 'rt_rotated', get_user_meta( $user_id, '_workos_refresh_token', true ) );
	}

	public function test_refresh_returns_409_when_no_refresh_token(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$response = rest_get_server()->dispatch(
			new WP_REST_Request( 'POST', '/workos/v1/auth/session/refresh' )
		);

		$this->assertSame( 409, $response->get_status() );
	}

	// ------------------------------- /auth/session/logout ----------------------

	public function test_logout_succeeds_even_when_not_logged_in(): void {
		$response = rest_get_server()->dispatch(
			new WP_REST_Request( 'POST', '/workos/v1/auth/session/logout' )
		);

		$this->assertSame( 200, $response->get_status() );
	}
}
