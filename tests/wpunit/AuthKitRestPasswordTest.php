<?php
/**
 * Tests for the REST Auth password + reset endpoints.
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
use WorkOS\RateLimit\DatabaseRateLimiter;
use WorkOS\Email\AddressCanonicalizer;
use WorkOS\Http\ClientIp;
use WorkOS\RateLimit\TieredRateLimiter;
use WorkOS\REST\Auth\Password;

/**
 * REST dispatch coverage for /auth/password/*.
 */
class AuthKitRestPasswordTest extends WPTestCase {

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
	 * Profile used in tests.
	 *
	 * @var Profile
	 */
	private Profile $profile;

	/**
	 * Captured HTTP calls.
	 *
	 * @var array
	 */
	private array $captured = [];

	/**
	 * Next WorkOS response body.
	 *
	 * @var array
	 */
	private array $next_workos = [
		'response' => [ 'code' => 200, 'message' => 'OK' ],
		'body'     => '{}',
	];

	/**
	 * Ordered queue of HTTP responses consumed one-per-call; falls back to $next_workos when empty.
	 *
	 * @var array
	 */
	private array $response_queue = [];

	/**
	 * WP user created for the WP-password-fallback tests.
	 *
	 * @var int
	 */
	private int $wp_fallback_user_id = 0;

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

		// Clean up residual profiles.
		foreach ( $this->repository->all() as $profile ) {
			wp_delete_post( $profile->get_id(), true );
		}

		$saved = $this->repository->save(
			Profile::from_array(
				[
					'slug'                => 'members',
					'title'               => 'Members',
					'methods'             => [ Profile::METHOD_PASSWORD, Profile::METHOD_MAGIC_CODE ],
					'password_reset_flow' => true,
				]
			)
		);
		$this->assertInstanceOf( Profile::class, $saved );
		$this->profile = $saved;

		$this->nonce = new Nonce();

		$password = new Password(
			$this->repository,
			$this->nonce,
			new Radar(),
			new TieredRateLimiter( new DatabaseRateLimiter() ),
			new ClientIp(),
			new AddressCanonicalizer(),
			new LoginCompleter()
		);

		// Hook into rest_api_init *before* dispatching so WP accepts the
		// route registration (WP complains otherwise via _doing_it_wrong).
		add_action( 'rest_api_init', [ $password, 'register_routes' ] );
		$server = rest_get_server();
		do_action( 'rest_api_init', $server );

