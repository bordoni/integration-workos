<?php
/**
 * Tests for the WorkOS user metadata the plugin writes: the `puid:` key,
 * the `workos_user_metadata` filter, the change hash and WorkOS's limits.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Config;
use WorkOS\Sync\UserSync;

/**
 * User metadata sync tests.
 */
class UserSyncMetadataTest extends WPTestCase {

	/**
	 * WorkOS user ID used by every test.
	 */
	private const WORKOS_ID = 'user_01META';

	/**
	 * Captured WorkOS API requests (method, url, body).
	 *
	 * @var array
	 */
	private array $requests = [];

	/**
	 * Metadata the fake WorkOS user currently holds.
	 *
	 * @var array
	 */
	private array $remote = [];

	/**
	 * When true, PUT requests fail with a 500.
	 *
	 * @var bool
	 */
	private bool $fail_put = false;

	/**
	 * Keys the test filter adds.
	 *
	 * @var array
	 */
	private array $extra = [];

	/**
	 * UserSync instance under test.
	 *
	 * @var UserSync
	 */
	private UserSync $sync;

	/**
	 * Set up each test.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->requests = [];
		$this->remote   = [];
		$this->fail_put = false;
		$this->extra    = [];

		Config::set_active_environment( 'production' );
		update_option(
			'workos_production',
			[
				'api_key'        => 'sk_test_fake',
				'client_id'      => 'client_fake',
				'environment_id' => 'environment_test',
			]
		);
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );

		$this->sync = new UserSync();

		// Factory-created users must not be pushed to WorkOS; tests call the sync directly.
		remove_all_actions( 'user_register' );
	}

	/**
	 * Tear down each test.
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10 );
		delete_option( 'workos_production' );
		Config::set_active_environment( 'staging' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		parent::tearDown();
	}

	/**
	 * Fake WorkOS: GET returns the user with `$this->remote` metadata; PUT
	 * applies the metadata it was sent (partial update, like WorkOS).
	 *
	 * @param false|array $response Pre-filtered response.
	 * @param array       $args     Request arguments.
	 * @param string      $url      Request URL.
	 *
	 * @return false|array
	 */
	public function intercept_http( $response, array $args, string $url ) {
		if ( false === strpos( $url, 'api.workos.com' ) ) {
			return $response;
		}

		$method = strtoupper( $args['method'] ?? 'GET' );
		$body   = json_decode( $args['body'] ?? '', true );

		$this->requests[] = [
			'method' => $method,
			'url'    => $url,
			'body'   => is_array( $body ) ? $body : [],
		];

		if ( 'PUT' === $method ) {
			if ( $this->fail_put ) {
				return [
					'response' => [
						'code'    => 500,
						'message' => 'Internal Server Error',
					],
					'body'     => wp_json_encode( [ 'message' => 'boom' ] ),
				];
			}

			foreach ( (array) ( $body['metadata'] ?? [] ) as $key => $value ) {
				if ( null === $value ) {
					unset( $this->remote[ $key ] );
				} else {
					$this->remote[ $key ] = $value;
				}
			}
		}

		return [
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'body'     => wp_json_encode(
				[
					'id'       => self::WORKOS_ID,
					'email'    => 'meta@example.com',
					'metadata' => (object) $this->remote,
				]
			),
		];
	}

	/**
	 * Test filter callback: add `$this->extra`.
	 *
	 * @param array $metadata Keys the plugin writes.
	 *
	 * @return array
	 */
	public function add_extra( array $metadata ): array {
		return array_merge( $metadata, $this->extra );
	}

	/**
	 * Create a WP user linked to the fake WorkOS user.
	 *
	 * @return int
	 */
	private function linked_user(): int {
		$user_id = self::factory()->user->create( [ 'user_email' => 'meta@example.com' ] );
		update_user_meta( $user_id, '_workos_user_id', self::WORKOS_ID );

		return $user_id;
	}

	/**
	 * The plugin's own key for this site.
	 *
	 * @return string
	 */
	private function puid_key(): string {
		return 'puid:' . Config::get_site_key();
	}

	/**
	 * Requests made with one HTTP method.
	 *
	 * @param string $method HTTP method.
	 *
	 * @return array
	 */
	private function requests_by( string $method ): array {
		return array_values(
			array_filter(
				$this->requests,
				static function ( $request ) use ( $method ) {
					return $method === $request['method'];
				}
			)
		);
	}

