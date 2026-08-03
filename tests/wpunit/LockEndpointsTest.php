<?php
/**
 * Coverage for locks attached to the sign-in and redemption flows.
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
use WorkOS\Auth\AuthKit\RateLimiter;
use WorkOS\Lock\Registry;
use WorkOS\REST\Auth\Password;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Failure-counting locks: repeated wrong answers are bounded, and a correct one
 * wipes the slate.
 *
 * @covers \WorkOS\REST\Auth\Password
 * @covers \WorkOS\Lock\Locks\PasswordAuthLock
 * @covers \WorkOS\Lock\Locks\TokenGuessLock
 */
class LockEndpointsTest extends WPTestCase {

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
	 * Lock registry.
	 *
	 * @var Registry
	 */
	private Registry $locks;

	/**
	 * Next mocked response, keyed by URL substring.
	 *
	 * @var array<string, array>
	 */
	private array $responses = [];

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();

		\WorkOS\Config::set_active_environment( 'production' );
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

		$this->repository = new ProfileRepository();
		$this->repository->register_post_type();
		foreach ( $this->repository->all() as $profile ) {
			wp_delete_post( $profile->get_id(), true );
		}

		$this->repository->save(
			Profile::from_array(
				[
					'slug'                => 'pw',
					'title'               => 'Passwords',
					'methods'             => [ Profile::METHOD_PASSWORD ],
					'password_reset_flow' => true,
				]
			)
		);

		$this->nonce = new Nonce();
		$this->locks = \WorkOS\App::container()->get( Registry::class );

		$password = new Password(
			$this->repository,
			$this->nonce,
			new Radar(),
			new RateLimiter(),
			new LoginCompleter(),
			$this->locks
		);

		add_action( 'rest_api_init', [ $password, 'register_routes' ] );
		do_action( 'rest_api_init', rest_get_server() );

