<?php
/**
 * Tests for LoginCompleter enforcing profile MFA policy.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Auth\AuthKit\LoginCompleter;
use WorkOS\Auth\AuthKit\Profile;

/**
 * Ensures the profile's MFA policy (`mfa.enforce` + `mfa.factors`) is
 * actually applied by LoginCompleter, not just advertised in the admin UI.
 */
class AuthKitLoginCompleterMfaTest extends WPTestCase {

	/**
	 * Completer under test. `remember=false` to avoid cookie headers in tests.
	 *
	 * @var LoginCompleter
	 */
	private LoginCompleter $completer;

	public function setUp(): void {
		parent::setUp();
		$this->completer = new LoginCompleter( false );
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * `mfa.enforce = always` + no pending factor = rejected.
	 *
	 * Closes the gap where a user with zero enrolled factors would
	 * otherwise complete login on a profile the admin flagged as
	 * "MFA always required."
	 */
	public function test_enforce_always_rejects_single_step_success(): void {
		$profile = Profile::from_array(
			[
				'slug'    => 'staff',
				'title'   => 'Staff',
				'mfa'     => [
					'enforce' => Profile::MFA_ENFORCE_ALWAYS,
					'factors' => [ Profile::FACTOR_TOTP ],
				],
			]
		);

		$result = $this->completer->complete(
			[
				'access_token'  => 'eyJ.eyJzdWIiOiJ1c2VyXzEifQ.sig',
				'refresh_token' => 'rt_1',
				'user'          => [ 'id' => 'user_1', 'email' => 'nomfa@example.com' ],
			],
			$profile,
			''
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'workos_authkit_mfa_required', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * `mfa.enforce = if_required` + no pending factor = login completes.
	 */
	public function test_enforce_if_required_allows_single_step_success(): void {
		$profile = Profile::from_array(
			[
				'slug' => 'members',
				'title' => 'Members',
				'mfa'  => [
					'enforce' => Profile::MFA_ENFORCE_IF_REQUIRED,
					'factors' => [ Profile::FACTOR_TOTP ],
				],
			]
		);

		$result = $this->completer->complete(
			[
				'access_token'  => 'eyJ.eyJzdWIiOiJ1c2VyXzIifQ.sig',
				'refresh_token' => 'rt_2',
				'user'          => [ 'id' => 'user_2', 'email' => 'ok@example.com' ],
			],
			$profile,
			''
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'user', $result );
		$this->assertSame( 'ok@example.com', $result['user']['email'] );
	}

	/**
	 * WorkOS returns a pending factor whose type is NOT in the profile's
	 * allowlist — rejected instead of being surfaced to the MFA step.
	 */
	public function test_pending_factor_not_in_allowlist_is_rejected(): void {
		$profile = Profile::from_array(
			[
				'slug'  => 'totp-only',
				'title' => 'TOTP Only',
				'mfa'   => [
					'enforce' => Profile::MFA_ENFORCE_IF_REQUIRED,
					'factors' => [ Profile::FACTOR_TOTP ],
				],
			]
		);

		$result = $this->completer->complete(
			[
				'pending_authentication_token' => 'pat_1',
				'authentication_factor'        => [ 'id' => 'factor_sms', 'type' => 'sms' ],
			],
			$profile
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'workos_authkit_factor_not_allowed', $result->get_error_code() );
	}

	/**
	 * Pending factor whose type IS in the allowlist surfaces an MFA step.
	 */
	public function test_pending_factor_in_allowlist_surfaces_mfa_step(): void {
		$profile = Profile::from_array(
			[
				'slug'  => 'flexible',
				'title' => 'Flexible',
				'mfa'   => [
					'enforce' => Profile::MFA_ENFORCE_IF_REQUIRED,
					'factors' => [ Profile::FACTOR_TOTP, Profile::FACTOR_SMS ],
				],
			]
		);

		$result = $this->completer->complete(
			[
				'pending_authentication_token' => 'pat_2',
				'authentication_factor'        => [ 'id' => 'factor_totp', 'type' => 'totp' ],
			],
			$profile
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['mfa_required'] );
		$this->assertSame( 'pat_2', $result['pending_authentication_token'] );
	}
}