	/**
	 * Last metadata payload sent in a PUT.
	 *
	 * @return array
	 */
	private function last_put_metadata(): array {
		$puts = $this->requests_by( 'PUT' );
		$this->assertNotEmpty( $puts, 'Expected a metadata write.' );

		return $puts[ count( $puts ) - 1 ]['body']['metadata'] ?? [];
	}

	// -------------------------------------------------------------------------
	// Without the filter: unchanged behaviour
	// -------------------------------------------------------------------------

	/**
	 * Without the filter only the puid key is written.
	 */
	public function test_without_filter_writes_only_puid(): void {
		$user_id = $this->linked_user();

		UserSync::update_platform_metadata( self::WORKOS_ID, $user_id );

		$this->assertCount( 1, $this->requests_by( 'GET' ) );
		$this->assertSame( [ $this->puid_key() => (string) $user_id ], $this->last_put_metadata() );
		$this->assertSame( '', get_user_meta( $user_id, UserSync::META_METADATA_HASH, true ), 'No hash is stored when the site adds no keys.' );
	}

	/**
	 * Without the filter every call reads the user, as before, and skips the
	 * write when the puid key is already right.
	 */
	public function test_without_filter_reads_every_time_and_skips_matching_write(): void {
		$user_id      = $this->linked_user();
		$this->remote = [ $this->puid_key() => (string) $user_id ];

		UserSync::update_platform_metadata( self::WORKOS_ID, $user_id );
		UserSync::update_platform_metadata( self::WORKOS_ID, $user_id );

		$this->assertCount( 2, $this->requests_by( 'GET' ) );
		$this->assertCount( 0, $this->requests_by( 'PUT' ) );
	}

	/**
	 * Saving a profile does nothing with metadata when the site adds no keys.
	 */
	public function test_profile_update_without_filter_makes_no_request(): void {
		$user_id = $this->linked_user();

		$this->sync->sync_metadata_on_profile_update( $user_id );

		$this->assertSame( [], $this->requests );
	}

	// -------------------------------------------------------------------------
	// The filter
	// -------------------------------------------------------------------------

	/**
	 * The filter gets the plugin's keys, the WP user ID and the WorkOS ID.
	 */
	public function test_filter_receives_plugin_keys_and_ids(): void {
		$user_id = $this->linked_user();
		$seen    = null;

		add_filter(
			'workos_user_metadata',
			static function ( $metadata, $wp_user_id, $workos_id ) use ( &$seen ) {
				$seen = [ $metadata, $wp_user_id, $workos_id ];
				return $metadata;
			},
			10,
			3
		);

		UserSync::update_platform_metadata( self::WORKOS_ID, $user_id );

		$this->assertSame( [ [ $this->puid_key() => (string) $user_id ], $user_id, self::WORKOS_ID ], $seen );
	}

	/**
	 * Filtered keys are merged with what WorkOS already holds.
	 */
	public function test_filter_keys_merge_with_existing_metadata(): void {
		$user_id      = $this->linked_user();
		$this->remote = [
			'puid:other.example' => '42',
			'timezone'           => 'America/New_York',
		];
		$this->extra  = [
			'customer_id' => 'C-1001',
			'legacy_id'   => 77,
		];
		add_filter( 'workos_user_metadata', [ $this, 'add_extra' ] );

		UserSync::update_platform_metadata( self::WORKOS_ID, $user_id );

		$this->assertSame(
			[
				'puid:other.example' => '42',
				'timezone'           => 'America/New_York',
				$this->puid_key()    => (string) $user_id,
				'customer_id'        => 'C-1001',
				'legacy_id'          => '77',
			],
			$this->last_put_metadata()
		);
	}

