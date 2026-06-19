<?php
/**
 * Tests for the change-email REST endpoints (initiate/confirm/cancel).
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Auth\AuthKit\RateLimiter;
use WorkOS\Auth\ChangeEmail\ConflictResolver;
use WorkOS\Auth\ChangeEmail\Notifier;
use WorkOS\Auth\ChangeEmail\PendingChange;
use WorkOS\Auth\ChangeEmail\RestApi;
use WorkOS\Auth\ChangeEmail\TokenFactory;
use WorkOS\Email\AddressMask;
use WorkOS\Email\Mailer;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Coverage for `POST /workos/v1/users/{id}/email-change` and the
 * associated /confirm + /cancel routes. HTTP traffic to WorkOS is
 * mocked at `pre_http_request`. wp_mail is captured via the
 * `wp_mail` filter so the verification email body can be asserted.
 */
class ChangeEmailRestApiTest extends WPTestCase {

	/**
	 * Captured outbound HTTP calls.
	 *
	 * @var array<int,array{url:string,method:string,body:string}>
	 */
	private array $http_captured = [];

	/**
	 * Captured wp_mail invocations.
	 *
	 * @var array<int,array{to:string|array,subject:string,message:string,headers:string|array,attachments:array}>
	 */
	private array $mail_captured = [];

	private int $linked_user_id   = 0;
	private int $admin_user_id    = 0;
	private int $unlinked_user_id = 0;
	private int $other_user_id    = 0;
	private string $linked_email  = '';
	private string $other_email   = '';

	public function setUp(): void {
		parent::setUp();

		\WorkOS\Config::set_active_environment( 'production' );
		update_option(
			'workos_production',
			[
				'api_key'                                 => 'sk_test_fake',
				'client_id'                               => 'client_fake',
				'environment_id'                          => 'environment_test',
				'enable_activity_log'                     => true,
				'change_email_enabled'                    => true,
				'change_email_conflict_policy'            => 'block',
				'change_email_token_lifetime'             => 3600,
				'change_email_rate_limit_user_count'      => 3,
				'change_email_rate_limit_user_window'     => 3600,
				'change_email_rate_limit_ip_count'        => 10,
				'change_email_rate_limit_ip_window'       => 3600,
				'change_email_notify_old_address'         => true,
			]
		);
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$this->reset_rate_limit_buckets();

		$tokens   = new TokenFactory();
		$pending  = new PendingChange( $tokens );
		$mailer   = new Mailer();
		$masker   = new AddressMask();
		$notifier = new Notifier( $mailer, $masker );

		$rest = new RestApi(
			new RateLimiter(),
			$tokens,
			$pending,
			new ConflictResolver(),
			$notifier,
			$masker
		);

		add_action( 'rest_api_init', [ $rest, 'register_routes' ] );
		$server = rest_get_server();
		do_action( 'rest_api_init', $server );

		add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );
		add_filter( 'wp_mail', [ $this, 'capture_mail' ], 10, 1 );
		// Stop wp_mail from actually trying to send (mailcatcher / sendmail).
		add_filter( 'pre_wp_mail', '__return_true' );

		$suffix                 = uniqid( 'ce_', true );
		$this->linked_email     = 'linked-' . $suffix . '@example.test';
		$this->other_email      = 'other-' . $suffix . '@example.test';
		$this->linked_user_id   = $this->create_user( $this->linked_email, 'subscriber' );
		$this->unlinked_user_id = $this->create_user( 'unlinked-' . $suffix . '@example.test', 'subscriber' );
		$this->admin_user_id    = $this->create_user( 'admin-' . $suffix . '@example.test', 'administrator' );
		$this->other_user_id    = $this->create_user( $this->other_email, 'subscriber' );

		update_user_meta( $this->linked_user_id, '_workos_user_id', 'user_linked_01' );

