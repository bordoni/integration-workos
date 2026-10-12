<?php
/**
 * Tests for Re-link Protection in UserSync::link_user() and the paths that link through it.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\App;
use WorkOS\Config;
use WorkOS\Options\Production;
use WorkOS\Sync\DirectorySync;
use WorkOS\Sync\UserSync;

/**
 * Re-link Protection tests.
 */
class UserSyncRelinkProtectionTest extends WPTestCase {

	/**
	 * Calls to workos_user_linked: [ wp_user_id, workos_id, previous_id, source ].
	 *
	 * @var array<int, array>
	 */
	private array $linked = [];

	/**
	 * Calls to workos_user_link_refused: [ wp_user_id, current_id, workos_user, source ].
	 *
	 * @var array<int, array>
	 */
	private array $refused = [];

	/**
	 * Set up each test.
	 */
	public function setUp(): void {
		parent::setUp();

		Config::set_active_environment( 'production' );
		$this->set_options( [] );

		add_filter( 'pre_http_request', [ $this, 'block_http' ], 10, 3 );

		remove_all_actions( 'user_register' );
		remove_all_actions( 'profile_update' );

		$this->linked  = [];
		$this->refused = [];
		add_action( 'workos_user_linked', [ $this, 'record_linked' ], 10, 4 );
		add_action( 'workos_user_link_refused', [ $this, 'record_refused' ], 10, 4 );
	}

	/**
	 * Tear down each test.
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'block_http' ], 10 );
		remove_action( 'workos_user_linked', [ $this, 'record_linked' ], 10 );
		remove_action( 'workos_user_link_refused', [ $this, 'record_refused' ], 10 );
		remove_all_filters( 'workos_relink_protection_enabled' );

		delete_option( 'workos_production' );
		Config::set_active_environment( 'staging' );
		App::container()->get( Production::class )->reset();

		$ref = new \ReflectionProperty( UserSync::class, 'syncing' );
		$ref->setAccessible( true );
		$ref->setValue( null, false );

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
	 * Turn Re-link Protection on through the option.
	 */
	private function enable_protection(): void {
		$this->set_options( [ 'relink_protection' => true ] );
	}

	/**
	 * Block outgoing WorkOS API calls.
	 *
	 * @param false|array $response Pre-filtered response.
	 * @param array       $args     Request arguments.
	 * @param string      $url      Request URL.
	 *
	 * @return array|false
	 */
	public function block_http( $response, array $args, string $url ) {
		if ( false === strpos( $url, 'api.workos.com' ) ) {
			return $response;
		}

		return [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'body'     => wp_json_encode( [ 'data' => [] ] ),
		];
	}

	/**
	 * Record a workos_user_linked call.
	 *
	 * @param int    $wp_user_id  WP user ID.
	 * @param string $workos_id   WorkOS user ID.
	 * @param string $previous_id Previous WorkOS user ID.
	 * @param string $source      Source.
	 */
	public function record_linked( $wp_user_id, $workos_id, $previous_id, $source ): void {
		$this->linked[] = [ $wp_user_id, $workos_id, $previous_id, $source ];
	}

	/**
	 * Record a workos_user_link_refused call.
	 *
	 * @param int    $wp_user_id  WP user ID.
	 * @param string $current_id  Current WorkOS user ID.
	 * @param array  $workos_user Attempted WorkOS user.
	 * @param string $source      Source.
	 */
	public function record_refused( $wp_user_id, $current_id, $workos_user, $source ): void {
		$this->refused[] = [ $wp_user_id, $current_id, $workos_user, $source ];
	}

	/**
	 * Create a WP user linked to a WorkOS user.
	 *
	 * @param string $email     Email.
	 * @param string $workos_id WorkOS user ID.
	 *
	 * @return int WP user ID.
	 */
	private function linked_user( string $email, string $workos_id ): int {
		$user_id = self::factory()->user->create( [ 'user_email' => $email ] );
		update_user_meta( $user_id, '_workos_user_id', $workos_id );

		return $user_id;
	}

	// -------------------------------------------------------------------------
	// Setting
	// -------------------------------------------------------------------------

	/**
	 * Protection is off by default.
	 */
	public function test_protection_is_off_by_default(): void {
		$this->assertFalse( Config::is_relink_protection_enabled() );
	}

	/**
	 * The option turns protection on.
	 */
	public function test_option_turns_protection_on(): void {
		$this->enable_protection();

		$this->assertTrue( Config::is_relink_protection_enabled() );
	}

	/**
	 * The filter overrides the option.
	 */
	public function test_filter_overrides_option(): void {
		$this->enable_protection();
		add_filter( 'workos_relink_protection_enabled', '__return_false' );

		$this->assertFalse( Config::is_relink_protection_enabled() );
	}

