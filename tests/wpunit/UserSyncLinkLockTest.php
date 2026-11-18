<?php
/**
 * Tests for the lock around UserSync::link_user() (LinkLock).
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\App;
use WorkOS\Config;
use WorkOS\Options\Production;
use WorkOS\Sync\LinkLock;
use WorkOS\Sync\UserLinks;
use WorkOS\Sync\UserSync;

/**
 * Two links of one WorkOS user at the same moment.
 *
 * WPUnit tests run inside a transaction. GET_LOCK() isn't transactional, so it
 * behaves as in production; a second connection plays the other request where a
 * test needs the lock held elsewhere. The options-row fallback runs on this
 * connection, inside the transaction, so its rows are rolled back with it.
 */
class UserSyncLinkLockTest extends WPTestCase {

	/**
	 * Calls to workos_user_linked: [ wp_user_id, workos_id, previous_id, source ].
	 *
	 * @var array<int, array>
	 */
	private array $linked = [];

	/**
	 * Second database connection, opened by other_connection().
	 *
	 * @var \wpdb|null
	 */
	private ?\wpdb $other = null;

	/**
	 * Set up each test.
	 */
	public function setUp(): void {
		parent::setUp();

		Config::set_active_environment( 'production' );
		update_option(
			'workos_production',
			[
				'api_key'           => 'sk_test_fake',
				'client_id'         => 'client_fake',
				'environment_id'    => 'environment_test',
				'relink_protection' => true,
			]
		);
		App::container()->get( Production::class )->reset();

		remove_all_actions( 'user_register' );
		remove_all_actions( 'profile_update' );

		$this->linked = [];
		add_action( 'workos_user_linked', [ $this, 'record_linked' ], 10, 4 );
	}

	/**
	 * Tear down each test.
	 */
	public function tearDown(): void {
		// Hooks added by a test are restored by parent::tearDown().
		remove_action( 'workos_user_linked', [ $this, 'record_linked' ], 10 );

		if ( $this->other ) {
			$this->other->query( 'SELECT RELEASE_ALL_LOCKS()' );
			$this->other->close();
			$this->other = null;
		}

		delete_option( 'workos_production' );
		Config::set_active_environment( 'staging' );
		App::container()->get( Production::class )->reset();

		parent::tearDown();
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
	 * A second database connection: another request.
	 *
	 * @return \wpdb
	 */
	private function other_connection(): \wpdb {
		if ( ! $this->other ) {
			$this->other = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		}
		return $this->other;
	}

	/**
	 * Whether no connection holds the GET_LOCK() lock for a WorkOS ID.
	 *
	 * @param string $workos_id WorkOS user ID.
	 *
	 * @return bool
	 */
	private function lock_is_free( string $workos_id ): bool {
		return '1' === (string) $this->other_connection()->get_var(
			$this->other_connection()->prepare( 'SELECT IS_FREE_LOCK(%s)', LinkLock::name( $workos_id ) )
		);
	}

	/**
	 * Hold the GET_LOCK() lock for a WorkOS ID from the second connection.
	 *
	 * @param string $workos_id WorkOS user ID.
	 */
	private function hold_lock_elsewhere( string $workos_id ): void {
		$got = $this->other_connection()->get_var(
			$this->other_connection()->prepare( 'SELECT GET_LOCK(%s, 0)', LinkLock::name( $workos_id ) )
		);
		$this->assertSame( '1', (string) $got );
	}

	/**
	 * `query` filter: make GET_LOCK() look unavailable (returns NULL, like a server without it).
	 *
	 * @param string $query SQL.
	 *
	 * @return string
	 */
	public function without_get_lock( $query ) {
		if ( preg_match( '/^\s*SELECT\s+(GET_LOCK|RELEASE_LOCK)\s*\(/i', (string) $query ) ) {
			return 'SELECT NULL';
		}
		return $query;
	}

	/**
	 * The options row the fallback mutex uses for a WorkOS ID, or null.
	 *
	 * @param string $workos_id WorkOS user ID.
	 *
	 * @return string|null Its value (the expiry time), or null when there is no row.
	 */
	private function lock_row( string $workos_id ): ?string {
		global $wpdb;
		return $wpdb->get_var(
			$wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->base_prefix . 'options', LinkLock::name( $workos_id ) )
		);
	}

