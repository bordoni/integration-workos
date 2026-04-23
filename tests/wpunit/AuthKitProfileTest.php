<?php
/**
 * Tests for the Login Profile value object.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Auth\AuthKit\Profile;

/**
 * Profile schema validation, defaults, and accessor coverage.
 */
class AuthKitProfileTest extends WPTestCase {

	/**
	 * Defaults produce a usable "default" profile with the reserved slug.
	 */
	public function test_defaults_produce_reserved_default_slug(): void {
		$profile = Profile::defaults();

		$this->assertSame( Profile::DEFAULT_SLUG, $profile->get_slug() );
		$this->assertSame( Profile::MODE_CUSTOM, $profile->get_mode() );
		$this->assertTrue( $profile->is_custom_mode() );
		$this->assertNotEmpty( $profile->get_methods() );
		$this->assertContains( Profile::METHOD_PASSWORD, $profile->get_methods() );
	}

	/**
	 * Unknown method strings are dropped by from_array().
	 */
	public function test_from_array_filters_unknown_methods(): void {
		$profile = Profile::from_array(
			[
				'slug'    => 'partner',
				'methods' => [ Profile::METHOD_PASSWORD, 'oauth_xyz', 'magic_code' ],
			]
		);

		$this->assertSame(
			[ Profile::METHOD_PASSWORD, Profile::METHOD_MAGIC_CODE ],
			$profile->get_methods()
		);
	}

	/**
	 * An empty methods array falls back to password + magic_code defaults.
	 */
	public function test_from_array_applies_method_fallback_when_empty(): void {
		$profile = Profile::from_array(
			[
				'slug'    => 'partner',
				'methods' => [],
			]
		);

		$this->assertSame(
			[ Profile::METHOD_PASSWORD, Profile::METHOD_MAGIC_CODE ],
			$profile->get_methods()
		);
	}

	/**
	 * Invalid MFA enforce values fall back to if_required.
	 */
	public function test_from_array_falls_back_on_invalid_mfa_enforce(): void {
		$profile = Profile::from_array(
			[
				'slug' => 'partner',
				'mfa'  => [ 'enforce' => 'bogus', 'factors' => [ Profile::FACTOR_TOTP ] ],
			]
		);

		$this->assertSame( Profile::MFA_ENFORCE_IF_REQUIRED, $profile->get_mfa()['enforce'] );
	}

	/**
	 * Invalid modes fall back to custom.
	 */
	public function test_from_array_falls_back_on_invalid_mode(): void {
		$profile = Profile::from_array(
			[
				'slug' => 'partner',
				'mode' => 'nonsense',
			]
		);

		$this->assertSame( Profile::MODE_CUSTOM, $profile->get_mode() );
	}

	/**
	 * Slugs are normalized via sanitize_title (lowercased, dashed).
	 */
	public function test_from_array_sanitizes_slug(): void {
		$profile = Profile::from_array(
			[
				'slug' => 'Partner Portal!',
			]
		);

		$this->assertSame( 'partner-portal', $profile->get_slug() );
	}

	/**
	 * Malformed organization_id values are stripped.
	 */
	public function test_from_array_rejects_malformed_organization_id(): void {
		$profile = Profile::from_array(
			[
				'slug'            => 'partner',
				'organization_id' => 'not-an-org',
			]
		);

		$this->assertSame( '', $profile->get_organization_id() );
	}

	/**
	 * Well-formed org_ ids pass through unchanged.
	 */
	public function test_from_array_accepts_wellformed_organization_id(): void {
		$profile = Profile::from_array(
			[
				'slug'            => 'partner',
				'organization_id' => 'org_01HXYZABC123',
			]
		);

		$this->assertSame( 'org_01HXYZABC123', $profile->get_organization_id() );
	}

	/**
	 * Non-hex primary colors are stripped.
	 */
	public function test_from_array_rejects_non_hex_primary_color(): void {
		$profile = Profile::from_array(
			[
				'slug'     => 'partner',
				'branding' => [ 'primary_color' => 'red' ],
			]
		);

		$this->assertSame( '', $profile->get_branding()['primary_color'] );
	}

