<?php
/**
 * Tests for the MFA factor service and helpers.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Mfa\FactorService;
use WorkOS\User;
use WP_Error;

/**
 * Enroll, confirm, list, delete, reset and the require flag, against
 * WorkOS responses shaped like the real API.
 */
class MfaFactorServiceTest extends WPTestCase {

	/**
	 * Service under test.
	 *
	 * @var FactorService
	 */
	private FactorService $service;

	/**
	 * Linked test user.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Captured HTTP calls.
	 *
	 * @var array
	 */
	private array $captured = [];

	/**
	 * Mocked responses keyed by "METHOD url-needle".
	 *
	 * @var array<string, array>
	 */
	private array $responses = [];

	/**
	 * Hooks fired during the test.
	 *
	 * @var array
	 */
	private array $fired = [];

	public function setUp(): void {
		parent::setUp();

		\WorkOS\Config::set_active_environment( 'production' );
		update_option(
			'workos_production',
			[
				'api_key'        => 'sk_test_fake',
				'client_id'      => 'client_fake',
				'environment_id' => 'environment_test',
			]
		);
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );

		$this->service = new FactorService();
		$this->user_id = self::factory()->user->create( [ 'user_email' => 'mfa@example.com' ] );
		update_user_meta( $this->user_id, User::META_WORKOS_ID, 'user_mfa' );

		// Creating the user pushes it to WorkOS; start the capture clean.
		$this->captured = [];

