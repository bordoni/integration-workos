<?php
/**
 * Tests for UserSync profile pushes (WP -> WorkOS) on profile edits.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Sync\UserSync;

/**
 * Profile edit push tests.
 */
class UserSyncProfilePushTest extends WPTestCase {

	/**
	 * WorkOS ID of the linked test user (unique per test).
	 *
	 * @var string
	 */
	private string $workos_id = '';

	/**
	 * Email of the linked test user (unique per test).
	 *
	 * @var string
	 */
	private string $email = '';

	/**
	 * Captured WorkOS API requests (method, URL, body).
	 *
	 * @var array
	 */
	private array $http_requests = [];

	/**
	 * The WorkOS user as the fake API holds it; PUTs are merged in.
	 *
	 * @var array
	 */
	private array $workos_user = [];

	/**
	 * Linked WP user ID.
	 *
	 * @var int
	 */
	private int $user_id = 0;

	/**
	 * How the fake API answers PUTs: '' (success), 'email_not_available' or 'transport'.
	 *
	 * @var string
	 */
	private string $put_error = '';

	/**
	 * Calls to workos_profile_push_failed: [ user_id, workos_id, update, error ].
	 *
	 * @var array
	 */
	private array $failed = [];

	/**
	 * Activity log events: [ event_type, data ].
	 *
	 * @var array
	 */
	private array $logged = [];

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
				'environment_id'      => 'environment_test',
				'enable_activity_log' => true,
			]
		);
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );
		remove_all_actions( 'user_register' );

		$this->put_error = '';
		$this->failed    = [];
		$this->logged    = [];
		add_action(
			'workos_profile_push_failed',
			function ( ...$args ) {
				$this->failed[] = $args;
			},
			10,
			4
		);
		add_action(
			'workos_activity_logged',
			function ( ...$args ) {
				$this->logged[] = $args;
			},
			10,
			2
		);

		$suffix          = uniqid();
		$this->workos_id = 'user_push_' . $suffix;
		$this->email     = 'ada.' . $suffix . '@acme.dev';

		$user_id = self::factory()->user->create(
			[
				'user_email' => $this->email,
				'first_name' => 'Ada',
				'last_name'  => 'Lovelace',
				'role'       => 'subscriber',
			]
		);
		if ( is_wp_error( $user_id ) ) {
			$this->fail( $user_id->get_error_message() );
		}
		$this->user_id = $user_id;
		update_user_meta( $this->user_id, '_workos_user_id', $this->workos_id );

		$this->workos_user = [
			'id'         => $this->workos_id,
			'email'      => $this->email,
			'first_name' => 'Ada',
			'last_name'  => 'Lovelace',
		];

		$this->http_requests = [];
	}

	/**
	 * Tear down each test.
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10 );
		delete_option( 'workos_production' );
		\WorkOS\Config::set_active_environment( 'staging' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$this->set_static( 'syncing', false );
		$this->set_static( 'pending_names', [] );

		unset( $_POST );
		$_POST = [];

		parent::tearDown();
	}

	/**
	 * Fake WorkOS API: records requests and applies PUTs to the user.
	 *
	 * @param false|array $response Pre-filtered response.
	 * @param array       $args     Request arguments.
	 * @param string      $url      Request URL.
	 *
	 * @return false|array|\WP_Error
	 */
	public function intercept_http( $response, array $args, string $url ) {
		if ( false === strpos( $url, 'api.workos.com' ) ) {
			return $response;
		}

		$body = json_decode( $args['body'] ?? '', true );

		$this->http_requests[] = [
			'method' => $args['method'] ?? 'GET',
			'url'    => $url,
			'body'   => is_array( $body ) ? $body : [],
		];

		if ( 'PUT' === ( $args['method'] ?? '' ) && 'transport' === $this->put_error ) {
			return new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		}

		if ( 'PUT' === ( $args['method'] ?? '' ) && 'email_not_available' === $this->put_error ) {
			return [
				'response' => [
					'code'    => 422,
					'message' => 'Unprocessable Entity',
				],
				'body'     => wp_json_encode(
					[
						'code'    => 'email_not_available',
						'message' => 'This email is not available.',
					]
				),
			];
		}

		if ( 'PUT' === ( $args['method'] ?? '' ) && is_array( $body ) ) {
			$this->workos_user = array_merge( $this->workos_user, $body );
		}

		return [
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'body'     => wp_json_encode( $this->workos_user ),
		];
	}

	/**
	 * The `update_user` calls made so far.
	 *
	 * @return array
	 */
	private function update_calls(): array {
		$path = '/user_management/users/' . $this->workos_id;

		return array_values(
			array_filter(
				$this->http_requests,
				static function ( $request ) use ( $path ) {
					return 'PUT' === $request['method'] && false !== strpos( $request['url'], $path );
				}
			)
		);
	}

	/**
	 * Set a private static on UserSync.
	 *
	 * @param string $name  Property name.
	 * @param mixed  $value Value.
	 */
	private function set_static( string $name, $value ): void {
		$ref = new \ReflectionProperty( UserSync::class, $name );
		$ref->setAccessible( true );
		$ref->setValue( null, $value );
	}

	/**
	 * Run the plugin's shutdown flush.
	 */
	private function flush(): void {
		\WorkOS\App::container()->get( UserSync::class )->flush_name_changes();
	}

	// -------------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------------

	/**
	 * A first name change through wp_update_user() is pushed, alone.
	 */
	public function test_wp_update_user_first_name_pushes_only_first_name(): void {
		wp_update_user(
			[
				'ID'         => $this->user_id,
				'first_name' => 'Augusta',
			]
		);

		$calls = $this->update_calls();
		$this->assertCount( 1, $calls );
		$this->assertSame( [ 'first_name' => 'Augusta' ], $calls[0]['body'] );
	}

	/**
	 * Both names changed in one save go out in one call.
	 */
	public function test_wp_update_user_both_names_push_once(): void {
		wp_update_user(
			[
				'ID'         => $this->user_id,
				'first_name' => 'Augusta',
				'last_name'  => 'King',
			]
		);

		$this->flush();

		$calls = $this->update_calls();
		$this->assertCount( 1, $calls );
		$this->assertSame(
			[
				'first_name' => 'Augusta',
				'last_name'  => 'King',
			],
			$calls[0]['body']
		);
	}

	/**
	 * A name and an email change in one save go out in one call.
	 */
	public function test_wp_update_user_name_and_email_push_once(): void {
		$new_email = 'king.' . uniqid() . '@acme.dev';

		wp_update_user(
			[
				'ID'         => $this->user_id,
				'last_name'  => 'King',
				'user_email' => $new_email,
			]
		);

		$this->flush();

		$calls = $this->update_calls();
		$this->assertCount( 1, $calls );
		$this->assertSame(
			[
				'last_name' => 'King',
				'email'     => $new_email,
			],
			$calls[0]['body']
		);
	}

	/**
	 * Saving the wp-admin profile form pushes the changed name.
	 */
	public function test_admin_profile_form_pushes_name(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$_POST = [
			'email'        => $this->email,
			'nickname'     => 'Ada',
			'display_name' => 'Ada Lovelace',
			'first_name'   => 'Ada',
			'last_name'    => 'King',
		];

		$result = edit_user( $this->user_id );
		$this->assertSame( $this->user_id, $result );

		$this->flush();

		$calls = $this->update_calls();
		$this->assertCount( 1, $calls );
		$this->assertSame( [ 'last_name' => 'King' ], $calls[0]['body'] );
	}

	/**
	 * Names written with update_user_meta() are pushed at shutdown, in one call.
	 */
	public function test_update_user_meta_names_push_once_at_shutdown(): void {
		update_user_meta( $this->user_id, 'first_name', 'Augusta' );
		update_user_meta( $this->user_id, 'last_name', 'King' );

		$this->assertCount( 0, $this->update_calls(), 'Nothing is sent until shutdown.' );

		$this->flush();

		$calls = $this->update_calls();
		$this->assertCount( 1, $calls );
		$this->assertSame(
			[
				'first_name' => 'Augusta',
				'last_name'  => 'King',
			],
			$calls[0]['body']
		);

		$this->flush();
		$this->assertCount( 1, $this->update_calls(), 'A second flush sends nothing.' );
	}

	/**
	 * The flush is hooked to shutdown.
	 */
	public function test_flush_is_hooked_to_shutdown(): void {
		$sync = \WorkOS\App::container()->get( UserSync::class );

		$this->assertNotFalse( has_action( 'shutdown', [ $sync, 'flush_name_changes' ] ) );
	}

	/**
	 * Saving without changes sends nothing.
	 */
	public function test_unchanged_save_sends_nothing(): void {
		wp_update_user(
			[
				'ID'         => $this->user_id,
				'first_name' => 'Ada',
				'last_name'  => 'Lovelace',
				'user_email' => $this->email,
			]
		);
		update_user_meta( $this->user_id, 'first_name', 'Ada' );

		$this->flush();

		$this->assertCount( 0, $this->update_calls() );
	}

	/**
	 * Unlinked users are never pushed.
	 */
	public function test_unlinked_user_is_not_pushed(): void {
		$other = self::factory()->user->create( [ 'first_name' => 'Grace' ] );

		wp_update_user(
			[
				'ID'         => $other,
				'first_name' => 'Gracie',
			]
		);
		update_user_meta( $other, 'last_name', 'Hopper' );

		$this->flush();

		$this->assertCount( 0, $this->http_requests );
	}

	/**
	 * Inbound syncs from WorkOS don't echo back.
	 */
	public function test_inbound_sync_does_not_echo_back(): void {
		$this->workos_user['first_name'] = 'Augusta';

		UserSync::find_or_create_wp_user( $this->workos_user, 'login' );
		$this->flush();

		$this->assertSame( 'Augusta', get_user_meta( $this->user_id, 'first_name', true ) );
		$this->assertCount( 0, $this->update_calls() );
	}

	/**
	 * A sign-in right after the edit keeps the new name.
	 */
	public function test_sign_in_after_edit_keeps_new_name(): void {
		wp_update_user(
			[
				'ID'         => $this->user_id,
				'first_name' => 'Augusta',
			]
		);

		// Sign-in hands over the WorkOS user as WorkOS now has it.
		$user = UserSync::find_or_create_wp_user( $this->workos_user, 'login' );

		$this->assertInstanceOf( \WP_User::class, $user );
		$this->assertSame( 'Augusta', get_user_meta( $this->user_id, 'first_name', true ) );
	}

	// -------------------------------------------------------------------------
	// Push errors
	// -------------------------------------------------------------------------

	/**
	 * A successful push stamps the sync time and stores no error.
	 */
	public function test_successful_push_stamps_sync_time(): void {
		update_user_meta( $this->user_id, '_workos_last_synced_at', '2020-01-01 00:00:00' );

		wp_update_user(
			[
				'ID'         => $this->user_id,
				'first_name' => 'Augusta',
			]
		);

		$this->assertNotSame( '2020-01-01 00:00:00', get_user_meta( $this->user_id, '_workos_last_synced_at', true ) );
		$this->assertSame( '', get_user_meta( $this->user_id, UserSync::META_SYNC_ERROR, true ) );
		$this->assertCount( 0, $this->failed );
	}

	/**
	 * WorkOS refusing the email is stored, logged and announced.
	 */
	public function test_email_not_available_is_recorded(): void {
		update_user_meta( $this->user_id, '_workos_last_synced_at', '2020-01-01 00:00:00' );
		$this->put_error = 'email_not_available';
		$taken           = 'taken.' . uniqid() . '@acme.dev';

		wp_update_user(
			[
				'ID'         => $this->user_id,
				'user_email' => $taken,
			]
		);

		$this->assertCount( 1, $this->update_calls() );

		// Still marked as out of sync.
		$this->assertSame( '2020-01-01 00:00:00', get_user_meta( $this->user_id, '_workos_last_synced_at', true ) );

		$error = get_user_meta( $this->user_id, UserSync::META_SYNC_ERROR, true );
		$this->assertIsArray( $error );
		$this->assertSame( 'email_not_available', $error['code'] );
		$this->assertSame( 'This email is not available.', $error['message'] );
		$this->assertSame( [ 'email' ], $error['fields'] );
		$this->assertIsInt( $error['time'] );

		$this->assertCount( 1, $this->failed );
		[ $user_id, $workos_id, $update, $wp_error ] = $this->failed[0];
		$this->assertSame( $this->user_id, $user_id );
		$this->assertSame( $this->workos_id, $workos_id );
		$this->assertSame( [ 'email' => $taken ], $update );
		$this->assertInstanceOf( \WP_Error::class, $wp_error );

		$events = array_values(
			array_filter(
				$this->logged,
				static function ( $entry ) {
					return 'profile_push_failed' === $entry[0];
				}
			)
		);
		$this->assertCount( 1, $events );
		$this->assertSame( $this->user_id, $events[0][1]['user_id'] );
		$this->assertSame( 'email_not_available', $events[0][1]['metadata']['code'] );
	}

	/**
	 * A transport error is recorded with its WP_Error code.
	 */
	public function test_transport_error_is_recorded(): void {
		$this->put_error = 'transport';

		update_user_meta( $this->user_id, 'last_name', 'King' );
		$this->flush();

		$error = get_user_meta( $this->user_id, UserSync::META_SYNC_ERROR, true );
		$this->assertIsArray( $error );
		$this->assertSame( 'http_request_failed', $error['code'] );
		$this->assertSame( [ 'last_name' ], $error['fields'] );
		$this->assertCount( 1, $this->failed );
	}

	/**
	 * The next successful push clears the stored error.
	 */
	public function test_successful_push_clears_error(): void {
		$this->put_error = 'email_not_available';
		wp_update_user(
			[
				'ID'         => $this->user_id,
				'user_email' => 'taken.' . uniqid() . '@acme.dev',
			]
		);
		$this->assertIsArray( get_user_meta( $this->user_id, UserSync::META_SYNC_ERROR, true ) );

		$this->put_error = '';
		wp_update_user(
			[
				'ID'         => $this->user_id,
				'first_name' => 'Augusta',
			]
		);

		$this->assertSame( '', get_user_meta( $this->user_id, UserSync::META_SYNC_ERROR, true ) );
	}

	/**
	 * Unlinking clears the stored error.
	 */
	public function test_unlink_clears_error(): void {
		update_user_meta( $this->user_id, UserSync::META_SYNC_ERROR, [ 'code' => 'email_not_available' ] );

		UserSync::unlink_user( $this->user_id );

		$this->assertSame( '', get_user_meta( $this->user_id, UserSync::META_SYNC_ERROR, true ) );
	}

	/**
	 * The WorkOS panel on the user-edit screen shows the stored error.
	 */
	public function test_profile_panel_shows_error(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		update_user_meta(
			$this->user_id,
			UserSync::META_SYNC_ERROR,
			[
				'code'    => 'email_not_available',
				'message' => 'This email is not available.',
				'fields'  => [ 'email' ],
				'time'    => time(),
			]
		);

		ob_start();
		( new \WorkOS\Admin\UserProfile() )->render( get_user_by( 'id', $this->user_id ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Out of sync', $html );
		$this->assertStringContainsString( '<code>email_not_available</code>', $html );
		$this->assertStringContainsString( 'This email is not available.', $html );
	}

	/**
	 * No error, no notice.
	 */
	public function test_profile_panel_without_error_has_no_notice(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		ob_start();
		( new \WorkOS\Admin\UserProfile() )->render( get_user_by( 'id', $this->user_id ) );
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'Out of sync', $html );
	}
}
