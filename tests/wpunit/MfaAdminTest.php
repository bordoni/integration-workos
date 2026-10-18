<?php
/**
 * Tests for the MFA admin tools: routes, user-edit panel, settings.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Admin\Settings as AdminSettings;
use WorkOS\Mfa\Admin\UserEditPanel;
use WorkOS\Mfa\FactorService;
use WorkOS\Mfa\Policy;
use WorkOS\Mfa\RestApi;
use WorkOS\Mfa\Settings;
use WorkOS\RateLimit\DatabaseRateLimiter;
use WorkOS\RateLimit\TieredRateLimiter;
use WorkOS\User;
use WP_REST_Request;

/**
 * Require flag and reset, as an admin and as anyone else.
 */
class MfaAdminTest extends WPTestCase {

	/**
	 * Linked user being administered.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Administrator.
	 *
	 * @var int
	 */
	private int $admin_id;

	/**
	 * Captured HTTP calls.
	 *
	 * @var array
	 */
	private array $captured = [];

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

		$api = new RestApi( new FactorService(), new TieredRateLimiter( new DatabaseRateLimiter() ) );
		add_action( 'rest_api_init', [ $api, 'register_routes' ] );
		do_action( 'rest_api_init', rest_get_server() );

		add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );

		$this->user_id  = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		update_user_meta( $this->user_id, User::META_WORKOS_ID, 'user_member' );

		$this->captured = [];
		wp_set_current_user( $this->admin_id );
	}

	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10 );
		remove_all_filters( 'workos_mfa_can_manage_user' );
		remove_all_filters( 'sanitize_option_workos_production' );
		unset( $_POST['workos_mfa_required'], $_POST['workos_mfa_reset'] );
		$this->captured = [];

		wp_set_current_user( 0 );
		delete_option( 'workos_production' );
		\WorkOS\Config::set_active_environment( 'staging' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		parent::tearDown();
	}

	public function intercept_http( $preempt, array $args, string $url ) {
		if ( ! str_contains( $url, 'api.workos.com' ) ) {
			return $preempt;
		}

		$method           = $args['method'] ?? 'GET';
		$this->captured[] = [
			'method' => $method,
			'url'    => $url,
		];

		$body = 'GET' === $method && str_contains( $url, '/auth_factors' )
			? [ 'data' => [ [ 'id' => 'auth_factor_1', 'type' => 'totp' ] ] ]
			: [];

		return [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'body'     => wp_json_encode( $body ),
		];
	}

	// --------------------------------- Routes ---------------------------------

	public function test_admin_requires_and_clears_mfa(): void {
		$on = $this->request( 'PUT', '/required', [ 'required' => true ] );
		$this->assertSame( 200, $on->get_status() );
		$this->assertTrue( $on->get_data()['required'] );
		$this->assertSame( '1', get_user_meta( $this->user_id, User::META_MFA_REQUIRED, true ) );

		$off = $this->request( 'PUT', '/required', [ 'required' => false ] );
		$this->assertFalse( $off->get_data()['required'] );
		$this->assertSame( '', (string) get_user_meta( $this->user_id, User::META_MFA_REQUIRED, true ) );
	}

	public function test_admin_resets_factors(): void {
		update_user_meta( $this->user_id, User::META_MFA_ENABLED, '1' );

		$response = $this->request( 'POST', '/reset' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $response->get_data()['removed'] );
		$this->assertSame( '', (string) get_user_meta( $this->user_id, User::META_MFA_ENABLED, true ) );
		$this->assertNotEmpty( array_filter( $this->captured, static fn( $c ) => 'DELETE' === $c['method'] ) );
	}

	public function test_non_admin_cannot_require_or_reset(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->assertContains( $this->request( 'PUT', '/required', [ 'required' => true ] )->get_status(), [ 401, 403 ] );
		$this->assertContains( $this->request( 'POST', '/reset' )->get_status(), [ 401, 403 ] );
		$this->assertSame( '', (string) get_user_meta( $this->user_id, User::META_MFA_REQUIRED, true ) );
	}

	public function test_can_manage_user_filter_grants_support_role(): void {
		$support = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $support );
		add_filter( 'workos_mfa_can_manage_user', static fn( $can ) => $can || get_current_user_id() === $support );

		$this->assertSame( 200, $this->request( 'POST', '/reset' )->get_status() );
	}

	// ------------------------------ User-edit panel ------------------------------

	public function test_panel_save_sets_flag_and_resets(): void {
		update_user_meta( $this->user_id, User::META_MFA_ENABLED, '1' );
		$_POST['workos_mfa_required'] = '1';
		$_POST['workos_mfa_reset']    = '1';

		( new UserEditPanel( new FactorService(), new Policy( new Settings() ) ) )->save( $this->user_id );

		$this->assertSame( '1', get_user_meta( $this->user_id, User::META_MFA_REQUIRED, true ) );
		$this->assertSame( '', (string) get_user_meta( $this->user_id, User::META_MFA_ENABLED, true ) );
	}

	public function test_panel_save_ignores_forms_without_the_panel(): void {
		update_user_meta( $this->user_id, User::META_MFA_REQUIRED, '1' );

		( new UserEditPanel( new FactorService(), new Policy( new Settings() ) ) )->save( $this->user_id );

		$this->assertSame( '1', get_user_meta( $this->user_id, User::META_MFA_REQUIRED, true ) );
	}

	public function test_panel_renders_status_for_admin_only(): void {
		$panel = new UserEditPanel( new FactorService(), new Policy( new Settings() ) );
		update_user_meta( $this->user_id, User::META_MFA_ENABLED, '1' );

		ob_start();
		$panel->render( get_userdata( $this->user_id ) );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="workos_mfa_required"', $html );
		$this->assertStringContainsString( 'name="workos_mfa_reset"', $html );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		ob_start();
		$panel->render( get_userdata( $this->user_id ) );
		$this->assertSame( '', (string) ob_get_clean() );
	}

	// -------------------------------- Settings --------------------------------

	public function test_settings_sanitize_mode_and_roles(): void {
		add_filter( 'sanitize_option_workos_production', [ new AdminSettings(), 'sanitize_environment_options' ] );

		$saved = sanitize_option(
			'workos_production',
			[
				Settings::OPTION_MODE           => Settings::MODE_WORKOS,
				Settings::OPTION_REQUIRED_ROLES => [ '', 'editor', 'not_a_role' ],
			]
		);
		$this->assertSame( Settings::MODE_WORKOS, $saved[ Settings::OPTION_MODE ] );
		$this->assertSame( [ 'editor' ], $saved[ Settings::OPTION_REQUIRED_ROLES ] );

		$bad = sanitize_option( 'workos_production', [ Settings::OPTION_MODE => 'sometimes' ] );
		$this->assertArrayNotHasKey( Settings::OPTION_MODE, $bad );

		$cleared = sanitize_option( 'workos_production', [ Settings::OPTION_REQUIRED_ROLES => [ '' ] ] );
		$this->assertSame( [], $cleared[ Settings::OPTION_REQUIRED_ROLES ] );
	}

	// -------------------------------- Fixtures --------------------------------

	private function request( string $method, string $path, array $body = [] ) {
		$request = new WP_REST_Request( $method, '/workos/v1/users/' . $this->user_id . '/mfa' . $path );
		$request->set_header( 'Content-Type', 'application/json' );
		if ( $body ) {
			$request->set_body( wp_json_encode( $body ) );
		}

		return rest_get_server()->dispatch( $request );
	}
}
