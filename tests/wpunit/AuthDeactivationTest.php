<?php
/**
 * Tests for Auth\Deactivation: deactivated accounts can't sign in.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\App;
use WorkOS\Auth\AuthKit\LoginCompleter;
use WorkOS\Auth\AuthKit\Profile;
use WorkOS\Auth\Deactivation;
use WorkOS\Config;
use WorkOS\Options\Production;
use WorkOS\Sync\UserSync;
use WorkOS\User;
use WP_Session_Tokens;

/**
 * Every sign-in path refuses an account WorkOS deprovisioned.
 */
class AuthDeactivationTest extends WPTestCase {

	/**
	 * Set up each test.
	 */
	public function setUp(): void {
		parent::setUp();

		Config::set_active_environment( 'production' );
		update_option(
			'workos_production',
			[
				'api_key'        => 'sk_test_fake',
				'client_id'      => 'client_fake',
				'environment_id' => 'environment_test',
			]
		);
		App::container()->get( Production::class )->reset();

		add_filter( 'pre_http_request', [ $this, 'block_http' ], 10, 3 );

		remove_all_actions( 'user_register' );
		remove_all_actions( 'profile_update' );
		remove_all_actions( 'set_user_role' );

		new Deactivation();
	}

	/**
	 * Tear down each test.
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'block_http' ], 10 );
		remove_all_filters( 'workos_user_is_deactivated' );
		delete_option( 'workos_production' );
		Config::set_active_environment( 'staging' );
		App::container()->get( Production::class )->reset();

		parent::tearDown();
	}

	/**
	 * WorkOS API calls fail, so WordPress password checks decide.
	 *
	 * @param false|array $response Pre-filtered response.
	 * @param array       $args     Request arguments.
	 * @param string      $url      Request URL.
	 *
	 * @return array|false
	 */
	public function block_http( $response, array $args, string $url ) {
		if ( false !== strpos( $url, 'api.workos.com' ) ) {
			return [
				'response' => [ 'code' => 400, 'message' => 'Bad Request' ],
				'body'     => wp_json_encode( [ 'message' => 'blocked in tests' ] ),
			];
		}
		return $response;
	}

	/**
	 * A user with a password, optionally deactivated.
	 *
	 * @param bool $deactivated Whether WorkOS deactivated it.
	 *
	 * @return \WP_User
	 */
	private function user( bool $deactivated ): \WP_User {
		$user_id = self::factory()->user->create(
			[
				'user_login' => 'deact_' . wp_generate_password( 6, false ),
				'user_pass'  => 'correct horse',
			]
		);
		if ( $deactivated ) {
			update_user_meta( $user_id, User::META_DEACTIVATED, true );
		}
		return get_userdata( $user_id );
	}

	/**
	 * A WordPress password sign-in (the ?fallback=1 path) is refused for a deactivated account.
	 */
	public function test_password_sign_in_refused_when_deactivated(): void {
		$user = $this->user( true );

		$result = wp_authenticate( $user->user_login, 'correct horse' );

		$this->assertWPError( $result );
		$this->assertSame( Deactivation::ERROR_CODE, $result->get_error_code() );
	}

	/**
	 * Active accounts sign in as before, and a wrong password still fails as before.
	 */
	public function test_password_sign_in_unchanged_for_active_accounts(): void {
		$user = $this->user( false );

		$this->assertInstanceOf( \WP_User::class, wp_authenticate( $user->user_login, 'correct horse' ) );

		$wrong = wp_authenticate( $user->user_login, 'wrong' );
		$this->assertWPError( $wrong );
		$this->assertNotSame( Deactivation::ERROR_CODE, $wrong->get_error_code() );
	}

	/**
	 * A WorkOS sign-in that resolves to a deactivated account (e.g. a new WorkOS user matching it by email) is refused.
	 */
	public function test_workos_sign_in_refused_when_deactivated(): void {
		$user = $this->user( true );
		update_user_meta( $user->ID, '_workos_user_id', 'user_deact' );

		$result = ( new LoginCompleter() )->complete(
			[
				'user'         => [
					'id'    => 'user_deact',
					'email' => $user->user_email,
				],
				'access_token' => 'token',
			],
			Profile::from_array( [ 'slug' => 'default' ] )
		);

		$this->assertWPError( $result );
		$this->assertSame( Deactivation::ERROR_CODE, $result->get_error_code() );
		$this->assertSame( 0, get_current_user_id() );
	}

	/**
	 * The filter can let an account through, or block one.
	 */
	public function test_filter_decides(): void {
		$deactivated = $this->user( true );
		$active      = $this->user( false );

		add_filter( 'workos_user_is_deactivated', '__return_false' );
		$this->assertFalse( Deactivation::is_deactivated( $deactivated->ID ) );

		remove_all_filters( 'workos_user_is_deactivated' );
		add_filter( 'workos_user_is_deactivated', '__return_true' );
		$this->assertTrue( Deactivation::is_deactivated( $active->ID ) );
	}

	/**
	 * Deprovisioning ends the sessions the account already has.
	 */
	public function test_deprovision_ends_sessions(): void {
		foreach ( [ 'deactivate', 'demote' ] as $action ) {
			$opts                       = get_option( 'workos_production' );
			$opts['deprovision_action'] = $action;
			update_option( 'workos_production', $opts );
			App::container()->get( Production::class )->reset();

			$user     = $this->user( false );
			$sessions = WP_Session_Tokens::get_instance( $user->ID );
			$sessions->create( time() + HOUR_IN_SECONDS );
			$this->assertCount( 1, $sessions->get_all() );

			UserSync::deprovision_user( $user->ID );

			$this->assertSame( [], WP_Session_Tokens::get_instance( $user->ID )->get_all(), $action );
			$this->assertTrue( Deactivation::is_deactivated( $user->ID ), $action );
		}
	}
}
