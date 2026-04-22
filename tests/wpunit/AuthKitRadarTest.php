<?php
/**
 * Tests for the AuthKit Radar helper.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Auth\AuthKit\Radar;
use WP_REST_Request;

/**
 * Site-key resolution + request header extraction.
 */
class AuthKitRadarTest extends WPTestCase {

	/**
	 * Radar helper under test.
	 *
	 * @var Radar
	 */
	private Radar $radar;

	/**
	 * Set up — configure a plugin options row and reset state.
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

		$this->radar = new Radar();
	}

	public function tearDown(): void {
		delete_option( 'workos_production' );
		\WorkOS\Config::set_active_environment( 'staging' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		parent::tearDown();
	}

	/**
	 * Absent site key means Radar is disabled.
	 */
	public function test_disabled_when_no_site_key(): void {
		$this->assertFalse( $this->radar->is_enabled() );
		$this->assertSame( '', $this->radar->get_site_key() );
	}

	/**
	 * Option-based site key enables Radar.
	 */
	public function test_enabled_when_site_key_option_present(): void {
		$options                     = get_option( 'workos_production' );
		$options[ Radar::SITE_KEY_OPTION ] = 'radar_pub_123';
		update_option( 'workos_production', $options );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$this->assertTrue( $this->radar->is_enabled() );
		$this->assertSame( 'radar_pub_123', $this->radar->get_site_key() );
	}

	/**
	 * extract_from_request returns the header value.
	 */
	public function test_extract_from_request_returns_trimmed_token(): void {
		$request = new WP_REST_Request( 'POST', '/test' );
		$request->set_header( Radar::REQUEST_HEADER, '  token_value  ' );

		$this->assertSame( 'token_value', $this->radar->extract_from_request( $request ) );
	}

	/**
	 * extract_from_request returns null when header is missing.
	 */
	public function test_extract_from_request_returns_null_when_missing(): void {
		$request = new WP_REST_Request( 'POST', '/test' );

		$this->assertNull( $this->radar->extract_from_request( $request ) );
	}

	/**
	 * extract_from_request returns null when header is blank whitespace only.
	 */
	public function test_extract_from_request_returns_null_when_blank(): void {
		$request = new WP_REST_Request( 'POST', '/test' );
		$request->set_header( Radar::REQUEST_HEADER, '   ' );

		$this->assertNull( $this->radar->extract_from_request( $request ) );
	}
}
