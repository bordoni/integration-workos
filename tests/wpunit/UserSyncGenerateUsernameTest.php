<?php
/**
 * Tests for UserSync::generate_username().
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Sync\UserSync;

/**
 * Username generation tests.
 *
 * The generator must derive usernames in O(1) lookups regardless of how many
 * users share the same email local part — the previous sequential probe
 * (info, info_1, info_2, …) walked the whole chain and exhausted memory on
 * deep chains (CONS-513). Expected hash values are precomputed sha256 hex
 * digests of the lowercased email (or email + '|' + attempt for salted
 * retries), independent of the implementation.
 */
class UserSyncGenerateUsernameTest extends WPTestCase {

	/**
	 * Set up each test.
	 */
	public function setUp(): void {
		parent::setUp();

		remove_all_actions( 'user_register' );
	}

	/**
	 * Invoke the private username generator via reflection.
	 *
	 * @param string $email Email to derive a username from.
	 *
	 * @return string Generated username.
	 */
	private function generate( string $email ): string {
		$method = new \ReflectionMethod( UserSync::class, 'generate_username' );
		$method->setAccessible( true );

		return $method->invoke( null, [ 'email' => $email ] );
	}

	/**
	 * Create a user occupying the given login.
	 *
	 * @param string $login Login to occupy.
	 */
	private function seed_login( string $login ): void {
		self::factory()->user->create(
			[
				'user_login' => $login,
				'user_email' => md5( $login ) . '@seed.test',
			]
		);
	}

	/**
	 * Test bare local part is used when free.
	 */
	public function test_uses_bare_local_part_when_base_is_free(): void {
		$this->assertSame( 'info', $this->generate( 'info@acme-widgets.com' ) );
	}

	/**
	 * Test the 5-hex email hash suffix is appended when the base is taken.
	 */
	public function test_appends_short_email_hash_when_base_is_taken(): void {
		$this->seed_login( 'info' );

		$this->assertSame( 'info_48f25', $this->generate( 'info@acme-widgets.com' ) );
	}

	/**
	 * Test the suffix widens to 12 hex chars when the 5-hex name is taken.
	 */
	public function test_widens_suffix_when_short_hash_name_is_taken(): void {
		$this->seed_login( 'info' );
		$this->seed_login( 'info_48f25' );

		$this->assertSame( 'info_48f257791ee0', $this->generate( 'info@acme-widgets.com' ) );
	}

	/**
	 * Test a salted re-derivation is used when the widened name is taken.
	 */
	public function test_salted_retry_when_widened_name_is_taken(): void {
		$this->seed_login( 'info' );
		$this->seed_login( 'info_48f25' );
		$this->seed_login( 'info_48f257791ee0' );

		$this->assertSame( 'info_b1dc4def5ca9', $this->generate( 'info@acme-widgets.com' ) );
	}

	/**
	 * Test salted attempts progress deterministically when the first salt is taken.
	 */
	public function test_second_salted_retry_when_first_salt_is_taken(): void {
		$this->seed_login( 'info' );
		$this->seed_login( 'info_48f25' );
		$this->seed_login( 'info_48f257791ee0' );
		$this->seed_login( 'info_b1dc4def5ca9' );

		$this->assertSame( 'info_023b84c3d9b3', $this->generate( 'info@acme-widgets.com' ) );
	}

	/**
	 * Test the same email derives the identical username across runs.
	 */
	public function test_same_email_derives_identical_username_across_runs(): void {
		$this->seed_login( 'info' );

		$first  = $this->generate( 'info@acme-widgets.com' );
		$second = $this->generate( 'info@acme-widgets.com' );

		$this->assertSame( $first, $second );
	}

	/**
	 * Test lookup count stays constant no matter how deep the existing chain is.
	 *
	 * This is the regression test for the production OOM: the old sequential
	 * probe performed one lookup per existing info_* user. With 150 seeded
	 * users it would need 152 lookups; the generator must need exactly 2.
	 */
	public function test_lookup_count_is_constant_for_deep_username_chains(): void {
		$this->seed_login( 'info' );
		for ( $i = 1; $i <= 150; $i++ ) {
			$this->seed_login( 'info_' . $i );
		}

		$lookups = 0;
		$counter = static function ( $user_id ) use ( &$lookups ) {
			++$lookups;

			return $user_id;
		};

		add_filter( 'username_exists', $counter );
		$username = $this->generate( 'info@acme-widgets.com' );
		remove_filter( 'username_exists', $counter );

		$this->assertSame( 'info_48f25', $username );
		$this->assertSame( 2, $lookups );
	}

	/**
	 * Test a long local part is capped so the widest suffix still fits varchar(60).
	 */
	public function test_long_local_part_is_capped_for_suffix_headroom(): void {
		$username = $this->generate( 'international-wholesale-distribution-and-logistics-coordination@globex.test' );

		$this->assertSame( 'international-wholesale-distribution-and-logist', $username );
		$this->assertSame( 47, strlen( $username ) );
	}

	/**
	 * Test the widened suffix on a capped base lands exactly on the 60-char limit.
	 */
	public function test_generated_username_never_exceeds_sixty_chars(): void {
		$this->seed_login( 'international-wholesale-distribution-and-logist' );
		$this->seed_login( 'international-wholesale-distribution-and-logist_6a3ae' );

		$username = $this->generate( 'international-wholesale-distribution-and-logistics-coordination@globex.test' );

		$this->assertSame( 'international-wholesale-distribution-and-logist_6a3aeb9adeab', $username );
		$this->assertSame( 60, strlen( $username ) );
	}

	/**
	 * Test emails sharing a truncated base still derive distinct usernames.
	 *
	 * The hash is computed from the full email, never the truncated base, so
	 * truncation must not create collisions.
	 */
	public function test_truncated_bases_still_get_distinct_usernames(): void {
		$this->seed_login( 'international-wholesale-distribution-and-logist' );

		$first  = $this->generate( 'international-wholesale-distribution-and-logistics-coordination@globex.test' );
		$second = $this->generate( 'international-wholesale-distribution-and-logistics-department@globex.test' );

		$this->assertSame( 'international-wholesale-distribution-and-logist_6a3ae', $first );
		$this->assertSame( 'international-wholesale-distribution-and-logist_1c20f', $second );
	}

	/**
	 * Test the fallback base is used when the local part sanitizes to nothing.
	 */
	public function test_falls_back_to_workos_user_when_local_part_empty(): void {
		$this->assertSame( 'workos_user', $this->generate( '@example.com' ) );
	}
}
