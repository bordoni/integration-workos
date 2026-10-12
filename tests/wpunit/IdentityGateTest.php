<?php
/**
 * Tests for the linked-identity gate.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\App;
use WorkOS\Auth\AuthKit\FrontendRoute;
use WorkOS\Auth\AuthKit\ProfileRepository;
use WorkOS\Auth\IdentityGate;
use WorkOS\Config;
use WorkOS\Options\Production;

/**
 * IdentityGate tests.
 */
class IdentityGateTest extends WPTestCase {

	/**
	 * Gate under test.
	 *
	 * @var IdentityGate
	 */
	private IdentityGate $gate;

	/**
	 * Set up each test.
	 */
	public function setUp(): void {
		parent::setUp();

		Config::set_active_environment( 'production' );
		$this->set_options( [] );

		remove_all_actions( 'user_register' );
		remove_all_actions( 'profile_update' );

		$this->gate = App::container()->get( IdentityGate::class );
	}

	/**
	 * Tear down each test.
	 */
	public function tearDown(): void {
		foreach ( [ 'workos_require_linked_identity', 'workos_identity_gate_exempt', 'workos_identity_gate_allow_rest_route', 'workos_identity_gate_ajax_actions', 'get_user_metadata' ] as $hook ) {
			remove_all_filters( $hook );
		}

		wp_set_current_user( 0 );
		delete_option( 'workos_production' );
		Config::set_active_environment( 'staging' );
		App::container()->get( Production::class )->reset();

		parent::tearDown();
	}

	/**
	 * Write the production options row with credentials plus extra settings.
	 *
	 * @param array $extra Extra settings.
	 */
	private function set_options( array $extra ): void {
		update_option(
			'workos_production',
			array_merge(
				[
					'api_key'        => 'sk_test_fake',
					'client_id'      => 'client_fake',
					'environment_id' => 'environment_test',
				],
				$extra
			)
		);
		App::container()->get( Production::class )->reset();
	}

	/**
	 * Turn on Re-link Protection and the gate.
	 */
	private function enable_gate(): void {
		$this->set_options(
			[
				'relink_protection'       => true,
				'require_linked_identity' => true,
			]
		);
	}

	/**
	 * Sign in a new user with a role, linked or not.
	 *
	 * @param bool   $linked Whether to link the user.
	 * @param string $role   Role.
	 *
	 * @return int User ID.
	 */
	private function sign_in( bool $linked, string $role = 'subscriber' ): int {
		$user_id = self::factory()->user->create( [ 'role' => $role ] );
		if ( $linked ) {
			update_user_meta( $user_id, '_workos_user_id', 'user_gate_' . $user_id );
		}
		wp_set_current_user( $user_id );

		return $user_id;
	}

	// -------------------------------------------------------------------------
	// Setting
	// -------------------------------------------------------------------------

	/**
	 * Off by default.
	 */
	public function test_off_by_default(): void {
		$this->assertFalse( IdentityGate::is_enabled() );
	}

	/**
	 * The gate needs Re-link Protection.
	 */
	public function test_requires_relink_protection(): void {
		$this->set_options( [ 'require_linked_identity' => true ] );

		$this->assertFalse( IdentityGate::is_enabled() );
	}

	/**
	 * Both settings on turn the gate on.
	 */
	public function test_on_with_protection(): void {
		$this->enable_gate();

		$this->assertTrue( IdentityGate::is_enabled() );
	}

	/**
	 * The filter can switch it off.
	 */
	public function test_filter_switches_it_off(): void {
		$this->enable_gate();
		add_filter( 'workos_require_linked_identity', '__return_false' );

		$this->assertFalse( IdentityGate::is_enabled() );
	}

	// -------------------------------------------------------------------------
	// Who is blocked
	// -------------------------------------------------------------------------

	/**
	 * A signed-in user without a link is blocked.
	 */
	public function test_blocks_unlinked_user(): void {
		$this->enable_gate();
		$this->sign_in( false );

		$this->assertTrue( $this->gate->blocks_current_user() );
	}

	/**
	 * A linked user is not blocked.
	 */
	public function test_does_not_block_linked_user(): void {
		$this->enable_gate();
		$this->sign_in( true );

		$this->assertFalse( $this->gate->blocks_current_user() );
	}

	/**
	 * Visitors who aren't signed in are not blocked.
	 */
	public function test_does_not_block_visitors(): void {
		$this->enable_gate();

		$this->assertFalse( $this->gate->blocks_current_user() );
	}

	/**
	 * Nobody is blocked while the gate is off.
	 */
	public function test_does_not_block_when_off(): void {
		$this->sign_in( false );

		$this->assertFalse( $this->gate->blocks_current_user() );
	}

	/**
	 * Administrators are exempt.
	 */
	public function test_administrators_are_exempt(): void {
		$this->enable_gate();
		$this->sign_in( false, 'administrator' );

		$this->assertFalse( $this->gate->blocks_current_user() );
	}

