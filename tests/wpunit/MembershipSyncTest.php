<?php
/**
 * Exercises manual membership refresh against mocked WorkOS responses and real
 * WordPress users, membership records, caches, and role-update hooks.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\App;
use WorkOS\Config;
use WorkOS\Options\Production;
use WorkOS\Organization\Manager;
use WorkOS\Sync\MembershipSync;
use WorkOS\Sync\RoleMapper;

/**
 * Manual refresh repairs stale roles without treating uncertainty as a downgrade.
 */
final class MembershipSyncTest extends WPTestCase {

	/** @var array Captured outbound requests. */
	private array $requests = [];

	/** @var array|\WP_Error Mock membership response body or transport error. */
	private $response;

	/** @var array Mock organization response. */
	private array $organization = [];

	/** @var int Fixture user ID. */
	private int $user_id;

	/** @var string Previous active environment. */
	private string $previous_environment;

	/** @var mixed Previous production options. */
	private $previous_options;

	/**
	 * Configure one organization and retain the outbound role hook during refresh.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->previous_environment = Config::get_active_environment();
		$this->previous_options     = get_option( 'workos_production' );
		Config::set_active_environment( 'production' );
		update_option( 'workos_production', [
			'api_key'         => 'sk_test_fake',
			'client_id'       => 'client_fake',
			'environment_id'  => 'environment_test',
			'organization_id' => 'org_refresh',
			'role_map'        => [ 'org-admin' => 'administrator', 'member' => 'subscriber' ],
		] );
		App::container()->get( Production::class )->reset();
		remove_all_actions( 'user_register' );
		remove_all_actions( 'profile_update' );
		remove_all_actions( 'set_user_role' );
		$this->user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		update_user_meta( $this->user_id, '_workos_user_id', 'user_refresh' );
		add_action( 'set_user_role', [ App::container()->get( RoleMapper::class ), 'push_role_to_workos' ], 10, 3 );
		$this->requests     = [];
		$this->response     = [ 'data' => [ $this->membership() ], 'list_metadata' => [ 'after' => null ] ];
		$this->organization = [ 'id' => 'org_refresh', 'name' => 'Refresh organization' ];
		add_filter( 'pre_http_request', [ $this, 'http' ], 10, 3 );
	}

	/**
	 * Restore configuration and remove fixture caches and organization records.
	 */
	public function tearDown(): void {
		global $wpdb;
		remove_filter( 'pre_http_request', [ $this, 'http' ], 10 );
		$org = Manager::get_by_workos_id( 'org_refresh' );
		if ( $org ) {
			$wpdb->delete( $wpdb->prefix . 'workos_org_memberships', [ 'org_id' => $org->id ] );
			$wpdb->delete( $wpdb->prefix . 'workos_org_sites', [ 'org_id' => $org->id ] );
			$wpdb->delete( $wpdb->prefix . 'workos_organizations', [ 'id' => $org->id ] );
			wp_cache_delete( 'org_' . $org->id, 'workos' );
		}
		wp_cache_delete( 'org_workos_org_refresh', 'workos' );
		wp_cache_delete( 'org_site_' . get_current_blog_id(), 'workos' );
		wp_cache_delete( 'user_orgs_' . $this->user_id, 'workos' );
		wp_cache_delete( 'membership_' . $this->user_id . '_org_refresh', 'workos' );
		delete_transient( 'workos_profile_memberships_' . md5( 'user_refresh' ) );
		if ( false === $this->previous_options ) {
			delete_option( 'workos_production' );
		} else {
			update_option( 'workos_production', $this->previous_options );
		}
		Config::set_active_environment( $this->previous_environment );
		App::container()->get( Production::class )->reset();
		parent::tearDown();
	}

