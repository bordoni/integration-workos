<?php
/**
 * Tests for re-owned accounts: split-account refusal, ending sessions when a
 * link changes, failed profile updates and `wp workos user move`.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

require_once dirname( __DIR__ ) . '/_support/fake-wp-cli.php';

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\App;
use WorkOS\CLI\UserCommand;
use WorkOS\Config;
use WorkOS\Options\Production;
use WorkOS\Sync\UserSync;
use WP_Session_Tokens;

/**
 * Account move and session tests.
 */
class AccountMoveTest extends WPTestCase {

	/**
	 * Captured WorkOS API requests.
	 *
	 * @var array
	 */
	private array $requests = [];

	/**
	 * WorkOS users the fake API returns, keyed by ID.
	 *
	 * @var array
	 */
	private array $workos_users = [];

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

		add_filter( 'pre_http_request', [ $this, 'fake_api' ], 10, 3 );

		remove_all_actions( 'user_register' );
		remove_all_actions( 'profile_update' );

		\WP_CLI::reset();
	}

	/**
	 * Tear down each test.
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'fake_api' ], 10 );
		remove_all_filters( 'workos_refuse_split_account' );
		delete_option( 'workos_production' );
		Config::set_active_environment( 'staging' );
		App::container()->get( Production::class )->reset();

		$ref = new \ReflectionProperty( UserSync::class, 'syncing' );
		$ref->setAccessible( true );
		$ref->setValue( null, false );

		$this->requests     = [];
		$this->workos_users = [];

		parent::tearDown();
	}

	/**
	 * Fake WorkOS API: records requests, serves known users, echoes updates.
	 *
	 * @param false|array $preempt Response override.
	 * @param array       $args    Request args.
	 * @param string      $url     Request URL.
	 *
	 * @return array|false
	 */
	public function fake_api( $preempt, array $args, string $url ) {
		if ( false === strpos( $url, 'api.workos.com' ) ) {
			return $preempt;
		}

		$method           = $args['method'] ?? 'GET';
		$this->requests[] = [
			'method' => $method,
			'url'    => $url,
			'body'   => $args['body'] ?? '',
		];

		$body = [];
		if ( preg_match( '#/user_management/users/([^/?]+)$#', wp_parse_url( $url, PHP_URL_PATH ), $m ) && isset( $this->workos_users[ $m[1] ] ) ) {
			$body = $this->workos_users[ $m[1] ];
			if ( 'PUT' === $method ) {
				$body                        = array_merge( $body, (array) json_decode( (string) $args['body'], true ) );
				$this->workos_users[ $m[1] ] = $body;
			}
		}

		return [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'body'     => wp_json_encode( $body ),
		];
	}

	/**
	 * Create a WP user.
	 *
	 * @param string $email     Email.
	 * @param string $workos_id WorkOS user ID to link, or ''.
	 *
	 * @return int
	 */
	private function user( string $email, string $workos_id = '' ): int {
		$id = self::factory()->user->create( [ 'user_email' => $email ] );
		if ( $workos_id ) {
			update_user_meta( $id, '_workos_user_id', $workos_id );
		}

		return $id;
	}

	/**
	 * Give a user a WordPress session and stored WorkOS session.
	 *
	 * @param int    $user_id    WP user ID.
	 * @param string $session_id WorkOS session ID.
	 */
	private function sign_in( int $user_id, string $session_id ): void {
		WP_Session_Tokens::get_instance( $user_id )->create( time() + DAY_IN_SECONDS );

		$payload = rtrim( strtr( base64_encode( wp_json_encode( [ 'sid' => $session_id ] ) ), '+/', '-_' ), '=' );
		update_user_meta( $user_id, '_workos_access_token', "h.{$payload}.s" );
		update_user_meta( $user_id, '_workos_refresh_token', 'refresh_fake' );
		update_user_meta( $user_id, '_workos_session_id', $session_id );
	}

	/**
	 * Revoke requests made, by session ID.
	 *
	 * @return string[]
	 */
	private function revoked(): array {
		$ids = [];
		foreach ( $this->requests as $request ) {
			if ( 'POST' === $request['method'] && preg_match( '#/sessions/([^/]+)/revoke$#', $request['url'], $m ) ) {
				$ids[] = $m[1];
			}
		}

		return $ids;
	}

	/**
	 * The CLI command under test.
	 *
	 * @return UserCommand
	 */
	private function cli(): UserCommand {
		return new UserCommand( App::container()->get( UserSync::class ) );
	}

	// -------------------------------------------------------------------------
	// Split accounts
	// -------------------------------------------------------------------------

	/**
	 * Re-owned account: the link stayed on the old user, the email moved to an
	 * unlinked one. The sign-in is refused instead of landing in the old user.
	 */
	public function test_sign_in_refused_when_email_moved_to_unlinked_user(): void {
		$old = $this->user( 'dummy-customer@example.com', 'user_c' );
		$new = $this->user( 'customer@example.com' );

		$result = UserSync::find_or_create_wp_user( [ 'id' => 'user_c', 'email' => 'customer@example.com' ], 'authkit' );

		$this->assertWPError( $result );
		$this->assertSame( 'workos_account_split', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( 'dummy-customer@example.com', get_userdata( $old )->user_email );
		$this->assertSame( '', get_user_meta( $new, '_workos_user_id', true ) );
	}

	/**
	 * The filter restores the old behavior.
	 */
	public function test_filter_allows_split_sign_in(): void {
		$old = $this->user( 'dummy-customer@example.com', 'user_c' );
		$this->user( 'customer@example.com' );
		add_filter( 'workos_refuse_split_account', '__return_false' );

		$result = UserSync::find_or_create_wp_user( [ 'id' => 'user_c', 'email' => 'customer@example.com' ], 'authkit' );

		$this->assertInstanceOf( \WP_User::class, $result );
		$this->assertSame( $old, $result->ID );
	}

	/**
	 * A WorkOS email change to a free address still updates the linked user.
	 */
	public function test_email_change_without_holder_updates_linked_user(): void {
		$linked = $this->user( 'old@example.com', 'user_c' );

		$result = UserSync::find_or_create_wp_user( [ 'id' => 'user_c', 'email' => 'new@example.com' ], 'authkit' );

		$this->assertSame( $linked, $result->ID );
		$this->assertSame( 'new@example.com', get_userdata( $linked )->user_email );
	}

	// -------------------------------------------------------------------------
	// Failed profile updates
	// -------------------------------------------------------------------------

	/**
	 * A refused update isn't recorded as synced.
	 */
	public function test_refused_profile_update_is_not_marked_synced(): void {
		$old = $this->user( 'dummy-customer@example.com', 'user_c' );
		$this->user( 'customer@example.com' );
		add_filter( 'workos_refuse_split_account', '__return_false' );

		UserSync::find_or_create_wp_user( [ 'id' => 'user_c', 'email' => 'customer@example.com', 'first_name' => 'Ana' ], 'authkit' );

		$this->assertSame( '', get_user_meta( $old, '_workos_profile_hash', true ) );
		$this->assertSame( '', get_user_meta( $old, '_workos_last_synced_at', true ) );
	}

	// -------------------------------------------------------------------------
	// Ending sessions
	// -------------------------------------------------------------------------

	/**
	 * Unlinking ends WordPress and WorkOS sessions.
	 */
	public function test_unlink_ends_sessions(): void {
		$user = $this->user( 'customer@example.com', 'user_c' );
		$this->sign_in( $user, 'session_1' );

		UserSync::unlink_user( $user );

		$this->assertSame( [], WP_Session_Tokens::get_instance( $user )->get_all() );
		$this->assertSame( '', get_user_meta( $user, '_workos_session_id', true ) );
		$this->assertSame( '', get_user_meta( $user, '_workos_access_token', true ) );
		$this->assertSame( [ 'session_1' ], $this->revoked() );
	}

	/**
	 * Re-linking to a different WorkOS user ends the sessions of the old link.
	 */
	public function test_relink_ends_sessions(): void {
		$user = $this->user( 'customer@example.com', 'user_old' );
		$this->sign_in( $user, 'session_old' );

		$this->assertTrue( UserSync::link_user( $user, [ 'id' => 'user_new', 'email' => 'customer@example.com' ], 'test', true ) );

		$this->assertSame( [], WP_Session_Tokens::get_instance( $user )->get_all() );
		$this->assertSame( [ 'session_old' ], $this->revoked() );
	}

	/**
	 * A first link, or writing the same link again, keeps the sessions.
	 */
	public function test_first_and_same_link_keep_sessions(): void {
		$user = $this->user( 'customer@example.com' );
		WP_Session_Tokens::get_instance( $user )->create( time() + DAY_IN_SECONDS );

		UserSync::link_user( $user, [ 'id' => 'user_c', 'email' => 'customer@example.com' ], 'test' );
		UserSync::link_user( $user, [ 'id' => 'user_c', 'email' => 'customer@example.com' ], 'test' );

		$this->assertCount( 1, WP_Session_Tokens::get_instance( $user )->get_all() );
		$this->assertSame( [], $this->revoked() );
	}

	// -------------------------------------------------------------------------
	// wp workos user move
	// -------------------------------------------------------------------------

	/**
	 * The re-owned case: the link moves, the old user's sessions end, and the
	 * customer then signs in to the right account.
	 */
	public function test_move_fixes_reowned_account(): void {
		$old = $this->user( 'dummy-customer@example.com', 'user_c' );
		$new = $this->user( 'customer@example.com' );
		$this->sign_in( $old, 'session_old' );
		$this->workos_users['user_c'] = [ 'id' => 'user_c', 'email' => 'customer@example.com' ];

		$this->cli()->move( [ $old, $new ], [ 'yes' => true ] );

		$this->assertSame( '', get_user_meta( $old, '_workos_user_id', true ) );
		$this->assertSame( 'user_c', get_user_meta( $new, '_workos_user_id', true ) );
		$this->assertSame( [], WP_Session_Tokens::get_instance( $old )->get_all() );
		$this->assertSame( [ 'session_old' ], $this->revoked() );

		$result = UserSync::find_or_create_wp_user( [ 'id' => 'user_c', 'email' => 'customer@example.com' ], 'authkit' );
		$this->assertSame( $new, $result->ID );
	}

	/**
	 * A dry run changes nothing.
	 */
	public function test_move_dry_run_changes_nothing(): void {
		$old = $this->user( 'dummy-customer@example.com', 'user_c' );
		$new = $this->user( 'customer@example.com' );
		$this->sign_in( $old, 'session_old' );
		$this->workos_users['user_c'] = [ 'id' => 'user_c', 'email' => 'customer@example.com' ];

		$this->cli()->move( [ $old, $new ], [ 'dry-run' => true ] );

		$this->assertSame( 'user_c', get_user_meta( $old, '_workos_user_id', true ) );
		$this->assertSame( '', get_user_meta( $new, '_workos_user_id', true ) );
		$this->assertCount( 1, WP_Session_Tokens::get_instance( $old )->get_all() );
		$this->assertSame( [], $this->revoked() );
	}

	/**
	 * Differing emails need an explicit choice; nothing changes without one.
	 */
	public function test_move_requires_email_choice_when_emails_differ(): void {
		$old = $this->user( 'dummy-customer@example.com', 'user_c' );
		$new = $this->user( 'customer@example.com' );
		$this->workos_users['user_c'] = [ 'id' => 'user_c', 'email' => 'dummy-customer@example.com' ];

		try {
			$this->cli()->move( [ $old, $new ], [ 'yes' => true ] );
			$this->fail( 'Expected the move to stop.' );
		} catch ( \WP_CLI_Error_Exception $e ) {
			$this->assertStringContainsString( '--use-wp-email', $e->getMessage() );
		}

		$this->assertSame( 'user_c', get_user_meta( $old, '_workos_user_id', true ) );
		$this->assertSame( '', get_user_meta( $new, '_workos_user_id', true ) );
	}

	/**
	 * --use-wp-email sets the WorkOS email to the target's before moving.
	 */
	public function test_move_use_wp_email_updates_workos(): void {
		$old = $this->user( 'dummy-customer@example.com', 'user_c' );
		$new = $this->user( 'customer@example.com' );
		$this->workos_users['user_c'] = [ 'id' => 'user_c', 'email' => 'dummy-customer@example.com' ];

		$this->cli()->move( [ $old, $new ], [ 'yes' => true, 'use-wp-email' => true ] );

		$this->assertSame( 'customer@example.com', $this->workos_users['user_c']['email'] );
		$this->assertSame( 'user_c', get_user_meta( $new, '_workos_user_id', true ) );
	}

	/**
	 * A target already linked to another WorkOS user needs --force.
	 */
	public function test_move_refuses_linked_target_without_force(): void {
		$old = $this->user( 'dummy-customer@example.com', 'user_c' );
		$new = $this->user( 'customer@example.com', 'user_other' );
		$this->workos_users['user_c'] = [ 'id' => 'user_c', 'email' => 'customer@example.com' ];

		$this->expectException( \WP_CLI_Error_Exception::class );
		$this->cli()->move( [ $old, $new ], [ 'yes' => true ] );
	}
}
