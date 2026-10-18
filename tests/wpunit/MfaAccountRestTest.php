<?php
/**
 * Tests for the MFA account routes and the `[workos:mfa]` shortcode.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Mfa\FactorService;
use WorkOS\Mfa\RestApi;
use WorkOS\Mfa\Shortcode;
use WorkOS\RateLimit\DatabaseRateLimiter;
use WorkOS\RateLimit\TieredRateLimiter;
use WorkOS\User;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Status, enroll, confirm and remove through `/users/{id}/mfa…`.
 */
class MfaAccountRestTest extends WPTestCase {

	/**
	 * Linked account owner.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Captured HTTP calls.
	 *
	 * @var array
	 */
	private array $captured = [];

	/**
	 * Factor IDs deleted during the test; later lists leave them out, as WorkOS would.
	 *
	 * @var string[]
	 */
	private array $deleted = [];

	/**
	 * Mocked responses keyed by "METHOD url-needle".
	 *
	 * @var array<string, array>
	 */
	private array $responses = [];

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

		$api = new RestApi( new FactorService(), new TieredRateLimiter( new DatabaseRateLimiter() ) );
		add_action( 'rest_api_init', [ $api, 'register_routes' ] );
		do_action( 'rest_api_init', rest_get_server() );

		add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );

		$this->user_id = self::factory()->user->create( [ 'user_email' => 'owner@example.com' ] );
		update_user_meta( $this->user_id, User::META_WORKOS_ID, 'user_owner' );

		$this->captured = [];
		wp_set_current_user( $this->user_id );
	}

	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10 );
		remove_all_filters( 'workos_mfa_can_manage' );
		remove_all_filters( 'workos_mfa_can_manage_user' );
		$this->captured  = [];
		$this->responses = [];
		$this->deleted   = [];

		wp_set_current_user( 0 );
		delete_option( 'workos_production' );
		\WorkOS\Config::set_active_environment( 'staging' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		parent::tearDown();
	}

	public function intercept_http( $preempt, array $args, string $url ) {
		if ( ! str_contains( $url, 'api.workos.com' ) ) {
			return $preempt;
		}

		$method           = $args['method'] ?? 'GET';
		$this->captured[] = [
			'method' => $method,
			'url'    => $url,
		];

		if ( 'DELETE' === $method && preg_match( '#/auth/factors/([^/]+)$#', $url, $m ) ) {
			$this->deleted[] = $m[1];
		}

		foreach ( $this->responses as $key => $resp ) {
			[ $want_method, $needle ] = explode( ' ', $key, 2 );
			if ( $want_method === $method && str_contains( $url, $needle ) ) {
				if ( 'GET' === $method && str_ends_with( $needle, '/auth_factors' ) ) {
					$body         = json_decode( $resp['body'], true );
					$body['data'] = array_values( array_filter( $body['data'], fn( $f ) => ! in_array( $f['id'], $this->deleted, true ) ) );
					$resp['body'] = wp_json_encode( $body );
				}
				return $resp;
			}
		}

		return [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'body'     => '{}',
		];
	}

	// ------------------------------- Permissions -------------------------------

	public function test_visitor_gets_401(): void {
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->request( 'POST', '/enroll' )->get_status() );
	}

	public function test_owner_reads_status(): void {
		$this->factors( [ [ 'id' => 'auth_factor_1', 'type' => 'totp' ] ] );

		$response = $this->request( 'GET', '' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['enabled'] );
		$this->assertSame( 'auth_factor_1', $response->get_data()['factors'][0]['id'] );
	}

	public function test_other_user_cannot_read_status_but_an_admin_can(): void {
		$this->factors( [] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertContains( $this->request( 'GET', '' )->get_status(), [ 401, 403 ] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 200, $this->request( 'GET', '' )->get_status() );
	}

	public function test_admin_cannot_enroll_someone_else(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->captured = [];

		$response = $this->request( 'POST', '/enroll' );

		$this->assertSame( 403, $response->get_status() );
		$this->assertEmpty( $this->captured );
	}

	public function test_can_manage_filter_hides_self_service(): void {
		add_filter( 'workos_mfa_can_manage', '__return_false' );

		$this->assertSame( 403, $this->request( 'POST', '/enroll' )->get_status() );
	}

	// ------------------------------- Enrollment -------------------------------

	public function test_enroll_then_confirm_turns_mfa_on(): void {
		$this->queue(
			'POST /user_management/users/user_owner/auth_factors',
			[
				'authentication_factor'    => [
					'id'   => 'auth_factor_new',
					'type' => 'totp',
					'totp' => [
						'qr_code' => 'data:image/png;base64,AAA',
						'secret'  => 'SECRET',
						'uri'     => 'otpauth://totp/x',
					],
				],
				'authentication_challenge' => [ 'id' => 'auth_challenge_new' ],
			],
			201
		);

		$enroll = $this->request( 'POST', '/enroll' );
		$this->assertSame( 201, $enroll->get_status() );
		$this->assertSame( 'SECRET', $enroll->get_data()['secret'] );

		$this->queue( 'POST /auth/challenges/auth_challenge_new/verify', [ 'valid' => true ] );
		$this->factors( [ [ 'id' => 'auth_factor_new', 'type' => 'totp' ] ] );

		$confirm = $this->request(
			'POST',
			'/confirm',
			[
				'factor_id'    => 'auth_factor_new',
				'challenge_id' => 'auth_challenge_new',
				'code'         => '123456',
			]
		);

		$this->assertSame( 200, $confirm->get_status() );
		$this->assertTrue( $confirm->get_data()['enabled'] );
		$this->assertSame( '1', get_user_meta( $this->user_id, User::META_MFA_ENABLED, true ) );
	}

	// --------------------------------- Remove ---------------------------------

	public function test_remove_needs_a_code_from_the_factor(): void {
		update_user_meta( $this->user_id, User::META_MFA_ENABLED, '1' );
		$this->factors( [ [ 'id' => 'auth_factor_1', 'type' => 'totp' ] ] );

		$response = $this->request( 'DELETE', '/factors/auth_factor_1' );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'workos_mfa_invalid_code', $response->get_data()['code'] );
		$this->assertEmpty( array_filter( $this->captured, static fn( $c ) => 'DELETE' === $c['method'] ) );
	}

	public function test_remove_with_code_turns_mfa_off(): void {
		update_user_meta( $this->user_id, User::META_MFA_ENABLED, '1' );
		$this->factors( [ [ 'id' => 'auth_factor_1', 'type' => 'totp' ] ] );
		$this->queue( 'POST /auth/factors/auth_factor_1/challenge', [ 'id' => 'auth_challenge_rm' ] );
		$this->queue( 'POST /auth/challenges/auth_challenge_rm/verify', [ 'valid' => true ] );

		$response = $this->request( 'DELETE', '/factors/auth_factor_1', [ 'code' => '123456' ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotEmpty( array_filter( $this->captured, static fn( $c ) => 'DELETE' === $c['method'] && str_contains( $c['url'], '/auth/factors/auth_factor_1' ) ) );
		$this->assertSame( '', (string) get_user_meta( $this->user_id, User::META_MFA_ENABLED, true ) );
	}

	public function test_remove_someone_elses_factor_is_404(): void {
		$this->factors( [ [ 'id' => 'auth_factor_mine', 'type' => 'totp' ] ] );

		$response = $this->request( 'DELETE', '/factors/auth_factor_theirs', [ 'code' => '123456' ] );

		$this->assertSame( 404, $response->get_status() );
	}

	// -------------------------------- Shortcode --------------------------------

	public function test_shortcode_mounts_for_linked_user_only(): void {
		$this->assertStringContainsString( 'data-user-id="' . $this->user_id . '"', ( new Shortcode() )->render() );

		wp_set_current_user( self::factory()->user->create() );
		$this->assertSame( '', ( new Shortcode() )->render() );

		wp_set_current_user( 0 );
		$this->assertSame( '', ( new Shortcode() )->render() );
	}

	// -------------------------------- Fixtures --------------------------------

	private function factors( array $factors ): void {
		$this->queue( 'GET /user_management/users/user_owner/auth_factors', [ 'data' => $factors ] );
	}

	private function queue( string $key, array $body, int $code = 200 ): void {
		$this->responses[ $key ] = [
			'response' => [ 'code' => $code, 'message' => 'OK' ],
			'body'     => wp_json_encode( $body ),
		];
	}

	private function request( string $method, string $path, array $body = [] ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/workos/v1/users/' . $this->user_id . '/mfa' . $path );
		$request->set_header( 'Content-Type', 'application/json' );
		if ( $body ) {
			$request->set_body( wp_json_encode( $body ) );
		}

		return rest_get_server()->dispatch( $request );
	}
}