	/**
	 * Return endpoint-specific responses and record every outbound write attempt.
	 *
	 * @param mixed  $pre  Existing HTTP interception.
	 * @param array  $args Request arguments.
	 * @param string $url  Request URL.
	 * @return array|\WP_Error|mixed
	 */
	public function http( $pre, array $args, string $url ) {
		if ( false === strpos( $url, 'api.workos.com' ) ) {
			return $pre;
		}
		$this->requests[] = [ 'url' => $url, 'method' => $args['method'] ?? 'GET' ];
		if ( false !== strpos( $url, '/user_management/organization_memberships' ) ) {
			$body = $this->response;
		} elseif ( false !== strpos( $url, '/organizations/org_refresh' ) ) {
			$body = $this->organization;
		} else {
			return new \WP_Error( 'unexpected_request', $url );
		}
		return is_wp_error( $body ) ? $body : [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'body'     => wp_json_encode( $body ),
		];
	}

	/**
	 * Current active membership supplied by WorkOS.
	 *
	 * @return array
	 */
	private function membership(): array {
		return [
			'id'              => 'om_refresh',
			'user_id'         => 'user_refresh',
			'organization_id' => 'org_refresh',
			'status'          => 'active',
			'role'            => [ 'slug' => 'org-admin' ],
			'updated_at'      => '2026-10-27T15:47:03.946Z',
		];
	}

	/**
	 * Store the stale membership that originally produced the mismatch badge.
	 */
	private function store_stale_membership(): void {
		$id = Manager::upsert_organization( $this->organization );
		Manager::add_membership( $id, $this->user_id, [
			'workos_membership_id' => 'om_refresh',
			'workos_role'          => 'member',
			'wp_role'              => 'subscriber',
		] );
	}

