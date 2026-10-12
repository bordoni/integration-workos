<?php
/**
 * Tests for the REST Auth email-verification endpoints.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Auth\AuthKit\LoginCompleter;
use WorkOS\Auth\AuthKit\Nonce;
use WorkOS\Auth\AuthKit\Profile;
use WorkOS\Auth\AuthKit\ProfileRepository;
use WorkOS\Auth\AuthKit\Radar;
use WorkOS\Email\AddressCanonicalizer;
use WorkOS\Http\ClientIp;
use WorkOS\RateLimit\DatabaseRateLimiter;
use WorkOS\RateLimit\TieredRateLimiter;
use WorkOS\REST\Auth\EmailVerification;
use WorkOS\REST\Auth\Password;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST dispatch coverage for /auth/email-verification/*.
 */
class AuthKitRestEmailVerificationTest extends WPTestCase {

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
	 * Endpoint registered by this test case, apart from the plugin's own.
	 *
	 * @var EmailVerification
	 */
	private EmailVerification $endpoint;

	/**
	 * Captured HTTP calls.
	 *
	 * @var array
	 */
	private array $captured = [];

	/**
	 * Ordered queue of HTTP responses consumed one-per-call.
	 *
	 * @var array
	 */
	private array $response_queue = [];

	/**
	 * Set up a standalone plugin environment + register routes.
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
		$this->repository->save(
			Profile::from_array(
				[
					'slug'    => 'members',
					'title'   => 'Members',
					'methods' => [ Profile::METHOD_PASSWORD ],
				]
			)
		);

		$this->nonce = new Nonce();

		$deps = [
			$this->repository,
			$this->nonce,
			new Radar(),
			new TieredRateLimiter( new DatabaseRateLimiter() ),
			new ClientIp(),
			new AddressCanonicalizer(),
			new LoginCompleter(),
		];

		add_action( 'rest_api_init', [ new Password( ...$deps ), 'register_routes' ] );
		$this->endpoint = new EmailVerification( ...$deps );
		add_action( 'rest_api_init', [ $this->endpoint, 'register_routes' ] );
		do_action( 'rest_api_init', rest_get_server() );

		add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );
		wp_set_current_user( 0 );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10 );
		$this->captured       = [];
		$this->response_queue = [];

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
	 * HTTP interceptor.
	 */
	public function intercept_http( $preempt, array $args, string $url ): array {
		$this->captured[] = [
			'url'    => $url,
			'method' => $args['method'] ?? 'GET',
			'body'   => $args['body'] ?? '',
		];
		if ( ! empty( $this->response_queue ) ) {
			return array_shift( $this->response_queue );
		}
		return [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'body'     => '{}',
		];
	}

	/**
	 * Dispatch with the profile nonce.
	 */
	private function dispatch( string $route, array $body ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/workos/v1/auth/' . $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WorkOS-Nonce', $this->nonce->mint( 'members' ) );
		$request->set_body( wp_json_encode( array_merge( [ 'profile' => 'members' ], $body ) ) );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * A JSON HTTP response for the interceptor.
	 */
	private function respond( int $status, array $body ): array {
		return [
			'response' => [ 'code' => $status, 'message' => '' ],
			'body'     => wp_json_encode( $body ),
		];
	}

	/**
	 * A completed WorkOS authentication.
	 */
	private function authenticated( string $email ): array {
		return $this->respond(
			200,
			[
				'access_token'  => 'eyJhbGciOi.eyJzdWIiOiJ1c2VyX3Vudm4iLCJleHAiOjk5OTk5OTk5OTl9.sig',
				'refresh_token' => 'rt_verify',
				'user'          => [
					'id'             => 'user_unverified',
					'email'          => $email,
					'email_verified' => true,
				],
			]
		);
	}

	/**
	 * Calls captured to a WorkOS path.
	 */
	private function calls_to( string $path ): array {
		return array_values(
			array_filter( $this->captured, static fn( array $c ) => str_contains( $c['url'], $path ) )
		);
	}

	/**
	 * The plugin's REST controller registers the route on its own.
	 */
	public function test_plugin_registers_verify_route(): void {
		global $wp_rest_server;

		remove_action( 'rest_api_init', [ $this->endpoint, 'register_routes' ] );
		$wp_rest_server = null;

		$this->assertArrayHasKey( '/workos/v1/auth/email-verification/verify', rest_get_server()->get_routes() );
	}