	/**
	 * The exempt filter can exempt anyone.
	 */
	public function test_exempt_filter(): void {
		$this->enable_gate();
		$this->sign_in( false );
		add_filter( 'workos_identity_gate_exempt', '__return_true' );

		$this->assertFalse( $this->gate->blocks_current_user() );
	}

	/**
	 * A failing identity check counts as unlinked.
	 */
	public function test_failed_identity_check_counts_as_unlinked(): void {
		$this->enable_gate();
		$this->sign_in( true );
		add_filter(
			'get_user_metadata',
			function ( $value, $object_id, $meta_key ) {
				if ( '_workos_user_id' === $meta_key ) {
					throw new \RuntimeException( 'database unavailable' );
				}
				return $value;
			},
			10,
			3
		);

		$this->assertTrue( $this->gate->blocks_current_user() );
	}

	// -------------------------------------------------------------------------
	// REST
	// -------------------------------------------------------------------------

	/**
	 * A blocked user gets 403 from REST routes outside workos/v1, before the route runs.
	 */
	public function test_rest_refuses_blocked_user(): void {
		$this->enable_gate();
		$this->sign_in( false );

		$response = rest_do_request( new \WP_REST_Request( 'GET', '/wp/v2/users/me' ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( IdentityGate::ERROR_CODE, $response->get_data()['code'] );
	}

	/**
	 * A blocked user's REST write changes nothing.
	 */
	public function test_rest_write_changes_nothing(): void {
		$this->enable_gate();
		$user_id = $this->sign_in( false );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/users/me' );
		$request->set_param( 'first_name', 'Changed' );
		$response = rest_do_request( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( '', get_user_meta( $user_id, 'first_name', true ) );
	}

	/**
	 * A linked user reaches REST routes normally.
	 */
	public function test_rest_allows_linked_user(): void {
		$this->enable_gate();
		$this->sign_in( true );

		$response = rest_do_request( new \WP_REST_Request( 'GET', '/wp/v2/users/me' ) );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Only the sign-in routes stay reachable; the rest of workos/v1 acts on accounts.
	 */
	public function test_rest_route_allowlist(): void {
		$this->assertTrue( $this->gate->is_allowed_rest_route( '/workos/v1/auth' ) );
		$this->assertTrue( $this->gate->is_allowed_rest_route( '/workos/v1/auth/nonce' ) );
		$this->assertTrue( $this->gate->is_allowed_rest_route( '/workos/v1/auth/password/authenticate' ) );
		$this->assertFalse( $this->gate->is_allowed_rest_route( '/workos/v1' ) );
		$this->assertFalse( $this->gate->is_allowed_rest_route( '/workos/v1/admin/users' ) );
		$this->assertFalse( $this->gate->is_allowed_rest_route( '/workos/v1/users/5/email-change' ) );
		$this->assertFalse( $this->gate->is_allowed_rest_route( '/workos/v1/admin/users/5/password-reset' ) );
		$this->assertFalse( $this->gate->is_allowed_rest_route( '/workos/v1/authx' ) );
		$this->assertFalse( $this->gate->is_allowed_rest_route( '/workos/v10/anything' ) );
		$this->assertFalse( $this->gate->is_allowed_rest_route( '/wp/v2/users/me' ) );
		$this->assertFalse( $this->gate->is_allowed_rest_route( '/wc/store/v1/cart' ) );
	}

	/**
	 * The REST allowlist can be extended.
	 */
	public function test_rest_route_allowlist_filter(): void {
		$this->enable_gate();
		$this->sign_in( false );
		add_filter(
			'workos_identity_gate_allow_rest_route',
			function ( $allowed, $route ) {
				return $allowed || '/wp/v2/users/me' === $route;
			},
			10,
			2
		);

		$response = rest_do_request( new \WP_REST_Request( 'GET', '/wp/v2/users/me' ) );

		$this->assertSame( 200, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// Page, AJAX and connect
	// -------------------------------------------------------------------------

	/**
	 * The page carries the heading, message and both links.
	 */
	public function test_connect_page_html(): void {
		$this->enable_gate();
		$this->sign_in( false );

		$html = $this->gate->connect_page_html( home_url( '/account/' ) );

		$this->assertStringContainsString( 'Connect your account', $html );
		$this->assertStringContainsString( 'Sign in with ' . esc_html( wp_get_current_user()->user_email ) . ' to connect it', $html );
		$this->assertStringContainsString( IdentityGate::CONNECT_ACTION . '=1', $html );
		$this->assertStringContainsString( rawurlencode( home_url( '/account/' ) ), $html );
		$this->assertStringContainsString( 'action=logout', $html );
		$this->assertStringNotContainsString( 'wpadminbar', $html );
	}

	/**
	 * The message filter changes the page, and receives the user.
	 */
	public function test_message_filter(): void {
		$user_id = $this->sign_in( false );
		$seen    = null;
		add_filter(
			'workos_identity_gate_message',
			function ( $message, $user ) use ( &$seen ) {
				$seen = $user;
				return 'Sign in with the email you used to purchase.';
			},
			10,
			2
		);

		$html = $this->gate->connect_page_html( home_url( '/' ) );

		remove_all_filters( 'workos_identity_gate_message' );

		$this->assertStringContainsString( 'Sign in with the email you used to purchase.', $html );
		$this->assertSame( $user_id, $seen->ID );
	}

	/**
	 * The REST error names the account's email too.
	 */
	public function test_rest_error_names_the_account_email(): void {
		$this->enable_gate();
		$this->sign_in( false );

		$response = rest_do_request( new \WP_REST_Request( 'GET', '/wp/v2/users/me' ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertStringContainsString( wp_get_current_user()->user_email, $response->get_data()['message'] );
	}

	/**
	 * WooCommerce's order-received and order-pay pages go through; other endpoints don't.
	 */
	public function test_woocommerce_order_pages_go_through(): void {
		$this->enable_gate();
		$this->sign_in( false );

		if ( ! function_exists( 'is_wc_endpoint_url' ) ) {
			require_once __DIR__ . '/../_data/wc-endpoint-stub.php';
		}
		if ( ! empty( $GLOBALS['workos_test_wc_endpoint_stub'] ) ) {
			$GLOBALS['workos_test_wc_endpoint'] = 'order-received';
			$this->assertTrue( $this->gate->is_allowed_front_end_request() );

			$GLOBALS['workos_test_wc_endpoint'] = 'order-pay';
			$this->assertTrue( $this->gate->is_allowed_front_end_request() );

			$GLOBALS['workos_test_wc_endpoint'] = 'edit-account';
			$this->assertFalse( $this->gate->is_allowed_front_end_request() );

			$GLOBALS['workos_test_wc_endpoint'] = '';
		}

		$this->assertFalse( $this->gate->is_allowed_front_end_request() );
	}

	/**
	 * Only heartbeat goes through admin-ajax by default.
	 */
	public function test_ajax_allowlist(): void {
		$this->assertTrue( $this->gate->is_allowed_ajax_action( 'heartbeat' ) );
		$this->assertFalse( $this->gate->is_allowed_ajax_action( 'woocommerce_checkout' ) );
	}

	/**
	 * Sign-in goes through the configured custom-mode login profile, returning to the page.
	 */
	public function test_sign_in_url_uses_login_profile(): void {
		App::container()->get( ProfileRepository::class )->ensure_default();

		$url = $this->gate->sign_in_url( home_url( '/account/?tab=keys&x=1' ) );

		$this->assertStringStartsWith( home_url( '/workos/login/default/' ), $url );

		// The return address survives with its own query string.
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame( home_url( '/account/?tab=keys&x=1' ), $query['redirect_to'] );
		$this->assertArrayNotHasKey( 'x', $query );
	}

	/**
	 * The connect URL carries the return address intact.
	 */
	public function test_connect_url_keeps_return_address(): void {
		$this->sign_in( false );

		$url = $this->gate->connect_url( home_url( '/account/?tab=keys&x=1' ) );

		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame( '1', $query[ IdentityGate::CONNECT_ACTION ] );
		$this->assertSame( home_url( '/account/?tab=keys&x=1' ), $query['redirect_to'] );
		$this->assertNotFalse( wp_verify_nonce( $query['_wpnonce'], IdentityGate::CONNECT_ACTION ) );
	}

	/**
	 * Without the login profile, sign-in falls back to wp-login.php with the return address.
	 */
	public function test_sign_in_url_falls_back_to_wp_login(): void {
		$this->set_options( [ 'identity_gate_profile' => 'no-such-profile' ] );

		$url = $this->gate->sign_in_url( home_url( '/account/?tab=keys&x=1' ) );

		$this->assertSame( wp_login_url( home_url( '/account/?tab=keys&x=1' ) ), $url );
	}

	/**
	 * Return addresses stay on this site.
	 */
	public function test_return_to_stays_on_site(): void {
		$this->assertSame( home_url( '/account/' ), $this->gate->safe_return_to( home_url( '/account/' ) ) );
		$this->assertSame( home_url( '/' ), $this->gate->safe_return_to( 'https://evil.example/' ) );
	}

	/**
	 * The WorkOS callback and login routes stay reachable.
	 */
	public function test_front_end_allows_sign_in_routes(): void {
		global $wp_query;

		$this->assertFalse( $this->gate->is_allowed_front_end_request() );

		$wp_query->set( 'workos_callback', '1' );
		$this->assertTrue( $this->gate->is_allowed_front_end_request() );
		$wp_query->set( 'workos_callback', '' );

		$wp_query->set( FrontendRoute::QUERY_VAR, 'default' );
		$this->assertTrue( $this->gate->is_allowed_front_end_request() );
		$wp_query->set( FrontendRoute::QUERY_VAR, '' );
	}
}
