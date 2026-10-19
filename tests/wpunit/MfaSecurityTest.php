<?php
/**
 * Regression tests for the MFA security review fixes.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\App;
use WorkOS\Auth\AuthKit\LoginCompleter;
use WorkOS\Auth\AuthKit\Nonce;
use WorkOS\Auth\AuthKit\Profile;
use WorkOS\Auth\AuthKit\ProfileRepository;
use WorkOS\Auth\AuthKit\Radar;
use WorkOS\Email\AddressCanonicalizer;
use WorkOS\Http\ClientIp;
use WorkOS\Mfa\Admin\UserEditPanel;
use WorkOS\Mfa\FactorService;
use WorkOS\Mfa\PasswordGuard;
use WorkOS\Mfa\PendingLogin;
use WorkOS\Mfa\Policy;
use WorkOS\Mfa\RestApi;
use WorkOS\Mfa\Settings;
use WorkOS\Mfa\VerifiedSessions;
use WorkOS\RateLimit\DatabaseRateLimiter;
use WorkOS\RateLimit\TieredRateLimiter;
use WorkOS\REST\Auth\Mfa;
use WorkOS\REST\Auth\Password;
use WorkOS\Sync\UserSync;
use WorkOS\User;
use WP_REST_Request;
use WP_REST_Response;
use WP_Session_Tokens;

/**
 * One test per attack the security review found, named after the attack.
 */
class MfaSecurityTest extends WPTestCase {

	/**
	 * Profiles.
	 *
	 * @var ProfileRepository
	 */
	private ProfileRepository $repository;

	/**
	 * Nonce minter.
	 *
	 * @var Nonce
	 */
	private Nonce $nonce;

	/**
	 * Linked WP user (subscriber) who signs in.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Administrator.
	 *
	 * @var int
	 */
	private int $admin_id;

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
	 * Mail captured through `pre_wp_mail`.
	 *
	 * @var array
	 */
	private array $mail = [];

