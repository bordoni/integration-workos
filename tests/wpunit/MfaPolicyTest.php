<?php
/**
 * Tests for the MFA policy table.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Auth\AuthKit\Profile;
use WorkOS\Mfa\Policy;
use WorkOS\Mfa\Settings;
use WorkOS\User;

/**
 * Every row of the decision table, plus the mode, role and filter inputs.
 */
class MfaPolicyTest extends WPTestCase {

	/**
	 * Policy under test.
	 *
	 * @var Policy
	 */
	private Policy $policy;

	/**
	 * Test user.
	 *
	 * @var int
	 */
	private int $user_id;

	public function setUp(): void {
		parent::setUp();

		\WorkOS\Config::set_active_environment( 'production' );
		update_option( 'workos_production', [ Settings::OPTION_MODE => Settings::MODE_PLUGIN ] );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$this->policy  = new Policy( new Settings() );
		$this->user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
	}

	public function tearDown(): void {
		remove_all_filters( 'workos_mfa_mode' );
		remove_all_filters( 'workos_mfa_decision' );
		remove_all_filters( 'workos_mfa_required_for_user' );
		delete_option( 'workos_production' );
		\WorkOS\Config::set_active_environment( 'staging' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		parent::tearDown();
	}

	/**
	 * @return array<string, array{string, bool, bool, string}>
	 */
	public function table_provider(): array {
		return [
			'never, enrolled'                 => [ Profile::MFA_ENFORCE_NEVER, true, false, Policy::BLOCK ],
			'never, required'                 => [ Profile::MFA_ENFORCE_NEVER, false, true, Policy::BLOCK ],
			'never, neither'                  => [ Profile::MFA_ENFORCE_NEVER, false, false, Policy::NONE ],
			'if_required, enrolled'           => [ Profile::MFA_ENFORCE_IF_REQUIRED, true, false, Policy::CHALLENGE ],
			'if_required, enrolled+required'  => [ Profile::MFA_ENFORCE_IF_REQUIRED, true, true, Policy::CHALLENGE ],
			'if_required, required'           => [ Profile::MFA_ENFORCE_IF_REQUIRED, false, true, Policy::ENROLL ],
			'if_required, neither'            => [ Profile::MFA_ENFORCE_IF_REQUIRED, false, false, Policy::NONE ],
			'always, enrolled'                => [ Profile::MFA_ENFORCE_ALWAYS, true, false, Policy::CHALLENGE ],
			'always, not enrolled'            => [ Profile::MFA_ENFORCE_ALWAYS, false, false, Policy::ENROLL ],
			'always, required not enrolled'   => [ Profile::MFA_ENFORCE_ALWAYS, false, true, Policy::ENROLL ],
		];
	}

	/**
	 * @dataProvider table_provider
	 */
	public function test_decision_table( string $enforce, bool $enrolled, bool $required, string $expected ): void {
		if ( $enrolled ) {
			update_user_meta( $this->user_id, User::META_MFA_ENABLED, '1' );
		}
		if ( $required ) {
			update_user_meta( $this->user_id, User::META_MFA_REQUIRED, '1' );
		}

		$this->assertSame( $expected, $this->policy->decide( $this->user_id, $this->profile( $enforce ) ) );
	}

	public function test_profile_without_totp_acts_like_never(): void {
		update_user_meta( $this->user_id, User::META_MFA_ENABLED, '1' );

		$profile = $this->profile( Profile::MFA_ENFORCE_ALWAYS, [ Profile::FACTOR_SMS ] );

		$this->assertSame( Policy::BLOCK, $this->policy->decide( $this->user_id, $profile ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function non_plugin_modes(): array {
		return [
			'workos' => [ Settings::MODE_WORKOS ],
			'off'    => [ Settings::MODE_OFF ],
		];
	}

	/**
	 * @dataProvider non_plugin_modes
	 */
	public function test_only_plugin_mode_decides( string $mode ): void {
		update_option( 'workos_production', [ Settings::OPTION_MODE => $mode ] );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();
		update_user_meta( $this->user_id, User::META_MFA_ENABLED, '1' );

		$this->assertSame( Policy::NONE, $this->policy->decide( $this->user_id, $this->profile( Profile::MFA_ENFORCE_ALWAYS ) ) );
	}

	public function test_mode_filter_overrides_option_and_rejects_unknown_values(): void {
		add_filter( 'workos_mfa_mode', static fn() => Settings::MODE_OFF );
		$this->assertSame( Settings::MODE_OFF, ( new Settings() )->mode() );

		remove_all_filters( 'workos_mfa_mode' );
		add_filter( 'workos_mfa_mode', static fn() => 'sometimes' );
		$this->assertSame( Settings::MODE_PLUGIN, ( new Settings() )->mode() );
	}

	public function test_required_role_requires_mfa(): void {
		update_option(
			'workos_production',
			[
				Settings::OPTION_MODE           => Settings::MODE_PLUGIN,
				Settings::OPTION_REQUIRED_ROLES => [ 'editor' ],
			]
		);
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$this->assertFalse( $this->policy->is_required( $this->user_id ) );

		( new \WP_User( $this->user_id ) )->set_role( 'editor' );

		$this->assertTrue( $this->policy->is_required( $this->user_id ) );
		$this->assertSame( Policy::ENROLL, $this->policy->decide( $this->user_id, $this->profile( Profile::MFA_ENFORCE_IF_REQUIRED ) ) );
	}

	public function test_required_filter_requires_mfa(): void {
		$user_id = $this->user_id;
		add_filter(
			'workos_mfa_required_for_user',
			static fn( bool $required, int $id ) => $required || $id === $user_id,
			10,
			2
		);

		$this->assertSame( Policy::ENROLL, $this->policy->decide( $this->user_id, $this->profile( Profile::MFA_ENFORCE_IF_REQUIRED ) ) );
	}

	public function test_decision_filter_wins_and_ignores_unknown_values(): void {
		add_filter( 'workos_mfa_decision', static fn() => Policy::CHALLENGE );
		$this->assertSame( Policy::CHALLENGE, $this->policy->decide( $this->user_id, $this->profile( Profile::MFA_ENFORCE_IF_REQUIRED ) ) );

		remove_all_filters( 'workos_mfa_decision' );
		add_filter( 'workos_mfa_decision', static fn() => 'maybe' );
		$this->assertSame( Policy::NONE, $this->policy->decide( $this->user_id, $this->profile( Profile::MFA_ENFORCE_IF_REQUIRED ) ) );
	}

	public function test_decision_filter_receives_context(): void {
		update_user_meta( $this->user_id, User::META_MFA_ENABLED, '1' );
		$seen = null;
		add_filter(
			'workos_mfa_decision',
			static function ( $decision, $user_id, $profile, $context ) use ( &$seen ) {
				$seen = $context;
				return $decision;
			},
			10,
			4
		);

		$this->policy->decide( $this->user_id, $this->profile( Profile::MFA_ENFORCE_IF_REQUIRED ) );

		$this->assertSame(
			[
				'mode'     => Settings::MODE_PLUGIN,
				'enrolled' => true,
				'required' => false,
			],
			$seen
		);
	}

	private function profile( string $enforce, array $factors = [ Profile::FACTOR_TOTP ] ): Profile {
		return Profile::from_array(
			[
				'slug'  => 'members',
				'title' => 'Members',
				'mfa'   => [
					'enforce' => $enforce,
					'factors' => $factors,
				],
			]
		);
	}
}
