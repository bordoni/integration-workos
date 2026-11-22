<?php
/**
 * Public contract: meta keys, error shapes, REST routes, query vars and handles.
 *
 * The Software Portal reads these with raw string literals (meta keys,
 * route paths, error codes), so a rename here breaks it silently.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\App;
use WorkOS\Config;
use WorkOS\Options\Production;
use WorkOS\Sync\UserSync;
use WP_REST_Request;

/**
 * Data, error and route shapes the Portal depends on.
 *
 * @group public-contract
 */
class PublicContractDataTest extends WPTestCase {

	/**
	 * Canned WorkOS response for the next API call: [ status, body ].
	 *
	 * @var array{0:int,1:array}
	 */
	private array $api_response = [ 200, [] ];

	/**
	 * Callback run inside the next `POST /user_management/users` call.
	 *
	 * @var callable|null
	 */
	private $during_create = null;

	public function setUp(): void {
		parent::setUp();

		$this->api_response  = [ 200, [] ];
		$this->during_create = null;

		// Keep factory-created users from being pushed to WorkOS.
		remove_all_actions( 'user_register' );
		add_filter( 'pre_http_request', [ $this, 'fake_workos_api' ], 10, 3 );
		add_filter( 'pre_wp_mail', '__return_true' );
	}

	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'fake_workos_api' ], 10 );
		remove_filter( 'pre_wp_mail', '__return_true' );
		wp_set_current_user( 0 );

		delete_option( 'workos_production' );
		Config::set_active_environment( 'staging' );
		App::container()->get( Production::class )->reset();

		parent::tearDown();
	}

	/**
	 * Configure the plugin so `workos()->is_enabled()` is true.
	 */
	private function enable_plugin(): void {
		Config::set_active_environment( 'production' );
		update_option(
			'workos_production',
			[
				'api_key'              => 'sk_test_contract',
				'client_id'            => 'client_contract',
				'environment_id'       => 'environment_contract',
				'change_email_enabled' => true,
			]
		);
		App::container()->get( Production::class )->reset();
	}

	/**
	 * Fake the WorkOS API.
	 *
	 * @param false|array $preempt Pre-filtered response.
	 * @param array       $args    Request args.
	 * @param string      $url     Request URL.
	 *
	 * @return false|array
	 */
	public function fake_workos_api( $preempt, array $args, string $url ) {
		if ( false === strpos( $url, 'api.workos.com' ) ) {
			return $preempt;
		}

		$is_create = 'POST' === ( $args['method'] ?? 'GET' ) && false !== strpos( $url, '/user_management/users' ) && false === strpos( $url, '/user_management/users/' );
		if ( $is_create && null !== $this->during_create ) {
			$during              = $this->during_create;
			$this->during_create = null;
			$during();
		}

		[ $status, $body ] = $this->api_response;

		return [
			'headers'  => [],
			'body'     => wp_json_encode( $body ),
			'response' => [ 'code' => $status, 'message' => 'Fake' ],
			'cookies'  => [],
		];
	}

	/**
	 * Dispatch a REST request with a JSON body.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Route path.
	 * @param array  $body   JSON body.
	 *
	 * @return \WP_REST_Response
	 */
	private function dispatch( string $method, string $path, array $body = [] ): \WP_REST_Response {
		$request = new WP_REST_Request( $method, $path );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );

		return rest_get_server()->dispatch( $request );
	}

	// -------------------------------------------------- sync_existing_user

	public function test_sync_existing_user_not_configured(): void {
		// Arrange: an environment with no credentials, whatever earlier tests cached.
		Config::set_active_environment( 'production' );
		delete_option( 'workos_production' );
		App::container()->get( Production::class )->reset();
		$this->assertFalse( workos()->is_enabled() );
		$user_id = self::factory()->user->create();

		// Act.
		$result = UserSync::sync_existing_user( $user_id );

		// Assert.
		$this->assertWPError( $result );
		$this->assertSame( 'workos_not_configured', $result->get_error_code() );
	}

	public function test_sync_existing_user_already_linked(): void {
		// Arrange.
		$this->enable_plugin();
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, '_workos_user_id', 'user_contract_linked' );

		// Act.
		$result = UserSync::sync_existing_user( $user_id );

		// Assert.
		$this->assertWPError( $result );
		$this->assertSame( 'workos_already_linked', $result->get_error_code() );
	}

	public function test_sync_existing_user_user_not_found(): void {
		// Arrange.
		$this->enable_plugin();

		// Act.
		$result = UserSync::sync_existing_user( PHP_INT_MAX );

		// Assert.
		$this->assertWPError( $result );
		$this->assertSame( 'workos_user_not_found', $result->get_error_code() );
	}

	public function test_sync_existing_user_in_progress(): void {
		// Arrange: a second sync starts while the first is talking to WorkOS.
		$this->enable_plugin();
		$first               = self::factory()->user->create();
		$second              = self::factory()->user->create();
		$nested              = null;
		$this->during_create = static function () use ( $second, &$nested ) {
			$nested = UserSync::sync_existing_user( $second );
		};
		$this->api_response  = [ 200, [ 'id' => 'user_contract_progress', 'email' => 'progress@example.test' ] ];

		// Act.
		UserSync::sync_existing_user( $first );

		// Assert.
		$this->assertWPError( $nested );
		$this->assertSame( 'workos_sync_in_progress', $nested->get_error_code() );
	}

	public function test_sync_existing_user_api_error_data_shape(): void {
		// Arrange: the Portal reads status, body.code and body.errors[*].code.
		$this->enable_plugin();
		$user_id            = self::factory()->user->create();
		$this->api_response = [
			422,
			[
				'code'    => 'user_creation_error',
				'message' => 'Could not create user.',
				'errors'  => [
					[
						'code'    => 'email_not_available',
						'message' => 'This email is not available.',
					],
				],
			],
		];

		// Act.
		$result = UserSync::sync_existing_user( $user_id );

		// Assert.
		$this->assertWPError( $result );
		$this->assertSame( 'workos_api_error', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 422, $data['status'] );
		$this->assertSame( 'user_creation_error', $data['body']['code'] );
		$this->assertSame( 'email_not_available', $data['body']['errors'][0]['code'] );
		$this->assertSame( '', get_user_meta( $user_id, '_workos_user_id', true ) );
	}

	public function test_sync_existing_user_success_links_the_user(): void {
		// Arrange.
		$this->enable_plugin();
		$user_id            = self::factory()->user->create();
		$this->api_response = [ 201, [ 'id' => 'user_contract_synced', 'email' => 'synced@example.test' ] ];

		// Act.
		$result = UserSync::sync_existing_user( $user_id );

		// Assert.
		$this->assertIsArray( $result );
		$this->assertSame( 'user_contract_synced', $result['id'] );
		$this->assertSame( 'user_contract_synced', get_user_meta( $user_id, '_workos_user_id', true ) );
	}

	public function test_list_users_returns_the_data_list(): void {
		// Arrange: the Portal reads ['data'][*]['id'|'email'].
		$this->enable_plugin();
		$this->api_response = [
			200,
			[
				'data'          => [ [ 'id' => 'user_contract_listed', 'email' => 'listed@example.test' ] ],
				'list_metadata' => [ 'before' => null, 'after' => null ],
			],
		];

		// Act.
		$result = workos()->api()->list_users( [ 'email' => 'listed@example.test' ] );

		// Assert.
		$this->assertIsArray( $result );
		$this->assertSame( 'user_contract_listed', $result['data'][0]['id'] );
		$this->assertSame( 'listed@example.test', $result['data'][0]['email'] );
	}

	// ------------------------------------------------------------ Meta keys

	public function test_logout_deletes_the_token_meta(): void {
		// Arrange: the Portal reads the access token before calling wp_logout().
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, '_workos_access_token', 'not-a-jwt' );
		update_user_meta( $user_id, '_workos_refresh_token', 'rt_contract' );
		update_user_meta( $user_id, '_workos_session_id', 'session_contract' );
		update_user_meta( $user_id, '_workos_user_id', 'user_contract_logout' );

		// Act.
		do_action( 'wp_logout', $user_id );

		// Assert.
		$this->assertSame( '', get_user_meta( $user_id, '_workos_access_token', true ) );
		$this->assertSame( '', get_user_meta( $user_id, '_workos_refresh_token', true ) );
		$this->assertSame( '', get_user_meta( $user_id, '_workos_session_id', true ) );
		$this->assertSame( 'user_contract_logout', get_user_meta( $user_id, '_workos_user_id', true ), 'The link survives logout.' );
	}

	public function test_user_helpers_read_the_raw_meta_keys(): void {
		// Arrange: the Portal writes and reads these keys directly.
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, '_workos_user_id', 'user_contract_meta' );
		update_user_meta( $user_id, '_workos_access_token', 'at_contract' );
		update_user_meta( $user_id, '_workos_refresh_token', 'rt_contract' );

		// Act + Assert.
		$this->assertSame( 'user_contract_meta', \WorkOS\User::get_workos_id( $user_id ) );
		$this->assertSame( 'at_contract', \WorkOS\User::get_access_token( $user_id ) );
		$this->assertSame( 'rt_contract', \WorkOS\User::get_refresh_token( $user_id ) );
		$this->assertSame( $user_id, UserSync::get_wp_user_id_by_workos_id( 'user_contract_meta' ) );
	}

	// ------------------------------------------------------------ REST routes

	public function test_workos_v1_routes_and_methods(): void {
		$expected = [
			'/workos/v1/admin/profiles'                            => [ 'GET', 'POST' ],
			'/workos/v1/admin/profiles/organizations'              => [ 'GET' ],
			'/workos/v1/admin/profiles/(?P<id>\d+)'                => [ 'DELETE', 'GET', 'PUT' ],
			'/workos/v1/admin/users'                               => [ 'GET' ],
			'/workos/v1/admin/users/(?P<id>\d+)/password-reset'    => [ 'POST' ],
			'/workos/v1/users/(?P<id>\d+)/email-change'            => [ 'POST' ],
			'/workos/v1/users/(?P<id>\d+)/email-change/confirm'    => [ 'POST' ],
			'/workos/v1/users/(?P<id>\d+)/email-change/cancel'     => [ 'POST' ],
			'/workos/v1/auth/password/authenticate'                => [ 'POST' ],
			'/workos/v1/auth/password/reset/start'                 => [ 'POST' ],
			'/workos/v1/auth/password/reset/confirm'               => [ 'POST' ],
			'/workos/v1/auth/magic/send'                           => [ 'POST' ],
			'/workos/v1/auth/magic/verify'                         => [ 'POST' ],
			'/workos/v1/auth/nonce'                                => [ 'GET' ],
			'/workos/v1/auth/session/refresh'                      => [ 'POST' ],
			'/workos/v1/auth/session/logout'                       => [ 'POST' ],
			'/workos/v1/auth/signup/create'                        => [ 'POST' ],
			'/workos/v1/auth/signup/verify'                        => [ 'POST' ],
			'/workos/v1/auth/invitation/(?P<token>[A-Za-z0-9_\-]+)' => [ 'GET' ],
			'/workos/v1/auth/invitation/accept'                    => [ 'POST' ],
			'/workos/v1/auth/oauth/authorize-url'                  => [ 'GET' ],
			'/workos/v1/auth/mfa/challenge'                        => [ 'POST' ],
			'/workos/v1/auth/mfa/verify'                           => [ 'POST' ],
			'/workos/v1/auth/mfa/factors'                          => [ 'GET' ],
			'/workos/v1/auth/mfa/totp/enroll'                      => [ 'POST' ],
			'/workos/v1/auth/mfa/sms/enroll'                       => [ 'POST' ],
			'/workos/v1/auth/mfa/factor/delete'                    => [ 'POST' ],
			'/workos/v1/auth/email-verification/verify'            => [ 'POST' ],
			'/workos/v1/auth/email-verification/resend'            => [ 'POST' ],
			'/workos/v1/webhook'                                   => [ 'POST' ],
		];

		$routes = rest_get_server()->get_routes();

		foreach ( $expected as $route => $methods ) {
			$this->assertArrayHasKey( $route, $routes, "REST route {$route} is gone." );

			$actual = [];
			foreach ( $routes[ $route ] as $handler ) {
				$actual = array_merge( $actual, array_keys( array_filter( (array) $handler['methods'] ) ) );
			}
			$actual = array_values( array_unique( $actual ) );

			foreach ( $methods as $method ) {
				$this->assertContains( $method, $actual, "{$method} {$route} is gone." );
			}
		}
	}

	public function test_email_change_initiate_response_and_pending_meta(): void {
		// Arrange: the Portal's React app calls this with the cookie nonce.
		$this->enable_plugin();
		$user_id = self::factory()->user->create( [ 'user_email' => 'before-contract@example.test' ] );
		update_user_meta( $user_id, '_workos_user_id', 'user_contract_email' );
		wp_set_current_user( $user_id );
		$this->api_response = [ 200, [ 'data' => [] ] ];

		// Act.
		$response = $this->dispatch(
			'POST',
			"/workos/v1/users/{$user_id}/email-change",
			[
				'new_email'    => 'after-contract@example.test',
				'redirect_url' => home_url( '/account/' ),
			]
		);

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['ok'] );
		$this->assertIsString( $data['masked_new_email'] );
		$this->assertIsInt( $data['expires_at'] );

		$pending = get_user_meta( $user_id, '_workos_pending_email_change', true );
		$this->assertIsArray( $pending );
		$this->assertSame( 'after-contract@example.test', $pending['new_email'] );
		$this->assertIsInt( $pending['expires_at'] );
		$this->assertGreaterThan( time(), $pending['expires_at'] );
	}

	public function test_email_change_same_address_is_a_no_op(): void {
		// Arrange.
		$this->enable_plugin();
		$user_id = self::factory()->user->create( [ 'user_email' => 'same-contract@example.test' ] );
		wp_set_current_user( $user_id );

		// Act.
		$response = $this->dispatch( 'POST', "/workos/v1/users/{$user_id}/email-change", [ 'new_email' => 'same-contract@example.test' ] );

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['ok'] );
		$this->assertTrue( $data['no_op'] );
		$this->assertArrayHasKey( 'masked_new_email', $data );
	}

	public function test_email_change_cancel_response(): void {
		// Arrange.
		$this->enable_plugin();
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, '_workos_user_id', 'user_contract_cancel' );
		wp_set_current_user( $user_id );
		$this->dispatch( 'POST', "/workos/v1/users/{$user_id}/email-change", [ 'new_email' => 'cancel-contract@example.test' ] );

		// Act.
		$response = $this->dispatch( 'POST', "/workos/v1/users/{$user_id}/email-change/cancel" );

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['ok'] );
		$this->assertSame( '', get_user_meta( $user_id, '_workos_pending_email_change', true ) );
	}

	// ------------------------------------------------ Query vars and rewrites

	public function test_query_vars_are_public(): void {
		global $wp_rewrite;

		// `workos_callback` is a rewrite tag; the other two go through the `query_vars` filter.
		$this->assertContains( '%workos_callback%', $wp_rewrite->rewritecode );
		$this->assertContains( 'workos_callback=', $wp_rewrite->queryreplace );

		$vars = apply_filters( 'query_vars', [] );
		$this->assertContains( 'workos_login_profile', $vars );
		$this->assertContains( 'workos_change_email_confirm', $vars );
	}

	public function test_rewrite_rules_for_callback_and_change_email(): void {
		global $wp_rewrite;

		$this->assertSame( 'index.php?workos_callback=1', $wp_rewrite->extra_rules_top['^workos/callback/?$'] ?? null );
		$this->assertSame( 'index.php?workos_change_email_confirm=1', $wp_rewrite->extra_rules_top['^workos/change\-email/?$'] ?? null );
		$this->assertSame( home_url( '/workos/callback' ), \WorkOS\Auth\Login::get_callback_url() );
	}

	// ---------------------------------------------------------------- Handles

	public function test_change_email_confirm_handles_are_registered_with_their_global(): void {
		$this->assertTrue( wp_script_is( 'workos-change-email-confirm', 'registered' ) );
		$this->assertTrue( wp_style_is( 'workos-change-email-confirm', 'registered' ) );

		$localized = (string) wp_scripts()->get_data( 'workos-change-email-confirm', 'data' );
		$this->assertStringContainsString( 'var workosChangeEmailConfirm = ', $localized );
	}
}
