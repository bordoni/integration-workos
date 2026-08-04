<?php
/**
 * Tests for how email subjects resolve to accounts and canonical forms.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\App;
use WorkOS\Lock\Registry;

/**
 * One counter per account when the address is owned, one per inbox when not.
 *
 * @covers \WorkOS\Lock\BaseLock::for_email
 */
class LockEmailSubjectTest extends WPTestCase {

	/**
	 * Lock registry.
	 *
	 * @var Registry
	 */
	private Registry $locks;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->locks = App::container()->get( Registry::class );
		$this->clear_lock_rows();
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		$this->clear_lock_rows();

		parent::tearDown();
	}

	/**
	 * Drop every option row the lock stores may have written.
	 *
	 * @return void
	 */
	private function clear_lock_rows(): void {
		global $wpdb;

		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'workos\_lock\_%'" );
	}

	/**
	 * Unowned spellings that reach one inbox share one count.
	 *
	 * Without this, re-spelling the address restarts the count and the send
	 * endpoints stay usable for aiming mail at a stranger.
	 */
	public function test_address_variants_share_one_count(): void {
		$lock = $this->locks->get( 'otp_send' );

		$variants = [
			'victim@gmail.com',
			'vic.tim@gmail.com',
			'victim+1@gmail.com',
			'v.i.c.t.i.m+tag@googlemail.com',
		];

		foreach ( $variants as $variant ) {
			$this->assertTrue(
				$lock->record( $lock->for_email( $variant ) ),
				sprintf( '%s should draw on the shared count without tripping it yet.', $variant )
			);
		}

		// Threshold is 5, so the fifth spelling engages the cooldown.
		$this->assertInstanceOf(
			\WP_Error::class,
			$lock->record( $lock->for_email( 'VICTIM+another@GoogleMail.com' ) )
		);
	}

	/**
	 * Every lock resolves variants the same way; there is one rule, not two.
	 *
	 * Collapsing unowned spellings gives an attacker nothing: anyone who can
	 * type `alice+x@` can type `alice@` and attack the exact address directly.
	 */
	public function test_all_locks_collapse_unowned_variants(): void {
		foreach ( [ 'password_auth', 'otp_send' ] as $slug ) {
			$lock = $this->locks->get( $slug );

			$this->assertSame(
				$lock->for_email( 'alice@gmail.com' )->identity(),
				$lock->for_email( 'alice+tag@gmail.com' )->identity(),
				sprintf( '%s should key unowned variants canonically.', $slug )
			);
		}
	}

	/**
	 * A variant nobody owns is never charged to the account it resembles.
	 *
	 * The ownership lookup uses the address as given, so `alice+1@` matching
	 * nothing keys on the canonical form rather than resolving to whoever
	 * holds `alice@`. Charging them would count activity that was never
	 * theirs and let it lock them out.
	 */
	public function test_an_unowned_variant_is_not_charged_to_a_lookalike(): void {
		$owner = self::factory()->user->create( [ 'user_email' => 'alice@gmail.com' ] );

		$lock    = $this->locks->get( 'otp_send' );
		$subject = $lock->for_email( 'alice+1@gmail.com' );

		$this->assertFalse(
			$subject->is_user_backed(),
			'An address nobody owns must not be stored against a user.'
		);
		$this->assertSame( 0, $subject->user_id() );

		$lock->record( $subject );

		// The account that happens to own the canonical address is untouched.
		$this->assertSame(
			0,
			$lock->status( $lock->for_user( $owner ) )->strikes()
		);
	}

	/**
	 * Counters recorded before the account existed follow it.
	 *
	 * Accounts are created upstream and only mirrored here after a successful
	 * login, so real counting starts before any WP user exists. The fold must
	 * work whichever spelling the account eventually registers with, which is
	 * why the subject identity is the canonical form in both branches.
	 */
	public function test_pre_account_counters_follow_the_user(): void {
		$lock = $this->locks->get( 'otp_send' );

		// Strikes accrue while nobody owns any spelling of the inbox.
		$lock->record( $lock->for_email( 'mi.grate@gmail.com' ) );
		$lock->record( $lock->for_email( 'migrate+app@gmail.com' ) );

		// The account arrives registered under yet another spelling.
		$user = self::factory()->user->create( [ 'user_email' => 'migrate+web@gmail.com' ] );

		$this->assertSame(
			2,
			$lock->status( $lock->for_email( 'migrate+web@gmail.com' ) )->strikes(),
			'Counters recorded before the account existed should fold onto it.'
		);

		$this->assertSame(
			2,
			$lock->status( $lock->for_user( $user ) )->strikes(),
			'After the fold the record lives on the user.'
		);
	}

	/**
	 * A known address counts against its account, and is stored there.
	 *
	 * The account is the better handle: it survives the address changing, and an
	 * operator can see and clear it from the profile screen rather than hunting
	 * an option row.
	 */
	public function test_a_known_address_counts_against_its_account(): void {
		$owner = self::factory()->user->create( [ 'user_email' => 'owner@example.com' ] );

		$lock    = $this->locks->get( 'otp_send' );
		$subject = $lock->for_email( 'owner@example.com' );

		$this->assertTrue( $subject->is_user_backed(), 'It should be stored on the user.' );
		$this->assertSame( $owner, $subject->user_id() );

		$lock->record( $subject );

		$this->assertSame( 1, $lock->status( $lock->for_user( $owner ) )->strikes() );
	}
}
