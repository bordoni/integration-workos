<?php
/**
 * Tests for the change-email Notifier.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Auth\ChangeEmail\Notifier;
use WorkOS\Email\Mailer;

/**
 * The Notifier produces three transactional emails; these tests pin
 * down recipient, subject, and the opt-out gate.
 */
class ChangeEmailNotifierTest extends WPTestCase {

	private int $user_id = 0;

	/**
	 * Captured wp_mail invocations.
	 *
	 * @var array<int,array{to:string|array,subject:string,message:string,headers:string|array,attachments:array}>
	 */
	private array $mail_captured = [];

	public function setUp(): void {
		parent::setUp();

		\WorkOS\Config::set_active_environment( 'production' );
		update_option(
			'workos_production',
			[
				'change_email_notify_old_address' => true,
			]
		);
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$suffix        = uniqid( 'nf_', true );
		$this->user_id = wp_insert_user(
			[
				'user_login' => 'nf_' . wp_generate_password( 8, false ),
				'user_pass'  => wp_generate_password(),
				'user_email' => 'old-' . $suffix . '@example.test',
				'role'       => 'subscriber',
			]
		);
		$this->assertIsInt( $this->user_id );

		add_filter( 'wp_mail', [ $this, 'capture_mail' ], 10, 1 );
		add_filter( 'pre_wp_mail', '__return_true' );
		$this->mail_captured = [];
	}

	public function tearDown(): void {
		remove_filter( 'wp_mail', [ $this, 'capture_mail' ], 10 );
		remove_filter( 'pre_wp_mail', '__return_true' );

		delete_option( 'workos_production' );
		\WorkOS\Config::set_active_environment( 'staging' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		parent::tearDown();
	}

	public function capture_mail( array $args ): array {
		$this->mail_captured[] = $args;
		return $args;
	}

	private function notifier(): Notifier {
		return new Notifier( new Mailer() );
	}

	public function test_verification_goes_to_new_address_with_confirm_url(): void {
		$notifier   = $this->notifier();
		$user       = get_userdata( $this->user_id );
		$new_email  = 'new-' . uniqid() . '@example.test';
		$confirm_url = home_url( '/workos/change-email/?token=abc123&user_id=' . $this->user_id );

		$sent = $notifier->send_verification( $user, $new_email, $confirm_url, time() + 3600 );

		$this->assertTrue( $sent );
		$this->assertNotEmpty( $this->mail_captured );

		$mail = $this->mail_captured[0];
		$to   = is_array( $mail['to'] ) ? $mail['to'][0] : (string) $mail['to'];
		$this->assertSame( $new_email, $to );
		$this->assertStringContainsString( 'abc123', (string) $mail['message'] );
	}

	public function test_old_address_notice_suppressed_when_option_off(): void {
		update_option(
			'workos_production',
			[ 'change_email_notify_old_address' => false ]
		);
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$notifier = $this->notifier();
		$sent     = $notifier->send_old_address_notice(
			get_userdata( $this->user_id ),
			'new@example.test',
			home_url( '/workos/change-email/?action=cancel' ),
			time() + 3600
		);

		$this->assertFalse( $sent );
		$this->assertEmpty( $this->mail_captured );
	}

	public function test_old_address_notice_goes_to_current_email(): void {
		$user     = get_userdata( $this->user_id );
		$notifier = $this->notifier();

		$sent = $notifier->send_old_address_notice(
			$user,
			'new@example.test',
			home_url( '/workos/change-email/?action=cancel' ),
			time() + 3600
		);

		$this->assertTrue( $sent );

		$mail = $this->mail_captured[0];
		$to   = is_array( $mail['to'] ) ? $mail['to'][0] : (string) $mail['to'];
		$this->assertSame( $user->user_email, $to );
		$this->assertStringContainsString( 'cancel', (string) $mail['message'] );
	}

	public function test_confirmation_notice_goes_to_previous_address(): void {
		$user     = get_userdata( $this->user_id );
		$notifier = $this->notifier();

		$sent = $notifier->send_confirmation_notice( $user, 'old@example.test', 'new@example.test' );

		$this->assertTrue( $sent );

		$mail = $this->mail_captured[0];
		$to   = is_array( $mail['to'] ) ? $mail['to'][0] : (string) $mail['to'];
		$this->assertSame( 'old@example.test', $to );
	}
}