		add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );

		wp_set_current_user( 0 );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		global $wpdb;

		remove_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10 );
		$this->responses = [];

		foreach ( $this->repository->all() as $profile ) {
			wp_delete_post( $profile->get_id(), true );
		}

		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'workos\_lock\_%'" );
		$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE '\_workos\_lock\_%'" );
		$this->flush_burst_limiter();

		wp_set_current_user( 0 );
		delete_option( 'workos_production' );
		delete_option( 'workos_active_environment' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		parent::tearDown();
	}

	/**
	 * Answer outbound calls from the queue, defaulting to a rejection.
	 *
	 * @param mixed  $preempt Short-circuit value.
	 * @param array  $args    Request args.
	 * @param string $url     Request URL.
	 *
	 * @return array
	 */
	public function intercept_http( $preempt, array $args, string $url ): array {
		foreach ( $this->responses as $needle => $response ) {
			if ( str_contains( $url, $needle ) ) {
				return $response;
			}
		}

		return [
			'response' => [ 'code' => 401, 'message' => 'Unauthorized' ],
			'body'     => wp_json_encode( [ 'code' => 'invalid_credentials', 'message' => 'Invalid.' ] ),
		];
	}

	/**
	 * Queue a response for a URL substring.
	 *
	 * @param string $needle URL substring.
	 * @param array  $body   Response body.
	 * @param int    $code   HTTP status.
	 *
	 * @return void
	 */
	private function queue_response( string $needle, array $body, int $code = 200 ): void {
		$this->responses[ $needle ] = [
			'response' => [ 'code' => $code, 'message' => 'OK' ],
			'body'     => wp_json_encode( $body ),
		];
	}

	/**
	 * Clear the burst limiter so the durable lock is what is being observed.
	 *
	 * @return void
	 */
	private function flush_burst_limiter(): void {
		global $wpdb;

		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_workos\_rl\_%'" );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_timeout\_workos\_rl\_%'" );

		wp_cache_flush();
	}

	/**
	 * Attempt a password sign-in.
	 *
	 * @param string $email    Address.
	 * @param string $password Password.
	 *
	 * @return WP_REST_Response
	 */
	private function authenticate( string $email, string $password = 'wrong-password' ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/workos/v1/auth/password/authenticate' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WorkOS-Nonce', $this->nonce->mint( 'pw' ) );
		$request->set_body(
			wp_json_encode( [ 'profile' => 'pw', 'email' => $email, 'password' => $password ] )
		);

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Attempt a reset-token redemption.
	 *
	 * @param string $token Reset token.
	 *
	 * @return WP_REST_Response
	 */
	private function redeem_token( string $token ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/workos/v1/auth/password/reset/confirm' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WorkOS-Nonce', $this->nonce->mint( 'pw' ) );
		$request->set_body(
			wp_json_encode( [ 'profile' => 'pw', 'token' => $token, 'new_password' => 'a-new-password' ] )
		);

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Repeated wrong passwords eventually stop being tried.
	 */
	public function test_repeated_sign_in_failures_engage_the_lock(): void {
		$saw_lock = false;

		for ( $i = 0; $i < 40; $i++ ) {
			$this->flush_burst_limiter();

			$response = $this->authenticate( 'victim@example.com' );

			if ( 429 === $response->get_status() ) {
				$saw_lock = true;
				break;
			}
		}

		$this->assertTrue( $saw_lock, 'Sustained failures must engage the sign-in lock.' );

		// And it stays engaged with the burst limiter out of the way.
		$this->flush_burst_limiter();
		$this->assertSame( 429, $this->authenticate( 'victim@example.com' )->get_status() );
	}

	/**
	 * Failures against one account do not lock another.
	 */
	public function test_sign_in_lock_is_scoped_to_the_account(): void {
		for ( $i = 0; $i < 40; $i++ ) {
			$this->flush_burst_limiter();
			$this->authenticate( 'noisy@example.com' );
		}

		$lock = $this->locks->get( 'password_auth' );

		$this->assertTrue( $lock->status( $lock->for_email( 'noisy@example.com' ) )->breaches() > 0 );
		$this->assertTrue( $lock->status( $lock->for_email( 'bystander@example.com' ) )->is_empty() );
	}

	/**
	 * A successful sign-in clears the accumulated failures.
	 *
	 * Without this, someone who mistypes their own password a few times each
	 * morning would be gradually locked out of an account they own.
	 */
	public function test_successful_sign_in_clears_the_failure_history(): void {
		$lock    = $this->locks->get( 'password_auth' );
		$subject = $lock->for_email( 'forgetful@example.com' );

		for ( $i = 0; $i < 5; $i++ ) {
			$this->flush_burst_limiter();
			$this->authenticate( 'forgetful@example.com' );
		}

		$this->assertGreaterThan( 0, $lock->status( $subject )->strikes() );

		$this->queue_response(
			'/user_management/authenticate',
			[
				'access_token'  => 'eyJhbGciOi.eyJzdWIiOiJ1c2VyXzEiLCJleHAiOjk5OTk5OTk5OTl9.sig',
				'refresh_token' => 'rt_1',
				'user'          => [ 'id' => 'user_1', 'email' => 'forgetful@example.com' ],
			]
		);

		$this->flush_burst_limiter();
		$this->assertSame( 200, $this->authenticate( 'forgetful@example.com', 'correct' )->get_status() );

		$this->assertTrue(
			$lock->status( $lock->for_email( 'forgetful@example.com' ) )->is_empty(),
			'A correct password wipes the slate.'
		);
	}

	/**
	 * A wrong password is not counted against the code-delivery budget.
	 *
	 * The two are separate ledgers: refusing a sign-in sends nothing, so it
	 * must not consume the allowance that governs how many codes an address
	 * receives — otherwise mistyping a password would produce a message about
	 * codes that were never sent.
	 */
	public function test_wrong_password_does_not_consume_the_delivery_budget(): void {
		for ( $i = 0; $i < 8; $i++ ) {
			$this->flush_burst_limiter();
			$this->authenticate( 'typo@example.com' );
		}

		$otp = $this->locks->get( 'otp_send' );

		$this->assertTrue(
			$otp->status( $otp->for_email( 'typo@example.com' ) )->is_empty(),
			'Credential failures leave the delivery budget untouched.'
		);
	}

	/**
	 * A refusal that dispatches a code *is* counted against that budget.
	 *
	 * An address the upstream has not verified gets a code sent on every
	 * attempt, whether or not the caller wanted one — which is exactly the
	 * repetition worth bounding.
	 */
	public function test_verification_refusals_consume_the_delivery_budget(): void {
		$this->queue_response(
			'/user_management/authenticate',
			[ 'code' => 'email_verification_required', 'message' => 'Verify your email.' ],
			403
		);

		for ( $i = 0; $i < 3; $i++ ) {
			$this->flush_burst_limiter();
			$this->authenticate( 'unverified@example.com' );
		}

		$otp = $this->locks->get( 'otp_send' );

		$this->assertSame(
			3,
			$otp->status( $otp->for_email( 'unverified@example.com' ) )->strikes(),
			'Each dispatched code is counted.'
		);
	}

	/**
	 * Sustained verification refusals stop reaching the upstream at all.
	 */
	public function test_verification_refusals_eventually_stop_being_forwarded(): void {
		$this->queue_response(
			'/user_management/authenticate',
			[ 'code' => 'email_verification_required', 'message' => 'Verify your email.' ],
			403
		);

		$saw_lock = false;

		for ( $i = 0; $i < 20; $i++ ) {
			$this->flush_burst_limiter();

			if ( 429 === $this->authenticate( 'flooded@example.com' )->get_status() ) {
				$saw_lock = true;
				break;
			}
		}

		$this->assertTrue( $saw_lock );

		$this->flush_burst_limiter();
		$this->assertSame( 429, $this->authenticate( 'flooded@example.com' )->get_status() );
	}

	/**
	 * Repeated bad reset tokens are bounded.
	 *
	 * This route previously counted by client address only, which left one
	 * token open to being worked on from a spread of addresses.
	 */
	public function test_repeated_token_failures_engage_the_lock(): void {
		$saw_lock = false;

		for ( $i = 0; $i < 40; $i++ ) {
			$this->flush_burst_limiter();

			if ( 429 === $this->redeem_token( 'the-same-token' )->get_status() ) {
				$saw_lock = true;
				break;
			}
		}

		$this->assertTrue( $saw_lock, 'Sustained token failures must engage the lock.' );
	}

	/**
	 * The token is never stored in the clear.
	 */
	public function test_token_subject_is_hashed(): void {
		global $wpdb;

		for ( $i = 0; $i < 20; $i++ ) {
			$this->flush_burst_limiter();
			$this->redeem_token( 'super-secret-token' );
		}

		$rows = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'workos\_lock\_%'" );

		$this->assertNotEmpty( $rows, 'Fixture assumption: something was recorded.' );

		foreach ( $rows as $name ) {
			$this->assertStringNotContainsString( 'super-secret-token', $name );
		}
	}

	/**
	 * Guessing one token does not bar a different one.
	 */
	public function test_token_lock_is_scoped_to_the_token(): void {
		for ( $i = 0; $i < 40; $i++ ) {
			$this->flush_burst_limiter();
			$this->redeem_token( 'token-under-attack' );
		}

		$lock = $this->locks->get( 'token_guess' );

		$this->assertGreaterThan(
			0,
			$lock->status( $lock->for_key( hash( 'sha256', 'token-under-attack' ) ) )->breaches()
		);
		$this->assertTrue(
			$lock->status( $lock->for_key( hash( 'sha256', 'an-unrelated-token' ) ) )->is_empty()
		);
	}
}
