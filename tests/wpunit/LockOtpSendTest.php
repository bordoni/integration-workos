<?php
/**
 * End-to-end coverage for one-time-code delivery throttling.
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
use WorkOS\Http\ClientIp;
use WorkOS\RateLimit\RateLimiter;
use WorkOS\RateLimit\TransientStore;
use WorkOS\Time\SystemClock;
use WorkOS\REST\Auth\MagicCode;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Repeated code requests for one address stop reaching WorkOS, and stay stopped
 * across a cache flush.
 *
 * The durability half is the point of the exercise. The burst limiter that runs
 * ahead of the lock keeps its counters in transients and an object-cache group,
 * both of which a flush empties — after which its budget is restored in full.
 * The lock's counters live in the database and are unaffected.
 *
 * @covers \WorkOS\REST\Auth\MagicCode
 * @covers \WorkOS\Lock\Locks\OtpSendLock
 */
class LockOtpSendTest extends WPTestCase {

	private const ROUTE = '/workos/v1/auth/magic/send';

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
	 * Captured outbound HTTP.
	 *
	 * @var array<int, array>
	 */
	private array $captured = [];

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

		$this->repository->save(
			Profile::from_array(
				[
					'slug'    => 'codes',
					'title'   => 'Codes',
					'methods' => [ Profile::METHOD_MAGIC_CODE ],
				]
			)
		);

		$this->nonce = new Nonce();

		$magic = new MagicCode(
			$this->repository,
			$this->nonce,
			new Radar(),
			new RateLimiter( new TransientStore(), new SystemClock() ),
			new ClientIp(),
			new LoginCompleter(),
			\WorkOS\App::container()->get( \WorkOS\Lock\Registry::class )
		);

		add_action( 'rest_api_init', [ $magic, 'register_routes' ] );
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
		$this->captured = [];

		foreach ( $this->repository->all() as $profile ) {
			wp_delete_post( $profile->get_id(), true );
		}

		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'workos\_lock\_%'" );
		$this->flush_burst_limiter();

		wp_set_current_user( 0 );
		delete_option( 'workos_production' );
		delete_option( 'workos_active_environment' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		parent::tearDown();
	}

	/**
	 * Capture outbound calls and answer them successfully.
	 *
	 * @param mixed  $preempt Short-circuit value.
	 * @param array  $args    Request args.
	 * @param string $url     Request URL.
	 *
	 * @return array
	 */
	public function intercept_http( $preempt, array $args, string $url ): array {
		$this->captured[] = [
			'url'  => $url,
			'body' => $args['body'] ?? '',
		];

		return [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'body'     => '{}',
		];
	}

	/**
	 * Outbound calls that actually asked WorkOS to send a code.
	 *
	 * @return int
	 */
	private function sends(): int {
		return count(
			array_filter(
				$this->captured,
				static fn( array $call ): bool => str_contains( $call['url'], '/magic_auth/send' )
			)
		);
	}

	/**
	 * Empty everything the burst limiter keeps its counters in.
	 *
	 * Mirrors what a cache flush, an eviction, or a restart of a non-persistent
	 * object cache does in production.
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
	 * Post a code request for an address.
	 *
	 * @param string $email Address to request a code for.
	 *
	 * @return WP_REST_Response
	 */
	private function request_code( string $email ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WorkOS-Nonce', $this->nonce->mint( 'codes' ) );
		$request->set_body( wp_json_encode( [ 'profile' => 'codes', 'email' => $email ] ) );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * A sustained run against one address stops being delivered.
	 */
	public function test_repeated_requests_stop_reaching_workos(): void {
		$statuses = [];

		for ( $i = 0; $i < 20; $i++ ) {
			$statuses[] = $this->request_code( 'target@example.com' )->get_status();
		}

		$accepted = count( array_filter( $statuses, static fn( int $s ): bool => 200 === $s ) );
		$refused  = count( array_filter( $statuses, static fn( int $s ): bool => 429 === $s ) );

		$this->assertSame( 20, $accepted + $refused, 'Every request is either accepted or refused.' );
		$this->assertLessThanOrEqual( 5, $this->sends(), 'At most the per-address allowance is delivered.' );
		$this->assertGreaterThan( 0, $refused, 'The run is cut off before it finishes.' );

		// Nothing further is delivered no matter how long the run continues.
		$before = $this->sends();
		for ( $i = 0; $i < 20; $i++ ) {
			$this->assertSame( 429, $this->request_code( 'target@example.com' )->get_status() );
		}
		$this->assertSame( $before, $this->sends() );
	}

	/**
	 * The refusal survives a cache flush.
	 *
	 * This is the behaviour the burst limiter alone cannot provide.
	 */
	public function test_refusal_survives_a_cache_flush(): void {
		for ( $i = 0; $i < 20; $i++ ) {
			$this->request_code( 'persistent@example.com' );
		}

		$this->assertSame( 429, $this->request_code( 'persistent@example.com' )->get_status() );

		$delivered = $this->sends();

		$this->flush_burst_limiter();

		$response = $this->request_code( 'persistent@example.com' );

		$this->assertSame( 429, $response->get_status(), 'A flush must not restore the allowance.' );
		$this->assertSame( $delivered, $this->sends(), 'No further code is delivered after the flush.' );
	}

	/**
	 * A 429 carries the wait and the lock that produced it.
	 */
	public function test_refusal_reports_retry_after(): void {
		for ( $i = 0; $i < 20; $i++ ) {
			$this->request_code( 'retry@example.com' );
		}

		$data = $this->request_code( 'retry@example.com' )->get_data();

		$this->assertSame( 'workos_rate_limited', $data['code'] );
		$this->assertIsInt( $data['data']['retry_after'] );
		$this->assertGreaterThan( 0, $data['data']['retry_after'] );
	}

	/**
	 * Throttling one address does not affect another.
	 */
	public function test_other_addresses_are_unaffected(): void {
		for ( $i = 0; $i < 20; $i++ ) {
			$this->request_code( 'noisy@example.com' );
		}

		$this->flush_burst_limiter();

		$this->assertSame(
			200,
			$this->request_code( 'quiet@example.com' )->get_status(),
			'An unrelated address still gets a code.'
		);
	}

	/**
	 * Counters are kept per address even when no local user exists.
	 *
	 * Accounts are created upstream and only mirrored into WordPress on a
	 * successful login, so this is the ordinary case rather than an edge one.
	 */
	public function test_counting_works_without_a_local_user(): void {
		$this->assertFalse(
			(bool) get_user_by( 'email', 'no-local-user@example.com' ),
			'Fixture assumption: this address has no WordPress user.'
		);

		for ( $i = 0; $i < 20; $i++ ) {
			$this->request_code( 'no-local-user@example.com' );
		}

		$this->flush_burst_limiter();

		$this->assertSame( 429, $this->request_code( 'no-local-user@example.com' )->get_status() );
	}

	/**
	 * An operator clearing the lock lets the address through again.
	 */
	public function test_clearing_the_lock_restores_delivery(): void {
		for ( $i = 0; $i < 20; $i++ ) {
			$this->request_code( 'stuck@example.com' );
		}

		$this->assertSame( 429, $this->request_code( 'stuck@example.com' )->get_status() );

		$lock = \WorkOS\App::container()->get( \WorkOS\Lock\Registry::class )->get( 'otp_send' );
		$lock->release( $lock->for_email( 'stuck@example.com' ) );
		$this->flush_burst_limiter();

		$this->assertSame( 200, $this->request_code( 'stuck@example.com' )->get_status() );
	}
}
