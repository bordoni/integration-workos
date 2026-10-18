<?php
/**
 * What happens around a change to a user's MFA.
 *
 * @package WorkOS\Mfa
 */

namespace WorkOS\Mfa;

use WorkOS\User;
use WP_Session_Tokens;

defined( 'ABSPATH' ) || exit;

/**
 * Emails the account and ends other sessions when its MFA changes.
 *
 * - Authenticator added or removed by the user: an email, and every other
 *   session signs out, so a session someone else got hold of doesn't
 *   outlive the change.
 * - Factors reset by an admin (or WP-CLI): an email, every session signs
 *   out, and the stored WorkOS session is revoked.
 * - Factors removed in WorkOS (its dashboard, another app): an email. The
 *   sessions stay; whoever removed them there can revoke WorkOS sessions.
 *
 * An authenticator added at sign-in also sends the email, so an owner
 * whose password was used to enroll someone else's app hears about it.
 */
class AccountChanges {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'workos_mfa_enrolled', [ $this, 'enrolled' ] );
		add_action( 'workos_mfa_factor_removed', [ $this, 'removed' ] );
		add_action( 'workos_mfa_reset', [ $this, 'reset' ] );
		add_action( 'workos_mfa_removed_in_workos', [ $this, 'removed_in_workos' ] );
	}

	/**
	 * A factor was confirmed.
	 *
	 * @param int $user_id WP user ID.
	 *
	 * @return void
	 */
	public function enrolled( $user_id ): void {
		$user_id = (int) $user_id;
		$this->sign_out_others( $user_id );
		$this->notify(
			$user_id,
			'enrolled',
			__( 'Two-step verification: authenticator added', 'integration-workos' ),
			__( 'An authenticator app was added to your account, and it will be asked for a code when you sign in.', 'integration-workos' )
		);
	}

	/**
	 * A factor was removed by its owner.
	 *
	 * @param int $user_id WP user ID.
	 *
	 * @return void
	 */
	public function removed( $user_id ): void {
		$user_id = (int) $user_id;
		$this->sign_out_others( $user_id );
		$this->notify(
			$user_id,
			'removed',
			__( 'Two-step verification: authenticator removed', 'integration-workos' ),
			__( 'An authenticator app was removed from your account.', 'integration-workos' )
		);
	}

	/**
	 * Every factor was removed by an admin.
	 *
	 * @param int $user_id WP user ID.
	 *
	 * @return void
	 */
	public function reset( $user_id ): void {
		$user_id = (int) $user_id;

		if ( get_current_user_id() !== $user_id ) {
			WP_Session_Tokens::get_instance( $user_id )->destroy_all();

			$session_id = User::get_session_id( $user_id );
			delete_user_meta( $user_id, User::META_ACCESS_TOKEN );
			delete_user_meta( $user_id, User::META_REFRESH_TOKEN );
			delete_user_meta( $user_id, User::META_SESSION_ID );
			delete_user_meta( $user_id, VerifiedSessions::META );
			if ( '' !== $session_id ) {
				workos()->api()->revoke_session( $session_id );
			}
		}

		$this->notify(
			$user_id,
			'reset',
			__( 'Two-step verification: authenticators removed', 'integration-workos' ),
			__( 'A site administrator removed every authenticator app from your account, and you were signed out everywhere. If your account requires two-step verification, you’ll set up a new app the next time you sign in.', 'integration-workos' )
		);
	}

	/**
	 * Every factor was removed in WorkOS, and WordPress followed.
	 *
	 * @param int $user_id WP user ID.
	 *
	 * @return void
	 */
	public function removed_in_workos( $user_id ): void {
		$this->notify(
			(int) $user_id,
			'removed_in_workos',
			__( 'Two-step verification: authenticators removed', 'integration-workos' ),
			__( 'Every authenticator app on your account was removed, so you’ll sign in without a code. If your account requires two-step verification, you’ll set up a new app the next time you sign in.', 'integration-workos' )
		);
	}

	/**
	 * End the user's other sessions, when the change came from the user.
	 *
	 * @param int $user_id WP user ID.
	 *
	 * @return void
	 */
	private function sign_out_others( int $user_id ): void {
		if ( $user_id <= 0 || get_current_user_id() !== $user_id ) {
			return;
		}

		WP_Session_Tokens::get_instance( $user_id )->destroy_others( wp_get_session_token() );
	}

	/**
	 * Email the account about the change.
	 *
	 * @param int    $user_id WP user ID.
	 * @param string $event   `enrolled`, `removed`, `reset` or `removed_in_workos`.
	 * @param string $subject Subject, without the site name.
	 * @param string $message What happened.
	 *
	 * @return void
	 */
	private function notify( int $user_id, string $event, string $subject, string $message ): void {
		$user = get_userdata( $user_id );
		if ( ! $user || '' === (string) $user->user_email ) {
			return;
		}

		/**
		 * Filters whether to email a user about a change to their MFA.
		 *
		 * @param bool   $send    Default true.
		 * @param int    $user_id WP user ID.
		 * @param string $event   `enrolled`, `removed`, `reset` or `removed_in_workos`.
		 */
		if ( ! apply_filters( 'workos_mfa_notify_user', true, $user_id, $event ) ) {
			return;
		}

		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$body = sprintf(
			/* translators: 1: user display name, 2: what changed, 3: site name, 4: site URL */
			__( "Hi %1\$s,\n\n%2\$s\n\nIf you didn’t do this, contact the administrator of %3\$s right away.\n\n%4\$s", 'integration-workos' ),
			$user->display_name,
			$message,
			$site,
			home_url( '/' )
		);

		wp_mail( $user->user_email, sprintf( '[%s] %s', $site, $subject ), $body );
	}
}
