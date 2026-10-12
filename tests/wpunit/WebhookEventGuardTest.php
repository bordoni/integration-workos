<?php
/**
 * Tests for duplicate and out-of-order webhook deliveries.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\App;
use WorkOS\Config;
use WorkOS\Options\Production;
use WorkOS\Sync\RoleMapper;
use WorkOS\Sync\UserSync;
use WorkOS\Webhook\EventGuard;
use WorkOS\Webhook\Receiver;

/**
 * Retried events run once; late events don't roll data back.
 */
class WebhookEventGuardTest extends WPTestCase {

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

		// No outbound sync while fixtures change.
		remove_all_actions( 'user_register' );
		remove_all_actions( 'profile_update' );
		remove_all_actions( 'set_user_role' );
	}

	/**
	 * Tear down each test.
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'block_http' ], 10 );
		delete_option( 'workos_production' );
		Config::set_active_environment( 'staging' );
		App::container()->get( Production::class )->reset();

		parent::tearDown();
	}

	/**
	 * Answer WorkOS API calls with an empty list.
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
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'body'     => wp_json_encode( [ 'data' => [] ] ),
			];
		}
		return $response;
	}

	/**
	 * A webhook request carrying this event.
	 *
	 * @param array $event Event payload.
	 *
	 * @return \WP_REST_Request
	 */
	private function request( array $event ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/workos/v1/webhook' );
		$request->set_body( wp_json_encode( $event ) );
		return $request;
	}

	/**
	 * A WP user linked to a WorkOS ID.
	 *
	 * @param string $workos_id WorkOS user ID.
	 * @param array  $args      User fields.
	 *
	 * @return int
	 */
	private function linked_user( string $workos_id, array $args = [] ): int {
		$user_id = self::factory()->user->create( $args );
		update_user_meta( $user_id, '_workos_user_id', $workos_id );
		return $user_id;
	}

	/**
	 * A user.updated event.
	 *
	 * @param string $workos_id  WorkOS user ID.
	 * @param string $first_name First name.
	 * @param string $updated_at updated_at, or '' to leave it out.
	 *
	 * @return array
	 */
	private function user_updated( string $workos_id, string $first_name, string $updated_at ): array {
		$data = [
			'id'         => $workos_id,
			'email'      => $workos_id . '@example.com',
			'first_name' => $first_name,
			'last_name'  => 'Tester',
		];
		if ( '' !== $updated_at ) {
			$data['updated_at'] = $updated_at;
		}
		return [ 'event' => 'user.updated', 'data' => $data ];
	}

	/**
	 * The same event ID delivered twice runs its handlers once.
	 */
	public function test_retried_event_runs_once(): void {
		$runs = 0;
		add_action(
			'workos_webhook_user.created',
			static function () use ( &$runs ) {
				++$runs;
			}
		);

		$receiver = new Receiver();
		$event    = [ 'id' => 'event_retry_1', 'event' => 'user.created', 'data' => [] ];

		$first  = $receiver->handle( $this->request( $event ) );
		$second = $receiver->handle( $this->request( $event ) );

		$this->assertSame( 1, $runs );
		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 200, $second->get_status() );
		$this->assertTrue( $second->get_data()['duplicate'] );
	}

	/**
	 * Different event IDs, and events without one, all run.
	 */
	public function test_distinct_and_unidentified_events_run(): void {
		$runs = 0;
		add_action(
			'workos_webhook_user.created',
			static function () use ( &$runs ) {
				++$runs;
			}
		);

		$receiver = new Receiver();
		$receiver->handle( $this->request( [ 'id' => 'event_a', 'event' => 'user.created', 'data' => [] ] ) );
		$receiver->handle( $this->request( [ 'id' => 'event_b', 'event' => 'user.created', 'data' => [] ] ) );
		$receiver->handle( $this->request( [ 'event' => 'user.created', 'data' => [] ] ) );
		$receiver->handle( $this->request( [ 'event' => 'user.created', 'data' => [] ] ) );

		$this->assertSame( 4, $runs );
	}

	/**
	 * Timestamps compare at millisecond precision; unknown is never stale.
	 */
	public function test_is_stale_and_latest(): void {
		$this->assertTrue( EventGuard::is_stale( '2026-10-12T10:00:00.100Z', '2026-10-12T10:00:00.200Z' ) );
		$this->assertTrue( EventGuard::is_stale( '2026-10-12T10:00:00.200Z', '2026-10-12T10:00:00.200Z' ) );
		$this->assertFalse( EventGuard::is_stale( '2026-10-12T10:00:00.300Z', '2026-10-12T10:00:00.200Z' ) );
		$this->assertFalse( EventGuard::is_stale( '', '2026-10-12T10:00:00.200Z' ) );
		$this->assertFalse( EventGuard::is_stale( '2026-10-12T10:00:00.100Z', '' ) );
		$this->assertFalse( EventGuard::is_stale( 'not a date', '2026-10-12T10:00:00.200Z' ) );

		$this->assertSame( '2026-10-12T10:00:01.000Z', EventGuard::latest( '2026-10-12T10:00:01.000Z', '2026-10-12T10:00:00.999Z' ) );
		$this->assertSame( '2026-10-12T10:00:00.999Z', EventGuard::latest( '2026-10-12T10:00:00.500Z', '2026-10-12T10:00:00.999Z' ) );
		$this->assertSame( '2026-10-12T10:00:00.999Z', EventGuard::latest( '', '2026-10-12T10:00:00.999Z' ) );
		$this->assertSame( '', EventGuard::latest( '', '' ) );
	}

	/**
	 * An older user.updated that arrives after a newer one doesn't roll the profile back.
	 */
	public function test_late_user_updated_does_not_roll_back(): void {
		$user_id = $this->linked_user( 'user_order' );
		$sync    = new UserSync();

		$sync->handle_user_updated( $this->user_updated( 'user_order', 'Newer', '2026-10-12T10:00:02.000Z' ) );
		$this->assertSame( 'Newer', get_user_meta( $user_id, 'first_name', true ) );

		$sync->handle_user_updated( $this->user_updated( 'user_order', 'Older', '2026-10-12T10:00:01.000Z' ) );
		$this->assertSame( 'Newer', get_user_meta( $user_id, 'first_name', true ) );
		$this->assertSame( '2026-10-12T10:00:02.000Z', get_user_meta( $user_id, UserSync::META_UPDATED_AT, true ) );

		$sync->handle_user_updated( $this->user_updated( 'user_order', 'Newest', '2026-10-12T10:00:03.000Z' ) );
		$this->assertSame( 'Newest', get_user_meta( $user_id, 'first_name', true ) );
	}

	/**
	 * A payload with no updated_at still applies, as before.
	 */
	public function test_user_updated_without_timestamp_applies(): void {
		$user_id = $this->linked_user( 'user_no_ts' );
		$sync    = new UserSync();

		$sync->handle_user_updated( $this->user_updated( 'user_no_ts', 'Stamped', '2026-10-12T10:00:02.000Z' ) );
		$sync->handle_user_updated( $this->user_updated( 'user_no_ts', 'Unstamped', '' ) );

		$this->assertSame( 'Unstamped', get_user_meta( $user_id, 'first_name', true ) );
	}

	/**
	 * An unchanged profile still moves the high-water mark, so an older change after it is skipped.
	 */
	public function test_unchanged_profile_records_updated_at(): void {
		$user_id = $this->linked_user( 'user_same' );
		$sync    = new UserSync();

		$sync->handle_user_updated( $this->user_updated( 'user_same', 'Same', '2026-10-12T10:00:01.000Z' ) );
		$sync->handle_user_updated( $this->user_updated( 'user_same', 'Same', '2026-10-12T10:00:05.000Z' ) );
		$sync->handle_user_updated( $this->user_updated( 'user_same', 'Between', '2026-10-12T10:00:03.000Z' ) );

		$this->assertSame( 'Same', get_user_meta( $user_id, 'first_name', true ) );
		$this->assertSame( '2026-10-12T10:00:05.000Z', get_user_meta( $user_id, UserSync::META_UPDATED_AT, true ) );
	}

	/**
	 * A late organization_membership.updated doesn't roll the role back.
	 */
	public function test_late_membership_updated_does_not_roll_back_role(): void {
		$user_id = $this->linked_user( 'user_role_order', [ 'role' => 'subscriber' ] );
		$mapper  = new RoleMapper();
		$event   = static function ( string $role, string $updated_at ): array {
			return [
				'event' => 'organization_membership.updated',
				'data'  => [
					'id'         => 'om_order',
					'user_id'    => 'user_role_order',
					'role'       => [ 'slug' => $role ],
					'updated_at' => $updated_at,
				],
			];
		};

		$mapper->handle_membership_updated( $event( 'admin', '2026-10-12T10:00:02.000Z' ) );
		$mapper->handle_membership_updated( $event( 'member', '2026-10-12T10:00:01.000Z' ) );

		$this->assertSame( [ 'administrator' ], get_userdata( $user_id )->roles );
	}

	/**
	 * Membership roles arrive as objects; the slug is what gets mapped and stored.
	 */
	public function test_role_slug_reads_objects_and_strings(): void {
		$this->assertSame( 'admin', RoleMapper::role_slug( [ 'slug' => 'admin' ] ) );
		$this->assertSame( 'member', RoleMapper::role_slug( 'member' ) );
		$this->assertSame( '', RoleMapper::role_slug( [] ) );
		$this->assertSame( '', RoleMapper::role_slug( null ) );
	}
}
