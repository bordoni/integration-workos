<?php
/**
 * Public contract: hook registrations, priorities and the hooks the plugin fires.
 *
 * The Software Portal hooks in around these at fixed priorities
 * (`template_redirect` at 5 and 8, `rest_authentication_errors` at 20,
 * `user_register` at 101) and listens to `workos_user_linked` and
 * `wp_login`. Moving a priority or dropping an argument here breaks it
 * without a fatal, so these tests are the only guard.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\App;
use WorkOS\Auth\AuthKit\LoginCompleter;
use WorkOS\Auth\AuthKit\Profile;
use WorkOS\Auth\AuthKit\Renderer;
use WorkOS\Sync\UserSync;

/**
 * Hook wiring the Portal depends on.
 *
 * @group public-contract
 */
class PublicContractHooksTest extends WPTestCase {

	/**
	 * Captured hook invocations: list of argument lists.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	public function setUp(): void {
		parent::setUp();
		$this->calls = [];
		add_filter( 'pre_http_request', [ $this, 'fake_workos_api' ], 10, 3 );
		add_filter( 'send_auth_cookies', '__return_false' );
	}

	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'fake_workos_api' ], 10 );
		remove_filter( 'send_auth_cookies', '__return_false' );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Answer every WorkOS API call with an empty 200 so nothing leaves the test.
	 *
	 * @param false|array $preempt Pre-filtered response.
	 * @param array       $args    Request args.
	 * @param string      $url     Request URL.
	 *
	 * @return false|array
	 */
	public function fake_workos_api( $preempt, array $args, string $url ) {
		if ( false === strpos( $url, 'api.workos.com' ) ) {
			return $preempt;
		}

		return [
			'headers'  => [],
			'body'     => '{}',
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'cookies'  => [],
		];
	}

	/**
	 * Record a hook call.
	 *
	 * @param mixed ...$args Hook arguments.
	 */
	public function record( ...$args ): void {
		$this->calls[] = $args;
	}

