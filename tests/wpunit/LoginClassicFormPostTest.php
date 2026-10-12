<?php
/**
 * Tests for Login::keep_classic_login_on_post().
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Auth\Login;
use WorkOS\Auth\LoginBypass;

/**
 * The classic login form keeps ?fallback=1 / ?workos=0 on its POST.
 */
class LoginClassicFormPostTest extends WPTestCase {

	/**
	 * Login instance.
	 *
	 * @var Login
	 */
	private Login $login;

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

		$this->login = new Login();
		$this->set_bypass( false );
	}

	/**
	 * Tear down each test.
	 */
	public function tearDown(): void {
		unset( $_GET['fallback'], $_GET['workos'] );
		$this->set_bypass( false );

		delete_option( 'workos_production' );
		\WorkOS\Config::set_active_environment( 'staging' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		parent::tearDown();
	}

	/**
	 * Set LoginBypass's static flag.
	 *
	 * @param bool $active Whether the bypass is active.
	 */
	private function set_bypass( bool $active ): void {
		$ref = new \ReflectionProperty( LoginBypass::class, 'active' );
		$ref->setAccessible( true );
		$ref->setValue( null, $active );
	}

	/**
	 * Update one option in the production settings.
	 *
	 * @param string $key   Option key.
	 * @param mixed  $value Value.
	 */
	private function set_option( string $key, $value ): void {
		$opts         = get_option( 'workos_production' );
		$opts[ $key ] = $value;
		update_option( 'workos_production', $opts );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();
	}

	/**
	 * Query args of a URL.
	 *
	 * @param string $url URL.
	 *
	 * @return array
	 */
	private function query_of( string $url ): array {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $args );
		return $args;
	}

	/**
	 * ?fallback=1 is carried onto the form's POST URL.
	 */
	public function test_carries_fallback_onto_login_post_url(): void {
		$_GET['fallback'] = '1';

		$url = $this->login->keep_classic_login_on_post( 'https://example.org/wp-login.php', 'wp-login.php', 'login_post' );

		$this->assertSame( '1', $this->query_of( $url )['fallback'] ?? null );
	}

	/**
	 * Through site_url(), the way wp-login.php builds its form action.
	 */
	public function test_site_url_login_post_keeps_fallback(): void {
		$_GET['fallback'] = '1';

		$url = site_url( 'wp-login.php', 'login_post' );

		$this->assertSame( '1', $this->query_of( $url )['fallback'] ?? null );
	}

	/**
	 * Existing query args (e.g. action=lostpassword) are kept.
	 */
	public function test_keeps_existing_query_args(): void {
		$_GET['fallback'] = '1';

		$url  = $this->login->keep_classic_login_on_post( 'https://example.org/wp-login.php?action=lostpassword', 'wp-login.php?action=lostpassword', 'login_post' );
		$args = $this->query_of( $url );

		$this->assertSame( 'lostpassword', $args['action'] ?? null );
		$this->assertSame( '1', $args['fallback'] ?? null );
	}

	/**
	 * Without ?fallback=1 the URL is unchanged.
	 */
	public function test_unchanged_without_fallback_request(): void {
		$url = 'https://example.org/wp-login.php';

		$this->assertSame( $url, $this->login->keep_classic_login_on_post( $url, 'wp-login.php', 'login_post' ) );
	}

	/**
	 * When password fallback is turned off, ?fallback=1 isn't carried.
	 */
	public function test_unchanged_when_password_fallback_disabled(): void {
		$this->set_option( 'allow_password_fallback', false );
		$_GET['fallback'] = '1';
		$url              = 'https://example.org/wp-login.php';

		$this->assertSame( $url, $this->login->keep_classic_login_on_post( $url, 'wp-login.php', 'login_post' ) );
	}

	/**
	 * Only the form-post scheme is touched; links built with other schemes are left alone.
	 */
	public function test_unchanged_for_other_schemes(): void {
		$_GET['fallback'] = '1';
		$url              = 'https://example.org/wp-login.php';

		foreach ( [ 'login', 'admin', 'https', null ] as $scheme ) {
			$this->assertSame( $url, $this->login->keep_classic_login_on_post( $url, 'wp-login.php', $scheme ) );
		}
	}

	/**
	 * An active ?workos=0 bypass is carried onto the POST URL too.
	 */
	public function test_carries_active_bypass(): void {
		$this->set_bypass( true );

		$url = $this->login->keep_classic_login_on_post( 'https://example.org/wp-login.php', 'wp-login.php', 'login_post' );

		$this->assertSame( '0', $this->query_of( $url )['workos'] ?? null );
	}

	/**
	 * A ?workos=0 that didn't activate the bypass (bypass not enabled) isn't carried.
	 */
	public function test_unchanged_when_bypass_not_active(): void {
		$_GET['workos'] = '0';
		$url            = 'https://example.org/wp-login.php';

		$this->assertSame( $url, $this->login->keep_classic_login_on_post( $url, 'wp-login.php', 'login_post' ) );
	}

	/**
	 * Nothing changes when the plugin isn't configured.
	 */
	public function test_unchanged_when_plugin_disabled(): void {
		delete_option( 'workos_production' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();
		$_GET['fallback'] = '1';
		$url              = 'https://example.org/wp-login.php';

		$this->assertSame( $url, $this->login->keep_classic_login_on_post( $url, 'wp-login.php', 'login_post' ) );
	}
}
