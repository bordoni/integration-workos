<?php
/**
 * Tests for the change-email conflict resolver.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Auth\ChangeEmail\ConflictResolver;

/**
 * Coverage for the policy matrix:
 *
 *  - block         → always rejects collisions
 *  - allow_orphan  → rejects only if the conflicting user has activity
 *  - merge_request → rejects but fires the merge-request hook (Issue 2)
 *
 * Same-user no-op is treated as success without touching the policy.
 */
class ChangeEmailConflictResolverTest extends WPTestCase {

	private int $target_id      = 0;
	private int $conflicting_id = 0;

	public function setUp(): void {
		parent::setUp();

		\WorkOS\Config::set_active_environment( 'production' );
		update_option( 'workos_production', [ 'change_email_conflict_policy' => 'block' ] );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$suffix               = uniqid( 'crv_', true );
		$this->target_id      = wp_insert_user(
			[
				'user_login' => 'tg_' . wp_generate_password( 8, false ),
				'user_pass'  => wp_generate_password(),
				'user_email' => 'target-' . $suffix . '@example.test',
				'role'       => 'subscriber',
			]
		);
		$this->conflicting_id = wp_insert_user(
			[
				'user_login' => 'cn_' . wp_generate_password( 8, false ),
				'user_pass'  => wp_generate_password(),
				'user_email' => 'taken-' . $suffix . '@example.test',
				'role'       => 'subscriber',
			]
		);

		$this->assertIsInt( $this->target_id );
		$this->assertIsInt( $this->conflicting_id );
	}

	public function tearDown(): void {
		delete_option( 'workos_production' );
		\WorkOS\Config::set_active_environment( 'staging' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();
		parent::tearDown();
	}

	/**
	 * No existing user with the new address → no conflict.
	 */
	public function test_allows_unique_email(): void {
		$resolver = new ConflictResolver();

		$result = $resolver->check( 'fresh-' . uniqid() . '@example.test', get_userdata( $this->target_id ) );

		$this->assertNull( $result );
	}

	/**
	 * Default `block` policy rejects a colliding address.
	 */
	public function test_block_policy_rejects_collision(): void {
		$resolver = new ConflictResolver();

		$existing = get_userdata( $this->conflicting_id );
		$result   = $resolver->check( $existing->user_email, get_userdata( $this->target_id ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'workos_change_email_conflict', $result->get_error_code() );
	}

	/**
	 * Changing to your own current email is a no-op, not a conflict.
	 */
	public function test_same_user_no_op_is_allowed(): void {
		$resolver = new ConflictResolver();
		$user     = get_userdata( $this->target_id );

		$result = $resolver->check( $user->user_email, $user );

		$this->assertNull( $result );
	}

	/**
	 * `allow_orphan` lets a takeover proceed when the conflicting user
	 * is unlinked, has no content, and was registered long ago.
	 */
	public function test_allow_orphan_permits_takeover_of_orphan(): void {
		update_option( 'workos_production', [ 'change_email_conflict_policy' => 'allow_orphan' ] );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		// Backdate the conflicting user's registration well beyond the 90-day window.
		global $wpdb;
		$wpdb->update(
			$wpdb->users,
			[ 'user_registered' => gmdate( 'Y-m-d H:i:s', time() - ( 365 * DAY_IN_SECONDS ) ) ],
			[ 'ID' => $this->conflicting_id ]
		);
		clean_user_cache( $this->conflicting_id );

		$resolver = new ConflictResolver();
		$existing = get_userdata( $this->conflicting_id );
		$result   = $resolver->check( $existing->user_email, get_userdata( $this->target_id ) );

		$this->assertNull( $result, 'Orphan takeover should be allowed under allow_orphan.' );
	}

	/**
	 * `allow_orphan` still rejects when the conflicting user is linked
	 * to a WorkOS profile — those are never orphans.
	 */
	public function test_allow_orphan_rejects_when_user_is_linked(): void {
		update_option( 'workos_production', [ 'change_email_conflict_policy' => 'allow_orphan' ] );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		update_user_meta( $this->conflicting_id, '_workos_user_id', 'user_existing_01' );

		$resolver = new ConflictResolver();
		$existing = get_userdata( $this->conflicting_id );
		$result   = $resolver->check( $existing->user_email, get_userdata( $this->target_id ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * `merge_request` rejects today (until Issue 2 ships) AND fires the
	 * dedicated merge-request action so a future plugin can observe.
	 */
	public function test_merge_request_policy_fires_action(): void {
		update_option( 'workos_production', [ 'change_email_conflict_policy' => 'merge_request' ] );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$fired = 0;
		$cb = static function () use ( &$fired ) {
			++$fired;
		};
		add_action( 'workos/change_email/merge_requested', $cb );

		$resolver = new ConflictResolver();
		$existing = get_userdata( $this->conflicting_id );
		$result   = $resolver->check( $existing->user_email, get_userdata( $this->target_id ) );

		remove_action( 'workos/change_email/merge_requested', $cb );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 1, $fired );
	}

	/**
	 * The conflict_detected action fires under every policy.
	 */
	public function test_conflict_detected_action_fires(): void {
		$fired = 0;
		$cb = static function () use ( &$fired ) {
			++$fired;
		};
		add_action( 'workos/change_email/conflict_detected', $cb );

		$resolver = new ConflictResolver();
		$existing = get_userdata( $this->conflicting_id );
		$resolver->check( $existing->user_email, get_userdata( $this->target_id ) );

		remove_action( 'workos/change_email/conflict_detected', $cb );

		$this->assertSame( 1, $fired );
	}

	/**
	 * The conflict-policy filter overrides the stored option for the
	 * current request — used by tightening security plugins.
	 */
	public function test_policy_filter_overrides_option(): void {
		update_option( 'workos_production', [ 'change_email_conflict_policy' => 'allow_orphan' ] );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$cb = static function () {
			return 'block';
		};
		add_filter( 'workos/change_email/conflict_policy', $cb );

		$resolver = new ConflictResolver();
		$this->assertSame( 'block', $resolver->resolve_policy() );

		remove_filter( 'workos/change_email/conflict_policy', $cb );
	}
}
