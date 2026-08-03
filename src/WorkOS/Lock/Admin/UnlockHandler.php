<?php
/**
 * Handles the operator "clear locks" action.
 *
 * @package WorkOS\Lock\Admin
 */

namespace WorkOS\Lock\Admin;

use WorkOS\ActivityLog\EventLogger;
use WorkOS\Lock\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Clears every lock held against a user, from a plain admin-post request.
 *
 * Deliberately server-rendered rather than a REST route with a JavaScript
 * bundle behind it. A nonced `admin-post.php` round-trip reuses WordPress's own
 * capability and nonce handling, adds no public endpoint to defend, ships no
 * script, and still works with JavaScript switched off.
 *
 * The capability is `edit_users` — the capability to edit *other* people. The
 * more usual `edit_user( $id )` check would be wrong here: it passes for anyone
 * editing their own profile, which would let a locked-out user lift their own
 * lock from the profile screen and defeat the whole mechanism.
 */
class UnlockHandler {

	/**
	 * Admin-post action name.
	 */
	public const ACTION = 'workos_clear_locks';

	/**
	 * Nonce action prefix; the target user ID is appended.
	 */
	public const NONCE_ACTION = 'workos_clear_locks_';

	/**
	 * Query arg used to report the outcome on the redirect.
	 */
	public const NOTICE_ARG = 'workos_locks';

	/**
	 * Lock registry.
	 *
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * Constructor.
	 *
	 * @param Registry $registry Lock registry.
	 */
	public function __construct( Registry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle' ] );
		add_action( 'admin_notices', [ $this, 'render_notice' ] );
	}

	/**
	 * Remove hooks.
	 *
	 * @return void
	 */
	public function unregister(): void {
		remove_action( 'admin_post_' . self::ACTION, [ $this, 'handle' ] );
		remove_action( 'admin_notices', [ $this, 'render_notice' ] );
	}

	/**
	 * Build the nonced URL that clears a user's locks.
	 *
	 * @param int    $user_id  Target WP user ID.
	 * @param string $redirect Screen to return to once the action completes.
	 *
	 * @return string
	 */
	public function action_url( int $user_id, string $redirect = '' ): string {
		$url = add_query_arg(
			[
				'action'   => self::ACTION,
				'user_id'  => $user_id,
				'redirect' => rawurlencode( $redirect ),
			],
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url( $url, self::NONCE_ACTION . $user_id );
	}

	/**
	 * Handle the request.
	 *
	 * @return void
	 */
	public function handle(): void {
		// The nonce action is scoped to the target user, so the ID has to be
		// read before it can be verified. check_admin_referer() below is what
		// makes that safe — it dies unless the nonce matches this exact user.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$user_id = isset( $_REQUEST['user_id'] ) ? absint( wp_unslash( $_REQUEST['user_id'] ) ) : 0;

		if ( $user_id <= 0 ) {
			wp_die( esc_html__( 'No user was specified.', 'integration-workos' ), '', [ 'response' => 400 ] );
		}

		check_admin_referer( self::NONCE_ACTION . $user_id );

		// See the class docblock: `edit_users`, not `edit_user( $user_id )`.
		if ( ! current_user_can( 'edit_users' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to clear locks for this user.', 'integration-workos' ),
				'',
				[ 'response' => 403 ]
			);
		}

		$cleared = $this->registry->release_all_for_user( $user_id );

		$user = get_userdata( $user_id );

		EventLogger::log(
			'lock.cleared',
			[
				'user_id'    => $user_id,
				'user_email' => $user ? $user->user_email : '',
				'metadata'   => [
					'cleared_by' => get_current_user_id(),
					'locks'      => $cleared,
				],
			]
		);

		wp_safe_redirect( $this->redirect_target( $user_id, $cleared ) );
		exit;
	}

	/**
	 * Render the outcome notice after the redirect.
	 *
	 * @return void
	 */
	public function render_notice(): void {
		// A display-only flag set by our own redirect. It selects wording and
		// nothing else — no data is written on this path, so there is no state
		// change for a nonce to protect.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET[ self::NOTICE_ARG ] ) ) {
			return;
		}

		$cleared = absint( wp_unslash( $_GET[ self::NOTICE_ARG ] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				$cleared > 0
					? sprintf(
						/* translators: %d: number of locks cleared. */
						_n( '%d lock cleared.', '%d locks cleared.', $cleared, 'integration-workos' ),
						$cleared
					)
					: __( 'There were no locks to clear.', 'integration-workos' )
			)
		);
	}

	/**
	 * Work out where to send the operator once the action completes.
	 *
	 * @param int $user_id Target user ID.
	 * @param int $cleared Number of locks cleared.
	 *
	 * @return string
	 */
	private function redirect_target( int $user_id, int $cleared ): string {
		// Reached only after check_admin_referer() has passed in handle().
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$raw = isset( $_REQUEST['redirect'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['redirect'] ) ) : '';

		$requested = '' !== $raw ? rawurldecode( $raw ) : '';
		$referer   = wp_get_referer();
		$fallback  = add_query_arg( 'user_id', $user_id, admin_url( 'user-edit.php' ) );

		if ( '' !== $requested ) {
			$target = $requested;
		} elseif ( is_string( $referer ) && '' !== $referer ) {
			$target = $referer;
		} else {
			$target = $fallback;
		}

		// wp_safe_redirect() rejects off-host targets, so a tampered `redirect`
		// lands the operator back in wp-admin rather than anywhere else.
		return add_query_arg( self::NOTICE_ARG, $cleared, $target );
	}
}
