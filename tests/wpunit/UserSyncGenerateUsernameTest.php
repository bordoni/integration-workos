<?php
/**
 * Tests for UserSync::generate_username().
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use Closure;
use lucatume\WPBrowser\TestCase\WPTestCase;
use ReflectionMethod;
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
		$method = new ReflectionMethod( UserSync::class, 'generate_username' );
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
	 * Collision-ladder scenarios.
	 *
	 * Each case seeds the usernames the email's derivation collides with,
	 * then states the expected outcome — reading top to bottom walks the
	 * full ladder: bare base → 5-hex suffix → 12-hex widening → salted
	 * retries.
	 *
	 * @return array<string, array{0: Closure, 1: string, 2: string}>
	 */
	public function username_scenario_provider(): array {
		$seed = static function ( string ...$logins ): Closure {
			return static function ( self $test ) use ( $logins ): void {
				foreach ( $logins as $login ) {
					$test->seed_login( $login );
				}
			};
		};

		return [
			'bare local part when base is free'            => [
				$seed(),
				'info@acme-widgets.com',
				'info',
			],
			'5-hex email hash suffix when base is taken'   => [
				$seed( 'info' ),
				'info@acme-widgets.com',
				'info_48f25',
			],
			'widened 12-hex suffix when 5-hex name taken'  => [
				$seed( 'info', 'info_48f25' ),
				'info@acme-widgets.com',
				'info_48f257791ee0',
			],
			'first salted retry when widened name taken'   => [
				$seed( 'info', 'info_48f25', 'info_48f257791ee0' ),
				'info@acme-widgets.com',
				'info_b1dc4def5ca9',
			],
			'second salted retry when first salt taken'    => [
				$seed( 'info', 'info_48f25', 'info_48f257791ee0', 'info_b1dc4def5ca9' ),
				'info@acme-widgets.com',
				'info_023b84c3d9b3',
			],
			'long local part capped to 47 chars'           => [
				$seed(),
				'international-wholesale-distribution-and-logistics-coordination@globex.test',
				'international-wholesale-distribution-and-logist',
			],
			'widened suffix on capped base lands on 60'    => [
				$seed(
					'international-wholesale-distribution-and-logist',
					'international-wholesale-distribution-and-logist_6a3ae'
				),
				'international-wholesale-distribution-and-logistics-coordination@globex.test',
				'international-wholesale-distribution-and-logist_6a3aeb9adeab',
			],
			'workos_user fallback when local part empty'   => [
				$seed(),
				'@example.com',
				'workos_user',
			],
		];
	}

	/**
	 * Test the generator walks the collision ladder deterministically.
	 *
	 * @dataProvider username_scenario_provider
	 *
	 * @param Closure $arrange  Seeds the usernames the scenario collides with.
	 * @param string  $email    Email to derive a username from.
	 * @param string  $expected Expected username.
	 */
	public function test_derives_expected_username( Closure $arrange, string $email, string $expected ): void {
		$arrange( $this );

		$username = $this->generate( $email );

		$this->assertSame( $expected, $username );
		$this->assertLessThanOrEqual( 60, strlen( $username ) );
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
}