		add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );

		// Create a WP user for fallback-path tests. Created after the HTTP filter
		// so push_user_to_workos fires but gets {} back and skips linking —
		// leaving _workos_user_id unset for tests that need to control it.
		$user_id = wp_create_user( 'fallback_user', 'wp_password_123', 'fallback@example.com' );
		if ( ! is_wp_error( $user_id ) ) {
			$this->wp_fallback_user_id = $user_id;
		}
		$this->captured = []; // discard creation noise

		// Ensure no residual user session.
		wp_set_current_user( 0 );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10 );
		$this->captured        = [];
		$this->response_queue  = [];

		if ( $this->wp_fallback_user_id ) {
			wp_delete_user( $this->wp_fallback_user_id );
			$this->wp_fallback_user_id = 0;
		}

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
			'url'     => $url,
			'method'  => $args['method'] ?? 'GET',
			'body'    => $args['body'] ?? '',
			'headers' => $args['headers'] ?? [],
		];
		if ( ! empty( $this->response_queue ) ) {
			return array_shift( $this->response_queue );
		}
		return $this->next_workos;
	}

	/**
	 * Helper to dispatch with nonce header.
	 */
	private function dispatch_with_nonce( string $method, string $route, array $body = [] ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WorkOS-Nonce', $this->nonce->mint( $this->profile->get_slug() ) );
		$request->set_body( wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Missing profile returns 400.
	 */
	public function test_authenticate_requires_profile(): void {
		$request = new WP_REST_Request( 'POST', '/workos/v1/auth/password/authenticate' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [ 'email' => 'a@b.c', 'password' => 'x' ] ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'workos_authkit_missing_profile', $response->get_data()['code'] );
	}

	/**
	 * Unknown profile returns 404.
	 */
	public function test_authenticate_unknown_profile_returns_404(): void {
		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/password/authenticate',
			[ 'profile' => 'nope', 'email' => 'a@b.c', 'password' => 'x' ]
		);

		// Nonce is profile-scoped, so this also fails nonce verification — but
		// profile resolution runs first and returns 404.
		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * Missing nonce returns 403.
	 */
	public function test_authenticate_rejects_missing_nonce(): void {
		$request = new WP_REST_Request( 'POST', '/workos/v1/auth/password/authenticate' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				[
					'profile'  => 'members',
					'email'    => 'a@b.c',
					'password' => 'x',
				]
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'workos_authkit_invalid_nonce', $response->get_data()['code'] );
	}

	/**
	 * Method disabled on profile returns 400.
	 */
	public function test_authenticate_respects_method_toggle(): void {
		// Save a profile that only allows magic codes.
		$magic_only = $this->repository->save(
			Profile::from_array(
				[
					'slug'    => 'magic-only',
					'title'   => 'Magic Only',
					'methods' => [ Profile::METHOD_MAGIC_CODE ],
				]
			)
		);
		$this->assertInstanceOf( Profile::class, $magic_only );

		$request = new WP_REST_Request( 'POST', '/workos/v1/auth/password/authenticate' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WorkOS-Nonce', $this->nonce->mint( 'magic-only' ) );
		$request->set_body(
			wp_json_encode(
				[
					'profile'  => 'magic-only',
					'email'    => 'a@b.c',
					'password' => 'x',
				]
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'workos_authkit_method_disabled', $response->get_data()['code'] );
	}

	/**
	 * Happy-path authenticate: calls WorkOS and completes login.
	 */
	public function test_authenticate_success_calls_workos_and_returns_redirect(): void {
		$this->next_workos['body'] = wp_json_encode(
			[
				'access_token'  => 'eyJhbGciOi.eyJzdWIiOiJ1c2VyX3dva28iLCJleHAiOjk5OTk5OTk5OTl9.sig',
				'refresh_token' => 'rt_abc',
				'user'          => [
					'id'             => 'user_woko',
					'email'          => 'alice@example.com',
					'first_name'     => 'Alice',
					'last_name'      => 'Doe',
					'email_verified' => true,
				],
			]
		);

		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/password/authenticate',
			[
				'profile'     => 'members',
				'email'       => 'alice@example.com',
				'password'    => 'hunter2',
				'redirect_to' => admin_url(),
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'user', $data );
		$this->assertSame( 'alice@example.com', $data['user']['email'] );
		$this->assertNotEmpty( $data['redirect_to'] );

		$authenticate_calls = array_filter(
			$this->captured,
			static fn( array $c ) => str_contains( $c['url'], '/user_management/authenticate' )
		);
		$this->assertNotEmpty( $authenticate_calls );
	}

	/**
	 * MFA-required response is surfaced to the client.
	 */
	public function test_authenticate_mfa_required_is_surfaced(): void {
		$this->next_workos['body'] = wp_json_encode(
			[
				'pending_authentication_token' => 'pat_123',
				'authentication_factor'        => [ 'id' => 'factor_1', 'type' => 'totp' ],
			]
		);

		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/password/authenticate',
			[
				'profile'  => 'members',
				'email'    => 'alice@example.com',
				'password' => 'hunter2',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['mfa_required'] );
		$this->assertSame( 'pat_123', $data['pending_authentication_token'] );
		$this->assertCount( 1, $data['factors'] );
	}

	/**
	 * A correct password on an unverified account returns the verification
	 * step, so the shell can collect the code WorkOS already mailed.
	 */
	public function test_authenticate_email_verification_required_is_surfaced(): void {
		$this->response_queue = [ $this->email_verification_required( 'ghost@example.com' ) ];

		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/password/authenticate',
			[
				'profile'  => 'members',
				'email'    => 'ghost@example.com',
				'password' => 'CorrectPassword!1',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['email_verification_required'] );
		$this->assertSame( 'pat_verify', $data['pending_authentication_token'] );
		$this->assertSame( 'ghost@example.com', $data['email'] );
	}

	/**
	 * The password was accepted, so the WP fallback has nothing to add: it
	 * must not sync the password or authenticate again (which would mail a
	 * second code).
	 */
	public function test_authenticate_email_verification_skips_wp_fallback(): void {
		update_user_meta( $this->wp_fallback_user_id, '_workos_user_id', 'user_wos_fallback' );
		$this->response_queue = [ $this->email_verification_required( 'fallback@example.com' ) ];

		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/password/authenticate',
			[
				'profile'  => 'members',
				'email'    => 'fallback@example.com',
				'password' => 'wp_password_123',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['email_verification_required'] );
		$this->assertCount( 1, $this->captured, 'Only the first authenticate call may reach WorkOS.' );
	}

	/**
	 * WorkOS's real `mfa_challenge` rejection becomes the MFA step.
	 *
	 * When the environment requires MFA, WorkOS answers the password grant
	 * with an error, not a success, so the step has to be read off the
	 * error body.
	 */
	public function test_authenticate_mfa_challenge_error_is_surfaced(): void {
		$this->response_queue = [ $this->mfa_challenge() ];

		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/password/authenticate',
			[
				'profile'  => 'members',
				'email'    => 'alice@example.com',
				'password' => 'hunter2',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['mfa_required'] );
		$this->assertSame( 'pat_mfa', $data['pending_authentication_token'] );
		$this->assertSame( 'auth_factor_1', $data['factors'][0]['id'] );
	}

	/**
	 * The password was accepted and only the second factor is missing, so
	 * the WP fallback must not sync the password and try again.
	 */
	public function test_authenticate_mfa_challenge_skips_wp_fallback(): void {
		update_user_meta( $this->wp_fallback_user_id, '_workos_user_id', 'user_wos_fallback' );
		$this->response_queue = [ $this->mfa_challenge() ];

		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/password/authenticate',
			[
				'profile'  => 'members',
				'email'    => 'fallback@example.com',
				'password' => 'wp_password_123',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['mfa_required'] );
		$this->assertCount( 1, $this->captured, 'Only the first authenticate call may reach WorkOS.' );
		$this->assertSame( 0, get_current_user_id() );
	}

	/**
	 * WorkOS's `mfa_challenge` rejection for an account with a TOTP factor.
	 *
	 * @return array HTTP response for the interceptor.
	 */
	private function mfa_challenge(): array {
		return [
			'response' => [ 'code' => 403, 'message' => 'Forbidden' ],
			'body'     => wp_json_encode(
				[
					'code'                         => 'mfa_challenge',
					'message'                      => 'The user must complete an MFA challenge to finish authenticating.',
					'pending_authentication_token' => 'pat_mfa',
					'authentication_factors'       => [
						[ 'id' => 'auth_factor_1', 'type' => 'totp' ],
					],
					'user'                         => [ 'id' => 'user_wos_fallback', 'email' => 'fallback@example.com' ],
				]
			),
		];
	}

	/**
	 * WorkOS's `email_verification_required` rejection for an account.
	 *
	 * @param string $email Account email.
	 *
	 * @return array HTTP response for the interceptor.
	 */
	private function email_verification_required( string $email ): array {
		return [
			'response' => [ 'code' => 403, 'message' => 'Forbidden' ],
			'body'     => wp_json_encode(
				[
					'code'                         => 'email_verification_required',
					'message'                      => 'Email ownership must be verified before authentication.',
					'email'                        => $email,
					'pending_authentication_token' => 'pat_verify',
					'email_verification_id'        => 'email_verification_1',
				]
			),
		];
	}

	/**
	 * Radar header is forwarded to WorkOS.
	 */
	public function test_authenticate_forwards_radar_header(): void {
		$request = new WP_REST_Request( 'POST', '/workos/v1/auth/password/authenticate' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WorkOS-Nonce', $this->nonce->mint( 'members' ) );
		$request->set_header( 'X-WorkOS-Radar-Action-Token', 'radar_tok' );
		$request->set_body(
			wp_json_encode(
				[
					'profile'  => 'members',
					'email'    => 'alice@example.com',
					'password' => 'hunter2',
				]
			)
		);

		$this->next_workos['body'] = wp_json_encode(
			[
				'access_token'  => 'eyJhbGciOi.eyJzdWIiOiJ1c2VyX3cxIiwiZXhwIjo5OTk5OTk5OTk5fQ.sig',
				'refresh_token' => 'rt_xyz',
				'user'          => [
					'id'             => 'user_w1',
					'email'          => 'alice@example.com',
					'email_verified' => true,
				],
			]
		);

		rest_get_server()->dispatch( $request );

		$authenticate_call = null;
		foreach ( $this->captured as $c ) {
			if ( str_contains( $c['url'], '/user_management/authenticate' ) ) {
				$authenticate_call = $c;
				break;
			}
		}

		$this->assertNotNull( $authenticate_call );
		$this->assertSame( 'radar_tok', $authenticate_call['headers']['x-workos-radar-action-token'] );
	}

	/**
	 * reset_start returns 200 even for unknown email (enumeration-safe).
	 */
	public function test_reset_start_returns_200_regardless_of_email_existence(): void {
		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/password/reset/start',
			[
				'profile' => 'members',
				'email'   => 'ghost@nowhere.test',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['ok'] );

		// A send-password-reset request still went out to WorkOS.
		$reset_calls = array_filter(
			$this->captured,
			static fn( array $c ) => str_contains( $c['url'], '/user_management/password_reset/send' )
		);
		$this->assertNotEmpty( $reset_calls );
	}

	/**
	 * Both the named (`&amp;`, e.g. `esc_attr()`) and numeric (`&#038;`,
	 * emitted by `esc_url()` in display context) forms an upstream
	 * `login_url` filter might produce.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function escaped_ampersand_provider(): array {
		return [
			'named entity (&amp;)'    => [ '&amp;' ],
			'numeric entity (&#038;)' => [ '&#038;' ],
		];
	}

	/**
	 * URLs sent to WorkOS use literal `&`, even when an upstream `home_url`
	 * filter HTML-escapes ampersands — WorkOS emails the URL verbatim, so
	 * the entity-decoded form is what reaches the inbox.
	 *
	 * Reset emails now point at the AuthKit React route
	 * (`/workos/login/{slug}/`), so the regression scenario is a
	 * `home_url` filter (not `login_url`) inserting HTML entities into the
	 * URL before we tack on `redirect_to` via `add_query_arg()`.
	 *
	 * @dataProvider escaped_ampersand_provider
	 */
	public function test_reset_start_sends_url_with_unescaped_ampersands( string $escaped_amp ): void {
		// Simulate a host-site home_url filter that runs the URL through
		// esc_url() / esc_attr(). The literal `&` that `add_query_arg()`
		// emits is what gets escaped, so we splice the entity in directly.
		$escape_home_url = static function ( $url ) use ( $escaped_amp ): string {
			// Add a pre-existing query arg with an HTML-escaped separator so
			// the next add_query_arg() call has to navigate past the entity.
			$url = (string) $url;
			$sep = false === strpos( $url, '?' ) ? '?' : $escaped_amp;
			return $url . $sep . 'foo=1' . $escaped_amp . 'bar=2';
		};
		add_filter( 'home_url', $escape_home_url );

		try {
			$response = $this->dispatch_with_nonce(
				'POST',
				'/workos/v1/auth/password/reset/start',
				[
					'profile'      => 'members',
					'email'        => 'someone@example.com',
					'redirect_url' => home_url( '/welcome' ),
				]
			);
		} finally {
			remove_filter( 'home_url', $escape_home_url );
		}

		$this->assertSame( 200, $response->get_status() );

		$send_call = null;
		foreach ( $this->captured as $call ) {
			if ( str_contains( $call['url'], '/user_management/password_reset/send' ) ) {
				$send_call = $call;
				break;
			}
		}
		$this->assertNotNull( $send_call, 'Expected a password_reset/send call to WorkOS.' );

		$body = json_decode( $send_call['body'], true );
		$this->assertIsArray( $body );
		$this->assertArrayHasKey( 'password_reset_url', $body );

		$reset_url = $body['password_reset_url'];
		$this->assertStringNotContainsString( '&amp;', $reset_url, 'URL must not contain HTML-escaped ampersands.' );
		$this->assertStringNotContainsString( '&#038;', $reset_url, 'URL must not contain numeric-entity ampersands.' );
		$this->assertStringContainsString( '/workos/login/members/', $reset_url, 'Reset URL must point at the AuthKit frontend route for the profile.' );
		$this->assertStringContainsString( 'redirect_to=', $reset_url, 'Validated redirect_to must be appended to the URL.' );
	}

	/**
	 * reset_confirm requires a token + new_password.
	 */
	public function test_reset_confirm_requires_token_and_password(): void {
		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/password/reset/confirm',
			[
				'profile' => 'members',
				'token'   => '',
			]
		);

		$this->assertSame( 400, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// WP password fallback tests
	// -------------------------------------------------------------------------

	/**
	 * When allow_password_fallback is disabled, WorkOS error is returned immediately
	 * and wp_authenticate is never attempted.
	 */
	public function test_fallback_skipped_when_option_disabled(): void {
		update_option(
			'workos_production',
			[
				'api_key'                 => 'sk_test_fake',
				'client_id'               => 'client_fake',
				'environment_id'          => 'environment_test',
				'allow_password_fallback' => false,
			]
		);
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$this->next_workos = [
			'response' => [ 'code' => 401, 'message' => 'Unauthorized' ],
			'body'     => wp_json_encode( [ 'message' => 'Invalid credentials.' ] ),
		];

		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/password/authenticate',
			[
				'profile'  => 'members',
				'email'    => 'fallback@example.com',
				'password' => 'wp_password_123',
			]
		);

		$this->assertSame( 'workos_api_error', $response->get_data()['code'] );

		$user_management_calls = array_filter(
			$this->captured,
			static fn( array $c ) => str_contains( $c['url'], '/user_management/users' )
		);
		$this->assertEmpty( $user_management_calls, 'WP fallback must not run when the option is disabled.' );
	}

	/**
	 * WorkOS fails and WP password is also wrong — original WorkOS error is returned.
	 */
	public function test_fallback_returns_error_when_wp_auth_fails(): void {
		$this->next_workos = [
			'response' => [ 'code' => 401, 'message' => 'Unauthorized' ],
			'body'     => wp_json_encode( [ 'message' => 'Invalid credentials.' ] ),
		];

		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/password/authenticate',
			[
				'profile'  => 'members',
				'email'    => 'fallback@example.com',
				'password' => 'wrong_password',
			]
		);

		$this->assertSame( 'workos_api_error', $response->get_data()['code'] );

		$authenticate_calls = array_filter(
			$this->captured,
			static fn( array $c ) => str_contains( $c['url'], '/user_management/authenticate' )
		);
		$this->assertCount( 1, $authenticate_calls, 'Should not retry authenticate after WP failure.' );
	}

	/**
	 * WorkOS fails, WP auth succeeds, user already linked, email confirmation disabled —
	 * password is synced to WorkOS and login completes directly.
	 */
	public function test_fallback_syncs_password_and_completes_login(): void {
		update_user_meta( $this->wp_fallback_user_id, '_workos_user_id', 'user_wos_fallback' );

		$auth_success = [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'body'     => wp_json_encode(
				[
					'access_token'  => 'eyJhbGciOi.eyJzdWIiOiJ1c2VyX3dva28iLCJleHAiOjk5OTk5OTk5OTl9.sig',
					'refresh_token' => 'rt_fallback',
					'user'          => [
						'id'             => 'user_wos_fallback',
						'email'          => 'fallback@example.com',
						'email_verified' => true,
					],
				]
			),
		];

		$this->response_queue = [
			[ 'response' => [ 'code' => 401, 'message' => 'Unauthorized' ], 'body' => wp_json_encode( [ 'message' => 'Invalid credentials.' ] ) ],
			[ 'response' => [ 'code' => 200, 'message' => 'OK' ], 'body' => '{}' ], // update_user
			$auth_success,
		];

		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/password/authenticate',
			[
				'profile'  => 'members',
				'email'    => 'fallback@example.com',
				'password' => 'wp_password_123',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'fallback@example.com', $response->get_data()['user']['email'] );

		$update_calls = array_filter(
			$this->captured,
			static fn( array $c ) => str_contains( $c['url'], '/user_management/users/user_wos_fallback' )
				&& 'PUT' === ( $c['method'] ?? '' )
		);
		$this->assertNotEmpty( $update_calls, 'Password must be synced to WorkOS via update_user.' );
	}

	/**
	 * WorkOS fails, WP auth succeeds, no _workos_user_id yet — user is found by
	 * email via list_users, linked, then password is synced and login completes.
	 */
	public function test_fallback_links_user_by_email_then_syncs(): void {
		$auth_success = [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'body'     => wp_json_encode(
				[
					'access_token'  => 'eyJhbGciOi.eyJzdWIiOiJ1c2VyX3dvcyIsImV4cCI6OTk5OTk5OTk5OX0.sig',
					'refresh_token' => 'rt_linked',
					'user'          => [
						'id'             => 'user_wos_linked',
						'email'          => 'fallback@example.com',
						'email_verified' => true,
					],
				]
			),
		];

		$this->response_queue = [
			[ 'response' => [ 'code' => 401, 'message' => 'Unauthorized' ], 'body' => wp_json_encode( [ 'message' => 'Invalid credentials.' ] ) ],
			[
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'body'     => wp_json_encode( [ 'data' => [ [ 'id' => 'user_wos_linked', 'email' => 'fallback@example.com' ] ] ] ),
			],
			[ 'response' => [ 'code' => 200, 'message' => 'OK' ], 'body' => '{}' ], // update_user
			$auth_success,
		];

		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/password/authenticate',
			[
				'profile'  => 'members',
				'email'    => 'fallback@example.com',
				'password' => 'wp_password_123',
			]
		);

		$this->assertSame( 200, $response->get_status() );

		$list_calls = array_filter(
			$this->captured,
			static fn( array $c ) => str_contains( $c['url'], '/user_management/users' )
				&& str_contains( $c['url'], 'fallback' )
		);
		$this->assertNotEmpty( $list_calls, 'list_users must be called to find the WorkOS account by email.' );
		$this->assertSame( 'user_wos_linked', get_user_meta( $this->wp_fallback_user_id, '_workos_user_id', true ) );
	}

	/**
	 * Email confirmation enabled: WorkOS fails, WP auth succeeds — magic code is sent
	 * and the shell receives email_confirmation_required. Password is never synced.
	 */
	public function test_fallback_email_confirmation_sends_magic_code(): void {
		update_user_meta( $this->wp_fallback_user_id, '_workos_user_id', 'user_wos_fallback' );
		update_option(
			'workos_production',
			[
				'api_key'                                 => 'sk_test_fake',
				'client_id'                               => 'client_fake',
				'environment_id'                          => 'environment_test',
				'wp_password_fallback_email_confirmation' => true,
			]
		);
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$this->response_queue = [
			[ 'response' => [ 'code' => 401, 'message' => 'Unauthorized' ], 'body' => wp_json_encode( [ 'message' => 'Invalid credentials.' ] ) ],
			[ 'response' => [ 'code' => 200, 'message' => 'OK' ], 'body' => '{}' ], // magic_auth/send
		];

		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/password/authenticate',
			[
				'profile'  => 'members',
				'email'    => 'fallback@example.com',
				'password' => 'wp_password_123',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['email_confirmation_required'] );
		$this->assertSame( 'fallback@example.com', $data['email'] );

		$magic_calls = array_filter(
			$this->captured,
			static fn( array $c ) => str_contains( $c['url'], '/user_management/magic_auth/send' )
		);
		$this->assertNotEmpty( $magic_calls, 'Magic code must be sent when email confirmation is enabled.' );

		$update_calls = array_filter(
			$this->captured,
			static fn( array $c ) => str_contains( $c['url'], '/user_management/users/user_wos_fallback' )
		);
		$this->assertEmpty( $update_calls, 'Password must not be synced in email-confirmation mode.' );
	}

	/**
	 * Confirmation OTP sends are capped per destination address: the 4th
	 * attempt with valid credentials returns 429 and sends no email.
	 */
	public function test_fallback_email_confirmation_rate_limits_sends_per_email(): void {
		update_user_meta( $this->wp_fallback_user_id, '_workos_user_id', 'user_wos_fallback' );
		update_option(
			'workos_production',
			[
				'api_key'                                 => 'sk_test_fake',
				'client_id'                               => 'client_fake',
				'environment_id'                          => 'environment_test',
				'wp_password_fallback_email_confirmation' => true,
			]
		);
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$workos_reject = [ 'response' => [ 'code' => 401, 'message' => 'Unauthorized' ], 'body' => wp_json_encode( [ 'message' => 'Email not verified.' ] ) ];
		$send_ok       = [ 'response' => [ 'code' => 200, 'message' => 'OK' ], 'body' => '{}' ];

		$this->response_queue = [
			$workos_reject, $send_ok,
			$workos_reject, $send_ok,
			$workos_reject, $send_ok,
			$workos_reject,
		];

		$body = [
			'profile'  => 'members',
			'email'    => 'fallback@example.com',
			'password' => 'wp_password_123',
		];

		for ( $i = 0; $i < 3; $i++ ) {
			$response = $this->dispatch_with_nonce( 'POST', '/workos/v1/auth/password/authenticate', $body );
			$this->assertSame( 200, $response->get_status(), "Send {$i} should be allowed." );
		}

		$response = $this->dispatch_with_nonce( 'POST', '/workos/v1/auth/password/authenticate', $body );
		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'workos_rate_limited', $response->get_data()['code'] );

		$magic_calls = array_filter(
			$this->captured,
			static fn( array $c ) => str_contains( $c['url'], '/user_management/magic_auth/send' )
		);
		$this->assertCount( 3, $magic_calls, 'The over-limit attempt must not send another code.' );
	}

	/**
	 * Confirmation OTP sends are also capped per IP across destination
	 * addresses: the 11th send from one IP returns 429 even though each
	 * email is under its own per-destination limit.
	 */
	public function test_fallback_email_confirmation_rate_limits_sends_per_ip(): void {
		update_option(
			'workos_production',
			[
				'api_key'                                 => 'sk_test_fake',
				'client_id'                               => 'client_fake',
				'environment_id'                          => 'environment_test',
				'wp_password_fallback_email_confirmation' => true,
			]
		);
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		// One unverified account per destination address, all valid credentials.
		for ( $i = 0; $i < 11; $i++ ) {
			$user_id = wp_create_user( "confirm_ip_{$i}", 'wp_password_123', "confirm_ip_{$i}@example.com" );
			$this->assertIsInt( $user_id );
			update_user_meta( $user_id, '_workos_user_id', "user_wos_confirm_{$i}" );
		}
		$this->captured = [];

		$workos_reject = [ 'response' => [ 'code' => 401, 'message' => 'Unauthorized' ], 'body' => wp_json_encode( [ 'message' => 'Email not verified.' ] ) ];
		$send_ok       = [ 'response' => [ 'code' => 200, 'message' => 'OK' ], 'body' => '{}' ];

		$limiter = new TieredRateLimiter( new DatabaseRateLimiter() );
		$ip      = ( new ClientIp() )->get();

		for ( $i = 0; $i < 10; $i++ ) {
			// The endpoint's own 10/min IP bucket would mask the hourly one;
			// clear it between attempts to simulate a slow, window-spanning attack.
			$limiter->reset( 'password_ip', $ip, TieredRateLimiter::REQUEST_IP );
			$this->response_queue = [ $workos_reject, $send_ok ];

			$response = $this->dispatch_with_nonce(
				'POST',
				'/workos/v1/auth/password/authenticate',
				[
					'profile'  => 'members',
					'email'    => "confirm_ip_{$i}@example.com",
					'password' => 'wp_password_123',
				]
			);
			$this->assertSame( 200, $response->get_status(), "Send {$i} should be allowed." );
		}

		$limiter->reset( 'password_ip', $ip, TieredRateLimiter::REQUEST_IP );
		$this->response_queue = [ $workos_reject ];

		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/password/authenticate',
			[
				'profile'  => 'members',
				'email'    => 'confirm_ip_10@example.com',
				'password' => 'wp_password_123',
			]
		);
		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'workos_rate_limited', $response->get_data()['code'] );

		$magic_calls = array_filter(
			$this->captured,
			static fn( array $c ) => str_contains( $c['url'], '/user_management/magic_auth/send' )
		);
		$this->assertCount( 10, $magic_calls, 'The over-limit attempt must not send another code.' );
	}

	/**
	 * WorkOS mails a verification OTP as a side effect of authenticating an
	 * unverified account and only reports it afterwards. The reserved slot is
	 * kept on `email_verification_required`, so the 4th attempt is refused
	 * before WorkOS is called, even with no WP user for the fallback path.
	 */
	public function test_authenticate_rate_limits_workos_verification_sends(): void {
		$verification_reject = [
			'response' => [ 'code' => 403, 'message' => 'Forbidden' ],
			'body'     => wp_json_encode(
				[
					'code'                         => 'email_verification_required',
					'message'                      => 'Email ownership must be verified before authentication.',
					'email'                        => 'ghost@example.com',
					'pending_authentication_token' => 'pat_123',
				]
			),
		];

		$body = [
			'profile'  => 'members',
			'email'    => 'ghost@example.com',
			'password' => 'CorrectPassword!1',
		];

		for ( $i = 0; $i < 3; $i++ ) {
			$this->response_queue = [ $verification_reject ];
			$response             = $this->dispatch_with_nonce( 'POST', '/workos/v1/auth/password/authenticate', $body );
			$this->assertTrue( $response->get_data()['email_verification_required'] ?? false, "Attempt {$i} should reach WorkOS." );
		}

		$response = $this->dispatch_with_nonce( 'POST', '/workos/v1/auth/password/authenticate', $body );
		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'workos_rate_limited', $response->get_data()['code'] );

		$authenticate_calls = array_filter(
			$this->captured,
			static fn( array $c ) => str_contains( $c['url'], '/user_management/authenticate' )
		);
		$this->assertCount( 3, $authenticate_calls, 'The over-limit attempt must not reach WorkOS at all.' );
	}

	/**
	 * Outcomes that send no email refund the reserved OTP slot: more
	 * successful logins than the send limit all pass.
	 */
	public function test_authenticate_refunds_otp_slot_when_no_email_sent(): void {
		update_user_meta( $this->wp_fallback_user_id, '_workos_user_id', 'user_wos_fallback' );

		$auth_success = [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'body'     => wp_json_encode(
				[
					'access_token'  => 'eyJhbGciOi.eyJzdWIiOiJ1c2VyX3dva28iLCJleHAiOjk5OTk5OTk5OTl9.sig',
					'refresh_token' => 'rt_refund',
					'user'          => [
						'id'             => 'user_wos_fallback',
						'email'          => 'fallback@example.com',
						'email_verified' => true,
					],
				]
			),
		];

		// 4 logins > the 3-per-hour send limit; without refunds the 4th would 429.
		for ( $i = 0; $i < 4; $i++ ) {
			$this->response_queue = [ $auth_success ];
			$response             = $this->dispatch_with_nonce(
				'POST',
				'/workos/v1/auth/password/authenticate',
				[
					'profile'  => 'members',
					'email'    => 'fallback@example.com',
					'password' => 'wp_password_123',
				]
			);
			$this->assertSame( 200, $response->get_status(), "Login {$i} should not be throttled by the send limit." );
		}
	}

	/**
	 * Reset emails are capped per recipient on the hourly bucket: the 4th
	 * request returns 429 and never reaches WorkOS.
	 */
	public function test_reset_start_rate_limits_recipient_hourly(): void {
		$body = [
			'profile' => 'members',
			'email'   => 'repeat@example.com',
		];

		for ( $i = 0; $i < 3; $i++ ) {
			$response = $this->dispatch_with_nonce( 'POST', '/workos/v1/auth/password/reset/start', $body );
			$this->assertSame( 200, $response->get_status(), "Reset {$i} should be allowed." );
		}

		$response = $this->dispatch_with_nonce( 'POST', '/workos/v1/auth/password/reset/start', $body );
		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'workos_rate_limited', $response->get_data()['code'] );

		$reset_calls = array_filter(
			$this->captured,
			static fn( array $c ) => str_contains( $c['url'], '/user_management/password_reset/send' )
		);
		$this->assertCount( 3, $reset_calls, 'The over-limit attempt must not trigger another reset email.' );
	}

	/**
	 * reset_start reads the same `otp_send` buckets as magic/send, so a
	 * recipient exhausted on one endpoint is refused on the other.
	 */
	public function test_reset_start_shares_recipient_bucket_across_endpoints(): void {
		$limiter = new TieredRateLimiter( new DatabaseRateLimiter() );
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertTrue( $limiter->attempt( 'otp_send_recipient', 'repeat@example.com', TieredRateLimiter::SEND_RECIPIENT ) );
		}

		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/password/reset/start',
			[
				'profile' => 'members',
				'email'   => 'repeat@example.com',
			]
		);

		$this->assertSame( 429, $response->get_status() );

		$reset_calls = array_filter(
			$this->captured,
			static fn( array $c ) => str_contains( $c['url'], '/user_management/password_reset/send' )
		);
		$this->assertEmpty( $reset_calls, 'A recipient exhausted elsewhere must not receive a reset email.' );
	}

	// -------------------------------------------------------------------------
	// reset_confirm auto-login tests
	// -------------------------------------------------------------------------

	/**
	 * When `auto_login_after_reset` is on (the default), a successful
	 * confirm runs through LoginCompleter, signs the user in, and returns
	 * `signed_in => true` with the resolved redirect destination.
	 *
	 * Two upstream calls: WorkOS `reset_password` then
	 * `authenticate_with_password` to mint a session.
	 */
	public function test_reset_confirm_auto_signs_user_in_when_toggle_on(): void {
		// reset_password response (carries the user object).
		$reset_body = wp_json_encode(
			[
				'user' => [
					'id'             => 'user_wos_reset',
					'email'          => 'reset_user@example.com',
					'email_verified' => true,
				],
			]
		);
		// authenticate_with_password response that LoginCompleter consumes.
		$auth_body = wp_json_encode(
			[
				'access_token'  => 'access_xyz',
				'refresh_token' => 'refresh_xyz',
				'user'          => [
					'id'             => 'user_wos_reset',
					'email'          => 'reset_user@example.com',
					'email_verified' => true,
				],
			]
		);

		$this->response_queue = [
			[ 'response' => [ 'code' => 200, 'message' => 'OK' ], 'body' => $reset_body ],
			[ 'response' => [ 'code' => 200, 'message' => 'OK' ], 'body' => $auth_body ],
		];

		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/password/reset/confirm',
			[
				'profile'      => 'members',
				'token'        => 'reset_token_abc',
				'new_password' => 'CorrectHorseBatteryStaple!',
				'redirect_url' => home_url( '/dashboard' ),
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['ok'] ?? false );
		$this->assertTrue( $data['signed_in'] ?? false, 'signed_in flag must be true on the auto-login path.' );
		$this->assertSame( home_url( '/dashboard' ), $data['redirect_to'] ?? null );
		$this->assertSame( 'reset_user@example.com', $data['user']['email'] ?? null );

		// Both upstream calls fired.
		$reset_calls = array_filter(
			$this->captured,
			static fn( array $c ) => str_contains( $c['url'], '/user_management/password_reset/confirm' )
		);
		$auth_calls = array_filter(
			$this->captured,
			static fn( array $c ) => str_contains( $c['url'], '/user_management/authenticate' )
		);
		$this->assertCount( 1, $reset_calls );
		$this->assertCount( 1, $auth_calls );

		// WP cookie was set.
		$this->assertGreaterThan( 0, get_current_user_id(), 'A WP session must exist after auto-login.' );
	}

	/**
	 * With `auto_login_after_reset` disabled the endpoint returns the
	 * plain "reset finished" payload, makes no second upstream call, and
	 * leaves the visitor logged out.
	 */
	public function test_reset_confirm_skips_auto_login_when_toggle_off(): void {
		// Replace the profile with one that has the toggle off.
		$this->repository->save(
			Profile::from_array(
				[
					'id'                     => $this->profile->get_id(),
					'slug'                   => 'members',
					'title'                  => 'Members',
					'methods'                => [ Profile::METHOD_PASSWORD ],
					'password_reset_flow'    => true,
					'auto_login_after_reset' => false,
				]
			)
		);

		$reset_body = wp_json_encode(
			[
				'user' => [
					'id'             => 'user_wos_reset2',
					'email'          => 'reset2@example.com',
					'email_verified' => true,
				],
			]
		);
		$this->response_queue = [
			[ 'response' => [ 'code' => 200, 'message' => 'OK' ], 'body' => $reset_body ],
		];

		wp_set_current_user( 0 );

		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/password/reset/confirm',
			[
				'profile'      => 'members',
				'token'        => 'reset_token_def',
				'new_password' => 'AnotherSecurePass!42',
				'redirect_url' => home_url( '/welcome' ),
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['ok'] ?? false );
		$this->assertArrayNotHasKey( 'signed_in', $data, 'signed_in must not surface when the toggle is off.' );
		$this->assertArrayNotHasKey( 'user', $data );
		$this->assertSame( home_url( '/welcome' ), $data['redirect_url'] ?? null );

		// Only the reset call fired — no second authenticate.
		$auth_calls = array_filter(
			$this->captured,
			static fn( array $c ) => str_contains( $c['url'], '/user_management/authenticate' )
		);
		$this->assertEmpty( $auth_calls );

		// Visitor remains logged out.
		$this->assertSame( 0, get_current_user_id() );
	}

	/**
	 * If the post-reset authenticate call surfaces an MFA challenge, the
	 * confirm response carries the standard MFA payload so the React shell
	 * hands off to the existing `mfa` step instead of treating the user as
	 * signed in.
	 */
	public function test_reset_confirm_surfaces_mfa_challenge(): void {
		$reset_body = wp_json_encode(
			[
				'user' => [
					'id'    => 'user_wos_mfa',
					'email' => 'mfa@example.com',
				],
			]
		);
		$mfa_body = wp_json_encode(
			[
				'pending_authentication_token' => 'pending_xyz',
				'authentication_factors'       => [
					[ 'id' => 'auth_factor_1', 'type' => 'totp' ],
				],
			]
		);

		$this->response_queue = [
			[ 'response' => [ 'code' => 200, 'message' => 'OK' ], 'body' => $reset_body ],
			[ 'response' => [ 'code' => 200, 'message' => 'OK' ], 'body' => $mfa_body ],
		];

		wp_set_current_user( 0 );

		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/password/reset/confirm',
			[
				'profile'      => 'members',
				'token'        => 'reset_token_mfa',
				'new_password' => 'StrongPassword4MFA!',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['mfa_required'] ?? false );
		$this->assertSame( 'pending_xyz', $data['pending_authentication_token'] ?? '' );
		$this->assertFalse( $data['signed_in'] ?? null, 'signed_in must NOT be true on the MFA path.' );
		// User is not yet signed in — MFA verify will complete the session.
		$this->assertSame( 0, get_current_user_id() );
	}

	/**
	 * After a successful reset the WP user row's `user_pass` is updated to
	 * match the new password. Keeps `?fallback=1` / `wp_authenticate` /
	 * REST app passwords from drifting away from what the user typed in
	 * the React shell.
	 */
	public function test_reset_confirm_mirrors_new_password_to_wp_user(): void {
		// Link the existing fallback WP user to a deterministic WorkOS id
		// so the mirror lookup resolves to a row we can assert against.
		$workos_id = 'user_wos_mirror';
		update_user_meta( $this->wp_fallback_user_id, '_workos_user_id', $workos_id );

		$reset_body = wp_json_encode(
			[
				'user' => [
					'id'             => $workos_id,
					'email'          => 'fallback@example.com',
					'email_verified' => true,
				],
			]
		);
		$auth_body = wp_json_encode(
			[
				'access_token' => 'access_xyz',
				'user'         => [
					'id'             => $workos_id,
					'email'          => 'fallback@example.com',
					'email_verified' => true,
				],
			]
		);

		$this->response_queue = [
			[ 'response' => [ 'code' => 200, 'message' => 'OK' ], 'body' => $reset_body ],
			[ 'response' => [ 'code' => 200, 'message' => 'OK' ], 'body' => $auth_body ],
		];

		$new_password = 'BrandNewPassword4Mirror!';

		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/password/reset/confirm',
			[
				'profile'      => 'members',
				'token'        => 'reset_token_mirror',
				'new_password' => $new_password,
			]
		);
		$this->assertSame( 200, $response->get_status() );

		// The WP user can now authenticate with the new plaintext password.
		$auth = wp_authenticate( 'fallback_user', $new_password );
		$this->assertInstanceOf( \WP_User::class, $auth );
		$this->assertSame( $this->wp_fallback_user_id, $auth->ID );

		// And the *old* WP password no longer works.
		$failed = wp_authenticate( 'fallback_user', 'wp_password_123' );
		$this->assertInstanceOf( \WP_Error::class, $failed );
	}

	/**
	 * Password mirror also runs when `auto_login_after_reset` is off — the
	 * WP password must follow the WorkOS one regardless of session policy.
	 */
	public function test_reset_confirm_mirrors_password_even_without_auto_login(): void {
		// Auto-login OFF.
		$this->repository->save(
			Profile::from_array(
				[
					'id'                     => $this->profile->get_id(),
					'slug'                   => 'members',
					'methods'                => [ Profile::METHOD_PASSWORD ],
					'password_reset_flow'    => true,
					'auto_login_after_reset' => false,
				]
			)
		);

		$workos_id = 'user_wos_mirror_no_auto';
		update_user_meta( $this->wp_fallback_user_id, '_workos_user_id', $workos_id );

		$this->response_queue = [
			[
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'body'     => wp_json_encode(
					[ 'user' => [ 'id' => $workos_id, 'email' => 'fallback@example.com' ] ]
				),
			],
		];

		$new_password = 'AnotherStrong1MirrorPass!';

		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/password/reset/confirm',
			[
				'profile'      => 'members',
				'token'        => 'reset_token_mirror_no_auto',
				'new_password' => $new_password,
			]
		);
		$this->assertSame( 200, $response->get_status() );

		$auth = wp_authenticate( 'fallback_user', $new_password );
		$this->assertInstanceOf( \WP_User::class, $auth );
	}

	/**
	 * When the WorkOS user is not linked to a WP user the mirror silently
	 * no-ops — the reset still returns 200 and the call to authenticate
	 * still fires (auto-login on by default).
	 */
	public function test_reset_confirm_skips_mirror_when_user_not_linked(): void {
		// Make sure the fallback user is NOT linked.
		delete_user_meta( $this->wp_fallback_user_id, '_workos_user_id' );

		$workos_id = 'user_wos_no_link';

		$this->response_queue = [
			[
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'body'     => wp_json_encode(
					[ 'user' => [ 'id' => $workos_id, 'email' => 'stranger@example.com' ] ]
				),
			],
			// auto_login attempt — return an MFA-shaped result so LoginCompleter
			// hands back early without trying to mint a session for a user it
			// can't resolve.
			[ 'response' => [ 'code' => 200, 'message' => 'OK' ], 'body' => '{}' ],
		];

		$old_hash = get_userdata( $this->wp_fallback_user_id )->user_pass;

		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/password/reset/confirm',
			[
				'profile'      => 'members',
				'token'        => 'reset_token_no_link',
				'new_password' => 'WhateverPassword!42',
			]
		);
		$this->assertSame( 200, $response->get_status() );

		// fallback user's password hash is unchanged.
		$this->assertSame(
			$old_hash,
			get_userdata( $this->wp_fallback_user_id )->user_pass,
			'Unlinked users must NOT have their WP password touched.'
		);
	}

	/**
	 * Off-site redirect_url is rejected (validated against home_url) on the
	 * confirm endpoint too — same policy as start.
	 */
	public function test_reset_confirm_rejects_off_site_redirect_url(): void {
		// Disable auto-login so the redirect_url surfaces as `redirect_url`
		// in the plain response (vs being merged into LoginCompleter's
		// `redirect_to`).
		$this->repository->save(
			Profile::from_array(
				[
					'id'                     => $this->profile->get_id(),
					'slug'                   => 'members',
					'methods'                => [ Profile::METHOD_PASSWORD ],
					'password_reset_flow'    => true,
					'auto_login_after_reset' => false,
				]
			)
		);

		$this->response_queue = [
			[ 'response' => [ 'code' => 200, 'message' => 'OK' ], 'body' => '{"user":{"id":"u","email":"e@example.com"}}' ],
		];

		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/password/reset/confirm',
			[
				'profile'      => 'members',
				'token'        => 'reset_token_evil',
				'new_password' => 'StillAStrongPassword!1',
				'redirect_url' => 'https://evil.example/landing',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( home_url( '/' ), $data['redirect_url'] ?? null );
	}

	// -------------------------------------------------------------------------

	/**
	 * Rate limiter: 11th attempt from the same IP returns 429.
	 */
	public function test_authenticate_rate_limits_by_ip(): void {
		for ( $i = 0; $i < 10; $i++ ) {
			$this->dispatch_with_nonce(
				'POST',
				'/workos/v1/auth/password/authenticate',
				[
					'profile'  => 'members',
					'email'    => 'alice@example.com',
					'password' => 'hunter2',
				]
			);
		}

		$response = $this->dispatch_with_nonce(
			'POST',
			'/workos/v1/auth/password/authenticate',
			[
				'profile'  => 'members',
				'email'    => 'alice@example.com',
				'password' => 'hunter2',
			]
		);

		$this->assertSame( 429, $response->get_status() );
	}

	/**
	 * The two subjects on this route diverge on purpose.
	 *
	 * Password guessing is counted against the address as typed, so two
	 * addresses that are separate WorkOS accounts keep separate budgets and
	 * an attacker on one can't lock the other out. Mail is counted against
	 * the mailbox, so tag variants of one inbox share a send allowance.
	 */
	public function test_guessing_counts_per_address_while_sends_count_per_mailbox(): void {
		$limiter = new TieredRateLimiter( new DatabaseRateLimiter() );
		$canon   = ( new AddressCanonicalizer() )->canonical( 'alice+work@gmail.com' );

		// The guessing bucket is keyed on the exact address, so the tag
		// variant is untouched by attempts against the bare address.
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertTrue(
				$limiter->attempt( 'password_email', 'alice@gmail.com', TieredRateLimiter::REQUEST_SUBJECT ),
				"Attempt {$i} on the bare address should be allowed."
			);
		}
		$this->assertInstanceOf(
			\WP_Error::class,
			$limiter->attempt( 'password_email', 'alice@gmail.com', TieredRateLimiter::REQUEST_SUBJECT )
		);
		$this->assertTrue(
			$limiter->attempt( 'password_email', 'alice+work@gmail.com', TieredRateLimiter::REQUEST_SUBJECT ),
			'A separate account must keep its own guessing budget.'
		);

		// The send bucket is keyed on the mailbox, so both variants spend it.
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertTrue( $limiter->attempt( 'password_otp_recipient', $canon, TieredRateLimiter::SEND_RECIPIENT ) );
		}
		$this->assertSame(
			$canon,
			( new AddressCanonicalizer() )->canonical( 'alice@gmail.com' ),
			'Both variants must reduce to the same mailbox.'
		);
		$this->assertInstanceOf(
			\WP_Error::class,
			$limiter->attempt( 'password_otp_recipient', $canon, TieredRateLimiter::SEND_RECIPIENT )
		);
	}
}
