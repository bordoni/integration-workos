<?php
/**
 * Tests for the change-email pending-state storage helper.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Auth\ChangeEmail\PendingChange;
use WorkOS\Auth\ChangeEmail\TokenFactory;

/**
 * The pending-state helper is the only thing standing between a
 * captured user_meta row and a forged email change. These tests pin
 * down the invariants the REST endpoint relies on.
 */
class ChangeEmailPendingChangeTest extends WPTestCase {

	/**
	 * Test user.
	 *
	 * @var int
	 */
	private int $user_id = 0;

	public function setUp(): void {
		parent::setUp();

		$this->user_id = wp_insert_user(
			[
				'user_login' => 'pc_' . wp_generate_password( 8, false ),
				'user_pass'  => wp_generate_password(),
				'user_email' => 'user-' . uniqid() . '@example.test',
				'role'       => 'subscriber',
			]
		);

		$this->assertIsInt( $this->user_id );
	}

	public function tearDown(): void {
		if ( $this->user_id ) {
			delete_user_meta( $this->user_id, PendingChange::META_KEY );
		}
		parent::tearDown();
	}

	/**
	 * Stored meta must contain hashes, never plaintext tokens.
	 */
	public function test_store_persists_hashes_only(): void {
		$factory       = new TokenFactory();
		$pending       = new PendingChange( $factory );
		$confirm_token = $factory->generate();
		$cancel_token  = $factory->generate();

		$pending->store(
			$this->user_id,
			'new@example.test',
			$confirm_token,
			$cancel_token,
			time() + 600,
			0
		);

		$raw = get_user_meta( $this->user_id, PendingChange::META_KEY, true );

		$this->assertIsArray( $raw );
		$this->assertSame( 'new@example.test', $raw['new_email'] );
		$this->assertNotSame( $confirm_token, $raw['token_hash'] );
		$this->assertNotSame( $cancel_token, $raw['cancel_token_hash'] );
		$this->assertSame( $factory->hash( $confirm_token ), $raw['token_hash'] );
		$this->assertSame( $factory->hash( $cancel_token ), $raw['cancel_token_hash'] );
	}

	/**
	 * `get()` returns null when no row exists or the row is malformed.
	 */
	public function test_get_returns_null_when_absent_or_malformed(): void {
		$pending = new PendingChange( new TokenFactory() );

		$this->assertNull( $pending->get( $this->user_id ) );

		update_user_meta( $this->user_id, PendingChange::META_KEY, [ 'new_email' => 'partial@example.test' ] );
		$this->assertNull( $pending->get( $this->user_id ) );
	}

	/**
	 * `expired()` flips to true once the wall clock passes `expires_at`.
	 */
	public function test_expired_after_window(): void {
		$factory = new TokenFactory();
		$pending = new PendingChange( $factory );

		$pending->store(
			$this->user_id,
			'new@example.test',
			$factory->generate(),
			$factory->generate(),
			time() - 1,
			0
		);

		$record = $pending->get( $this->user_id );
		$this->assertIsArray( $record );
		$this->assertTrue( $pending->expired( $record ) );
	}

	/**
	 * Confirm-token verification: matching plaintext passes, tampered fails.
	 */
	public function test_verify_confirm_paths(): void {
		$factory = new TokenFactory();
		$pending = new PendingChange( $factory );
		$confirm = $factory->generate();
		$cancel  = $factory->generate();

		$pending->store( $this->user_id, 'new@example.test', $confirm, $cancel, time() + 600, 0 );

		$record = $pending->get( $this->user_id );
		$this->assertTrue( $pending->verify_confirm( $record, $confirm ) );
		$this->assertFalse( $pending->verify_confirm( $record, $cancel ) );
	}

	/**
	 * Cancel-token verification is a separate channel.
	 */
	public function test_verify_cancel_paths(): void {
		$factory = new TokenFactory();
		$pending = new PendingChange( $factory );
		$confirm = $factory->generate();
		$cancel  = $factory->generate();

		$pending->store( $this->user_id, 'new@example.test', $confirm, $cancel, time() + 600, 0 );

		$record = $pending->get( $this->user_id );
		$this->assertTrue( $pending->verify_cancel( $record, $cancel ) );
		$this->assertFalse( $pending->verify_cancel( $record, $confirm ) );
	}

	/**
	 * `clear()` removes the meta row.
	 */
	public function test_clear_removes_meta(): void {
		$factory = new TokenFactory();
		$pending = new PendingChange( $factory );

		$pending->store(
			$this->user_id,
			'new@example.test',
			$factory->generate(),
			$factory->generate(),
			time() + 600,
			0
		);

		$pending->clear( $this->user_id );

		$this->assertNull( $pending->get( $this->user_id ) );
		$this->assertSame( '', get_user_meta( $this->user_id, PendingChange::META_KEY, true ) );
	}
}