	/**
	 * A filter can't remove or change the plugin's puid key.
	 */
	public function test_puid_key_cannot_be_removed_or_changed(): void {
		$user_id = $this->linked_user();

		add_filter(
			'workos_user_metadata',
			function () {
				return [ 'customer_id' => 'C-1' ];
			}
		);

		UserSync::update_platform_metadata( self::WORKOS_ID, $user_id );

		$this->assertSame( (string) $user_id, $this->last_put_metadata()[ $this->puid_key() ] );

		$this->requests = [];
		remove_all_filters( 'workos_user_metadata' );
		add_filter(
			'workos_user_metadata',
			function ( $metadata ) {
				$metadata[ $this->puid_key() ] = '999';
				$metadata['customer_id']       = 'C-2';
				return $metadata;
			}
		);

		UserSync::update_platform_metadata( self::WORKOS_ID, $user_id );

		$this->assertSame( (string) $user_id, $this->last_put_metadata()[ $this->puid_key() ] );
		$this->assertSame( (string) $user_id, $this->remote[ $this->puid_key() ] );
	}

	/**
	 * Other sites' `puid:` keys are off limits.
	 */
	public function test_other_puid_keys_are_rejected(): void {
		$user_id      = $this->linked_user();
		$this->remote = [ 'puid:other.example' => '42' ];
		$this->extra  = [
			'puid:other.example' => '1',
			'customer_id'        => 'C-1',
		];
		add_filter( 'workos_user_metadata', [ $this, 'add_extra' ] );

		UserSync::update_platform_metadata( self::WORKOS_ID, $user_id );

		$this->assertSame( '42', $this->last_put_metadata()['puid:other.example'] );
		$this->assertSame( 'C-1', $this->remote['customer_id'] );
	}

	/**
	 * A filter that returns something other than an array is ignored.
	 */
	public function test_non_array_filter_result_is_ignored(): void {
		$user_id = $this->linked_user();
		add_filter( 'workos_user_metadata', '__return_false' );

		UserSync::update_platform_metadata( self::WORKOS_ID, $user_id );

		$this->assertSame( [ $this->puid_key() => (string) $user_id ], $this->last_put_metadata() );
	}

	// -------------------------------------------------------------------------
	// Change hash
	// -------------------------------------------------------------------------

	/**
	 * Once the site's keys are written, the same keys cost no API call.
	 */
	public function test_unchanged_metadata_makes_no_request(): void {
		$user_id     = $this->linked_user();
		$this->extra = [ 'customer_id' => 'C-1001' ];
		add_filter( 'workos_user_metadata', [ $this, 'add_extra' ] );

		UserSync::update_platform_metadata( self::WORKOS_ID, $user_id );
		$this->assertCount( 1, $this->requests_by( 'PUT' ) );
		$this->assertNotSame( '', get_user_meta( $user_id, UserSync::META_METADATA_HASH, true ) );

		$this->requests = [];
		UserSync::update_platform_metadata( self::WORKOS_ID, $user_id );
		$this->sync->sync_metadata_on_login( $user_id, [] );

		$this->assertSame( [], $this->requests );
	}

	/**
	 * A changed value is written on the next sync.
	 */
	public function test_changed_value_is_written(): void {
		$user_id     = $this->linked_user();
		$this->extra = [ 'customer_id' => 'C-1001' ];
		add_filter( 'workos_user_metadata', [ $this, 'add_extra' ] );

		UserSync::update_platform_metadata( self::WORKOS_ID, $user_id );

		$this->requests = [];
		$this->extra    = [ 'customer_id' => 'C-2002' ];
		UserSync::update_platform_metadata( self::WORKOS_ID, $user_id );

		$this->assertCount( 1, $this->requests_by( 'GET' ) );
		$this->assertSame( 'C-2002', $this->last_put_metadata()['customer_id'] );
	}

	/**
	 * When WorkOS already holds the keys, nothing is written but the hash is
	 * stored, so the next sync is free.
	 */
	public function test_matching_remote_metadata_stores_hash_without_write(): void {
		$user_id      = $this->linked_user();
		$this->remote = [
			$this->puid_key() => (string) $user_id,
			'customer_id'     => 'C-1001',
		];
		$this->extra  = [ 'customer_id' => 'C-1001' ];
		add_filter( 'workos_user_metadata', [ $this, 'add_extra' ] );

		UserSync::update_platform_metadata( self::WORKOS_ID, $user_id );

		$this->assertCount( 1, $this->requests_by( 'GET' ) );
		$this->assertCount( 0, $this->requests_by( 'PUT' ) );

		$this->requests = [];
		UserSync::update_platform_metadata( self::WORKOS_ID, $user_id );
		$this->assertSame( [], $this->requests );
	}

