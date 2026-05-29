<?php
/**
 * Tests for the change-email token factory.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Auth\ChangeEmail\TokenFactory;

/**
 * The token factory has a tiny surface but is security-critical: any
 * regression here weakens the verification of an email-change link.
 */
class ChangeEmailTokenFactoryTest extends WPTestCase {

	/**
	 * Two consecutive tokens must not be equal (basic entropy sanity check).
	 */
	public function test_tokens_are_unique(): void {
		$factory = new TokenFactory();

		$a = $factory->generate();
		$b = $factory->generate();

		$this->assertNotSame( $a, $b );
		$this->assertNotSame( '', $a );
	}

	/**
	 * Tokens must be long enough to resist brute force.
	 */
	public function test_token_is_long(): void {
		$factory = new TokenFactory();
		$token   = $factory->generate();

		$this->assertGreaterThanOrEqual( 32, strlen( $token ) );
	}

	/**
	 * Hashing the same input twice yields the same hash (deterministic
	 * with site salt).
	 */
	public function test_hash_is_deterministic(): void {
		$factory = new TokenFactory();

		$this->assertSame( $factory->hash( 'abc' ), $factory->hash( 'abc' ) );
	}

	/**
	 * Different inputs produce different hashes.
	 */
	public function test_hash_diverges_on_different_input(): void {
		$factory = new TokenFactory();

		$this->assertNotSame( $factory->hash( 'abc' ), $factory->hash( 'abd' ) );
	}

	/**
	 * `verify()` returns true only for the exact plaintext that produced
	 * the hash.
	 */
	public function test_verify_accepts_matching_token(): void {
		$factory = new TokenFactory();
		$token   = $factory->generate();
		$hash    = $factory->hash( $token );

		$this->assertTrue( $factory->verify( $token, $hash ) );
	}

	/**
	 * A single-byte tamper invalidates the token. Guards against bugs
	 * that downgrade the constant-time comparison to substring matching.
	 */
	public function test_verify_rejects_tampered_token(): void {
		$factory = new TokenFactory();
		$token   = $factory->generate();
		$hash    = $factory->hash( $token );

		$tampered = $token;
		$tampered[0] = 'A' === $tampered[0] ? 'B' : 'A';

		$this->assertFalse( $factory->verify( $tampered, $hash ) );
	}

	/**
	 * Empty strings never validate.
	 */
	public function test_verify_rejects_empty_token(): void {
		$factory = new TokenFactory();

		$this->assertFalse( $factory->verify( '', 'doesnt-matter' ) );
		$this->assertFalse( $factory->verify( 'token', '' ) );
	}
}