	public function setUp(): void {
		parent::setUp();

		\WorkOS\Config::set_active_environment( 'production' );
		update_option(
			'workos_production',
			[
				'api_key'                 => 'sk_test_fake',
				'client_id'               => 'client_fake',
				'environment_id'          => 'environment_test',
				'allow_password_fallback' => true,
			]
		);
		App::container()->get( \WorkOS\Options\Production::class )->reset();

		$this->repository = new ProfileRepository();
		$this->repository->register_post_type();
		foreach ( $this->repository->all() as $profile ) {
			wp_delete_post( $profile->get_id(), true );
		}
		$this->save_profile( 'members', Profile::MFA_ENFORCE_IF_REQUIRED );

		$this->nonce = new Nonce();
		$limiter     = new TieredRateLimiter( new DatabaseRateLimiter() );
		$deps        = [
			$this->repository,
			$this->nonce,
			new Radar(),
			$limiter,
			new ClientIp(),
			new AddressCanonicalizer(),
			new LoginCompleter(),
		];
		$password    = new Password( ...$deps );
		$mfa         = new Mfa( ...$deps );
		$account     = new RestApi( new FactorService(), $limiter );
		add_action( 'rest_api_init', [ $password, 'register_routes' ] );
		add_action( 'rest_api_init', [ $mfa, 'register_routes' ] );
		add_action( 'rest_api_init', [ $account, 'register_routes' ] );
		do_action( 'rest_api_init', rest_get_server() );

		add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );
		add_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10, 2 );

		$this->user_id = (int) wp_create_user( 'mfa_member', 'wp_password_123', 'member@example.com' );
		UserSync::link_user( $this->user_id, [ 'id' => 'user_mfa', 'email' => 'member@example.com' ], 'test' );
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

		$this->captured = [];
		$this->mail     = [];
		wp_set_current_user( 0 );
	}

	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10 );
		remove_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10 );
		remove_all_filters( 'workos_mfa_mode' );
		remove_all_filters( 'workos_mfa_notify_user' );
		unset( $_POST['workos_mfa_required'], $_POST['workos_mfa_reset'], $_COOKIE[ LOGGED_IN_COOKIE ] );
		$this->captured  = [];
		$this->responses = [];
		$this->mail      = [];

		foreach ( $this->repository->all() as $profile ) {
			wp_delete_post( $profile->get_id(), true );
		}

		wp_set_current_user( 0 );
		delete_option( 'workos_production' );
		\WorkOS\Config::set_active_environment( 'staging' );
		App::container()->get( \WorkOS\Options\Production::class )->reset();

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

	public function capture_mail( $short_circuit, array $atts ) {
		$this->mail[] = $atts;
		return true;
	}

	// ------------------------- Admin routes on yourself -------------------------

	public function test_subscriber_cannot_reset_or_unrequire_their_own_mfa(): void {
		update_user_meta( $this->user_id, User::META_MFA_REQUIRED, '1' );
		update_user_meta( $this->user_id, User::META_MFA_ENABLED, '1' );
		wp_set_current_user( $this->user_id );

		$this->assertContains( $this->account( 'PUT', $this->user_id, '/required', [ 'required' => false ] )->get_status(), [ 401, 403 ] );
		$this->assertContains( $this->account( 'POST', $this->user_id, '/reset' )->get_status(), [ 401, 403 ] );
		$this->assertSame( '1', get_user_meta( $this->user_id, User::META_MFA_REQUIRED, true ) );
		$this->assertEmpty( array_filter( $this->captured, static fn( $c ) => 'DELETE' === $c['method'] ) );

		wp_set_current_user( $this->admin_id );
		$this->assertSame( 200, $this->account( 'PUT', $this->user_id, '/required', [ 'required' => false ] )->get_status() );
	}

	// ------------------------- Second authenticator -------------------------

	public function test_hijacked_session_cannot_add_a_second_authenticator_without_a_code(): void {
		wp_set_current_user( $this->user_id );
		$this->factors( [ [ 'id' => 'auth_factor_1', 'type' => 'totp' ] ] );
		$this->queue( 'POST /user_management/users/user_mfa/auth_factors', $this->enroll_response(), 201 );

		$refused = $this->account( 'POST', $this->user_id, '/enroll' );
		$this->assertSame( 403, $refused->get_status() );
		$this->assertSame( 'workos_mfa_code_required', $refused->get_data()['code'] );
		$this->assertEmpty( array_filter( $this->captured, static fn( $c ) => 'POST' === $c['method'] && str_ends_with( $c['url'], '/auth_factors' ) ) );

		$this->queue( 'POST /auth/factors/auth_factor_1/challenge', [ 'id' => 'auth_challenge_step' ] );
		$this->queue( 'POST /auth/challenges/auth_challenge_step/verify', $this->valid( 'auth_factor_1' ) );

		$allowed = $this->account(
			'POST',
			$this->user_id,
			'/enroll',
			[
				'factor_id' => 'auth_factor_1',
				'code'      => '123456',
			]
		);
		$this->assertSame( 201, $allowed->get_status() );
	}

	public function test_confirm_with_another_factors_challenge_does_not_turn_mfa_on(): void {
		wp_set_current_user( $this->user_id );
		update_user_meta(
			$this->user_id,
			User::META_MFA_PENDING_FACTOR,
			[
				'factor_id' => 'auth_factor_new',
				'created'   => time(),
			]
		);
		$this->queue( 'POST /auth/challenges/auth_challenge_other/verify', $this->valid( 'auth_factor_other' ) );

		$response = $this->account(
			'POST',
			$this->user_id,
			'/confirm',
			[
				'factor_id'    => 'auth_factor_new',
				'code'         => '123456',
				'challenge_id' => 'auth_challenge_other',
			]
		);

		$this->assertSame( 'workos_mfa_invalid_code', $response->get_data()['code'] );
		$this->assertSame( '', (string) get_user_meta( $this->user_id, User::META_MFA_ENABLED, true ) );
	}

	// ------------------------- Per-account code limit -------------------------

	public function test_new_sign_ins_do_not_reset_the_wrong_code_budget(): void {
		$this->enroll_user();
		$this->queue( 'POST /auth/factors/auth_factor_1/challenge', [ 'id' => 'auth_challenge_1' ] );
		$this->queue( 'POST /auth/challenges/auth_challenge_1/verify', [ 'valid' => false ] );

		$codes = [];
		foreach ( [ 3, 3 ] as $tries ) {
			$token = $this->sign_in()->get_data()['mfa_token'];
			$this->dispatch( '/workos/v1/auth/mfa/challenge', [ 'mfa_token' => $token, 'factor_id' => 'auth_factor_1' ] );
			for ( $i = 0; $i < $tries; $i++ ) {
				$codes[] = $this->dispatch(
					'/workos/v1/auth/mfa/verify',
					[
						'mfa_token'                   => $token,
						'authentication_challenge_id' => 'auth_challenge_1',
						'code'                        => '000000',
					]
				)->get_data()['code'];
			}
		}

		$this->assertSame( array_fill( 0, 5, 'workos_mfa_invalid_code' ), array_slice( $codes, 0, 5 ) );
		$this->assertSame( 'workos_mfa_too_many_codes', $codes[5] );
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_right_code_does_not_count_toward_the_limit(): void {
		$service = new FactorService();
		$this->queue( 'POST /auth/factors/auth_factor_1/challenge', [ 'id' => 'auth_challenge_1' ] );
		$this->queue( 'POST /auth/challenges/auth_challenge_1/verify', [ 'valid' => false ] );

		for ( $i = 0; $i < 4; $i++ ) {
			$this->assertSame( 'workos_mfa_invalid_code', $service->check_code( $this->user_id, 'auth_factor_1', '000000' )->get_error_code() );
		}

		$this->queue( 'POST /auth/challenges/auth_challenge_1/verify', $this->valid( 'auth_factor_1' ) );
		$this->assertTrue( $service->check_code( $this->user_id, 'auth_factor_1', '123456' ) );

		$this->queue( 'POST /auth/challenges/auth_challenge_1/verify', [ 'valid' => false ] );
		$this->assertSame( 'workos_mfa_invalid_code', $service->check_code( $this->user_id, 'auth_factor_1', '000000' )->get_error_code() );
		$this->assertSame( 'workos_mfa_too_many_codes', $service->check_code( $this->user_id, 'auth_factor_1', '000000' )->get_error_code() );
	}

	// ------------------------- Token required -------------------------

	public function test_challenge_and_verify_refuse_requests_without_a_parked_sign_in(): void {
		$challenge = $this->dispatch( '/workos/v1/auth/mfa/challenge', [ 'factor_id' => 'auth_factor_1' ] );
		$this->assertSame( 403, $challenge->get_status() );
		$this->assertSame( 'workos_authkit_mfa_expired', $challenge->get_data()['code'] );

		$verify = $this->dispatch(
			'/workos/v1/auth/mfa/verify',
			[
				'pending_authentication_token' => 'pat_stolen',
				'authentication_challenge_id'  => 'auth_challenge_attacker',
				'code'                         => '123456',
			]
		);
		$this->assertSame( 403, $verify->get_status() );
		$this->assertSame( 'workos_authkit_mfa_expired', $verify->get_data()['code'] );
		$this->assertEmpty( $this->captured, 'Nothing reaches WorkOS.' );
	}

	public function test_workos_challenge_is_parked_and_bound_to_its_factors(): void {
		$this->queue(
			'POST /user_management/authenticate',
			[
				'code'                         => 'mfa_challenge',
				'message'                      => 'The user must complete an MFA challenge.',
				'pending_authentication_token' => 'pat_secret',
				'authentication_factors'       => [ [ 'id' => 'auth_factor_w', 'type' => 'totp' ] ],
				'user'                         => [ 'id' => 'user_mfa', 'email' => 'member@example.com' ],
			],
			403
		);

		$data = $this->sign_in()->get_data();

		$this->assertTrue( $data['mfa_required'] );
		$this->assertNotEmpty( $data['mfa_token'] );
		$this->assertArrayNotHasKey( 'pending_authentication_token', $data );
		$this->assertStringNotContainsString( 'pat_secret', (string) wp_json_encode( $data ) );

		$other = $this->dispatch( '/workos/v1/auth/mfa/challenge', [ 'mfa_token' => $data['mfa_token'], 'factor_id' => 'auth_factor_attacker' ] );
		$this->assertSame( 404, $other->get_status() );

		$this->queue( 'POST /auth/factors/auth_factor_w/challenge', [ 'id' => 'auth_challenge_w' ] );
		$own = $this->dispatch( '/workos/v1/auth/mfa/challenge', [ 'mfa_token' => $data['mfa_token'], 'factor_id' => 'auth_factor_w' ] );
		$this->assertSame( 200, $own->get_status() );
	}

	// ------------------------- Mirror flag -------------------------

	public function test_factor_added_outside_wordpress_is_still_challenged(): void {
		$this->factors( [ [ 'id' => 'auth_factor_outside', 'type' => 'totp' ] ] );

		$data = $this->sign_in()->get_data();

		$this->assertTrue( $data['mfa_required'] );
		$this->assertFalse( $data['enroll'] );
		$this->assertSame( [ [ 'id' => 'auth_factor_outside', 'type' => 'totp' ] ], $data['factors'] );
		$this->assertSame( 0, get_current_user_id() );
		$this->assertSame( '1', get_user_meta( $this->user_id, User::META_MFA_ENABLED, true ) );
	}

	public function test_factors_removed_in_workos_turn_mfa_off(): void {
		$fired = 0;
		add_action(
			'workos_mfa_removed_in_workos',
			static function () use ( &$fired ) {
				++$fired;
			}
		);
		update_user_meta( $this->user_id, User::META_MFA_ENABLED, '1' );
		$this->factors( [] );

		$data = $this->sign_in()->get_data();

		$this->assertArrayNotHasKey( 'mfa_required', $data );
		$this->assertSame( $this->user_id, get_current_user_id() );
		$this->assertSame( '', get_user_meta( $this->user_id, User::META_MFA_ENABLED, true ) );
		$this->assertSame( 1, $fired );
	}

	public function test_factors_removed_in_workos_still_enroll_a_required_user(): void {
		update_user_meta( $this->user_id, User::META_MFA_ENABLED, '1' );
		update_user_meta( $this->user_id, User::META_MFA_REQUIRED, '1' );
		$this->factors( [] );

		$data = $this->sign_in()->get_data();

		$this->assertTrue( $data['enroll'] );
		$this->assertSame( 0, get_current_user_id() );
		$this->assertSame( '', get_user_meta( $this->user_id, User::META_MFA_ENABLED, true ) );
	}

	public function test_failed_factor_lookup_keeps_mfa_on(): void {
		update_user_meta( $this->user_id, User::META_MFA_ENABLED, '1' );
		$this->queue( 'GET /user_management/users/user_mfa/auth_factors', [ 'message' => 'down' ], 500 );

		$this->assertWPError( ( new FactorService() )->list_factors( $this->user_id ) );
		$this->assertSame( '1', get_user_meta( $this->user_id, User::META_MFA_ENABLED, true ) );
	}

	// ------------------------- Sign-in methods that count as MFA -------------------------

	public function test_passkey_sign_in_skips_the_code_step(): void {
		$this->enroll_member();
		$skipped = [];
		add_action(
			'workos_mfa_step_skipped',
			static function ( $user_id, $method ) use ( &$skipped ) {
				$skipped[] = [ $user_id, $method ];
			},
			10,
			2
		);

		$result = $this->complete_with( Policy::SIGN_IN_PASSKEY );

		$this->assertArrayNotHasKey( 'mfa_required', $result );
		$this->assertSame( $this->user_id, get_current_user_id() );
		$this->assertSame( [ [ $this->user_id, 'Passkey' ] ], $skipped );
	}

	public function test_passkey_sign_in_also_satisfies_a_required_user_without_factors(): void {
		delete_user_meta( $this->user_id, User::META_MFA_ENABLED );
		update_user_meta( $this->user_id, User::META_MFA_REQUIRED, '1' );
		$this->factors( [] );

		$result = $this->complete_with( Policy::SIGN_IN_PASSKEY );

		$this->assertArrayNotHasKey( 'mfa_required', $result );
		$this->assertSame( $this->user_id, get_current_user_id() );
	}

	public function test_sso_sign_in_trusts_the_identity_provider_by_default(): void {
		$this->enroll_member();
		$result = $this->complete_with( Policy::SIGN_IN_SSO );

		$this->assertArrayNotHasKey( 'mfa_required', $result );
		$this->assertSame( $this->user_id, get_current_user_id() );
	}

	public function test_sso_sign_in_asks_for_a_code_when_the_site_stops_trusting_it(): void {
		$this->enroll_member();
		$options                             = get_option( 'workos_production' );
		$options[ Settings::OPTION_TRUST_SSO ] = false;
		update_option( 'workos_production', $options );
		App::container()->get( \WorkOS\Options\Production::class )->reset();

		$result = $this->complete_with( Policy::SIGN_IN_SSO );

		$this->assertTrue( $result['mfa_required'] );
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_password_and_social_sign_ins_still_ask_for_a_code(): void {
		$this->enroll_member();
		foreach ( [ 'Password', 'GoogleOAuth', 'MagicAuth', '' ] as $method ) {
			$result = $this->complete_with( $method );
			$this->assertTrue( $result['mfa_required'], $method );
			$this->assertSame( 0, get_current_user_id(), $method );
		}
	}

	public function test_satisfied_by_sign_in_filter_can_require_a_code_after_a_passkey(): void {
		$this->enroll_member();
		add_filter( 'workos_mfa_satisfied_by_sign_in', '__return_false' );

		$result = $this->complete_with( Policy::SIGN_IN_PASSKEY );

		remove_filter( 'workos_mfa_satisfied_by_sign_in', '__return_false' );
		$this->assertTrue( $result['mfa_required'] );
	}

	// ------------------------- PendingLogin -------------------------

	public function test_touching_a_parked_sign_in_never_extends_it(): void {
		$token = PendingLogin::park(
			[
				'profile'  => 'members',
				'user_id'  => $this->user_id,
				'response' => [ 'access_token' => 'secret_at_123' ],
			]
		);
		$key   = 'workos_mfa_pending_' . substr( hash( 'sha256', $token ), 0, 40 );

		$raw = get_transient( $key );
		$this->assertIsString( $raw['response'] );
		$this->assertStringNotContainsString( 'secret_at_123', (string) wp_json_encode( $raw ) );
		$this->assertSame( 'secret_at_123', PendingLogin::get( $token, 'members' )['response']['access_token'] );

		$raw['expires_at'] = time() + 30;
		set_transient( $key, $raw, 600 );
		$record = PendingLogin::get( $token, 'members' );
		PendingLogin::update( $token, $record );
		$this->assertLessThanOrEqual( time() + 30, PendingLogin::get( $token, 'members' )['expires_at'] );

		$raw               = get_transient( $key );
		$raw['expires_at'] = time() - 1;
		set_transient( $key, $raw, 600 );
		$this->assertNull( PendingLogin::get( $token, 'members' ) );
		$this->assertFalse( get_transient( $key ) );
	}

	public function test_update_does_not_bring_back_a_finished_sign_in(): void {
		$token  = PendingLogin::park(
			[
				'profile' => 'members',
				'user_id' => $this->user_id,
			]
		);
		$record = PendingLogin::get( $token, 'members' );

		PendingLogin::forget( $token );
		PendingLogin::update( $token, $record );

		$this->assertNull( PendingLogin::get( $token, 'members' ) );
	}

	public function test_resume_code_works_once(): void {
		$code = PendingLogin::handoff( 'the_token' );

		$this->assertNotSame( 'the_token', $code );
		$this->assertSame( 'the_token', PendingLogin::redeem( $code ) );
		$this->assertSame( '', PendingLogin::redeem( $code ) );
	}

	// ------------------------- Password guard -------------------------

	public function test_xmlrpc_multicall_app_password_does_not_exempt_another_user(): void {
		$a = self::factory()->user->create_and_get();
		$b = get_userdata( $this->user_id );
		update_user_meta( $a->ID, User::META_MFA_ENABLED, '1' );
		update_user_meta( $b->ID, User::META_MFA_ENABLED, '1' );

		// Call 1 of the multicall authenticated A with an application password.
		do_action( 'application_password_did_authenticate', $a, [] );

		// Call 2: B with a password.
		$this->assertWPError( apply_filters( 'authenticate', $b, 'mfa_member', 'wp_password_123' ) );

		// The exemption only lasted for its own run, even for A.
		$this->assertWPError( apply_filters( 'authenticate', $a, $a->user_login, 'x' ) );

		// In the run where A's application password authenticated, A passes.
		$fire = static function ( $user ) use ( $a ) {
			do_action( 'application_password_did_authenticate', $a, [] );
			return $user;
		};
		add_filter( 'authenticate', $fire, 50 );
		$this->assertSame( $a->ID, apply_filters( 'authenticate', $a, $a->user_login, 'x' )->ID );
		remove_filter( 'authenticate', $fire, 50 );
	}

	public function test_always_profile_blocks_password_only_sign_in_for_linked_users(): void {
		$this->save_profile( Profile::DEFAULT_SLUG, Profile::MFA_ENFORCE_ALWAYS );
		$guard    = App::container()->get( PasswordGuard::class );
		$unlinked = self::factory()->user->create();

		$this->assertTrue( $guard->must_use_mfa( $this->user_id ) );
		$this->assertFalse( $guard->must_use_mfa( $unlinked ) );
	}

	// ------------------------- Bearer tokens -------------------------

	public function test_bearer_token_from_another_app_cannot_act_for_an_mfa_user(): void {
		update_user_meta( $this->user_id, User::META_MFA_ENABLED, '1' );
		$plain = self::factory()->user->create();

		$this->assertSame( 0, apply_filters( 'workos_bearer_token_user', $this->user_id, [ 'sid' => 's1' ] ) );
		$this->assertSame( $plain, apply_filters( 'workos_bearer_token_user', $plain, [ 'sid' => 's1' ] ) );

		VerifiedSessions::remember( $this->user_id, [ 'access_token' => $this->jwt( [ 'sid' => 's1' ] ) ] );

		$this->assertSame( $this->user_id, apply_filters( 'workos_bearer_token_user', $this->user_id, [ 'sid' => 's1' ] ) );
		$this->assertSame( 0, apply_filters( 'workos_bearer_token_user', $this->user_id, [ 'sid' => 's2' ] ) );
	}

	// ------------------------- Re-link -------------------------

	public function test_mfa_account_cannot_be_relinked_to_another_workos_identity(): void {
		$plain = self::factory()->user->create();
		update_user_meta( $this->user_id, User::META_MFA_ENABLED, '1' );

		$this->assertTrue( apply_filters( 'workos_relink_protected', false, $this->user_id ) );
		$this->assertFalse( apply_filters( 'workos_relink_protected', false, $plain ) );

		$this->assertFalse( UserSync::link_user( $this->user_id, [ 'id' => 'user_attacker', 'email' => 'member@example.com' ], 'test' ) );
		$this->assertSame( 'user_mfa', User::get_workos_id( $this->user_id ) );
	}

	// ------------------------- Account changes -------------------------

	public function test_adding_an_authenticator_emails_and_signs_out_other_sessions(): void {
		$manager = WP_Session_Tokens::get_instance( $this->user_id );
		$mine    = $manager->create( time() + HOUR_IN_SECONDS );
		$other   = $manager->create( time() + HOUR_IN_SECONDS );
		wp_set_current_user( $this->user_id );
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $this->user_id, time() + HOUR_IN_SECONDS, 'logged_in', $mine );

		do_action(
			'workos_mfa_enrolled',
			$this->user_id,
			[
				'id'   => 'auth_factor_1',
				'type' => 'totp',
			]
		);

		$this->assertTrue( $manager->verify( $mine ) );
		$this->assertFalse( $manager->verify( $other ) );
		$this->assertCount( 1, $this->mail );
		$this->assertSame( 'member@example.com', $this->mail[0]['to'] );
	}

	public function test_admin_reset_signs_the_user_out_everywhere(): void {
		$manager = WP_Session_Tokens::get_instance( $this->user_id );
		$manager->create( time() + HOUR_IN_SECONDS );
		update_user_meta( $this->user_id, User::META_ACCESS_TOKEN, 'at' );
		update_user_meta( $this->user_id, User::META_REFRESH_TOKEN, 'rt' );
		update_user_meta( $this->user_id, User::META_SESSION_ID, 'sess_1' );
		wp_set_current_user( $this->admin_id );

		do_action( 'workos_mfa_reset', $this->user_id, 1 );

		$this->assertSame( [], $manager->get_all() );
		$this->assertSame( '', (string) get_user_meta( $this->user_id, User::META_REFRESH_TOKEN, true ) );
		$this->assertSame( '', (string) get_user_meta( $this->user_id, User::META_SESSION_ID, true ) );
		$this->assertNotEmpty( array_filter( $this->captured, static fn( $c ) => str_contains( $c['url'], 'sess_1' ) ) );
		$this->assertCount( 1, $this->mail );
	}

	public function test_notify_filter_suppresses_the_email(): void {
		add_filter( 'workos_mfa_notify_user', '__return_false' );

		do_action( 'workos_mfa_factor_removed', $this->user_id, 'auth_factor_1' );

		$this->assertSame( [], $this->mail );
	}

	// ------------------------- Admin panel -------------------------

	public function test_panel_changes_wait_for_core_to_save_the_profile(): void {
		wp_set_current_user( $this->admin_id );
		$_POST['workos_mfa_required'] = '1';
		$panel                        = new UserEditPanel( new FactorService(), new Policy( new Settings() ) );
		$panel->register();

		$panel->save( $this->user_id );
		$this->assertSame( '', (string) get_user_meta( $this->user_id, User::META_MFA_REQUIRED, true ) );

		do_action( 'profile_update', $this->user_id, get_userdata( $this->user_id ), [] );
		$this->assertSame( '1', get_user_meta( $this->user_id, User::META_MFA_REQUIRED, true ) );
	}

	// -------------------------------- Fixtures --------------------------------

	private function enroll_user(): void {
		update_user_meta( $this->user_id, User::META_MFA_ENABLED, '1' );
		$this->factors( [ [ 'id' => 'auth_factor_1', 'type' => 'totp' ] ] );
	}

	private function factors( array $factors ): void {
		$this->queue( 'GET /user_management/users/user_mfa/auth_factors', [ 'data' => $factors ] );
	}

	private function valid( string $factor_id ): array {
		return [
			'valid'     => true,
			'challenge' => [ 'authentication_factor_id' => $factor_id ],
		];
	}

	private function jwt( array $claims ): string {
		return 'eyJhbGciOiJSUzI1NiJ9.' . rtrim( strtr( base64_encode( (string) wp_json_encode( $claims ) ), '+/', '-_' ), '=' ) . '.sig';
	}

	private function sign_in( string $profile = 'members' ): WP_REST_Response {
		if ( ! isset( $this->responses['POST /user_management/authenticate'] ) ) {
			$this->queue(
				'POST /user_management/authenticate',
				[
					'access_token'  => $this->jwt(
						[
							'sub' => 'user_mfa',
							'sid' => 'sess_1',
						]
					),
					'refresh_token' => 'rt_mfa',
					'user'          => [ 'id' => 'user_mfa', 'email' => 'member@example.com' ],
				]
			);
		}

		return $this->dispatch(
			'/workos/v1/auth/password/authenticate',
			[
				'email'    => 'member@example.com',
				'password' => 'CorrectPassword!1',
			],
			$profile
		);
	}

	private function enroll_member(): void {
		update_user_meta( $this->user_id, User::META_MFA_ENABLED, '1' );
		$this->factors( [ [ 'id' => 'auth_factor_1', 'type' => 'totp' ] ] );
	}

	private function complete_with( string $method ): array {
		$response = [
			'access_token'  => $this->jwt(
				[
					'sub' => 'user_mfa',
					'sid' => 'sess_m',
				]
			),
			'refresh_token' => 'rt_mfa',
			'user'          => [ 'id' => 'user_mfa', 'email' => 'member@example.com' ],
		];
		if ( '' !== $method ) {
			$response['authentication_method'] = $method;
		}

		$result = ( new LoginCompleter() )->complete( $response, $this->repository->find_by_slug( 'members' ) );
		$this->assertIsArray( $result );

		return $result;
	}

	private function enroll_response(): array {
		return [
			'authentication_factor'    => [
				'id'   => 'auth_factor_new',
				'type' => 'totp',
				'totp' => [
					'qr_code' => 'data:image/png;base64,AAA',
					'secret'  => 'SECRET',
					'uri'     => 'otpauth://totp/Site:member@example.com?secret=SECRET',
				],
			],
			'authentication_challenge' => [ 'id' => 'auth_challenge_enroll' ],
		];
	}

	private function save_profile( string $slug, string $enforce ): void {
		$this->repository->save(
			Profile::from_array(
				[
					'slug'    => $slug,
					'title'   => $slug,
					'methods' => [ Profile::METHOD_PASSWORD ],
					'mfa'     => [
						'enforce' => $enforce,
						'factors' => [ Profile::FACTOR_TOTP ],
					],
				]
			)
		);
	}

	private function queue( string $key, array $body, int $code = 200 ): void {
		$this->responses[ $key ] = [
			'response' => [ 'code' => $code, 'message' => 'OK' ],
			'body'     => wp_json_encode( $body ),
		];
	}

	private function dispatch( string $route, array $body, string $profile = 'members' ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WorkOS-Nonce', $this->nonce->mint( $profile ) );
		$request->set_body( wp_json_encode( array_merge( [ 'profile' => $profile ], $body ) ) );

		return rest_get_server()->dispatch( $request );
	}

	private function account( string $method, int $user_id, string $path, array $body = [] ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/workos/v1/users/' . $user_id . '/mfa' . $path );
		$request->set_header( 'Content-Type', 'application/json' );
		if ( $body ) {
			$request->set_body( wp_json_encode( $body ) );
		}

		return rest_get_server()->dispatch( $request );
	}
}