	/**
	 * No constant is reported when none is defined.
	 */
	public function test_bool_constant_is_empty_without_constant(): void {
		$this->assertSame( '', Config::bool_constant( 'relink_protection' ) );
	}

	// -------------------------------------------------------------------------
	// link_user()
	// -------------------------------------------------------------------------

	/**
	 * A first link fires workos_user_linked with no previous ID.
	 */
	public function test_first_link_fires_linked_action(): void {
		$user_id = self::factory()->user->create();

		$result = UserSync::link_user( $user_id, [ 'id' => 'user_first', 'email' => 'first@example.com' ], 'login' );

		$this->assertTrue( $result );
		$this->assertSame( [ [ $user_id, 'user_first', '', 'login' ] ], $this->linked );
	}

	/**
	 * Writing the same link again doesn't fire workos_user_linked.
	 */
	public function test_same_link_again_does_not_fire_linked_action(): void {
		$user_id = $this->linked_user( 'same@example.com', 'user_same' );

		$result = UserSync::link_user( $user_id, [ 'id' => 'user_same', 'email' => 'same@example.com' ], 'login' );

		$this->assertTrue( $result );
		$this->assertSame( [], $this->linked );
	}

	/**
	 * With protection off, a re-link goes through as before and reports the previous ID.
	 */
	public function test_relink_allowed_when_protection_off(): void {
		$user_id = $this->linked_user( 'off@example.com', 'user_old' );

		$result = UserSync::link_user( $user_id, [ 'id' => 'user_new', 'email' => 'off@example.com' ], 'webhook' );

		$this->assertTrue( $result );
		$this->assertSame( 'user_new', get_user_meta( $user_id, '_workos_user_id', true ) );
		$this->assertSame( [ [ $user_id, 'user_new', 'user_old', 'webhook' ] ], $this->linked );
		$this->assertSame( [], $this->refused );
	}

	/**
	 * With protection on, a re-link is refused and nothing changes.
	 */
	public function test_relink_refused_when_protection_on(): void {
		$this->enable_protection();
		$user_id = $this->linked_user( 'on@example.com', 'user_old' );
		update_user_meta( $user_id, '_workos_profile_hash', 'hash_before' );

		$result = UserSync::link_user( $user_id, [ 'id' => 'user_new', 'email' => 'on@example.com' ], 'login' );

		$this->assertFalse( $result );
		$this->assertSame( 'user_old', get_user_meta( $user_id, '_workos_user_id', true ) );
		$this->assertSame( 'hash_before', get_user_meta( $user_id, '_workos_profile_hash', true ) );
		$this->assertSame( [], $this->linked );
		$this->assertCount( 1, $this->refused );
		$this->assertSame( $user_id, $this->refused[0][0] );
		$this->assertSame( 'user_old', $this->refused[0][1] );
		$this->assertSame( 'user_new', $this->refused[0][2]['id'] );
		$this->assertSame( 'login', $this->refused[0][3] );
	}

	/**
	 * A refused re-link is recorded on the user for the profile notice.
	 */
	public function test_refused_relink_is_recorded_on_user(): void {
		$this->enable_protection();
		$user_id = $this->linked_user( 'record@example.com', 'user_old' );

		UserSync::link_user( $user_id, [ 'id' => 'user_new', 'email' => 'other@example.com' ], 'webhook' );

		$refused = get_user_meta( $user_id, UserSync::META_LINK_REFUSED, true );
		$this->assertIsArray( $refused );
		$this->assertSame( 'user_new', $refused['workos_user_id'] );
		$this->assertSame( 'other@example.com', $refused['email'] );
		$this->assertSame( 'webhook', $refused['source'] );
		$this->assertGreaterThan( 0, $refused['time'] );
	}