		foreach ( [ 'workos_mfa_enrolled', 'workos_mfa_factor_removed', 'workos_mfa_reset', 'workos_mfa_required_changed' ] as $hook ) {
			add_action(
				$hook,
				function ( ...$args ) use ( $hook ) {
					$this->fired[] = [ $hook, $args ];
				},
				10,
				2
			);
		}
	}

	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10 );
		foreach ( [ 'workos_mfa_enrolled', 'workos_mfa_factor_removed', 'workos_mfa_reset', 'workos_mfa_required_changed', 'workos_mfa_totp_issuer' ] as $hook ) {
			remove_all_filters( $hook );
		}
		$this->captured  = [];
		$this->responses = [];
		$this->fired     = [];

		wp_set_current_user( 0 );
		delete_option( 'workos_production' );
		\WorkOS\Config::set_active_environment( 'staging' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		parent::tearDown();
	}

	public function intercept_http( $preempt, array $args, string $url ) {
		if ( ! str_contains( $url, 'api.workos.com' ) ) {
			return $preempt;
		}

		$method           = $args['method'] ?? 'GET';
		$this->captured[] = [
			'method' => $method,
			'url'    => $url,
			'body'   => $args['body'] ?? '',
		];

		foreach ( $this->responses as $key => $resp ) {
			[ $want_method, $needle ] = explode( ' ', $key, 2 );
			if ( $want_method === $method && str_contains( $url, $needle ) ) {
				return $resp;
			}
		}

		return [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'body'     => '{}',
		];
	}

	// ------------------------------- Enrollment -------------------------------

	public function test_start_enrollment_creates_factor_and_remembers_it_unconfirmed(): void {
		$this->queue( 'POST /user_management/users/user_mfa/auth_factors', $this->enroll_response( 'auth_factor_new' ), 201 );

		$result = $this->service->start_enrollment( $this->user_id );

		$this->assertIsArray( $result );
		$this->assertSame( 'auth_factor_new', $result['factor_id'] );
		$this->assertSame( 'auth_challenge_new', $result['challenge_id'] );
		$this->assertSame( 'SECRET', $result['secret'] );
		$this->assertSame( 'auth_factor_new', $this->service->pending_factor( $this->user_id )['factor_id'] );
		$this->assertFalse( $this->service->is_enabled( $this->user_id ) );

		$body = json_decode( $this->captured[0]['body'], true );
		$this->assertSame( 'totp', $body['type'] );
		$this->assertSame( 'mfa@example.com', $body['totp_user'] );
	}

	public function test_start_enrollment_uses_issuer_filter(): void {
		add_filter( 'workos_mfa_totp_issuer', static fn() => 'Nexcess Portal' );
		$this->queue( 'POST /user_management/users/user_mfa/auth_factors', $this->enroll_response( 'auth_factor_new' ), 201 );

		$this->service->start_enrollment( $this->user_id );

		$this->assertSame( 'Nexcess Portal', json_decode( $this->captured[0]['body'], true )['totp_issuer'] );
	}

	public function test_restarting_enrollment_deletes_the_unfinished_factor(): void {
		update_user_meta(
			$this->user_id,
			User::META_MFA_PENDING_FACTOR,
			[
				'factor_id' => 'auth_factor_old',
				'created'   => time(),
			]
		);
		$this->queue( 'POST /user_management/users/user_mfa/auth_factors', $this->enroll_response( 'auth_factor_new' ), 201 );

		$this->service->start_enrollment( $this->user_id );

		$this->assertSame( 'DELETE', $this->captured[0]['method'] );
		$this->assertStringContainsString( '/auth/factors/auth_factor_old', $this->captured[0]['url'] );
		$this->assertSame( 'auth_factor_new', $this->service->pending_factor( $this->user_id )['factor_id'] );
	}

	public function test_unlinked_user_gets_409(): void {
		$unlinked       = self::factory()->user->create();
		$this->captured = [];

		$result = $this->service->start_enrollment( $unlinked );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'workos_mfa_user_not_linked', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertEmpty( $this->captured );
	}

	public function test_confirm_with_valid_code_enables_mfa_and_fires_hook(): void {
		$this->set_pending( 'auth_factor_new' );
		$this->queue( 'POST /auth/challenges/auth_challenge_new/verify', [ 'challenge' => [ 'id' => 'auth_challenge_new' ], 'valid' => true ] );
		$this->queue( 'GET /user_management/users/user_mfa/auth_factors', [ 'data' => [ [ 'id' => 'auth_factor_new', 'type' => 'totp' ] ] ] );

		$result = $this->service->confirm_enrollment( $this->user_id, 'auth_factor_new', '123 456', 'auth_challenge_new' );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['enabled'] );
		$this->assertSame( 'auth_factor_new', $result['factors'][0]['id'] );
		$this->assertNull( $this->service->pending_factor( $this->user_id ) );
		$this->assertSame( 'workos_mfa_enrolled', $this->fired[0][0] );
		$this->assertSame( $this->user_id, $this->fired[0][1][0] );
		$this->assertSame( '123456', json_decode( $this->captured[0]['body'], true )['code'] );
	}

	public function test_confirm_creates_challenge_when_none_given(): void {
		$this->set_pending( 'auth_factor_new' );
		$this->queue( 'POST /auth/factors/auth_factor_new/challenge', [ 'id' => 'auth_challenge_fresh' ] );
		$this->queue( 'POST /auth/challenges/auth_challenge_fresh/verify', [ 'valid' => true ] );
		$this->queue( 'GET /user_management/users/user_mfa/auth_factors', [ 'data' => [ [ 'id' => 'auth_factor_new', 'type' => 'totp' ] ] ] );

		$this->service->confirm_enrollment( $this->user_id, 'auth_factor_new', '123456' );

		$this->assertStringContainsString( '/auth/factors/auth_factor_new/challenge', $this->captured[0]['url'] );
		$this->assertStringContainsString( '/auth/challenges/auth_challenge_fresh/verify', $this->captured[1]['url'] );
		$this->assertTrue( $this->service->is_enabled( $this->user_id ) );
	}

	/**
	 * @return array<string, array{array, int}>
	 */
	public function wrong_code_responses(): array {
		return [
			'valid false' => [ [ 'valid' => false ], 200 ],
			'4xx error'   => [ [ 'code' => 'authentication_challenge_failed' ], 400 ],
		];
	}

	/**
	 * @dataProvider wrong_code_responses
	 */
	public function test_confirm_with_wrong_code_keeps_mfa_off( array $body, int $status ): void {
		$this->set_pending( 'auth_factor_new' );
		$this->queue( 'POST /auth/challenges/auth_challenge_new/verify', $body, $status );

		$result = $this->service->confirm_enrollment( $this->user_id, 'auth_factor_new', '000000', 'auth_challenge_new' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'workos_mfa_invalid_code', $result->get_error_code() );
		$this->assertFalse( $this->service->is_enabled( $this->user_id ) );
		$this->assertSame( 'auth_factor_new', $this->service->pending_factor( $this->user_id )['factor_id'] );
		$this->assertEmpty( $this->fired );
	}

	public function test_confirm_rejects_malformed_code_without_calling_workos(): void {
		$this->set_pending( 'auth_factor_new' );

		$result = $this->service->confirm_enrollment( $this->user_id, 'auth_factor_new', '12ab', 'auth_challenge_new' );

		$this->assertSame( 'workos_mfa_invalid_code', $result->get_error_code() );
		$this->assertEmpty( $this->captured );
	}

	public function test_confirm_rejects_factor_that_is_not_pending(): void {
		$this->set_pending( 'auth_factor_new' );

		$result = $this->service->confirm_enrollment( $this->user_id, 'auth_factor_someone_else', '123456', 'auth_challenge_new' );

		$this->assertSame( 'workos_mfa_factor_not_found', $result->get_error_code() );
		$this->assertEmpty( $this->captured );
	}

	// --------------------------------- Listing ---------------------------------

	public function test_list_hides_pending_factor_and_reconciles_enabled_flag(): void {
		$this->set_pending( 'auth_factor_pending' );
		update_user_meta( $this->user_id, User::META_MFA_ENABLED, '1' );
		$this->queue(
			'GET /user_management/users/user_mfa/auth_factors',
			[ 'data' => [ [ 'id' => 'auth_factor_pending', 'type' => 'totp' ] ] ]
		);

		$factors = $this->service->list_factors( $this->user_id );

		$this->assertSame( [], $factors );
		$this->assertFalse( $this->service->is_enabled( $this->user_id ), 'Only a pending factor in WorkOS: MFA is off.' );
	}

	public function test_list_turns_flag_on_for_factor_added_outside_wordpress(): void {
		$this->queue(
			'GET /user_management/users/user_mfa/auth_factors',
			[ 'data' => [ [ 'id' => 'auth_factor_1', 'type' => 'totp', 'created_at' => '2026-10-18T00:00:00Z' ] ] ]
		);

		$factors = $this->service->list_factors( $this->user_id );

		$this->assertSame( [ [ 'id' => 'auth_factor_1', 'type' => 'totp', 'created' => '2026-10-18T00:00:00Z' ] ], $factors );
		$this->assertTrue( $this->service->is_enabled( $this->user_id ) );
	}

	public function test_list_deletes_stale_pending_factor(): void {
		update_user_meta(
			$this->user_id,
			User::META_MFA_PENDING_FACTOR,
			[
				'factor_id' => 'auth_factor_stale',
				'created'   => time() - FactorService::PENDING_FACTOR_TTL - 60,
			]
		);
		$this->queue( 'GET /user_management/users/user_mfa/auth_factors', [ 'data' => [ [ 'id' => 'auth_factor_stale', 'type' => 'totp' ] ] ] );

		$this->service->list_factors( $this->user_id );

		$deletes = array_filter( $this->captured, static fn( $c ) => 'DELETE' === $c['method'] );
		$this->assertCount( 1, $deletes );
		$this->assertNull( $this->service->pending_factor( $this->user_id ) );
	}

	// ----------------------------- Delete and reset -----------------------------

	public function test_delete_owned_factor_turns_mfa_off_when_last(): void {
		update_user_meta( $this->user_id, User::META_MFA_ENABLED, '1' );
		$this->queue( 'GET /user_management/users/user_mfa/auth_factors', [ 'data' => [ [ 'id' => 'auth_factor_1', 'type' => 'totp' ] ] ] );

		$result = $this->service->delete_factor( $this->user_id, 'auth_factor_1' );

		$this->assertTrue( $result );
		$this->assertFalse( $this->service->is_enabled( $this->user_id ) );
		$this->assertSame( [ 'workos_mfa_factor_removed', [ $this->user_id, 'auth_factor_1' ] ], $this->fired[0] );
	}

	public function test_delete_other_users_factor_is_404_and_deletes_nothing(): void {
		$this->queue( 'GET /user_management/users/user_mfa/auth_factors', [ 'data' => [ [ 'id' => 'auth_factor_mine', 'type' => 'totp' ] ] ] );

		$result = $this->service->delete_factor( $this->user_id, 'auth_factor_theirs' );

		$this->assertSame( 'workos_mfa_factor_not_found', $result->get_error_code() );
		$this->assertEmpty( array_filter( $this->captured, static fn( $c ) => 'DELETE' === $c['method'] ) );
	}

	public function test_reset_deletes_every_factor(): void {
		update_user_meta( $this->user_id, User::META_MFA_ENABLED, '1' );
		$this->set_pending( 'auth_factor_pending' );
		$this->queue(
			'GET /user_management/users/user_mfa/auth_factors',
			[ 'data' => [ [ 'id' => 'auth_factor_1' ], [ 'id' => 'auth_factor_pending' ] ] ]
		);

		$removed = $this->service->reset( $this->user_id );

		$this->assertSame( 2, $removed );
		$this->assertFalse( $this->service->is_enabled( $this->user_id ) );
		$this->assertNull( $this->service->pending_factor( $this->user_id ) );
		$this->assertSame( [ 'workos_mfa_reset', [ $this->user_id, 2 ] ], $this->fired[0] );
	}

	// ------------------------------ Require flag ------------------------------

	public function test_set_required_fires_only_on_change(): void {
		$this->assertTrue( $this->service->set_required( $this->user_id, true ) );
		$this->assertFalse( $this->service->set_required( $this->user_id, true ) );
		$this->assertTrue( $this->service->is_required_flag( $this->user_id ) );
		$this->assertTrue( $this->service->set_required( $this->user_id, false ) );

		$this->assertSame(
			[
				[ 'workos_mfa_required_changed', [ $this->user_id, true ] ],
				[ 'workos_mfa_required_changed', [ $this->user_id, false ] ],
			],
			$this->fired
		);
	}

	// --------------------------------- Helpers ---------------------------------

	public function test_helpers_default_to_current_user(): void {
		wp_set_current_user( $this->user_id );
		update_user_meta( $this->user_id, User::META_MFA_ENABLED, '1' );
		update_user_meta( $this->user_id, User::META_MFA_REQUIRED, '1' );

		$this->assertTrue( workos_mfa_is_enabled() );
		$this->assertTrue( workos_mfa_is_required() );
	}

	public function test_set_required_helper_rejects_unknown_user(): void {
		$result = workos_mfa_set_required( 999999, true );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'workos_mfa_invalid_user', $result->get_error_code() );
	}

	public function test_set_required_helper_sets_flag(): void {
		$this->assertTrue( workos_mfa_set_required( $this->user_id, true ) );
		$this->assertTrue( workos_mfa_is_required( $this->user_id ) );
	}

	public function test_decision_helper_returns_none_for_logged_out_visitor(): void {
		$this->assertSame( 'none', workos_mfa_decision() );
	}

	// -------------------------------- Fixtures --------------------------------

	private function set_pending( string $factor_id ): void {
		update_user_meta(
			$this->user_id,
			User::META_MFA_PENDING_FACTOR,
			[
				'factor_id' => $factor_id,
				'created'   => time(),
			]
		);
	}

	private function enroll_response( string $factor_id ): array {
		return [
			'authentication_factor'    => [
				'object' => 'authentication_factor',
				'id'     => $factor_id,
				'type'   => 'totp',
				'totp'   => [
					'qr_code' => 'data:image/png;base64,AAA',
					'secret'  => 'SECRET',
					'uri'     => 'otpauth://totp/Site:mfa@example.com?secret=SECRET',
				],
			],
			'authentication_challenge' => [
				'object' => 'authentication_challenge',
				'id'     => 'auth_challenge_new',
			],
		];
	}

	private function queue( string $key, array $body, int $code = 200 ): void {
		$this->responses[ $key ] = [
			'response' => [ 'code' => $code, 'message' => 'OK' ],
			'body'     => wp_json_encode( $body ),
		];
	}
}