		$this->http_captured = [];
		$this->mail_captured = [];
	}

	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10 );
		remove_filter( 'wp_mail', [ $this, 'capture_mail' ], 10 );
		remove_filter( 'pre_wp_mail', '__return_true' );

		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}workos_activity_log" );

		wp_set_current_user( 0 );

		delete_option( 'workos_production' );
		\WorkOS\Config::set_active_environment( 'staging' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		parent::tearDown();
	}

	private function create_user( string $email, string $role ): int {
		$user_id = wp_insert_user(
			[
				'user_login' => 'ce_' . wp_generate_password( 8, false ),
				'user_pass'  => wp_generate_password(),
				'user_email' => $email,
				'role'       => $role,
			]
		);
		$this->assertIsInt( $user_id );
		return $user_id;
	}

	/**
	 * Capture outbound HTTP and return a 200 OK so the endpoint code
	 * doesn't error out talking to WorkOS.
	 */
	public function intercept_http( $preempt, array $args, string $url ): array {
		$this->http_captured[] = [
			'url'    => $url,
			'method' => $args['method'] ?? 'GET',
			'body'   => (string) ( $args['body'] ?? '' ),
		];
		return [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'body'     => '{}',
		];
	}

	/**
	 * Capture wp_mail() invocations.
	 *
	 * @param array $args Mail args.
	 *
	 * @return array
	 */
	public function capture_mail( array $args ): array {
		$this->mail_captured[] = $args;
		return $args;
	}

	private function dispatch( string $method, string $path, array $body = [], ?string $nonce = null ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $path );
		$request->set_header( 'Content-Type', 'application/json' );
		if ( null !== $nonce ) {
			$request->set_header( 'X-WP-Nonce', $nonce );
		}
		$request->set_body( wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $request );
	}

	// ----------------------------------------------------------------- initiate

	public function test_initiate_writes_pending_meta_and_sends_verification(): void {
		// Self-service: the user changes their own email, so the verified
		// (token + email) flow runs rather than the admin-direct commit.
		wp_set_current_user( $this->linked_user_id );

		$new_email = 'brand-new-' . uniqid() . '@example.test';
		$response  = $this->dispatch(
			'POST',
			'/workos/v1/users/' . $this->linked_user_id . '/email-change',
			[ 'new_email' => $new_email ]
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['ok'] ?? false );
		$this->assertStringContainsString( '•', $data['masked_new_email'] ?? '' );

		$stored = get_user_meta( $this->linked_user_id, PendingChange::META_KEY, true );
		$this->assertIsArray( $stored );
		$this->assertSame( strtolower( $new_email ), $stored['new_email'] );
		$this->assertNotEmpty( $stored['token_hash'] );

		// Verification email landed on the new address.
		$found = false;
		foreach ( $this->mail_captured as $mail ) {
			$to = is_array( $mail['to'] ) ? implode( ',', $mail['to'] ) : (string) $mail['to'];
			if ( str_contains( strtolower( $to ), strtolower( $new_email ) ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Verification email must be delivered to the new address.' );
	}

	public function test_initiate_rejects_invalid_email(): void {
		wp_set_current_user( $this->admin_user_id );

		$response = $this->dispatch(
			'POST',
			'/workos/v1/users/' . $this->linked_user_id . '/email-change',
			[ 'new_email' => 'not-an-email' ]
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertNull( get_user_meta( $this->linked_user_id, PendingChange::META_KEY, true ) ?: null );
	}

	public function test_initiate_returns_403_when_no_edit_user_cap(): void {
		wp_set_current_user( $this->unlinked_user_id );

		$response = $this->dispatch(
			'POST',
			'/workos/v1/users/' . $this->linked_user_id . '/email-change',
			[ 'new_email' => 'free-' . uniqid() . '@example.test' ]
		);

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_initiate_conflict_is_revealed_to_admin_acting_on_other(): void {
		wp_set_current_user( $this->admin_user_id );

		// Target the address owned by another local user.
		$response = $this->dispatch(
			'POST',
			'/workos/v1/users/' . $this->linked_user_id . '/email-change',
			[ 'new_email' => $this->other_email ]
		);

		// An admin acting on someone else's account can already enumerate
		// users, so the real reason is surfaced rather than hidden.
		$this->assertSame( 409, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'workos_change_email_conflict', $data['code'] ?? '' );
		$this->assertStringContainsString( 'already in use', strtolower( $data['message'] ?? '' ) );

		// Still no pending meta, and the block is still recorded.
		$stored = get_user_meta( $this->linked_user_id, PendingChange::META_KEY, true );
		$this->assertTrue( '' === $stored || empty( $stored ) );

		global $wpdb;
		$row = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}workos_activity_log WHERE event_type = %s",
				'email_change.conflict_blocked'
			)
		);
		$this->assertSame( '1', (string) $row );
	}

	public function test_initiate_conflict_is_enumeration_safe_for_self_service(): void {
		// A non-privileged user changing their *own* email to an address
		// owned by someone else must not learn that it's taken.
		wp_set_current_user( $this->unlinked_user_id );

		$response = $this->dispatch(
			'POST',
			'/workos/v1/users/' . $this->unlinked_user_id . '/email-change',
			[ 'new_email' => $this->other_email ]
		);

		// Enumeration-safe: response shape is identical to success.
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['ok'] ?? false );
		$this->assertStringContainsString( '•', $data['masked_new_email'] ?? '' );

		// But no pending meta was written.
		$stored = get_user_meta( $this->unlinked_user_id, PendingChange::META_KEY, true );
		$this->assertTrue( '' === $stored || empty( $stored ) );

		// And the activity log still records the block.
		global $wpdb;
		$row = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}workos_activity_log WHERE event_type = %s",
				'email_change.conflict_blocked'
			)
		);
		$this->assertSame( '1', (string) $row );
	}

	public function test_initiate_same_email_is_noop(): void {
		wp_set_current_user( $this->linked_user_id );

		$response = $this->dispatch(
			'POST',
			'/workos/v1/users/' . $this->linked_user_id . '/email-change',
			[ 'new_email' => $this->linked_email ]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['no_op'] ?? false );

		// No pending meta, no email.
		$this->assertEmpty( get_user_meta( $this->linked_user_id, PendingChange::META_KEY, true ) );
		$this->assertEmpty( $this->mail_captured );
	}

	public function test_initiate_user_rate_limit(): void {
		// Rate limits only apply to self-service; the admin-direct path
		// bypasses them, so this must run as the user acting on themselves.
		wp_set_current_user( $this->linked_user_id );

		$last = null;
		for ( $i = 0; $i < 4; $i++ ) {
			$last = $this->dispatch(
				'POST',
				'/workos/v1/users/' . $this->linked_user_id . '/email-change',
				[ 'new_email' => 'free-' . uniqid() . '@example.test' ]
			);
		}

		$this->assertNotNull( $last );
		$this->assertSame( 429, $last->get_status() );
	}

	public function test_initiate_admin_commits_immediately_without_verification(): void {
		wp_set_current_user( $this->admin_user_id );

		$new_email = 'admin-set-' . uniqid() . '@example.test';
		$response  = $this->dispatch(
			'POST',
			'/workos/v1/users/' . $this->linked_user_id . '/email-change',
			[ 'new_email' => $new_email ]
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['committed'] ?? false );
		$this->assertSame( strtolower( $new_email ), $data['email'] ?? '' );

		// Committed straight to WP — no pending meta, no verification email.
		$updated = get_userdata( $this->linked_user_id );
		$this->assertSame( strtolower( $new_email ), strtolower( $updated->user_email ) );
		$this->assertEmpty( get_user_meta( $this->linked_user_id, PendingChange::META_KEY, true ) );
		$this->assertEmpty( $this->mail_captured );

		// WorkOS was updated for the linked user.
		$hit = false;
		foreach ( $this->http_captured as $req ) {
			if ( str_contains( (string) $req['url'], 'user_linked_01' ) ) {
				$hit = true;
				break;
			}
		}
		$this->assertTrue( $hit, 'WorkOS update_user must be called for a linked user.' );

		// Audit trail records the admin-direct change.
		global $wpdb;
		$row = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}workos_activity_log WHERE event_type = %s",
				'email_change.admin_changed'
			)
		);
		$this->assertSame( '1', (string) $row );
	}

	public function test_initiate_admin_acting_on_self_uses_verified_flow(): void {
		// An admin changing *their own* email is not an "admin action" — it
		// must go through emailed verification like any self-service change,
		// not commit immediately.
		wp_set_current_user( $this->admin_user_id );

		$new_email = 'admin-own-' . uniqid() . '@example.test';
		$response  = $this->dispatch(
			'POST',
			'/workos/v1/users/' . $this->admin_user_id . '/email-change',
			[ 'new_email' => $new_email ]
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		// Verified-flow shape: a pending expiry, and crucially NOT committed.
		$this->assertArrayNotHasKey( 'committed', $data );
		$this->assertArrayHasKey( 'expires_at', $data );

		// The address is only pending — WP still holds the old email.
		$this->assertNotEmpty( get_user_meta( $this->admin_user_id, PendingChange::META_KEY, true ) );
		$unchanged = get_userdata( $this->admin_user_id );
		$this->assertNotSame( strtolower( $new_email ), strtolower( $unchanged->user_email ) );
	}

	public function test_initiate_admin_direct_on_unlinked_user_skips_workos(): void {
		// Admin-direct change for a user with no WorkOS link should mirror
		// only into WordPress and make no upstream call.
		wp_set_current_user( $this->admin_user_id );

		$new_email = 'unlinked-new-' . uniqid() . '@example.test';
		$response  = $this->dispatch(
			'POST',
			'/workos/v1/users/' . $this->unlinked_user_id . '/email-change',
			[ 'new_email' => $new_email ]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['committed'] ?? false );

		// WP updated, but no WorkOS round-trip (user has no `_workos_user_id`).
		$updated = get_userdata( $this->unlinked_user_id );
		$this->assertSame( strtolower( $new_email ), strtolower( $updated->user_email ) );
		$this->assertEmpty( $this->http_captured );
	}

	// ----------------------------------------------------------------- confirm

	public function test_confirm_commits_change_and_clears_meta(): void {
		wp_set_current_user( $this->admin_user_id );

		$new_email = 'fresh-' . uniqid() . '@example.test';

		// Patch the token factory so we know the plaintext to confirm with.
		// We do this by storing a known token directly.
		$factory = new TokenFactory();
		$pending = new PendingChange( $factory );
		$token   = $factory->generate();
		$cancel  = $factory->generate();
		$pending->store(
			$this->linked_user_id,
			strtolower( $new_email ),
			$token,
			$cancel,
			time() + 600,
			$this->admin_user_id
		);

		$this->http_captured = [];

		$response = $this->dispatch(
			'POST',
			'/workos/v1/users/' . $this->linked_user_id . '/email-change/confirm',
			[ 'token' => $token ]
		);

		$this->assertSame( 200, $response->get_status() );

		// WP email updated.
		$updated = get_userdata( $this->linked_user_id );
		$this->assertSame( strtolower( $new_email ), strtolower( $updated->user_email ) );

		// Pending meta cleared.
		$this->assertEmpty( get_user_meta( $this->linked_user_id, PendingChange::META_KEY, true ) );

		// WorkOS API was called with the new email.
		$found_workos_call = false;
		foreach ( $this->http_captured as $call ) {
			if ( str_contains( $call['url'], '/user_management/users/user_linked_01' ) ) {
				$decoded = json_decode( $call['body'], true );
				if ( is_array( $decoded ) && ( $decoded['email'] ?? '' ) === strtolower( $new_email ) ) {
					$found_workos_call = true;
					break;
				}
			}
		}
		$this->assertTrue( $found_workos_call, 'WorkOS update_user must be called with the new email.' );
	}

	public function test_confirm_rejects_expired_token(): void {
		$factory = new TokenFactory();
		$pending = new PendingChange( $factory );
		$token   = $factory->generate();
		$pending->store(
			$this->linked_user_id,
			'never@example.test',
			$token,
			$factory->generate(),
			time() - 10,
			$this->admin_user_id
		);

		$response = $this->dispatch(
			'POST',
			'/workos/v1/users/' . $this->linked_user_id . '/email-change/confirm',
			[ 'token' => $token ]
		);

		$this->assertSame( 410, $response->get_status() );

		// Expired meta is cleared as a side-effect.
		$this->assertEmpty( get_user_meta( $this->linked_user_id, PendingChange::META_KEY, true ) );
	}

	public function test_confirm_rejects_tampered_token(): void {
		$factory = new TokenFactory();
		$pending = new PendingChange( $factory );
		$token   = $factory->generate();
		$pending->store(
			$this->linked_user_id,
			'never@example.test',
			$token,
			$factory->generate(),
			time() + 600,
			$this->admin_user_id
		);

		$tampered    = $token;
		$tampered[0] = 'A' === $tampered[0] ? 'B' : 'A';

		$response = $this->dispatch(
			'POST',
			'/workos/v1/users/' . $this->linked_user_id . '/email-change/confirm',
			[ 'token' => $tampered ]
		);

		$this->assertSame( 400, $response->get_status() );
		// Pending meta remains — single-use is only on valid confirms.
		$this->assertNotEmpty( get_user_meta( $this->linked_user_id, PendingChange::META_KEY, true ) );
	}

	public function test_confirm_race_check_rejects_new_collision(): void {
		$factory = new TokenFactory();
		$pending = new PendingChange( $factory );
		$token   = $factory->generate();
		$pending->store(
			$this->linked_user_id,
			$this->other_email,
			$token,
			$factory->generate(),
			time() + 600,
			$this->admin_user_id
		);

		$response = $this->dispatch(
			'POST',
			'/workos/v1/users/' . $this->linked_user_id . '/email-change/confirm',
			[ 'token' => $token ]
		);

		$this->assertSame( 409, $response->get_status() );
		// And the pending meta is cleared so the user can start over.
		$this->assertEmpty( get_user_meta( $this->linked_user_id, PendingChange::META_KEY, true ) );
	}

	public function test_confirm_honors_same_host_redirect(): void {
		wp_set_current_user( $this->admin_user_id );

		$factory = new TokenFactory();
		$pending = new PendingChange( $factory );
		$token   = $factory->generate();
		$pending->store(
			$this->linked_user_id,
			'rd-' . uniqid() . '@example.test',
			$token,
			$factory->generate(),
			time() + 600,
			$this->admin_user_id
		);

		$target   = home_url( '/welcome' );
		$response = $this->dispatch(
			'POST',
			'/workos/v1/users/' . $this->linked_user_id . '/email-change/confirm',
			[
				'token'        => $token,
				'redirect_url' => $target,
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $target, $response->get_data()['redirect_url'] ?? '' );
	}

	public function test_confirm_rejects_cross_host_redirect(): void {
		wp_set_current_user( $this->admin_user_id );

		$factory = new TokenFactory();
		$pending = new PendingChange( $factory );
		$token   = $factory->generate();
		$pending->store(
			$this->linked_user_id,
			'rd2-' . uniqid() . '@example.test',
			$token,
			$factory->generate(),
			time() + 600,
			$this->admin_user_id
		);

		$response = $this->dispatch(
			'POST',
			'/workos/v1/users/' . $this->linked_user_id . '/email-change/confirm',
			[
				'token'        => $token,
				'redirect_url' => 'https://evil.example/landing',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		// Cross-host targets fall back to home rather than redirecting off-site.
		$this->assertSame( home_url( '/' ), $response->get_data()['redirect_url'] ?? '' );
	}

	// ----------------------------------------------------------------- cancel

	public function test_cancel_via_token_clears_pending(): void {
		$factory = new TokenFactory();
		$pending = new PendingChange( $factory );
		$cancel  = $factory->generate();
		$pending->store(
			$this->linked_user_id,
			'free-' . uniqid() . '@example.test',
			$factory->generate(),
			$cancel,
			time() + 600,
			$this->admin_user_id
		);

		$response = $this->dispatch(
			'POST',
			'/workos/v1/users/' . $this->linked_user_id . '/email-change/cancel',
			[ 'token' => $cancel ]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertEmpty( get_user_meta( $this->linked_user_id, PendingChange::META_KEY, true ) );
	}

	public function test_cancel_via_capability_clears_pending(): void {
		$factory = new TokenFactory();
		$pending = new PendingChange( $factory );
		$pending->store(
			$this->linked_user_id,
			'free-' . uniqid() . '@example.test',
			$factory->generate(),
			$factory->generate(),
			time() + 600,
			$this->admin_user_id
		);

		wp_set_current_user( $this->admin_user_id );

		$response = $this->dispatch(
			'POST',
			'/workos/v1/users/' . $this->linked_user_id . '/email-change/cancel',
			[]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertEmpty( get_user_meta( $this->linked_user_id, PendingChange::META_KEY, true ) );
	}

	public function test_cancel_without_token_or_cap_is_403(): void {
		$factory = new TokenFactory();
		$pending = new PendingChange( $factory );
		$pending->store(
			$this->linked_user_id,
			'free-' . uniqid() . '@example.test',
			$factory->generate(),
			$factory->generate(),
			time() + 600,
			$this->admin_user_id
		);

		wp_set_current_user( $this->unlinked_user_id );

		$response = $this->dispatch(
			'POST',
			'/workos/v1/users/' . $this->linked_user_id . '/email-change/cancel',
			[]
		);

		$this->assertSame( 403, $response->get_status() );
	}

	// ----------------------------------------------------------------- helpers

	private function reset_rate_limit_buckets(): void {
		global $wpdb;
		$wpdb->query(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_workos_rl_%' OR option_name LIKE '_transient_timeout_workos_rl_%'"
		);

		if ( wp_using_ext_object_cache() ) {
			wp_cache_flush();
		}
	}
}
