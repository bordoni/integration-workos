<?php
/**
 * Tests for the UserSync race guard added for the change-email flow.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Auth\ChangeEmail\RestApi;
use WorkOS\Sync\UserSync;

/**
 * `UserSync::handle_user_updated()` must short-circuit while the
 * change-email flow is mid-commit, otherwise the WorkOS webhook
 * fan-back races the local `wp_update_user()` and can re-trigger the
 * mutation we just made.
 */
class ChangeEmailUserSyncRaceGuardTest extends WPTestCase {

	private int $user_id = 0;
	private string $workos_id = 'user_race_guard_01';

	public function setUp(): void {
		parent::setUp();

		$suffix        = uniqid( 'rg_', true );
		$this->user_id = wp_insert_user(
			[
				'user_login' => 'rg_' . wp_generate_password( 8, false ),
				'user_pass'  => wp_generate_password(),
				'user_email' => 'rg-' . $suffix . '@example.test',
				'role'       => 'subscriber',
			]
		);
		$this->assertIsInt( $this->user_id );

		update_user_meta( $this->user_id, '_workos_user_id', $this->workos_id );
		update_user_meta( $this->user_id, '_workos_profile_hash', 'stale-hash' );
	}

	public function tearDown(): void {
		delete_transient( RestApi::TRANSIENT_PREFIX . $this->user_id );
		parent::tearDown();
	}

	public function test_handle_user_updated_skips_while_in_progress(): void {
		set_transient( RestApi::TRANSIENT_PREFIX . $this->user_id, 1, 30 );

		$sync = new UserSync();
		$sync->handle_user_updated(
			[
				'data' => [
					'id'         => $this->workos_id,
					'email'      => 'changed-' . uniqid() . '@example.test',
					'first_name' => 'Changed',
					'last_name'  => 'Person',
				],
			]
		);

		// The hash would have been rewritten if the handler had run;
		// it should still be the stale placeholder.
		$this->assertSame( 'stale-hash', get_user_meta( $this->user_id, '_workos_profile_hash', true ) );
	}

	public function test_handle_user_updated_runs_when_transient_absent(): void {
		delete_transient( RestApi::TRANSIENT_PREFIX . $this->user_id );

		$sync = new UserSync();
		$sync->handle_user_updated(
			[
				'data' => [
					'id'         => $this->workos_id,
					'email'      => 'changed-' . uniqid() . '@example.test',
					'first_name' => 'Changed',
					'last_name'  => 'Person',
				],
			]
		);

		// Without the transient set the handler must run, refreshing the
		// stored profile hash with whatever the new payload produced.
		$this->assertNotSame( 'stale-hash', get_user_meta( $this->user_id, '_workos_profile_hash', true ) );
	}
}
