<?php
/**
 * Users-screen navigation and authorization during row re-sync.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Admin\UserList;
use WorkOS\App;
use WorkOS\Config;
use WorkOS\Options\Production;
use WorkOS\Sync\MembershipSync;
use WorkOS\Sync\UserSync;

/**
 * Follow the rendered action through the handler and capture its safe redirect.
 */
final class UserListReturnUrlTest extends WPTestCase {

	/** @var array Request globals before each test. */
	private array $request_globals;

	/** @var string Active environment before each test. */
	private string $environment;

	/** @var mixed Saved production settings. */
	private $options;

	/** @var int Linked target user. */
	private int $target;

	/** @var UserSync|\PHPUnit\Framework\MockObject\MockObject Refresh collaborator. */
	private $sync;

	/** @var UserList Users-screen controller. */
	private UserList $list;

	/**
	 * Configure a linked user and isolate the refresh operation.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->request_globals = [
			$_GET,
			$_REQUEST,
			$_SERVER,
		];

		$this->environment = Config::get_active_environment();
		$this->options     = get_option( 'workos_production' );
		Config::set_active_environment( 'production' );

		update_option( 'workos_production', [
			'api_key'         => 'sk_test_fake',
			'client_id'       => 'client_fake',
			'environment_id'  => 'environment_test',
			'organization_id' => 'org_redirect_test',
		] );

		App::container()->get( Production::class )->reset();
		remove_all_actions( 'user_register' );

		wp_set_current_user( self::factory()->user->create( [
			'role' => 'administrator',
		] ) );
		$this->target = self::factory()->user->create( [
			'role' => 'subscriber',
		] );
		update_user_meta( $this->target, '_workos_user_id', 'user_redirect_test' );

		$this->sync = $this->getMockBuilder( UserSync::class )
			->disableOriginalConstructor()
			->onlyMethods( [
				'resync_from_workos',
			] )
			->getMock();

		$this->list = new UserList( App::container()->get( MembershipSync::class ), $this->sync );

		$_GET                   = [];
		$_REQUEST               = [];
		$_SERVER['REQUEST_URI'] = wp_parse_url( admin_url( 'users.php' ), PHP_URL_PATH );
	}

	/**
	 * Restore globals and configuration for subsequent tests.
	 */
	public function tearDown(): void {
		[
			$_GET,
			$_REQUEST,
			$_SERVER,
		] = $this->request_globals;

		wp_set_current_user( 0 );

		if ( false === $this->options ) {
			delete_option( 'workos_production' );
		} else {
			update_option( 'workos_production', $this->options );
		}
		Config::set_active_environment( $this->environment );
		App::container()->get( Production::class )->reset();

		parent::tearDown();
	}

	/**
	 * Extract the actual row link, including its target-specific nonce.
	 *
	 * @return array Action query parameters.
	 */
	private function row_request(): array {
		$html = $this->list->render_column( '', 'workos', $this->target );
		$this->assertSame( 1, preg_match( '/<span class="resync"><a href="([^"]+)"/', $html, $matches ) );
		$url = html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame( 'workos_resync_user', $query['action'] );
		$this->assertSame( (string) $this->target, $query['user_id'] );
		$this->assertNotFalse( wp_verify_nonce( $query['_wpnonce'], 'workos_resync_user_' . $this->target ) );

		return $query;
	}

	/**
	 * Capture the real redirect before the handler exits PHP.
	 *
	 * @return string Safe redirect destination.
	 */
	private function dispatch(): string {
		$_SERVER['REQUEST_URI'] = wp_slash( wp_parse_url( admin_url( 'users.php' ), PHP_URL_PATH ) . '?' . http_build_query( wp_unslash( $_GET ) ) );
		$location               = null;
		$stop                   = new \RuntimeException( 'Redirect captured.' );
		$capture                = static function ( string $url ) use ( &$location, $stop ) {
			$location = $url;
			throw $stop;
		};
		add_filter( 'wp_redirect', $capture );

		try {
			$this->list->handle_single_resync();
		} catch ( \RuntimeException $exception ) {
			if ( $exception !== $stop ) {
				throw $exception;
			}
		} finally {
			remove_filter( 'wp_redirect', $capture );
		}

		$this->assertNotNull( $location );

		return $location;
	}

	/**
	 * Both successful and failed refreshes must return to the original view.
	 *
	 * @return array<string, array{bool}>
	 */
	public static function refresh_results(): array {
		return [
			'success' => [
				true,
			],
			'failure' => [
				false,
			],
		];
	}

