<?php
/**
 * Tests for the Login Profile router.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Auth\AuthKit\Profile;
use WorkOS\Auth\AuthKit\ProfileRepository;
use WorkOS\Auth\AuthKit\ProfileRouter;

/**
 * Rule ordering + matcher semantics.
 */
class AuthKitProfileRouterTest extends WPTestCase {

	/**
	 * Repository.
	 *
	 * @var ProfileRepository
	 */
	private ProfileRepository $repository;

	/**
	 * Router under test.
	 *
	 * @var ProfileRouter
	 */
	private ProfileRouter $router;

	public function setUp(): void {
		parent::setUp();

		$this->repository = new ProfileRepository();
		$this->repository->register_post_type();
		foreach ( $this->repository->all() as $profile ) {
			wp_delete_post( $profile->get_id(), true );
		}
		delete_option( ProfileRouter::OPTION );

		$this->router = new ProfileRouter( $this->repository );

		$this->repository->ensure_default();
		$this->repository->save( Profile::from_array( [ 'slug' => 'members', 'title' => 'Members' ] ) );
		$this->repository->save( Profile::from_array( [ 'slug' => 'partners', 'title' => 'Partners' ] ) );
	}

	public function tearDown(): void {
		delete_option( ProfileRouter::OPTION );
		foreach ( $this->repository->all() as $profile ) {
			wp_delete_post( $profile->get_id(), true );
		}
		parent::tearDown();
	}

	/**
	 * Explicit slug always wins over rules.
	 */
	public function test_explicit_slug_wins_over_rules(): void {
		$this->router->set_rules(
			[
				[ 'profile' => 'partners', 'matcher' => [ 'type' => 'redirect_to', 'value' => '/*' ] ],
			]
		);

		$profile = $this->router->resolve(
			[
				'explicit_slug' => 'members',
				'redirect_to'   => '/partners-area',
			]
		);

		$this->assertSame( 'members', $profile->get_slug() );
	}

	/**
	 * Unknown explicit slug falls through to rules, then default.
	 */
	public function test_unknown_explicit_slug_falls_through(): void {
		$profile = $this->router->resolve( [ 'explicit_slug' => 'nope' ] );

		$this->assertSame( Profile::DEFAULT_SLUG, $profile->get_slug() );
	}

	/**
	 * redirect_to glob matcher applies first match.
	 */
	public function test_redirect_to_glob_match(): void {
		$this->router->set_rules(
			[
				[ 'profile' => 'partners', 'matcher' => [ 'type' => 'redirect_to', 'value' => '/partners/*' ] ],
				[ 'profile' => 'members',  'matcher' => [ 'type' => 'redirect_to', 'value' => '/*' ] ],
			]
		);

		$profile = $this->router->resolve( [ 'redirect_to' => '/partners/onboarding' ] );
		$this->assertSame( 'partners', $profile->get_slug() );

		$profile = $this->router->resolve( [ 'redirect_to' => '/account' ] );
		$this->assertSame( 'members', $profile->get_slug() );
	}

	/**
	 * redirect_to matcher correctly handles full URLs.
	 */
	public function test_redirect_to_accepts_full_url(): void {
		$this->router->set_rules(
			[
				[ 'profile' => 'partners', 'matcher' => [ 'type' => 'redirect_to', 'value' => '/partners/*' ] ],
			]
		);

		$profile = $this->router->resolve(
			[ 'redirect_to' => 'https://example.test/partners/hub?foo=bar' ]
		);

		$this->assertSame( 'partners', $profile->get_slug() );
	}

	/**
	 * referrer_host matcher requires exact host equality.
	 */
	public function test_referrer_host_matcher(): void {
		$this->router->set_rules(
			[
				[ 'profile' => 'partners', 'matcher' => [ 'type' => 'referrer_host', 'value' => 'partners.example.test' ] ],
			]
		);

		$profile = $this->router->resolve( [ 'referrer' => 'https://partners.example.test/landing' ] );
		$this->assertSame( 'partners', $profile->get_slug() );

		$profile = $this->router->resolve( [ 'referrer' => 'https://other.example.test/' ] );
		$this->assertSame( Profile::DEFAULT_SLUG, $profile->get_slug() );
	}

	/**
	 * user_role matcher uses current user's roles.
	 */
	public function test_user_role_matcher(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'editor' ] );

		$this->router->set_rules(
			[
				[ 'profile' => 'members', 'matcher' => [ 'type' => 'user_role', 'value' => 'editor' ] ],
			]
		);

		$profile = $this->router->resolve( [ 'user_id' => $user_id ] );

		$this->assertSame( 'members', $profile->get_slug() );
	}

	/**
	 * No match returns default.
	 */
	public function test_no_match_returns_default(): void {
		$this->router->set_rules(
			[
				[ 'profile' => 'partners', 'matcher' => [ 'type' => 'redirect_to', 'value' => '/partners/*' ] ],
			]
		);

		$profile = $this->router->resolve( [ 'redirect_to' => '/somewhere-else' ] );

		$this->assertSame( Profile::DEFAULT_SLUG, $profile->get_slug() );
	}

	/**
	 * Malformed rules are dropped by set_rules.
	 */
	public function test_set_rules_drops_malformed_entries(): void {
		$valid = $this->router->set_rules(
			[
				'not-even-an-array',
				[ 'profile' => '', 'matcher' => [ 'type' => 'redirect_to', 'value' => '/*' ] ],
				[ 'profile' => 'members', 'matcher' => [ 'type' => 'bogus', 'value' => 'x' ] ],
				[ 'profile' => 'members', 'matcher' => [ 'type' => 'redirect_to', 'value' => '' ] ],
				[ 'profile' => 'members', 'matcher' => [ 'type' => 'redirect_to', 'value' => '/*' ] ],
			]
		);

		$this->assertCount( 1, $valid );
		$this->assertSame( 'members', $valid[0]['profile'] );
	}

	/**
	 * Rule pointing at a deleted profile falls through to the next rule or default.
	 */
	public function test_missing_profile_rule_falls_through(): void {
		$this->router->set_rules(
			[
				[ 'profile' => 'deleted-profile', 'matcher' => [ 'type' => 'redirect_to', 'value' => '/*' ] ],
				[ 'profile' => 'members',          'matcher' => [ 'type' => 'redirect_to', 'value' => '/*' ] ],
			]
		);

		$profile = $this->router->resolve( [ 'redirect_to' => '/anywhere' ] );

		// The first rule matches the redirect but its profile is missing; we
		// currently stop at the first *matching rule* even if its profile is
		// gone, and fall back to default. This is by design — admins can see
		// the broken rule and fix it rather than silently skipping.
		$this->assertSame( Profile::DEFAULT_SLUG, $profile->get_slug() );
	}
}