	/**
	 * Run a callback once, the next time link_user() writes `_workos_user_id` for a user.
	 *
	 * That's after link_user() has checked the link table and before it writes:
	 * the window two requests used to race in.
	 *
	 * @param int      $wp_user_id WP user ID being linked.
	 * @param callable $callback   What the other request does.
	 */
	private function in_the_window( int $wp_user_id, callable $callback ): void {
		$done = false;
		add_filter(
			'update_user_metadata',
			static function ( $check, $object_id, $meta_key ) use ( $wp_user_id, $callback, &$done ) {
				if ( ! $done && (int) $object_id === $wp_user_id && '_workos_user_id' === $meta_key ) {
					$done = true;
					$callback();
				}
				return $check;
			},
			10,
			3
		);
	}

	// -------------------------------------------------------------------------
	// The race
	// -------------------------------------------------------------------------

	/**
	 * A second caller that links the same WorkOS ID between the check and the write is refused.
	 */
	public function test_second_caller_in_the_window_is_refused(): void {
		$first  = self::factory()->user->create();
		$second = self::factory()->user->create();
		$nested = null;

		$this->in_the_window(
			$first,
			static function () use ( $second, &$nested ) {
				$nested = UserSync::link_user( $second, [ 'id' => 'user_race', 'email' => 'race@example.com' ], 'webhook' );
			}
		);

		$result = UserSync::link_user( $first, [ 'id' => 'user_race', 'email' => 'race@example.com' ], 'login' );

		$this->assertTrue( $result );
		$this->assertFalse( $nested, 'The second caller must not link while the first holds the lock.' );
		$this->assertSame( 'user_race', get_user_meta( $first, '_workos_user_id', true ) );
		$this->assertEmpty( get_user_meta( $second, '_workos_user_id', true ) );
		$this->assertSame( $first, UserLinks::find( 'user_race' ) );
		$this->assertSame( [ [ $first, 'user_race', '', 'login' ] ], $this->linked );
	}

	/**
	 * A link another request wrote while this one waited for the lock is seen: the second link is refused.
	 */
	public function test_link_written_before_the_lock_is_rechecked(): void {
		$first  = self::factory()->user->create();
		$second = self::factory()->user->create();

		// The other request finishes its link just before this one gets the lock.
		add_filter(
			'query',
			static function ( $query ) use ( $first ) {
				static $done = false;
				if ( ! $done && preg_match( '/^\s*SELECT\s+GET_LOCK\s*\(/i', (string) $query ) ) {
					$done = true;
					update_user_meta( $first, '_workos_user_id', 'user_race2' );
				}
				return $query;
			}
		);

		$result = UserSync::link_user( $second, [ 'id' => 'user_race2', 'email' => 'race2@example.com' ], 'webhook' );

		$this->assertFalse( $result );
		$this->assertEmpty( get_user_meta( $second, '_workos_user_id', true ) );
		$this->assertSame( $first, UserLinks::find( 'user_race2' ) );
		$this->assertSame( [], $this->linked );
	}

	/**
	 * When the other request linked the same WP user first, the link is written again
	 * without a second workos_user_linked.
	 */
	public function test_same_link_written_before_the_lock_fires_no_second_linked_action(): void {
		$user_id = self::factory()->user->create();

		add_filter(
			'query',
			static function ( $query ) use ( $user_id ) {
				static $done = false;
				if ( ! $done && preg_match( '/^\s*SELECT\s+GET_LOCK\s*\(/i', (string) $query ) ) {
					$done = true;
					update_user_meta( $user_id, '_workos_user_id', 'user_both' );
				}
				return $query;
			}
		);

		$result = UserSync::link_user( $user_id, [ 'id' => 'user_both', 'email' => 'both@example.com' ], 'webhook' );

		$this->assertTrue( $result );
		$this->assertSame( 'user_both', get_user_meta( $user_id, '_workos_user_id', true ) );
		$this->assertSame( [], $this->linked );
	}

	// -------------------------------------------------------------------------
	// Release
	// -------------------------------------------------------------------------

	/**
	 * The lock is released after a link.
	 */
	public function test_lock_released_after_link(): void {
		$user_id = self::factory()->user->create();

		$this->assertTrue( UserSync::link_user( $user_id, [ 'id' => 'user_ok', 'email' => 'ok@example.com' ], 'login' ) );

		$this->assertTrue( $this->lock_is_free( 'user_ok' ) );
		$this->assertTrue( LinkLock::acquire( 'user_ok' ) );
		LinkLock::release( 'user_ok' );
	}