	/**
	 * A fresh response repairs the database and caches without changing WorkOS.
	 */
	public function test_refresh_repairs_stale_membership_and_caches(): void {
		$this->store_stale_membership();
		$this->assertSame( 'member', Manager::get_user_orgs( $this->user_id )[0]->workos_role );
		set_transient( 'workos_profile_memberships_' . md5( 'user_refresh' ), [ 'stale' ], 300 );

		$this->assertTrue( App::container()->get( MembershipSync::class )->refresh( $this->user_id ) );

		$rows = Manager::get_user_orgs( $this->user_id );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'org-admin', $rows[0]->workos_role );
		$this->assertSame( 'administrator', $rows[0]->wp_role );
		$this->assertSame( [ 'administrator' ], get_userdata( $this->user_id )->roles );
		$this->assertFalse( get_transient( 'workos_profile_memberships_' . md5( 'user_refresh' ) ) );
		$this->assertSame( [ 'om_refresh' => $this->membership()['updated_at'] ], get_user_meta( $this->user_id, RoleMapper::META_MEMBERSHIP_UPDATED_AT, true ) );
		$this->assertSame( [ 'GET' ], array_column( $this->requests, 'method' ) );
		parse_str( wp_parse_url( $this->requests[0]['url'], PHP_URL_QUERY ), $query );
		$this->assertSame( 'user_refresh', $query['user_id'] );
		$this->assertSame( 'org_refresh', $query['organization_id'] );
	}

	/**
	 * A real downgrade changes WordPress without writing it back to WorkOS.
	 */
	public function test_refresh_applies_current_member_role_without_outbound_update(): void {
		$this->store_stale_membership();
		$this->response['data'][0]['role'] = [ 'slug' => 'member' ];

		$this->assertTrue( App::container()->get( MembershipSync::class )->refresh( $this->user_id ) );

		$this->assertSame( [ 'subscriber' ], get_userdata( $this->user_id )->roles );
		$this->assertSame( [ 'GET' ], array_column( $this->requests, 'method' ) );
	}

	/**
	 * Failed capability persistence must not be acknowledged as an applied update.
	 */
	public function test_failed_role_write_reports_error_without_advancing_timestamp(): void {
		global $wpdb;
		$this->store_stale_membership();
		$this->response['data'][0]['role'] = [ 'slug' => 'member' ];
		$capabilities_key = $wpdb->get_blog_prefix() . 'capabilities';
		$reject_write = function ( $check, $user_id, $meta_key ) use ( $capabilities_key ) {
			return $this->user_id === (int) $user_id && $capabilities_key === $meta_key ? false : $check;
		};
		add_filter( 'update_user_metadata', $reject_write, 10, 3 );

		try {
			$result = App::container()->get( MembershipSync::class )->refresh( $this->user_id );
		} finally {
			remove_filter( 'update_user_metadata', $reject_write, 10 );
		}

		$this->assertWPError( $result );
		$this->assertSame( [ 'administrator' ], get_userdata( $this->user_id )->roles );
		$this->assertSame( '', get_user_meta( $this->user_id, RoleMapper::META_MEMBERSHIP_UPDATED_AT, true ) );
		$this->assertSame( [ 'GET' ], array_column( $this->requests, 'method' ) );
	}

	/**
	 * A refresh is incomplete if its protection against older events is not saved.
	 */
	public function test_failed_timestamp_write_reports_error_and_can_be_retried(): void {
		$this->store_stale_membership();
		$this->response['data'][0]['role'] = [ 'slug' => 'member' ];

		$reject_write = function ( $check, $user_id, $meta_key ) {
			return $this->user_id === (int) $user_id && RoleMapper::META_MEMBERSHIP_UPDATED_AT === $meta_key ? false : $check;
		};
		add_filter( 'update_user_metadata', $reject_write, 10, 3 );

		try {
			$result = App::container()->get( MembershipSync::class )->refresh( $this->user_id );
		} finally {
			remove_filter( 'update_user_metadata', $reject_write, 10 );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'workos_membership_timestamp_save_failed', $result->get_error_code() );
		$this->assertSame( [ 'subscriber' ], get_userdata( $this->user_id )->roles );
		$this->assertSame( '', get_user_meta( $this->user_id, RoleMapper::META_MEMBERSHIP_UPDATED_AT, true ) );

		$this->assertTrue( App::container()->get( MembershipSync::class )->refresh( $this->user_id ) );
		$this->assertSame(
			[ 'om_refresh' => $this->membership()['updated_at'] ],
			get_user_meta( $this->user_id, RoleMapper::META_MEMBERSHIP_UPDATED_AT, true )
		);
	}

	/**
	 * An existing mapped role must not conceal an additional privileged role.
	 */
	public function test_additional_roles_are_reported_without_silently_discarding_them(): void {
		$this->store_stale_membership();
		get_userdata( $this->user_id )->add_role( 'subscriber' );
		$this->response['data'][0]['role'] = [ 'slug' => 'member' ];

		$result = App::container()->get( MembershipSync::class )->refresh( $this->user_id );

		$this->assertWPError( $result );
		$this->assertSame( [ 'administrator', 'subscriber' ], get_userdata( $this->user_id )->roles );
		$this->assertSame( '', get_user_meta( $this->user_id, RoleMapper::META_MEMBERSHIP_UPDATED_AT, true ) );
		$this->assertSame( [ 'GET' ], array_column( $this->requests, 'method' ) );
	}

	/**
	 * Missing local organization and membership can be recovered, without duplicates.
	 */
	public function test_refresh_creates_missing_local_records_and_can_repeat(): void {
		$this->assertNull( Manager::get_by_workos_id( 'org_refresh' ) );
		$this->assertSame( '', Manager::get_membership_id_for_user( $this->user_id, 'org_refresh' ) );

		$this->assertTrue( App::container()->get( MembershipSync::class )->refresh( $this->user_id ) );
		$this->assertTrue( App::container()->get( MembershipSync::class )->refresh( $this->user_id ) );

		$rows = Manager::get_user_orgs( $this->user_id );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'org-admin', $rows[0]->workos_role );
		$this->assertSame( 'om_refresh', Manager::get_membership_id_for_user( $this->user_id, 'org_refresh' ) );
		$this->assertSame( [ 'GET', 'GET', 'GET' ], array_column( $this->requests, 'method' ) );
	}

	/**
	 * Equal timestamps must still repair locally drifted data.
	 */
	public function test_refresh_repairs_drift_at_an_already_applied_timestamp(): void {
		$this->store_stale_membership();
		update_user_meta( $this->user_id, RoleMapper::META_MEMBERSHIP_UPDATED_AT, [ 'om_refresh' => $this->membership()['updated_at'] ] );

		$this->assertTrue( App::container()->get( MembershipSync::class )->refresh( $this->user_id ) );

		$this->assertSame( 'org-admin', Manager::get_user_orgs( $this->user_id )[0]->workos_role );
	}

	/**
	 * An older API snapshot must not override a newer applied membership event.
	 */
	public function test_refresh_rejects_older_snapshot(): void {
		$this->store_stale_membership();
		$newer = [ 'om_refresh' => '2026-10-28T00:00:00.000Z' ];
		update_user_meta( $this->user_id, RoleMapper::META_MEMBERSHIP_UPDATED_AT, $newer );

		$this->assertWPError( App::container()->get( MembershipSync::class )->refresh( $this->user_id ) );

		$this->assertSame( 'member', Manager::get_user_orgs( $this->user_id )[0]->workos_role );
		$this->assertSame( $newer, get_user_meta( $this->user_id, RoleMapper::META_MEMBERSHIP_UPDATED_AT, true ) );
	}

	/**
	 * Re-read persisted timestamps after HTTP, even when this request cached older meta.
	 */
	public function test_refresh_rejects_newer_watermark_written_during_request(): void {
		global $wpdb;
		$this->store_stale_membership();
		$this->response['data'][0]['role'] = [ 'slug' => 'member' ];
		$older = [ 'om_refresh' => '2026-10-26T00:00:00.000Z' ];
		$newer = [ 'om_refresh' => '2026-10-28T00:00:00.000Z' ];
		update_user_meta( $this->user_id, RoleMapper::META_MEMBERSHIP_UPDATED_AT, $older );
		$this->assertSame( $older, get_user_meta( $this->user_id, RoleMapper::META_MEMBERSHIP_UPDATED_AT, true ) );
		$write = function ( $pre, array $args, string $url ) use ( $wpdb, $newer ) {
			if ( false !== strpos( $url, '/user_management/organization_memberships' ) ) {
				$wpdb->update( $wpdb->usermeta, [ 'meta_value' => maybe_serialize( $newer ) ], [
					'user_id' => $this->user_id,
					'meta_key' => RoleMapper::META_MEMBERSHIP_UPDATED_AT,
				] );
			}
			return $pre;
		};
		add_filter( 'pre_http_request', $write, 9, 3 );
		try {
			$result = App::container()->get( MembershipSync::class )->refresh( $this->user_id );
		} finally {
			remove_filter( 'pre_http_request', $write, 9 );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'workos_stale_membership', $result->get_error_code() );
		$this->assertSame( [ 'administrator' ], get_userdata( $this->user_id )->roles );
		$this->assertSame( 'member', Manager::get_user_orgs( $this->user_id )[0]->workos_role );
		$this->assertSame( $newer, get_user_meta( $this->user_id, RoleMapper::META_MEMBERSHIP_UPDATED_AT, true ) );
	}

	/**
	 * A response for a previous identity must not change a user relinked during HTTP.
	 */
	public function test_refresh_rejects_identity_changed_during_request(): void {
		global $wpdb;
		$this->store_stale_membership();
		$this->response['data'][0]['role'] = [ 'slug' => 'member' ];
		$this->assertSame( 'user_refresh', get_user_meta( $this->user_id, '_workos_user_id', true ) );
		$write = function ( $pre, array $args, string $url ) use ( $wpdb ) {
			if ( false !== strpos( $url, '/user_management/organization_memberships' ) ) {
				$wpdb->update( $wpdb->usermeta, [ 'meta_value' => 'user_relinked' ], [
					'user_id' => $this->user_id,
					'meta_key' => '_workos_user_id',
				] );
			}
			return $pre;
		};
		add_filter( 'pre_http_request', $write, 9, 3 );
		try {
			$result = App::container()->get( MembershipSync::class )->refresh( $this->user_id );
		} finally {
			remove_filter( 'pre_http_request', $write, 9 );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'workos_identity_changed', $result->get_error_code() );
		$this->assertSame( [ 'administrator' ], get_userdata( $this->user_id )->roles );
		$this->assertSame( 'member', Manager::get_user_orgs( $this->user_id )[0]->workos_role );
		$this->assertSame( 'user_relinked', get_user_meta( $this->user_id, '_workos_user_id', true ) );
		$this->assertSame( '', get_user_meta( $this->user_id, RoleMapper::META_MEMBERSHIP_UPDATED_AT, true ) );
	}

	/**
	 * The refresh timestamp prevents later delivery of an older role event undoing it.
	 */
	public function test_older_webhook_cannot_undo_refresh(): void {
		$this->store_stale_membership();
		$this->assertTrue( App::container()->get( MembershipSync::class )->refresh( $this->user_id ) );
		$older               = $this->membership();
		$older['role']       = [ 'slug' => 'member' ];
		$older['updated_at'] = '2026-10-26T00:00:00.000Z';

		App::container()->get( RoleMapper::class )->handle_membership_updated( [ 'data' => $older ] );

		$this->assertSame( [ 'administrator' ], get_userdata( $this->user_id )->roles );
		$this->assertSame( 'org-admin', Manager::get_user_orgs( $this->user_id )[0]->workos_role );
	}

	/**
	 * Uncertain membership responses report failure without changing existing access.
	 *
	 * @dataProvider invalid_responses
	 * @param string $case Response variant.
	 */
	public function test_invalid_response_does_not_change_access( string $case ): void {
		$this->store_stale_membership();
		switch ( $case ) {
			case 'transport':
				$this->response = new \WP_Error( 'http_request_failed', 'Timed out' );
				break;
			case 'malformed':
				$this->response = [ 'message' => 'not a membership list' ];
				break;
			case 'missing':
				$this->response['data'] = [];
				break;
			case 'ambiguous':
				$this->response['data'][] = $this->membership();
				break;
			case 'inactive':
				$this->response['data'][0]['status'] = 'inactive';
				break;
			case 'wrong-user':
				$this->response['data'][0]['user_id'] = 'user_other';
				break;
			case 'wrong-organization':
				$this->response['data'][0]['organization_id'] = 'org_other';
				break;
			case 'unknown-role':
				$this->response['data'][0]['role'] = [ 'slug' => 'unmapped' ];
				break;
			case 'missing-role':
				unset( $this->response['data'][0]['role'] );
				break;
			case 'invalid-timestamp':
				$this->response['data'][0]['updated_at'] = 'not-a-date';
				break;
			case 'more-pages':
				$this->response['list_metadata']['after'] = 'om_another_page';
				break;
		}

		$this->assertWPError( App::container()->get( MembershipSync::class )->refresh( $this->user_id ) );

		$this->assertSame( [ 'administrator' ], get_userdata( $this->user_id )->roles );
		$this->assertSame( 'member', Manager::get_user_orgs( $this->user_id )[0]->workos_role );
		$this->assertSame( '', get_user_meta( $this->user_id, RoleMapper::META_MEMBERSHIP_UPDATED_AT, true ) );
		$this->assertSame( [ 'GET' ], array_column( $this->requests, 'method' ) );
	}

	/**
	 * Responses that cannot establish a current active mapped role.
	 *
	 * @return array
	 */
	public static function invalid_responses(): array {
		$cases = [ 'transport', 'malformed', 'missing', 'ambiguous', 'inactive', 'wrong-user', 'wrong-organization', 'unknown-role', 'missing-role', 'invalid-timestamp', 'more-pages' ];
		return array_combine(
			$cases,
			array_map(
				static function ( $case ) {
					return [ $case ];
				},
				$cases
			)
		);
	}
}