	/**
	 * A failed write stores no hash, so the next sync tries again.
	 */
	public function test_failed_write_is_retried(): void {
		$user_id        = $this->linked_user();
		$this->extra    = [ 'customer_id' => 'C-1001' ];
		$this->fail_put = true;
		add_filter( 'workos_user_metadata', [ $this, 'add_extra' ] );

		UserSync::update_platform_metadata( self::WORKOS_ID, $user_id );
		$this->assertSame( '', get_user_meta( $user_id, UserSync::META_METADATA_HASH, true ) );

		$this->requests = [];
		$this->fail_put = false;
		UserSync::update_platform_metadata( self::WORKOS_ID, $user_id );

		$this->assertCount( 1, $this->requests_by( 'PUT' ) );
		$this->assertSame( 'C-1001', $this->remote['customer_id'] );
	}

	/**
	 * Linking the account to a different WorkOS user writes the keys there.
	 */
	public function test_new_workos_id_is_written_even_with_same_keys(): void {
		$user_id     = $this->linked_user();
		$this->extra = [ 'customer_id' => 'C-1001' ];
		add_filter( 'workos_user_metadata', [ $this, 'add_extra' ] );

		UserSync::update_platform_metadata( self::WORKOS_ID, $user_id );

		$this->requests = [];
		$this->remote   = [];
		UserSync::update_platform_metadata( 'user_01OTHER', $user_id );

		$this->assertCount( 1, $this->requests_by( 'PUT' ) );
		$this->assertStringContainsString( 'user_01OTHER', $this->requests_by( 'PUT' )[0]['url'] );
	}

	/**
	 * Unlinking clears the stored hash.
	 */
	public function test_unlink_clears_hash(): void {
		$user_id     = $this->linked_user();
		$this->extra = [ 'customer_id' => 'C-1001' ];
		add_filter( 'workos_user_metadata', [ $this, 'add_extra' ] );

		UserSync::update_platform_metadata( self::WORKOS_ID, $user_id );
		UserSync::unlink_user( $user_id );

		$this->assertSame( '', get_user_meta( $user_id, UserSync::META_METADATA_HASH, true ) );
	}

	// -------------------------------------------------------------------------
	// WorkOS limits
	// -------------------------------------------------------------------------

	/**
	 * Entries over WorkOS's key or value limits are dropped; the rest are written.
	 */
	public function test_over_limit_entries_are_rejected_and_rest_written(): void {
		$user_id     = $this->linked_user();
		$this->extra = [
			'customer_id'          => 'C-1001',
			str_repeat( 'k', 41 )  => 'too long a key',
			'long_value'           => str_repeat( 'v', 601 ),
			'max_value'            => str_repeat( 'v', 600 ),
			'non_ascii_value'      => 'café',
			'kéy'                  => 'non-ascii key',
			'array_value'          => [ 'a' ],
			'bool_value'           => true,
			'null_value'           => null,
		];
		add_filter( 'workos_user_metadata', [ $this, 'add_extra' ] );

		UserSync::update_platform_metadata( self::WORKOS_ID, $user_id );

		$this->assertSame(
			[
				$this->puid_key() => (string) $user_id,
				'customer_id'     => 'C-1001',
				'max_value'       => str_repeat( 'v', 600 ),
			],
			$this->last_put_metadata()
		);
	}

	/**
	 * WorkOS allows 10 keys per user, counting keys other apps wrote. New
	 * keys past that are dropped; keys already on the user can still change.
	 */
	public function test_key_count_limit_counts_existing_keys(): void {
		$user_id = $this->linked_user();

		for ( $i = 1; $i <= 8; $i++ ) {
			$this->remote[ 'other_' . $i ] = 'x';
		}

		$this->extra = [
			'other_1'     => 'changed',
			'customer_id' => 'C-1001',
			'legacy_id'   => 'L-1',
		];
		add_filter( 'workos_user_metadata', [ $this, 'add_extra' ] );

		UserSync::update_platform_metadata( self::WORKOS_ID, $user_id );

		$sent = $this->last_put_metadata();
		$this->assertCount( UserSync::METADATA_MAX_KEYS, $sent );
		$this->assertSame( (string) $user_id, $sent[ $this->puid_key() ] );
		$this->assertSame( 'changed', $sent['other_1'] );
		$this->assertSame( 'C-1001', $sent['customer_id'] );
		$this->assertArrayNotHasKey( 'legacy_id', $sent );
	}

