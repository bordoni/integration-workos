<?php
/**
 * Sends change-email notifications via wp_mail().
 *
 * @package WorkOS\Auth\ChangeEmail
 */

namespace WorkOS\Auth\ChangeEmail;

use WorkOS\Email\Mailer;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the three transactional emails the change-email flow produces.
 *
 *  1. Verification — to the **new** address; contains the confirm link.
 *  2. Old-address notice — to the **old** address on initiate; contains
 *     a cancel link and a "wasn't me" prompt. Opt-out via the
 *     `change_email_notify_old_address` setting.
 *  3. Confirmation notice — to the **old** address after the change
 *     commits. Same opt-out.
 *
 * Each subject/body/template is filterable so a site can rebrand
 * without forking the plugin.
 */
class Notifier {

	/**
	 * Mailer.
	 *
	 * @var Mailer
	 */
	private Mailer $mailer;

	/**
	 * Constructor.
	 *
	 * @param Mailer $mailer Mailer.
	 */
	public function __construct( Mailer $mailer ) {
		$this->mailer = $mailer;
	}

	/**
	 * Send the verification email to the new address.
	 *
	 * @param WP_User $user        Target WP user (used for context only).
	 * @param string  $new_email   New address (recipient).
	 * @param string  $confirm_url URL the user clicks to confirm.
	 * @param int     $expires_at  Unix timestamp at which the link stops working.
	 *
	 * @return bool
	 */
	public function send_verification( WP_User $user, string $new_email, string $confirm_url, int $expires_at ): bool {
		$site_name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		/* translators: %s: site name. */
		$subject = sprintf( __( 'Confirm your new email address on %s', 'integration-workos' ), $site_name );

		return $this->mailer->send(
			$new_email,
			$subject,
			'change-email/verification-email',
			[
				'user'        => $user,
				'new_email'   => $new_email,
				'confirm_url' => $confirm_url,
				'expires_at'  => $expires_at,
				'site_name'   => $site_name,
				'site_url'    => home_url( '/' ),
			]
		);
	}

	/**
	 * Send the "change requested" notice to the old address.
	 *
	 * Suppressed when `change_email_notify_old_address` is false.
	 *
	 * @param WP_User $user        Target WP user (old address comes from $user->user_email).
	 * @param string  $new_email   Requested new address (masked in the body).
	 * @param string  $cancel_url  URL that consumes the cancel token.
	 * @param int     $expires_at  Unix timestamp at which the change request expires.
	 *
	 * @return bool
	 */
	public function send_old_address_notice( WP_User $user, string $new_email, string $cancel_url, int $expires_at ): bool {
		if ( ! $this->should_notify_old_address() ) {
			return false;
		}

		$old_email = (string) $user->user_email;
		if ( '' === $old_email ) {
			return false;
		}

		$site_name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		/* translators: %s: site name. */
		$subject = sprintf( __( 'Email change requested for your %s account', 'integration-workos' ), $site_name );

		return $this->mailer->send(
			$old_email,
			$subject,
			'change-email/old-address-notice',
			[
				'user'             => $user,
				'old_email'        => $old_email,
				'new_email'        => $new_email,
				'masked_new_email' => $this->mask_email( $new_email ),
				'cancel_url'       => $cancel_url,
				'expires_at'       => $expires_at,
				'site_name'        => $site_name,
				'site_url'         => home_url( '/' ),
			]
		);
	}

	/**
	 * Send the post-commit confirmation to the (now former) old address.
	 *
	 * @param WP_User $user      Target WP user (now holds the new email).
	 * @param string  $old_email Previous address (recipient).
	 * @param string  $new_email New address (masked in the body).
	 *
	 * @return bool
	 */
	public function send_confirmation_notice( WP_User $user, string $old_email, string $new_email ): bool {
		if ( ! $this->should_notify_old_address() ) {
			return false;
		}

		if ( '' === $old_email ) {
			return false;
		}

		$site_name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		/* translators: %s: site name. */
		$subject = sprintf( __( 'Your email address on %s was changed', 'integration-workos' ), $site_name );

		return $this->mailer->send(
			$old_email,
			$subject,
			'change-email/confirmation-notice',
			[
				'user'             => $user,
				'old_email'        => $old_email,
				'new_email'        => $new_email,
				'masked_new_email' => $this->mask_email( $new_email ),
				'site_name'        => $site_name,
				'site_url'         => home_url( '/' ),
			]
		);
	}

	/**
	 * Whether the old address should receive notices, gated by the
	 * `change_email_notify_old_address` setting.
	 *
	 * @return bool
	 */
	private function should_notify_old_address(): bool {
		$enabled = (bool) workos()->option( 'change_email_notify_old_address', true );

		/**
		 * Filter whether change-email notices fan out to the old address.
		 *
		 * @param bool $enabled
		 */
		return (bool) apply_filters( 'workos_change_email_notify_old_address', $enabled );
	}

	/**
	 * Mask an email for inclusion in user-visible bodies.
	 *
	 * Mirrors the masking used by PasswordResetAdmin so notices look
	 * the same to end users (j•••@e•••.com).
	 *
	 * @param string $email Address.
	 *
	 * @return string Masked form.
	 */
	private function mask_email( string $email ): string {
		$at = strpos( $email, '@' );
		if ( false === $at || $at < 1 ) {
			return '•••';
		}

		$local  = substr( $email, 0, $at );
		$domain = substr( $email, $at + 1 );

		$local_mask  = ( $local[0] ?? '' ) . str_repeat( '•', max( 1, strlen( $local ) - 1 ) );
		$dot         = strrpos( $domain, '.' );
		$domain_mask = false === $dot
			? ( $domain[0] ?? '' ) . str_repeat( '•', max( 1, strlen( $domain ) - 1 ) )
			: ( $domain[0] ?? '' ) . str_repeat( '•', max( 1, $dot - 1 ) ) . substr( $domain, $dot );

		return $local_mask . '@' . $domain_mask;
	}
}
