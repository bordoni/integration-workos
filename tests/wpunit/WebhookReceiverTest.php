<?php
/**
 * Tests for Webhook\Receiver::handle().
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Webhook\Receiver;

/**
 * Webhook receiver tests.
 */
class WebhookReceiverTest extends WPTestCase {

	/**
	 * Receiver instance.
	 *
	 * @var Receiver
	 */
	private Receiver $receiver;

	/**
	 * Set up each test.
	 */
	public function setUp(): void {
		parent::setUp();

		\WorkOS\Config::set_active_environment( 'production' );
		update_option( 'workos_production', [
			'api_key'        => 'sk_test_fake',
			'client_id'      => 'client_fake',
			'environment_id' => 'environment_test',
		] );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$this->receiver = new Receiver();
	}

	/**
	 * Tear down each test.
	 */
	public function tearDown(): void {
		delete_option( 'workos_production' );
		\WorkOS\Config::set_active_environment( 'staging' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		parent::tearDown();
	}

	/**
	 * Build a fake REST request.
	 *
	 * @param string      $body      JSON body.
	 * @param string|null $signature Signature header value.
	 *
	 * @return \WP_REST_Request
	 */
	private function build_request( string $body, ?string $signature = null ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/workos/v1/webhook' );
		$request->set_body( $body );

		if ( null !== $signature ) {
			$request->set_header( 'workos-signature', $signature );
		}

		return $request;
	}

	/**
	 * Build a valid signature for a payload.
	 *
	 * @param string $payload Payload body.
	 * @param string $secret  Webhook secret.
	 *
	 * @return string Signature header.
	 */
	private function build_signature( string $payload, string $secret ): string {
		$timestamp = time();
		$hash      = hash_hmac( 'sha256', "{$timestamp}.{$payload}", $secret );
		return "t={$timestamp}, v1={$hash}";
	}

	/**
	 * Test rejects invalid signature.
	 */
	public function test_rejects_invalid_signature(): void {
		$opts                   = get_option( 'workos_production' );
		$opts['webhook_secret'] = 'whsec_test_secret';
		update_option( 'workos_production', $opts );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$body    = wp_json_encode( [ 'event' => 'user.created', 'data' => [] ] );
		$request = $this->build_request( $body, 't=' . time() . ', v1=invalidsignaturevalue' );

		$result = $this->receiver->verify_signature( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'workos_invalid_signature', $result->get_error_code() );
	}

	/**
	 * Test rejects missing signature when secret is configured.
	 */
	public function test_rejects_missing_signature(): void {
		$opts                   = get_option( 'workos_production' );
		$opts['webhook_secret'] = 'whsec_test_secret';
		update_option( 'workos_production', $opts );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$body    = wp_json_encode( [ 'event' => 'user.created', 'data' => [] ] );
		$request = $this->build_request( $body );

		$result = $this->receiver->verify_signature( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'workos_missing_signature', $result->get_error_code() );
	}

	/**
	 * Test rejects request when no webhook secret is configured.
	 */
	public function test_rejects_when_no_secret_configured(): void {
		$body    = wp_json_encode( [ 'event' => 'user.created', 'data' => [] ] );
		$request = $this->build_request( $body );

		$result = $this->receiver->verify_signature( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'workos_webhook_not_configured', $result->get_error_code() );
	}

	/**
	 * Test rejects invalid event payload.
	 */
	public function test_rejects_invalid_event_payload(): void {
		$body    = wp_json_encode( [ 'data' => [] ] ); // Missing 'event' key.
		$request = $this->build_request( $body );

		$response = $this->receiver->handle( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Test routes known event to specific action.
	 */
	public function test_routes_known_event_to_action(): void {
		$fired = false;
		add_action( 'workos_webhook_user.created', function () use ( &$fired ) {
			$fired = true;
		} );

		$body    = wp_json_encode( [ 'event' => 'user.created', 'data' => [ 'id' => 'user_1' ] ] );
		$request = $this->build_request( $body );

		$response = $this->receiver->handle( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $fired, 'workos_webhook_user.created action should have fired.' );
	}

	/**
	 * Test fires generic workos_webhook action.
	 */
	public function test_fires_generic_action(): void {
		$captured_type = '';
		add_action( 'workos_webhook', function ( $event, $type ) use ( &$captured_type ) {
			$captured_type = $type;
		}, 10, 2 );

		$body    = wp_json_encode( [ 'event' => 'user.updated', 'data' => [] ] );
		$request = $this->build_request( $body );

		$this->receiver->handle( $request );

		$this->assertSame( 'user.updated', $captured_type );
	}

	/**
	 * Test ignores unknown event types (no specific action, generic fires).
	 */
	public function test_ignores_unknown_event_types(): void {
		$specific_fired = false;
		add_action( 'workos_webhook_custom.unknown', function () use ( &$specific_fired ) {
			$specific_fired = true;
		} );

		$generic_fired = false;
		add_action( 'workos_webhook', function () use ( &$generic_fired ) {
			$generic_fired = true;
		} );

		$body    = wp_json_encode( [ 'event' => 'custom.unknown', 'data' => [] ] );
		$request = $this->build_request( $body );

		$response = $this->receiver->handle( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $specific_fired, 'Unknown event should not fire specific action.' );
		$this->assertTrue( $generic_fired, 'Generic action should still fire.' );
	}

	/**
	 * Test returns 200 on success with valid signature.
	 */
	public function test_returns_200_on_success(): void {
		$secret                 = 'whsec_test_valid';
		$opts                   = get_option( 'workos_production' );
		$opts['webhook_secret'] = $secret;
		update_option( 'workos_production', $opts );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$body      = wp_json_encode( [ 'event' => 'user.created', 'data' => [ 'id' => 'user_1' ] ] );
		$signature = $this->build_signature( $body, $secret );
		$request   = $this->build_request( $body, $signature );

		$this->assertTrue( $this->receiver->verify_signature( $request ) );

		$response = $this->receiver->handle( $request );
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Test full REST dispatch rejects invalid signature via permission_callback.
	 *
	 * This integration test ensures the permission_callback is wired correctly
	 * in the route registration so signature checks cannot be bypassed.
	 */
	public function test_dispatch_rejects_invalid_signature(): void {
		$secret                 = 'whsec_dispatch_test';
		$opts                   = get_option( 'workos_production' );
		$opts['webhook_secret'] = $secret;
		update_option( 'workos_production', $opts );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		// Initialize the REST server, which fires rest_api_init and registers our route.
		$server = rest_get_server();
		do_action( 'rest_api_init', $server );

		$body    = wp_json_encode( [ 'event' => 'user.created', 'data' => [] ] );
		$request = $this->build_request( $body, 't=' . time() . ', v1=bad' );

		$response = $server->dispatch( $request );

		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
		$this->assertLessThan( 500, $response->get_status() );
	}

	/**
	 * Test full REST dispatch accepts valid signature via permission_callback.
	 */
	public function test_dispatch_accepts_valid_signature(): void {
		$secret                 = 'whsec_dispatch_test';
		$opts                   = get_option( 'workos_production' );
		$opts['webhook_secret'] = $secret;
		update_option( 'workos_production', $opts );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		// Initialize the REST server, which fires rest_api_init and registers our route.
		$server = rest_get_server();
		do_action( 'rest_api_init', $server );

		$body      = wp_json_encode( [ 'event' => 'user.created', 'data' => [ 'id' => 'user_1' ] ] );
		$signature = $this->build_signature( $body, $secret );
		$request   = $this->build_request( $body, $signature );

		$response = $server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
	}
}