	/**
	 * Search punctuation, filtering, sorting, and paging survive the round trip.
	 *
	 * @dataProvider refresh_results
	 * @param bool $success Whether the refresh succeeds.
	 */
	public function test_resync_preserves_list_context( bool $success ): void {
		$context = [
			's'                  => "O'Reilly & José+team@example.test",
			'role'               => 'subscriber',
			'paged'              => '3',
			'orderby'            => 'email',
			'order'              => 'desc',
			'workos_sync_status'  => 'out_of_sync',
			'custom_filter'       => 'customer-team',
		];

		$_GET                   = wp_slash( $context + [
			'workos_failed' => '9',
			'workos_errors' => 'Old error',
			'workos_synced' => '5',
		] );
		$_SERVER['REQUEST_URI'] = wp_slash( wp_parse_url( admin_url( 'users.php' ), PHP_URL_PATH ) . '?' . http_build_query( wp_unslash( $_GET ) ) );

		$request = $this->row_request();
		parse_str( (string) wp_parse_url( $request['_wp_http_referer'], PHP_URL_QUERY ), $return_query );

		foreach ( $context as $key => $value ) {
			$this->assertSame( $value, $return_query[ $key ] ?? null );
		}

		$_GET = $_REQUEST = wp_slash( $request );
		unset( $_SERVER['HTTP_REFERER'] );
		$this->sync->expects( $this->once() )->method( 'resync_from_workos' )->with( $this->target )
			->willReturn( $success ? true : new \WP_Error( 'unavailable', 'WorkOS unavailable.' ) );

		$destination = $this->dispatch();
		parse_str( (string) wp_parse_url( $destination, PHP_URL_QUERY ), $query );
		$this->assertSame( admin_url( 'users.php' ), strtok( $destination, '?' ) );
		$notice = [
			'workos_resynced' => '1',
		];

		if ( ! $success ) {
			$notice = [
				'workos_failed' => '1',
				'workos_errors' => 'WorkOS unavailable.',
			];
		}

		$this->assertSame( $context + $notice, $query );
	}

	/**
	 * Request-controlled destinations and actions never enter the return URL.
	 */
	public function test_resync_ignores_redirect_injection_and_malformed_filters(): void {
		$request                 = $this->row_request();
		$_GET                    = $_REQUEST = wp_slash( array_merge( $request, [
			'redirect_to'      => 'https://attacker.example/collect',
			'_wp_http_referer' => '//attacker.example/collect',
			'action2'          => 'delete',
			'users'            => [
				$this->target,
			],
			's'                => [
				'malformed',
			],
			'paged'            => [
				'malformed',
			],
			'workos_failed'    => '99',
		] ) );
		$_SERVER['HTTP_REFERER'] = 'https://attacker.example/collect';
		$this->sync->expects( $this->once() )->method( 'resync_from_workos' )->willReturn( true );

		$this->assertSame( admin_url( 'users.php?workos_resynced=1' ), $this->dispatch() );
	}

	/**
	 * Unsafe or malformed return destinations fall back to the Users screen.
	 *
	 * @dataProvider invalid_referers
	 * @param mixed $referer Supplied return address.
	 */
	public function test_invalid_referer_returns_to_users( $referer ): void {
		$request                     = $this->row_request();
		$request['_wp_http_referer'] = 'other_admin_page' === $referer ? admin_url( 'options.php?s=lost' ) : $referer;
		$_GET                        = $_REQUEST = wp_slash( $request );
		unset( $_SERVER['HTTP_REFERER'] );
		$this->sync->expects( $this->once() )->method( 'resync_from_workos' )->willReturn( true );

		$this->assertSame( admin_url( 'users.php?workos_resynced=1' ), $this->dispatch() );
	}

