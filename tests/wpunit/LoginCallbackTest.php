<?php
/**
 * Tests for Login::handle_callback().
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Auth\Login;

/**
 * Hosted AuthKit callback outcomes.
 */
class LoginCallbackTest extends WPTestCase {

	/**
	 * Login instance.
	 *
	 * @var Login
	 */
	private Login $login;

	/**
	 * Next WorkOS HTTP response.
	 *
	 * @var array
	 */
	private array $workos_response = [];

	/**
	 * Set up each test.
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

		$this->login = new Login();

		add_filter( 'pre_http_request', fn() => $this->workos_response, 10, 0 );

		// The callback exits after redirecting; surface the redirect instead.
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new \RuntimeException( 'Redirected to ' . $location );
			}
		);
	}

	/**
	 * Tear down each test.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'wp_redirect' );
		unset( $_GET['code'], $_GET['state'] );
		set_query_var( 'workos_callback', '' );

		delete_option( 'workos_production' );
		\WorkOS\Config::set_active_environment( 'staging' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		parent::tearDown();
	}

	/**
	 * The hosted callback has no code step, so a sign-in WorkOS pauses for
	 * email verification is refused rather than redirected without a session.
	 */
	public function test_email_verification_required_is_refused(): void {
		$this->workos_response = [
			'response' => [ 'code' => 403, 'message' => 'Forbidden' ],
			'body'     => wp_json_encode(
				[
					'code'                         => 'email_verification_required',
					'message'                      => 'Email ownership must be verified before authentication.',
					'email'                        => 'ghost@example.net',
					'pending_authentication_token' => 'pat_callback',
					'email_verification_id'        => 'email_verification_1',
				]
			),
		];

		set_query_var( 'workos_callback', '1' );
		$_GET['code']  = 'auth_code';
		$_GET['state'] = wp_create_nonce( 'workos_auth' ) . '|' . admin_url();

		$this->expectException( \WPDieException::class );
		$this->expectExceptionMessage( 'Please try again.' );

		$this->login->handle_callback();
	}
}