	/**
	 * The lock is released after a refused link.
	 */
	public function test_lock_released_after_refused_link(): void {
		$holder = self::factory()->user->create();
		update_user_meta( $holder, '_workos_user_id', 'user_taken' );
		$other = self::factory()->user->create();

		$this->assertFalse( UserSync::link_user( $other, [ 'id' => 'user_taken', 'email' => 'taken@example.com' ], 'login' ) );

		$this->assertTrue( $this->lock_is_free( 'user_taken' ) );
	}

	/**
	 * The lock is released when the write throws.
	 */
	public function test_lock_released_when_the_write_throws(): void {
		$user_id = self::factory()->user->create();

		$this->in_the_window(
			$user_id,
			static function () {
				throw new \RuntimeException( 'write failed' );
			}
		);

		$thrown = null;
		try {
			UserSync::link_user( $user_id, [ 'id' => 'user_throw', 'email' => 'throw@example.com' ], 'login' );
		} catch ( \RuntimeException $e ) {
			$thrown = $e;
		}

		$this->assertInstanceOf( \RuntimeException::class, $thrown );
		$this->assertTrue( $this->lock_is_free( 'user_throw' ) );
		$this->assertTrue( LinkLock::acquire( 'user_throw' ), 'The request must not think it still holds the lock.' );
		LinkLock::release( 'user_throw' );
	}

	// -------------------------------------------------------------------------
	// Timeout
	// -------------------------------------------------------------------------

	/**
	 * A lock held by another request past the timeout is a WP_Error.
	 */
	public function test_acquire_times_out_with_wp_error(): void {
		$this->hold_lock_elsewhere( 'user_busy' );
		add_filter( 'workos_link_lock_timeout', '__return_zero' );

		$result = LinkLock::acquire( 'user_busy' );

		$this->assertWPError( $result );
		$this->assertSame( 'workos_link_lock_timeout', $result->get_error_code() );
	}

	/**
	 * link_user() fails instead of linking without the lock.
	 */
	public function test_link_fails_when_the_lock_times_out(): void {
		$this->hold_lock_elsewhere( 'user_busy2' );
		add_filter( 'workos_link_lock_timeout', '__return_zero' );
		$user_id = self::factory()->user->create();

		$result = UserSync::link_user( $user_id, [ 'id' => 'user_busy2', 'email' => 'busy@example.com' ], 'webhook' );

		$this->assertFalse( $result );
		$this->assertEmpty( get_user_meta( $user_id, '_workos_user_id', true ) );
		$this->assertSame( [], $this->linked );
	}

	/**
	 * --force takes the lock too.
	 */
	public function test_force_link_takes_the_lock(): void {
		update_option( 'workos_production', [ 'api_key' => 'sk_test_fake' ] );
		App::container()->get( Production::class )->reset();
		$this->assertFalse( Config::is_relink_protection_enabled() );

		$this->hold_lock_elsewhere( 'user_busy3' );
		add_filter( 'workos_link_lock_timeout', '__return_zero' );
		$user_id = self::factory()->user->create();

		$this->assertFalse( UserSync::link_user( $user_id, [ 'id' => 'user_busy3', 'email' => 'busy3@example.com' ], 'cli_link', true ) );
	}

	/**
	 * With protection off and no --force, link_user() has no check to protect and takes no lock (unchanged).
	 */
	public function test_unprotected_link_takes_no_lock(): void {
		update_option( 'workos_production', [ 'api_key' => 'sk_test_fake' ] );
		App::container()->get( Production::class )->reset();

		$this->hold_lock_elsewhere( 'user_busy4' );
		add_filter( 'workos_link_lock_timeout', '__return_zero' );
		$user_id = self::factory()->user->create();

		$this->assertTrue( UserSync::link_user( $user_id, [ 'id' => 'user_busy4', 'email' => 'busy4@example.com' ], 'login' ) );
	}

	/**
	 * Locks for different WorkOS IDs don't block each other.
	 */
	public function test_locks_are_per_workos_id(): void {
		$this->hold_lock_elsewhere( 'user_a' );
		add_filter( 'workos_link_lock_timeout', '__return_zero' );

		$this->assertTrue( LinkLock::acquire( 'user_b' ) );
		LinkLock::release( 'user_b' );
	}