	/**
	 * Return addresses are untrusted even when the re-sync nonce is valid.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function invalid_referers(): array {
		return [
			'foreign host'      => [
				'https://attacker.example/wp-admin/users.php?s=lost',
			],
			'protocol relative' => [
				'//attacker.example/wp-admin/users.php?s=lost',
			],
			'script'            => [
				'javascript:alert(1)',
			],
			'malformed URL'     => [
				'http://[invalid',
			],
			'array'             => [
				[
					'malformed',
				],
			],
			'missing'           => [
				'',
			],
			'other admin page'  => [
				'other_admin_page',
			],
		];
	}

	/**
	 * Older WordPress versions read the referer inside nonce verification.
	 */
	public function test_malformed_referer_is_normalized_before_nonce_verification(): void {
		$request                     = $this->row_request();
		$request['_wp_http_referer'] = [
			'malformed',
		];
		$_GET                        = $_REQUEST = $request;
		$checked                     = false;
		$inspect                     = function ( string $action ) use ( &$checked ): void {
			if ( 'workos_resync_user_' . $this->target === $action ) {
				$checked = true;
				$this->assertSame( admin_url( 'users.php' ), $_REQUEST['_wp_http_referer'] );
			}
		};
		add_action( 'check_admin_referer', $inspect );
		$this->sync->expects( $this->once() )->method( 'resync_from_workos' )->willReturn( true );

		try {
			$this->assertSame( admin_url( 'users.php?workos_resynced=1' ), $this->dispatch() );
			$this->assertTrue( $checked );
		} finally {
			remove_action( 'check_admin_referer', $inspect );
		}
	}

	/**
	 * Replacing malformed navigation data must not authorize an unsigned action.
	 */
	public function test_malformed_referer_without_nonce_cannot_refresh(): void {
		$_GET = $_REQUEST = [
			'action'           => 'workos_resync_user',
			'user_id'          => $this->target,
			'_wp_http_referer' => [
				'malformed',
			],
		];
		$this->sync->expects( $this->never() )->method( 'resync_from_workos' );
		$this->expectException( \WPDieException::class );

		$this->list->handle_single_resync();
	}

	/**
	 * Older links without an explicit return address can use the browser referer.
	 */
	public function test_browser_referer_preserves_custom_filters_and_strips_actions(): void {
		$request = $this->row_request();
		unset( $request['_wp_http_referer'] );
		$_GET                    = $_REQUEST = $request;
		$_SERVER['HTTP_REFERER'] = admin_url( 'users.php?s=someone&custom_filter=team&action=delete&action2=delete&user_id=5&users%5B%5D=5&_wpnonce=old&_wp_http_referer=old&wp_http_referer=old&redirect_to=elsewhere&workos_failed=9&workos_errors=old&updated=1' );
		$this->sync->expects( $this->once() )->method( 'resync_from_workos' )->willReturn( true );

		$this->assertSame( admin_url( 'users.php?s=someone&custom_filter=team&workos_resynced=1' ), $this->dispatch() );
	}

	/**
	 * A nonce for another user cannot authorize this target's refresh.
	 */
	public function test_wrong_target_nonce_cannot_refresh(): void {
		$_GET = $_REQUEST = [
			'action'   => 'workos_resync_user',
			'user_id'  => $this->target,
			'_wpnonce' => wp_create_nonce( 'workos_resync_user_' . get_current_user_id() ),
		];
		$this->sync->expects( $this->never() )->method( 'resync_from_workos' );
		$this->expectException( \WPDieException::class );

		$this->list->handle_single_resync();
	}

	/**
	 * A missing nonce cannot authorize a refresh, even from a local referer.
	 */
	public function test_missing_nonce_cannot_refresh(): void {
		$_GET                    = $_REQUEST = [
			'action' => 'workos_resync_user',
			'user_id' => $this->target,
		];
		$_SERVER['HTTP_REFERER'] = admin_url( 'users.php' );
		$this->sync->expects( $this->never() )->method( 'resync_from_workos' );
		$this->expectException( \WPDieException::class );

		$this->list->handle_single_resync();
	}

	/**
	 * A valid nonce does not bypass per-user editing or promotion permissions.
	 *
	 * @dataProvider denied_capabilities
	 * @param string $denied_cap Capability to deny.
	 */
	public function test_valid_nonce_still_requires_target_permission( string $denied_cap ): void {
		$_GET = $_REQUEST = $this->row_request();
		$deny = static function ( array $caps, string $cap, int $actor, array $args ) use ( $denied_cap ): array {
			if ( $denied_cap === $cap ) {
				return [
					'do_not_allow',
				];
			}

			return $caps;
		};
		add_filter( 'map_meta_cap', $deny, 10, 4 );
		$this->sync->expects( $this->never() )->method( 'resync_from_workos' );
		$this->expectException( \WPDieException::class );

		try {
			$this->list->handle_single_resync();
		} finally {
			remove_filter( 'map_meta_cap', $deny, 10 );
		}
	}

	/**
	 * Permissions checked independently of the nonce.
	 *
	 * @return array<string, array{string}>
	 */
	public static function denied_capabilities(): array {
		return [
			'edit' => [
				'edit_user',
			],
			'promote' => [
				'promote_user',
			],
		];
	}
}