	/**
	 * has_method reports correctly.
	 */
	public function test_has_method(): void {
		$profile = Profile::from_array(
			[
				'slug'    => 'partner',
				'methods' => [ Profile::METHOD_MAGIC_CODE, Profile::METHOD_OAUTH_GOOGLE ],
			]
		);

		$this->assertTrue( $profile->has_method( Profile::METHOD_MAGIC_CODE ) );
		$this->assertTrue( $profile->has_method( Profile::METHOD_OAUTH_GOOGLE ) );
		$this->assertFalse( $profile->has_method( Profile::METHOD_PASSWORD ) );
	}

	/**
	 * allows_factor reports correctly.
	 */
	public function test_allows_factor(): void {
		$profile = Profile::from_array(
			[
				'slug' => 'partner',
				'mfa'  => [
					'enforce' => Profile::MFA_ENFORCE_IF_REQUIRED,
					'factors' => [ Profile::FACTOR_TOTP, Profile::FACTOR_WEBAUTHN ],
				],
			]
		);

		$this->assertTrue( $profile->allows_factor( Profile::FACTOR_TOTP ) );
		$this->assertTrue( $profile->allows_factor( Profile::FACTOR_WEBAUTHN ) );
		$this->assertFalse( $profile->allows_factor( Profile::FACTOR_SMS ) );
	}

	/**
	 * to_array / from_array round-trip preserves all keys.
	 */
	public function test_to_array_round_trip(): void {
		$input = [
			'id'                  => 42,
			'slug'                => 'members',
			'title'               => 'Members Area',
			'methods'             => [ Profile::METHOD_PASSWORD, Profile::METHOD_OAUTH_GOOGLE ],
			'organization_id'     => 'org_01ABC',
			'signup'              => [ 'enabled' => true, 'require_invite' => false ],
			'invite_flow'         => true,
			'password_reset_flow' => true,
			'mfa'                 => [
				'enforce' => Profile::MFA_ENFORCE_ALWAYS,
				'factors' => [ Profile::FACTOR_TOTP, Profile::FACTOR_SMS ],
			],
			'branding'            => [
				'logo_attachment_id' => 99,
				'primary_color'      => '#ff0066',
				'heading'            => 'Welcome',
				'subheading'         => 'Back',
			],
			'post_login_redirect' => '/dashboard',
			'mode'                => Profile::MODE_CUSTOM,
		];

		$serialized = Profile::from_array( $input )->to_array();

		$this->assertSame( 42, $serialized['id'] );
		$this->assertSame( 'members', $serialized['slug'] );
		$this->assertSame( 'Members Area', $serialized['title'] );
		$this->assertSame( [ Profile::METHOD_PASSWORD, Profile::METHOD_OAUTH_GOOGLE ], $serialized['methods'] );
		$this->assertSame( 'org_01ABC', $serialized['organization_id'] );
		$this->assertTrue( $serialized['signup']['enabled'] );
		$this->assertFalse( $serialized['signup']['require_invite'] );
		$this->assertSame( Profile::MFA_ENFORCE_ALWAYS, $serialized['mfa']['enforce'] );
		$this->assertSame( [ Profile::FACTOR_TOTP, Profile::FACTOR_SMS ], $serialized['mfa']['factors'] );
		$this->assertSame( '#ff0066', $serialized['branding']['primary_color'] );
		$this->assertSame( '/dashboard', $serialized['post_login_redirect'] );
		$this->assertSame( Profile::MODE_CUSTOM, $serialized['mode'] );
	}

	/**
	 * with_id returns a new instance rather than mutating.
	 */
	public function test_with_id_is_immutable(): void {
		$profile = Profile::defaults();
		$clone   = $profile->with_id( 7 );

		$this->assertSame( 0, $profile->get_id() );
		$this->assertSame( 7, $clone->get_id() );
	}
}