	/**
	 * A refused re-link writes a link_refused row to the Activity Log.
	 */
	public function test_refused_relink_writes_activity_log(): void {
		global $wpdb;

		$this->set_options(
			[
				'relink_protection'   => true,
				'enable_activity_log' => true,
			]
		);
		$user_id = $this->linked_user( 'log@example.com', 'user_old' );

		UserSync::link_user( $user_id, [ 'id' => 'user_new', 'email' => 'log@example.com' ], 'directory_sync' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}workos_activity_log WHERE event_type = %s AND user_id = %d",
				'link_refused',
				$user_id
			),
			ARRAY_A
		);

		$this->assertNotNull( $row );
		$this->assertSame( 'user_old', $row['workos_user_id'] );
	}

	/**
	 * Force replaces the link even with protection on, and clears the refusal record.
	 */
	public function test_force_relinks_when_protection_on(): void {
		$this->enable_protection();
		$user_id = $this->linked_user( 'force@example.com', 'user_old' );
		UserSync::link_user( $user_id, [ 'id' => 'user_new', 'email' => 'force@example.com' ], 'login' );

		$result = UserSync::link_user( $user_id, [ 'id' => 'user_new', 'email' => 'force@example.com' ], 'cli_link', true );

		$this->assertTrue( $result );
		$this->assertSame( 'user_new', get_user_meta( $user_id, '_workos_user_id', true ) );
		$this->assertEmpty( get_user_meta( $user_id, UserSync::META_LINK_REFUSED, true ) );
		$this->assertSame( [ [ $user_id, 'user_new', 'user_old', 'cli_link' ] ], $this->linked );
	}

	/**
	 * An unlinked user links normally with protection on.
	 */
	public function test_unlinked_user_links_when_protection_on(): void {
		$this->enable_protection();
		$user_id = self::factory()->user->create();

		$result = UserSync::link_user( $user_id, [ 'id' => 'user_fresh', 'email' => 'fresh@example.com' ], 'login' );

		$this->assertTrue( $result );
		$this->assertSame( 'user_fresh', get_user_meta( $user_id, '_workos_user_id', true ) );
	}

	/**
	 * A WorkOS user without an ID is never linked.
	 */
	public function test_missing_workos_id_is_not_linked(): void {
		$user_id = self::factory()->user->create();

		$this->assertFalse( UserSync::link_user( $user_id, [ 'email' => 'noid@example.com' ] ) );
		$this->assertEmpty( get_user_meta( $user_id, '_workos_user_id', true ) );
	}

	// -------------------------------------------------------------------------
	// Paths that link
	// -------------------------------------------------------------------------

	/**
	 * Sign-in: an email match on an account linked elsewhere returns a conflict error.
	 */
	public function test_sign_in_email_match_returns_conflict_when_protection_on(): void {
		$this->enable_protection();
		$user_id = $this->linked_user( 'signin@example.com', 'user_old' );

		$result = UserSync::find_or_create_wp_user( [ 'id' => 'user_new', 'email' => 'signin@example.com' ], 'authkit' );

		$this->assertWPError( $result );
		$this->assertSame( 'workos_link_conflict', $result->get_error_code() );
		$this->assertSame( 'user_old', get_user_meta( $user_id, '_workos_user_id', true ) );
		$this->assertSame( 'authkit', $this->refused[0][3] );
	}

	/**
	 * Sign-in: with protection off, the email match re-links as before.
	 */
	public function test_sign_in_email_match_relinks_when_protection_off(): void {
		$user_id = $this->linked_user( 'signin-off@example.com', 'user_old' );

		$result = UserSync::find_or_create_wp_user( [ 'id' => 'user_new', 'email' => 'signin-off@example.com' ], 'login' );

		$this->assertInstanceOf( \WP_User::class, $result );
		$this->assertSame( $user_id, $result->ID );
		$this->assertSame( 'user_new', get_user_meta( $user_id, '_workos_user_id', true ) );
	}

	/**
	 * Sign-in: a new user still gets created and linked with protection on.
	 */
	public function test_sign_in_creates_new_user_when_protection_on(): void {
		$this->enable_protection();

		$result = UserSync::find_or_create_wp_user( [ 'id' => 'user_brand_new', 'email' => 'brand-new@example.com' ], 'login' );

		$this->assertInstanceOf( \WP_User::class, $result );
		$this->assertSame( 'user_brand_new', get_user_meta( $result->ID, '_workos_user_id', true ) );
		$this->assertSame( [ [ $result->ID, 'user_brand_new', '', 'login' ] ], $this->linked );
	}

	/**
	 * Webhook: user.created for an email linked elsewhere doesn't move the account.
	 */
	public function test_user_created_webhook_does_not_relink_when_protection_on(): void {
		$this->enable_protection();
		$user_id = $this->linked_user( 'hook@example.com', 'user_old' );

		( new UserSync() )->handle_user_created(
			[
				'event' => 'user.created',
				'data'  => [ 'id' => 'user_new', 'email' => 'hook@example.com' ],
			]
		);

		$this->assertSame( 'user_old', get_user_meta( $user_id, '_workos_user_id', true ) );
		$this->assertSame( 'webhook', $this->refused[0][3] );
	}

	/**
	 * Directory sync: dsync.user.created for an email linked elsewhere doesn't move the account.
	 */
	public function test_directory_sync_does_not_relink_when_protection_on(): void {
		$this->enable_protection();
		$user_id = $this->linked_user( 'dsync@example.com', 'user_old' );

		( new DirectorySync() )->handle_user_created(
			[
				'event' => 'dsync.user.created',
				'data'  => [
					'id'     => 'directory_user_new',
					'emails' => [
						[
							'primary' => true,
							'value'   => 'dsync@example.com',
						],
					],
				],
			]
		);

		$this->assertSame( 'user_old', get_user_meta( $user_id, '_workos_user_id', true ) );
		$this->assertSame( 'directory_sync', $this->refused[0][3] );
	}

	// -------------------------------------------------------------------------
	// Review follow-ups
	// -------------------------------------------------------------------------

	/**
	 * A WorkOS ID match wins over an email match, and nothing is refused.
	 *
	 * WorkOS user A is linked to WP user 1. A signs in with an email that belongs to
	 * WP user 2 (linked to B). Step 1 finds WP user 1; WP user 2 is never considered.
	 */
	public function test_workos_id_match_beats_email_match(): void {
		$this->enable_protection();
		$one = $this->linked_user( 'one@example.com', 'user_a' );
		$two = $this->linked_user( 'two@example.com', 'user_b' );

		$result = UserSync::find_or_create_wp_user( [ 'id' => 'user_a', 'email' => 'two@example.com' ], 'login' );

		$this->assertInstanceOf( \WP_User::class, $result );
		$this->assertSame( $one, $result->ID );
		$this->assertSame( [], $this->refused );
		$this->assertSame( 'user_a', get_user_meta( $one, '_workos_user_id', true ) );
		$this->assertSame( 'user_b', get_user_meta( $two, '_workos_user_id', true ) );
		$this->assertEmpty( get_user_meta( $two, UserSync::META_LINK_REFUSED, true ) );
	}

	/**
	 * Unlinking clears a recorded refusal.
	 */
	public function test_unlink_clears_refusal(): void {
		$this->enable_protection();
		$user_id = $this->linked_user( 'unlink@example.com', 'user_old' );
		UserSync::link_user( $user_id, [ 'id' => 'user_new', 'email' => 'unlink@example.com' ], 'login' );
		$this->assertNotEmpty( get_user_meta( $user_id, UserSync::META_LINK_REFUSED, true ) );

		UserSync::unlink_user( $user_id );

		$this->assertEmpty( get_user_meta( $user_id, UserSync::META_LINK_REFUSED, true ) );
		$this->assertEmpty( get_user_meta( $user_id, '_workos_user_id', true ) );
	}

	/**
	 * --force never links a WorkOS ID that another WP user holds.
	 */
	public function test_force_refuses_workos_id_linked_elsewhere(): void {
		$holder = $this->linked_user( 'holder@example.com', 'user_held' );
		$other  = $this->linked_user( 'other@example.com', 'user_other' );

		$result = UserSync::link_user( $other, [ 'id' => 'user_held', 'email' => 'other@example.com' ], 'cli_link', true );

		$this->assertFalse( $result );
		$this->assertSame( 'user_other', get_user_meta( $other, '_workos_user_id', true ) );
		$this->assertSame( 'user_held', get_user_meta( $holder, '_workos_user_id', true ) );
		$this->assertSame( [], $this->linked );
	}

	/**
	 * With protection on, a first link of a WorkOS ID another WP user holds is refused too.
	 */
	public function test_protection_refuses_first_link_of_workos_id_linked_elsewhere(): void {
		$this->enable_protection();
		$this->linked_user( 'holder2@example.com', 'user_held2' );
		$fresh = self::factory()->user->create();

		$this->assertFalse( UserSync::link_user( $fresh, [ 'id' => 'user_held2', 'email' => 'fresh2@example.com' ], 'onboarding' ) );
		$this->assertEmpty( get_user_meta( $fresh, '_workos_user_id', true ) );
	}

	/**
	 * With protection off and no --force, link_user() behaves as before.
	 */
	public function test_unprotected_link_leaves_holder_check_to_caller(): void {
		$this->linked_user( 'holder3@example.com', 'user_held3' );
		$fresh = self::factory()->user->create();

		$this->assertTrue( UserSync::link_user( $fresh, [ 'id' => 'user_held3', 'email' => 'fresh3@example.com' ], 'onboarding' ) );
	}

	/**
	 * New installs start with protection on; keys a site already has are left alone.
	 */
	public function test_new_install_defaults_turn_protection_on(): void {
		delete_option( 'workos_staging' );
		update_option( 'workos_production', [ 'api_key' => 'sk_test_fake', 'relink_protection' => false ] );

		\WorkOS\Plugin::seed_new_install_defaults();

		$this->assertTrue( get_option( 'workos_staging' )['relink_protection'] );
		$this->assertFalse( get_option( 'workos_production' )['relink_protection'] );
		$this->assertSame( 'sk_test_fake', get_option( 'workos_production' )['api_key'] );
	}
}