	/**
	 * Code and pending token are both required.
	 */
	public function test_verify_requires_code_and_token(): void {
		$response = $this->dispatch( 'email-verification/verify', [ 'code' => '123456' ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'workos_authkit_invalid_input', $response->get_data()['code'] );
		$this->assertEmpty( $this->captured );
	}

	/**
	 * The code and pending token go to WorkOS on the email-verification
	 * grant, and the sign-in completes.
	 */
	public function test_verify_completes_sign_in(): void {
		$this->response_queue = [ $this->authenticated( 'ghost@example.com' ) ];

		$response = $this->dispatch(
			'email-verification/verify',
			[
				'code'                         => '123456',
				'pending_authentication_token' => 'pat_verify',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ghost@example.com', $response->get_data()['user']['email'] );

		$sent = json_decode( $this->calls_to( '/user_management/authenticate' )[0]['body'], true );
		$this->assertSame( 'urn:workos:oauth:grant-type:email-verification:code', $sent['grant_type'] );
		$this->assertSame( '123456', $sent['code'] );
		$this->assertSame( 'pat_verify', $sent['pending_authentication_token'] );
	}

	/**
	 * A second factor after verification goes to the MFA step.
	 */
	public function test_verify_surfaces_mfa(): void {
		$this->response_queue = [
			$this->respond(
				200,
				[
					'pending_authentication_token' => 'pat_mfa',
					'authentication_factor'        => [ 'id' => 'factor_1', 'type' => 'totp' ],
				]
			),
		];

		$response = $this->dispatch(
			'email-verification/verify',
			[
				'code'                         => '123456',
				'pending_authentication_token' => 'pat_verify',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['mfa_required'] );
		$this->assertSame( 'pat_mfa', $response->get_data()['pending_authentication_token'] );
	}

	/**
	 * Code guessing is capped per pending token.
	 */
	public function test_verify_rate_limits_per_token(): void {
		$wrong_code = $this->respond( 400, [ 'code' => 'invalid_one_time_code', 'message' => 'Invalid code.' ] );
		$body       = [
			'code'                         => '000000',
			'pending_authentication_token' => 'pat_guess',
		];

		for ( $i = 0; $i < 5; $i++ ) {
			$this->response_queue = [ $wrong_code ];
			$this->dispatch( 'email-verification/verify', $body );
		}

		$response = $this->dispatch( 'email-verification/verify', $body );

		$this->assertSame( 429, $response->get_status() );
		$this->assertCount( 5, $this->calls_to( '/user_management/authenticate' ) );
	}

	/**
	 * Start a password sign-in that WorkOS pauses for verification.
	 */
	private function start_unverified_sign_in( string $email ): void {
		$this->response_queue = [
			$this->respond(
				403,
				[
					'code'                         => 'email_verification_required',
					'message'                      => 'Email ownership must be verified before authentication.',
					'email'                        => $email,
					'pending_authentication_token' => 'pat_verify',
					'email_verification_id'        => 'email_verification_1',
				]
			),
		];

		$response = $this->dispatch(
			'password/authenticate',
			[
				'email'    => $email,
				'password' => 'CorrectPassword!1',
			]
		);
		$this->assertTrue( $response->get_data()['email_verification_required'] );

		$this->captured = [];
	}

	/**
	 * Responses for one resend: the user lookup, then the send.
	 */
	private function queue_resend(): void {
		$this->response_queue = [
			$this->respond( 200, [ 'data' => [ [ 'id' => 'user_unverified', 'email' => 'ghost@example.com' ] ] ] ),
			$this->respond( 200, [] ),
		];
	}

	/**
	 * Resend finds the account the pending sign-in belongs to and mails it
	 * a new code, without the browser naming the account.
	 */
	public function test_resend_sends_new_code_for_pending_sign_in(): void {
		$this->start_unverified_sign_in( 'ghost@example.com' );
		$this->queue_resend();

		$response = $this->dispatch( 'email-verification/resend', [ 'pending_authentication_token' => 'pat_verify' ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'A new code is on its way. Use the newest one.', $response->get_data()['message'] );
		$this->assertStringContainsString( 'email=ghost%40example.com', $this->calls_to( '/user_management/users' )[0]['url'] );
		$this->assertCount( 1, $this->calls_to( '/user_management/users/user_unverified/email_verification/send' ) );
	}

	/**
	 * An unknown or expired token gets the same answer and sends nothing.
	 */
	public function test_resend_unknown_token_sends_nothing(): void {
		$response = $this->dispatch( 'email-verification/resend', [ 'pending_authentication_token' => 'pat_unknown' ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertEmpty( $this->captured );
	}

	/**
	 * Resends count against the same send limit as the code WorkOS mailed
	 * on the password attempt: that one plus two resends fill it.
	 */
	public function test_resend_shares_password_send_limit(): void {
		$this->start_unverified_sign_in( 'ghost@example.com' );

		for ( $i = 0; $i < 2; $i++ ) {
			$this->queue_resend();
			$this->assertSame( 200, $this->dispatch( 'email-verification/resend', [ 'pending_authentication_token' => 'pat_verify' ] )->get_status() );
		}

		$response = $this->dispatch( 'email-verification/resend', [ 'pending_authentication_token' => 'pat_verify' ] );

		$this->assertSame( 429, $response->get_status() );
		$this->assertCount( 2, $this->calls_to( '/email_verification/send' ) );
	}

	/**
	 * Once the sign-in completes, its token can't trigger more mail.
	 */
	public function test_verify_success_ends_pending_sign_in(): void {
		$this->start_unverified_sign_in( 'ghost@example.com' );
		$this->response_queue = [ $this->authenticated( 'ghost@example.com' ) ];
		$this->dispatch(
			'email-verification/verify',
			[
				'code'                         => '123456',
				'pending_authentication_token' => 'pat_verify',
			]
		);
		$this->captured = [];

		$this->dispatch( 'email-verification/resend', [ 'pending_authentication_token' => 'pat_verify' ] );

		$this->assertEmpty( $this->captured );
	}
}