	/**
	 * The lock name fits GET_LOCK()'s 64-character limit.
	 */
	public function test_lock_name_fits_get_lock_limit(): void {
		$this->assertLessThanOrEqual( 64, strlen( LinkLock::name( str_repeat( 'x', 500 ) ) ) );
		$this->assertNotSame( LinkLock::name( 'user_a' ), LinkLock::name( 'user_b' ) );
	}

	// -------------------------------------------------------------------------
	// Fallback without GET_LOCK()
	// -------------------------------------------------------------------------

	/**
	 * Without GET_LOCK() the options row is the lock, and release deletes it.
	 */
	public function test_fallback_acquires_and_releases_options_row(): void {
		add_filter( 'query', [ $this, 'without_get_lock' ] );

		$this->assertTrue( LinkLock::acquire( 'user_fb' ) );
		$this->assertNotNull( $this->lock_row( 'user_fb' ) );

		LinkLock::release( 'user_fb' );
		$this->assertNull( $this->lock_row( 'user_fb' ) );
	}

	/**
	 * Without GET_LOCK(), a row another request holds times out with a WP_Error.
	 */
	public function test_fallback_times_out_on_held_row(): void {
		global $wpdb;
		add_filter( 'query', [ $this, 'without_get_lock' ] );
		add_filter( 'workos_link_lock_timeout', '__return_zero' );
		$wpdb->insert(
			$wpdb->base_prefix . 'options',
			[
				'option_name'  => LinkLock::name( 'user_fb2' ),
				'option_value' => (string) ( time() + 60 ),
				'autoload'     => 'no',
			]
		);

		$result = LinkLock::acquire( 'user_fb2' );

		$this->assertWPError( $result );
		$this->assertSame( 'workos_link_lock_timeout', $result->get_error_code() );
	}

	/**
	 * Without GET_LOCK(), a row left by a request that died is taken over once it expires.
	 */
	public function test_fallback_takes_over_expired_row(): void {
		global $wpdb;
		add_filter( 'query', [ $this, 'without_get_lock' ] );
		add_filter( 'workos_link_lock_timeout', '__return_zero' );
		$wpdb->insert(
			$wpdb->base_prefix . 'options',
			[
				'option_name'  => LinkLock::name( 'user_fb3' ),
				'option_value' => (string) ( time() - 1 ),
				'autoload'     => 'no',
			]
		);

		$this->assertTrue( LinkLock::acquire( 'user_fb3' ) );
		LinkLock::release( 'user_fb3' );
	}

	/**
	 * Without GET_LOCK(), the second caller is still refused and the row is gone afterwards.
	 */
	public function test_fallback_refuses_second_caller_and_releases(): void {
		add_filter( 'query', [ $this, 'without_get_lock' ] );
		$first  = self::factory()->user->create();
		$second = self::factory()->user->create();
		$nested = null;

		$this->in_the_window(
			$first,
			static function () use ( $second, &$nested ) {
				$nested = UserSync::link_user( $second, [ 'id' => 'user_fb4', 'email' => 'fb4@example.com' ], 'webhook' );
			}
		);

		$this->assertTrue( UserSync::link_user( $first, [ 'id' => 'user_fb4', 'email' => 'fb4@example.com' ], 'login' ) );
		$this->assertFalse( $nested );
		$this->assertEmpty( get_user_meta( $second, '_workos_user_id', true ) );
		$this->assertNull( $this->lock_row( 'user_fb4' ) );
	}

	/**
	 * Without GET_LOCK(), the row is deleted when the write throws.
	 */
	public function test_fallback_releases_when_the_write_throws(): void {
		add_filter( 'query', [ $this, 'without_get_lock' ] );
		$user_id = self::factory()->user->create();

		$this->in_the_window(
			$user_id,
			static function () {
				throw new \RuntimeException( 'write failed' );
			}
		);

		try {
			UserSync::link_user( $user_id, [ 'id' => 'user_fb5', 'email' => 'fb5@example.com' ], 'login' );
			$this->fail( 'Expected the write to throw.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'write failed', $e->getMessage() );
		}

		$this->assertNull( $this->lock_row( 'user_fb5' ) );
	}
}
