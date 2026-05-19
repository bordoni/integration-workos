<?php
/**
 * Tests for the password-reset RedirectValidator helper.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Auth\AuthKit\Profile;
use WorkOS\Auth\PasswordResetAdmin\RedirectValidator;

/**
 * Same-host validation + profile fallback behaviour.
 *
 * The validator decides what URL we hand to WorkOS for the password
 * reset email; a regression here lets a caller bounce the user off-site
 * after a successful reset, so the gating logic deserves coverage even
 * though the helper is small.
 */
class PasswordResetAdminRedirectValidatorTest extends WPTestCase {

	/**
	 * Fresh validator instance per test.
	 *
	 * @var RedirectValidator
	 */
	private RedirectValidator $validator;

	/**
	 * Default profile (no post_login_redirect configured).
	 *
	 * @var Profile
	 */
	private Profile $profile_no_default;

	/**
	 * Profile carrying an explicit post_login_redirect.
	 *
	 * @var Profile
	 */
	private Profile $profile_with_default;

	/**
	 * Set up shared fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->validator = new RedirectValidator();

		$this->profile_no_default = Profile::from_array(
			[
				'slug'                => 'no-default',
				'post_login_redirect' => '',
			]
		);

		$this->profile_with_default = Profile::from_array(
			[
				'slug'                => 'with-default',
				'post_login_redirect' => home_url( '/welcome' ),
			]
		);
	}

	/**
	 * Same-host absolute URL is returned unchanged.
	 */
	public function test_validate_accepts_same_host_absolute_url(): void {
		$url = home_url( '/dashboard' );

		$this->assertSame( $url, $this->validator->validate( $url, $this->profile_no_default ) );
	}

	/**
	 * Same-host URL with a query string is accepted.
	 */
	public function test_validate_accepts_same_host_url_with_query(): void {
		$url = home_url( '/dashboard?step=2' );

		$this->assertSame( $url, $this->validator->validate( $url, $this->profile_no_default ) );
	}

	/**
	 * Site-relative paths are resolved against home_url and accepted.
	 */
	public function test_validate_accepts_root_relative_path(): void {
		$result = $this->validator->validate( '/welcome', $this->profile_no_default );

		$this->assertSame( home_url( '/welcome' ), $result );
	}

	/**
	 * Cross-origin URLs fall back to the profile default.
	 */
	public function test_validate_rejects_cross_origin_url(): void {
		$result = $this->validator->validate(
			'https://evil.example/whatever',
			$this->profile_with_default
		);

		$this->assertSame( home_url( '/welcome' ), $result );
	}

	/**
	 * Protocol-relative URLs are treated as cross-origin and rejected.
	 */
	public function test_validate_rejects_protocol_relative_url(): void {
		$result = $this->validator->validate(
			'//evil.example/whatever',
			$this->profile_no_default
		);

		// No profile default → home_url('/').
		$this->assertSame( home_url( '/' ), $result );
	}

	/**
	 * Non-http(s) schemes are rejected.
	 */
	public function test_validate_rejects_non_http_schemes(): void {
		$result = $this->validator->validate(
			'javascript:alert(1)',
			$this->profile_no_default
		);

		$this->assertSame( home_url( '/' ), $result );
	}

	/**
	 * Empty input falls back to the profile's post_login_redirect when set.
	 */
	public function test_validate_falls_back_to_profile_default(): void {
		$result = $this->validator->validate( '', $this->profile_with_default );

		$this->assertSame( home_url( '/welcome' ), $result );
	}

	/**
	 * Empty input + no profile default → home_url('/').
	 */
	public function test_validate_falls_back_to_home_when_no_default(): void {
		$result = $this->validator->validate( '', $this->profile_no_default );

		$this->assertSame( home_url( '/' ), $result );
	}

	/**
	 * `null` input behaves the same as empty string.
	 */
	public function test_validate_accepts_null(): void {
		$result = $this->validator->validate( null, $this->profile_no_default );

		$this->assertSame( home_url( '/' ), $result );
	}

	/**
	 * The fallback() helper is exposed for callers that already know they
	 * don't have a candidate URL.
	 */
	public function test_fallback_returns_profile_default_when_set(): void {
		$this->assertSame(
			home_url( '/welcome' ),
			$this->validator->fallback( $this->profile_with_default )
		);
	}

	/**
	 * fallback() with no profile default → home_url('/').
	 */
	public function test_fallback_returns_home_when_profile_default_empty(): void {
		$this->assertSame(
			home_url( '/' ),
			$this->validator->fallback( $this->profile_no_default )
		);
	}

	/**
	 * A profile default that itself fails validation (cross-origin) is
	 * ignored — the validator does NOT trust stored configuration blindly.
	 */
	public function test_validate_skips_invalid_profile_default(): void {
		$bad_default = Profile::from_array(
			[
				'slug'                => 'bad-default',
				'post_login_redirect' => 'https://evil.example/landing',
			]
		);

		$result = $this->validator->validate( '', $bad_default );

		$this->assertSame( home_url( '/' ), $result );
	}
}
