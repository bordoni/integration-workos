<?php
/**
 * Tests for mailbox canonicalization.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use Codeception\Attribute\DataProvider;
use WorkOS\Email\AddressCanonicalizer;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * Which strings mean the same inbox.
 *
 * The interesting half is what must *not* collapse. Merging two real
 * mailboxes into one bucket lets either exhaust the other's allowance, so
 * a wrong guess here turns a spam control into a way to lock someone out.
 */
class AddressCanonicalizerTest extends WPTestCase {

	/**
	 * Canonicalizer under test.
	 *
	 * @var AddressCanonicalizer
	 */
	private AddressCanonicalizer $canonicalizer;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->canonicalizer = new AddressCanonicalizer();
	}

	/**
	 * Addresses that reduce to the same mailbox.
	 *
	 * @param string $email    Input address.
	 * @param string $expected Canonical form.
	 */
	#[DataProvider( 'collapses_provider' )]
	public function test_collapses_variants_of_one_mailbox( string $email, string $expected ): void {
		$this->assertSame( $expected, $this->canonicalizer->canonical( $email ) );
	}

	/**
	 * Addresses that must stay distinct, because on these domains the
	 * character is part of the mailbox name rather than a tag.
	 *
	 * @param string $email Input address.
	 */
	#[DataProvider( 'stays_distinct_provider' )]
	public function test_leaves_unknown_providers_alone( string $email ): void {
		$this->assertSame( $email, $this->canonicalizer->canonical( $email ) );
	}

	/**
	 * Two people on a corporate domain keep separate allowances. This is
	 * the case the plus-stripping allowlist exists for.
	 */
	public function test_corporate_plus_addresses_do_not_share_a_bucket(): void {
		$shared   = $this->canonicalizer->canonical( 'business+brian@corp.com' );
		$personal = $this->canonicalizer->canonical( 'business@corp.com' );

		$this->assertNotSame( $shared, $personal );
	}

	/**
	 * Dots are only inert on the providers that ignore them.
	 */
	public function test_dots_only_collapse_for_known_providers(): void {
		$this->assertSame( 'alice@gmail.com', $this->canonicalizer->canonical( 'a.l.i.c.e@gmail.com' ) );
		$this->assertSame( 'a.lice@outlook.com', $this->canonicalizer->canonical( 'a.lice@outlook.com' ) );
	}

	/**
	 * An address that is nothing but a tag would collapse to an empty
	 * local part, which would merge unrelated senders; it is left alone.
	 */
	public function test_tag_only_local_part_is_left_intact(): void {
		$this->assertSame( '+tag@gmail.com', $this->canonicalizer->canonical( '+tag@gmail.com' ) );
	}

	/**
	 * Non-addresses yield empty rather than a bucket key.
	 *
	 * @param string $input Input string.
	 */
	#[DataProvider( 'not_an_address_provider' )]
	public function test_rejects_non_addresses( string $input ): void {
		$this->assertSame( '', $this->canonicalizer->canonical( $input ) );
	}

	/**
	 * Normalization lowercases and trims without touching the mailbox.
	 */
	public function test_normalize_lowercases_and_trims(): void {
		$this->assertSame( 'alice+tag@corp.com', $this->canonicalizer->normalize( '  Alice+Tag@Corp.com ' ) );
		$this->assertSame( '', $this->canonicalizer->normalize( 'nope' ) );
	}

	// -------------------------------------------------------------------------
	// Providers
	// -------------------------------------------------------------------------

	/**
	 * Variants that are genuinely one inbox.
	 *
	 * @return array<string, array{0:string,1:string}>
	 */
	public function collapses_provider(): array {
		return [
			'gmail tag'            => [ 'alice+newsletter@gmail.com', 'alice@gmail.com' ],
			'gmail dots'           => [ 'a.l.i.c.e@gmail.com', 'alice@gmail.com' ],
			'gmail dots and tag'   => [ 'a.lice+x@gmail.com', 'alice@gmail.com' ],
			'googlemail alias'     => [ 'alice@googlemail.com', 'alice@gmail.com' ],
			'outlook tag'          => [ 'alice+x@outlook.com', 'alice@outlook.com' ],
			'hotmail tag'          => [ 'alice+x@hotmail.com', 'alice@hotmail.com' ],
			'fastmail tag'         => [ 'alice+x@fastmail.com', 'alice@fastmail.com' ],
			'case and whitespace'  => [ '  Alice@Gmail.com  ', 'alice@gmail.com' ],
			'multiple tag markers' => [ 'alice+a+b@gmail.com', 'alice@gmail.com' ],
		];
	}

	/**
	 * Addresses that must survive untouched.
	 *
	 * Yahoo and iCloud offer disposable addresses rather than tags, and a
	 * self-hosted or corporate server commonly treats `+` as an ordinary
	 * character, so on all of these the tag form is a different mailbox.
	 *
	 * @return array<string, array{0:string}>
	 */
	public function stays_distinct_provider(): array {
		return [
			'corporate tag'    => [ 'business+brian@corp.com' ],
			'self-hosted tag'  => [ 'ops+alerts@example.org' ],
			'yahoo tag'        => [ 'alice+x@yahoo.com' ],
			'icloud tag'       => [ 'alice+x@icloud.com' ],
			'proton tag'       => [ 'alice+x@proton.me' ],
			'corporate dots'   => [ 'a.lice@corp.com' ],
			'outlook dots'     => [ 'a.lice@outlook.com' ],
			'plain corporate'  => [ 'alice@corp.com' ],
		];
	}

	/**
	 * Strings that are not addresses.
	 *
	 * @return array<string, array{0:string}>
	 */
	public function not_an_address_provider(): array {
		return [
			'empty'       => [ '' ],
			'no at sign'  => [ 'alice' ],
			'no domain'   => [ 'alice@' ],
			'spaces only' => [ '   ' ],
		];
	}
}