	/**
	 * Find where a plugin method is hooked.
	 *
	 * Matches by class and method name rather than by instance, so the
	 * check survives the object being built a different way.
	 *
	 * @param string $hook   Hook name.
	 * @param string $class  Fully qualified class name.
	 * @param string $method Method name.
	 *
	 * @return array<int,array{priority:int,accepted_args:int}>
	 */
	private function find_callbacks( string $hook, string $class, string $method ): array {
		global $wp_filter;

		$found = [];
		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return $found;
		}

		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$fn = $callback['function'];
				if ( ! is_array( $fn ) || ! isset( $fn[0], $fn[1] ) ) {
					continue;
				}
				$owner = is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0];
				if ( $owner === $class && $fn[1] === $method ) {
					$found[] = [
						'priority'      => (int) $priority,
						'accepted_args' => (int) $callback['accepted_args'],
					];
				}
			}
		}

		return $found;
	}

	/**
	 * Assert a plugin method is hooked exactly once at a priority.
	 *
	 * @param string   $hook          Hook name.
	 * @param string   $class         Class name.
	 * @param string   $method        Method name.
	 * @param int      $priority      Expected priority.
	 * @param int|null $accepted_args Expected accepted args, or null to skip.
	 */
	private function assertHookedAt( string $hook, string $class, string $method, int $priority, ?int $accepted_args = null ): void {
		$found = $this->find_callbacks( $hook, $class, $method );
		$label = "{$class}::{$method} on {$hook}";

		$this->assertCount( 1, $found, "{$label} must be hooked exactly once." );
		$this->assertSame( $priority, $found[0]['priority'], "{$label} moved priority." );
		if ( null !== $accepted_args ) {
			$this->assertSame( $accepted_args, $found[0]['accepted_args'], "{$label} changed its accepted args." );
		}
	}

	// ---------------------------------------------------------- Registrations

	public function test_user_register_pushes_to_workos_at_10_with_2_args(): void {
		// The Portal's checkout fallback runs at 101, after this.
		$this->assertHookedAt( 'user_register', 'WorkOS\Sync\UserSync', 'push_user_to_workos', 10, 2 );
	}

	public function test_token_auth_runs_at_15(): void {
		// A Portal endpoint clears TokenAuth's stored error at 20.
		$this->assertHookedAt( 'rest_authentication_errors', 'WorkOS\REST\TokenAuth', 'check_errors', 15 );
		$this->assertHookedAt( 'determine_current_user', 'WorkOS\REST\TokenAuth', 'authenticate', 15 );
	}

	public function test_template_redirect_priorities(): void {
		// The Portal runs at 5 and 8, between the gate and these handlers.
		$this->assertHookedAt( 'template_redirect', 'WorkOS\Auth\Login', 'handle_callback', 10 );
		$this->assertHookedAt( 'template_redirect', 'WorkOS\Auth\ChangeEmail\FrontendConfirmRoute', 'maybe_render', 10 );
		$this->assertHookedAt( 'template_redirect', 'WorkOS\Auth\IdentityGate', 'gate_front_end', -100 );
	}

	public function test_wp_logout_clears_the_session(): void {
		$this->assertHookedAt( 'wp_logout', 'WorkOS\Auth\Login', 'handle_logout', 10 );
	}

	public function test_login_takeover_and_admin_bar(): void {
		$this->assertNotEmpty( $this->find_callbacks( 'login_init', 'WorkOS\Auth\AuthKit\LoginTakeover', 'maybe_takeover' ), 'The wp-login.php takeover must stay on login_init.' );
		$this->assertHookedAt( 'admin_bar_menu', 'WorkOS\Admin\AdminBar', 'add_environment_indicator', 100 );
	}

	public function test_assets_and_rewrites_register_on_init(): void {
		$this->assertNotEmpty( $this->find_callbacks( 'init', 'WorkOS\Auth\ChangeEmail\Assets', 'register_assets' ), 'Change-email assets must register on init.' );
		$this->assertNotEmpty( $this->find_callbacks( 'init', 'WorkOS\Auth\Login', 'register_rewrite' ), 'The /workos/callback rewrite must register on init.' );
		$this->assertNotEmpty( $this->find_callbacks( 'init', 'WorkOS\Auth\ChangeEmail\FrontendConfirmRoute', 'register_rewrite' ), 'The change-email confirm rewrite must register on init.' );
	}

	// ----------------------------------------------------- workos_user_linked

	public function test_workos_user_linked_fires_with_4_args_on_first_link(): void {
		// Arrange.
		$user_id = self::factory()->user->create();
		add_action( 'workos_user_linked', [ $this, 'record' ], 10, 4 );

		// Act.
		$linked = UserSync::link_user( $user_id, [ 'id' => 'user_contract_first', 'email' => 'first@example.test' ], 'commerce_sync' );

		// Assert.
		$this->assertTrue( $linked );
		$this->assertSame( [ [ $user_id, 'user_contract_first', '', 'commerce_sync' ] ], $this->calls );
		$this->assertSame( 'user_contract_first', get_user_meta( $user_id, '_workos_user_id', true ) );
	}

	public function test_workos_user_linked_fires_on_relink_with_previous_id(): void {
		// Arrange.
		$user_id = self::factory()->user->create();
		UserSync::link_user( $user_id, [ 'id' => 'user_contract_old', 'email' => 'old@example.test' ], 'login' );
		add_action( 'workos_user_linked', [ $this, 'record' ], 10, 4 );

		// Act.
		$linked = UserSync::link_user( $user_id, [ 'id' => 'user_contract_new', 'email' => 'new@example.test' ], 'commerce_sync', true );

		// Assert.
		$this->assertTrue( $linked );
		$this->assertSame( [ [ $user_id, 'user_contract_new', 'user_contract_old', 'commerce_sync' ] ], $this->calls );
	}

	public function test_workos_user_linked_does_not_fire_when_the_same_link_is_rewritten(): void {
		// Arrange.
		$user_id     = self::factory()->user->create();
		$workos_user = [ 'id' => 'user_contract_same', 'email' => 'same@example.test' ];
		UserSync::link_user( $user_id, $workos_user, 'login' );
		add_action( 'workos_user_linked', [ $this, 'record' ], 10, 4 );

		// Act.
		$linked = UserSync::link_user( $user_id, $workos_user, 'commerce_sync' );

		// Assert.
		$this->assertTrue( $linked );
		$this->assertSame( [], $this->calls );
	}

	public function test_link_user_returns_false_and_stays_silent_when_refused(): void {
		// Arrange: Re-link Protection on, user already linked elsewhere.
		$user_id = self::factory()->user->create();
		UserSync::link_user( $user_id, [ 'id' => 'user_contract_kept', 'email' => 'kept@example.test' ], 'login' );
		add_filter( 'workos_relink_protection_enabled', '__return_true' );
		add_action( 'workos_user_linked', [ $this, 'record' ], 10, 4 );

		// Act.
		$linked = UserSync::link_user( $user_id, [ 'id' => 'user_contract_other', 'email' => 'other@example.test' ], 'commerce_sync' );

		// Assert.
		$this->assertFalse( $linked );
		$this->assertSame( [], $this->calls );
		$this->assertSame( 'user_contract_kept', get_user_meta( $user_id, '_workos_user_id', true ) );

		remove_filter( 'workos_relink_protection_enabled', '__return_true' );
	}

	// ------------------------------------------ workos_authkit_enqueue_assets

	public function test_authkit_enqueue_assets_receives_the_profile_and_handles_are_enqueued(): void {
		// Arrange.
		$profile = Profile::from_array( [ 'slug' => 'contract', 'title' => 'Contract' ] );
		add_action( 'workos_authkit_enqueue_assets', [ $this, 'record' ], 10, 1 );

		// Act.
		App::container()->get( Renderer::class )->enqueue( $profile );

		// Assert.
		$this->assertCount( 1, $this->calls );
		$this->assertCount( 1, $this->calls[0] );
		$this->assertInstanceOf( Profile::class, $this->calls[0][0] );
		$this->assertSame( 'contract', $this->calls[0][0]->get_slug() );
		$this->assertTrue( wp_script_is( 'workos-authkit', 'enqueued' ), 'Portal assets depend on the workos-authkit script handle.' );
		$this->assertTrue( wp_style_is( 'workos-authkit', 'enqueued' ), 'Portal assets depend on the workos-authkit style handle.' );
	}

	// ---------------------------------------------------------------- wp_login

	public function test_wp_login_fires_after_authkit_sign_in(): void {
		// Arrange: a WP user already linked, so the completer finds it.
		$user_id = self::factory()->user->create( [ 'user_email' => 'signin-contract@example.test' ] );
		update_user_meta( $user_id, '_workos_user_id', 'user_contract_signin' );
		$profile = Profile::from_array( [ 'slug' => 'contract', 'title' => 'Contract' ] );
		add_action( 'wp_login', [ $this, 'record' ], 10, 2 );

		// Act.
		$result = ( new LoginCompleter() )->complete(
			[
				'access_token'  => 'eyJhbGciOiJub25lIn0.eyJzdWIiOiJ1c2VyX2NvbnRyYWN0X3NpZ25pbiJ9.sig',
				'refresh_token' => 'rt_contract',
				'user'          => [
					'id'    => 'user_contract_signin',
					'email' => 'signin-contract@example.test',
				],
			],
			$profile
		);

		// Assert.
		$this->assertIsArray( $result );
		$this->assertCount( 1, $this->calls, 'wp_login must fire once after the AuthKit sign-in.' );
		$this->assertSame( get_userdata( $user_id )->user_login, $this->calls[0][0] );
		$this->assertInstanceOf( \WP_User::class, $this->calls[0][1] );
		$this->assertSame( $user_id, $this->calls[0][1]->ID );
	}
}
