<?php
/**
 * Tests for the AuthKit nonce helper.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Auth\AuthKit\Nonce;

/**
 * Profile-scoped nonce mint/verify.
 */
class AuthKitNonceTest extends WPTestCase {

	/**
	 * Nonce helper.
	 *
	 * @var Nonce
	 */
	private Nonce $nonce;

	public function setUp(): void {
		parent::setUp();
		$this->nonce = new Nonce();
	}

	/**
	 * A freshly-minted nonce verifies against the same profile.
	 */
	public function test_mint_then_verify_round_trip(): void {
		$token = $this->nonce->mint( 'members' );

		$this->assertNotEmpty( $token );
		$this->assertTrue( $this->nonce->verify( $token, 'members' ) );
	}

	/**
	 * A nonce minted for one profile does not verify against another.
	 */
	public function test_nonce_is_profile_scoped(): void {
		$token = $this->nonce->mint( 'members' );

		$this->assertFalse( $this->nonce->verify( $token, 'partners' ) );
	}

	/**
	 * Empty inputs fail verification.
	 */
	public function test_empty_inputs_fail_verification(): void {
		$this->assertFalse( $this->nonce->verify( '', 'members' ) );
		$this->assertFalse( $this->nonce->verify( 'anything', '' ) );
	}

	/**
	 * Garbage nonces fail verification.
	 */
	public function test_garbage_nonce_fails(): void {
		$this->assertFalse( $this->nonce->verify( 'not-a-real-nonce', 'members' ) );
	}

	/**
	 * Slug normalization means equivalent slugs verify each other.
	 */
	public function test_slug_normalization_is_consistent(): void {
		$token = $this->nonce->mint( 'Members Area' );

		$this->assertTrue( $this->nonce->verify( $token, 'members-area' ) );
	}
}