	// -------------------------------------------------------------------------
	// Profile save + public helper
	// -------------------------------------------------------------------------

	/**
	 * The profile listener is hooked on `profile_update`.
	 */
	public function test_profile_update_listener_is_registered(): void {
		$this->assertNotFalse( has_action( 'profile_update', [ $this->sync, 'sync_metadata_on_profile_update' ] ) );
	}

	/**
	 * Saving a profile writes the site's keys when they changed.
	 */
	public function test_profile_update_with_filter_writes_keys(): void {
		$user_id     = $this->linked_user();
		$this->extra = [ 'customer_id' => 'C-1001' ];
		add_filter( 'workos_user_metadata', [ $this, 'add_extra' ] );

		$this->sync->sync_metadata_on_profile_update( $user_id );

		$this->assertSame( 'C-1001', $this->last_put_metadata()['customer_id'] );

		$this->requests = [];
		$this->sync->sync_metadata_on_profile_update( $user_id );
		$this->assertSame( [], $this->requests );
	}

	/**
	 * The profile listener stands down while WorkOS data is being applied.
	 */
	public function test_profile_update_skips_while_syncing(): void {
		$user_id     = $this->linked_user();
		$this->extra = [ 'customer_id' => 'C-1001' ];
		add_filter( 'workos_user_metadata', [ $this, 'add_extra' ] );

		$ref = new \ReflectionProperty( UserSync::class, 'syncing' );
		$ref->setAccessible( true );
		$ref->setValue( null, true );

		$this->sync->sync_metadata_on_profile_update( $user_id );

		$ref->setValue( null, false );

		$this->assertSame( [], $this->requests );
	}

	/**
	 * The helper errors for a user with no WorkOS link.
	 */
	public function test_helper_errors_for_unlinked_user(): void {
		$user_id = self::factory()->user->create();

		$result = workos_sync_user_metadata( $user_id );

		$this->assertWPError( $result );
		$this->assertSame( 'workos_not_linked', $result->get_error_code() );
		$this->assertSame( [], $this->requests );
	}

	/**
	 * The helper errors when WorkOS isn't configured.
	 */
	public function test_helper_errors_when_not_configured(): void {
		$user_id = $this->linked_user();
		update_option( 'workos_production', [ 'client_id' => 'client_fake' ] );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$result = workos_sync_user_metadata( $user_id );

		$this->assertWPError( $result );
		$this->assertSame( 'workos_not_configured', $result->get_error_code() );
	}

	/**
	 * The helper returns true when it wrote, false when WorkOS already matched.
	 */
	public function test_helper_returns_whether_it_wrote(): void {
		$user_id     = $this->linked_user();
		$this->extra = [ 'customer_id' => 'C-1001' ];
		add_filter( 'workos_user_metadata', [ $this, 'add_extra' ] );

		$this->assertTrue( workos_sync_user_metadata( $user_id ) );
		$this->assertSame( 'C-1001', $this->remote['customer_id'] );

		$this->requests = [];
		$this->assertFalse( workos_sync_user_metadata( $user_id ) );
	}

	/**
	 * The helper always reads WorkOS, so it repairs keys removed elsewhere
	 * even when the stored hash says they were sent.
	 */
	public function test_helper_ignores_hash_and_repairs_remote(): void {
		$user_id     = $this->linked_user();
		$this->extra = [ 'customer_id' => 'C-1001' ];
		add_filter( 'workos_user_metadata', [ $this, 'add_extra' ] );

		UserSync::update_platform_metadata( self::WORKOS_ID, $user_id );
		unset( $this->remote['customer_id'] );

		$this->requests = [];
		$this->assertTrue( workos_sync_user_metadata( $user_id ) );

		$this->assertCount( 1, $this->requests_by( 'GET' ) );
		$this->assertSame( 'C-1001', $this->remote['customer_id'] );
	}

	/**
	 * The helper returns the API error when the write fails.
	 */
	public function test_helper_returns_api_error(): void {
		$user_id        = $this->linked_user();
		$this->fail_put = true;

		$result = workos_sync_user_metadata( $user_id );

		$this->assertWPError( $result );
	}
}
