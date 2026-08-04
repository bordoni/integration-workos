<?php
/**
 * Tests for email-address canonicalization.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use Codeception\Attribute\DataProvider;
use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Email\AddressCanonicalizer;

/**
 * Reducing an address to the mailbox it lands in.
 *
 * @covers \WorkOS\Email\AddressCanonicalizer
 */
class AddressCanonicalizerTest extends WPTestCase {

	/**
	 * Canonicalizer under test.
	 *
	 * @var AddressCanonicalizer
	 */
	private AddressCanonicalizer $addresses;

	/**
	 * Set up each test.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->addresses = new AddressCanonicalizer();
	}

	/**
	 * Addresses that reach one mailbox reduce to one string.
	 *
	 * @param string $raw      Address as typed.
	 * @param string $expected Mailbox it belongs to.
	 */
	#[DataProvider( 'canonicalAddresses' )]
	public function test_canonical_addressing( string $raw, string $expected ): void {
		$this->assertSame( $expected, $this->addresses->canonical( $raw ) );
	}

	// ------------------------------------------------------------ data providers

	/**
	 * Addresses paired with the mailbox they reduce to.
	 *
	 * @return array<string, array{0:string, 1:string}>
	 */
	public function canonicalAddresses(): array {
		return [
			'gmail drops dots and tags' => [
				'a.l.i.c.e+spam@gmail.com',
				'alice@gmail.com',
			],
			'googlemail folds to gmail' => [
				'Alice@googlemail.com',
				'alice@gmail.com',
			],
			'tags stripped everywhere' => [
				'alice+tag@example.com',
				'alice@example.com',
			],
			'dots kept off alias domains' => [
				'a.lice@example.com',
				'a.lice@example.com',
			],
			'uppercase folded' => [
				'  Alice@Example.COM ',
				'alice@example.com',
			],
			'tag-only local part kept' => [
				'+tag@example.com',
				'+tag@example.com',
			],
			'not an address' => [
				'not-an-email',
				'',
			],
			'empty' => [
				'',
				'',
			],
		];
	}
}
