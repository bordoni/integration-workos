<?php
/**
 * Tests for Client::verify_webhook_signature().
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Api\Client;

/**
 * Webhook signature verification tests.
 */
class WebhookSignatureTest extends WPTestCase {

	/**
	 * Secret used for signing.
	 *
	 * @var string
	 */
	private string $secret = 'whsec_test_secret_key';

	/**
	 * Build a valid signature header value.
	 *
	 * @param string $payload   Raw body.
	 * @param string $secret    Signing secret.
	 * @param int    $timestamp Unix timestamp.
	 *
	 * @return string Signature header.
	 */
	private function build_signature( string $payload, string $secret, int $timestamp ): string {
		$hash = hash_hmac( 'sha256', "{$timestamp}.{$payload}", $secret );
		return "t={$timestamp}, v1={$hash}";
	}

	/**
	 * Test valid signature returns true.
	 */
	public function test_valid_signature_returns_true(): void {
		$payload   = '{"event":"user.created"}';
		$timestamp = time();
		$signature = $this->build_signature( $payload, $this->secret, $timestamp );

		$this->assertTrue(
			Client::verify_webhook_signature( $payload, $signature, $this->secret )
		);
	}

	/**
	 * Test invalid signature returns false.
	 */
	public function test_invalid_signature_returns_false(): void {
		$payload   = '{"event":"user.created"}';
		$signature = 't=' . time() . ', v1=invalidsignature';

		$this->assertFalse(
			Client::verify_webhook_signature( $payload, $signature, $this->secret )
		);
	}

	/**
	 * Test expired timestamp returns false.
	 */
	public function test_expired_timestamp_returns_false(): void {
		$payload   = '{"event":"user.created"}';
		$timestamp = time() - 600; // 10 minutes ago, exceeds 300s tolerance.
		$signature = $this->build_signature( $payload, $this->secret, $timestamp );

		$this->assertFalse(
			Client::verify_webhook_signature( $payload, $signature, $this->secret )
		);
	}

	/**
	 * Test missing timestamp returns false.
	 */
	public function test_missing_timestamp_returns_false(): void {
		$hash      = hash_hmac( 'sha256', time() . '.payload', $this->secret );
		$signature = "v1={$hash}";

		$this->assertFalse(
			Client::verify_webhook_signature( 'payload', $signature, $this->secret )
		);
	}

	/**
	 * Test missing v1 returns false.
	 */
	public function test_missing_v1_returns_false(): void {
		$signature = 't=' . time();

		$this->assertFalse(
			Client::verify_webhook_signature( 'payload', $signature, $this->secret )
		);
	}

	/**
	 * Test tolerance boundary — timestamp at exact limit passes.
	 */
	public function test_tolerance_boundary_passes(): void {
		$payload   = '{"event":"test"}';
		$timestamp = time() - 300; // Exactly at 300s tolerance.
		$signature = $this->build_signature( $payload, $this->secret, $timestamp );

		$this->assertTrue(
			Client::verify_webhook_signature( $payload, $signature, $this->secret )
		);
	}

	/**
	 * Test future timestamp within tolerance passes.
	 */
	public function test_future_timestamp_within_tolerance_passes(): void {
		$payload   = '{"event":"test"}';
		$timestamp = time() + 100; // 100s in future, within 300s tolerance.
		$signature = $this->build_signature( $payload, $this->secret, $timestamp );

		$this->assertTrue(
			Client::verify_webhook_signature( $payload, $signature, $this->secret )
		);
	}
}
