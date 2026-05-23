<?php
/**
 * REST endpoints for the WorkOS-verified email-change flow.
 *
 * @package WorkOS\Auth\ChangeEmail
 */

namespace WorkOS\Auth\ChangeEmail;

use WorkOS\ActivityLog\EventLogger;
use WorkOS\Auth\AuthKit\RateLimiter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Three endpoints, all under `/wp-json/workos/v1/`:
 *
 *  - `POST users/{id}/email-change`          → initiate
 *  - `POST users/{id}/email-change/confirm`  → confirm (consumes token)
 *  - `POST users/{id}/email-change/cancel`   → cancel  (consumes cancel token OR uses edit_user)
 *
 * Auth model mirrors PasswordResetAdmin: `edit_user` on the target,
 * which the WP cap mapper grants both for self (the user's own ID) and
 * for admins acting on another account.
 *
 * Rate limits are applied separately to per-IP and per-user buckets on
 * the initiate path so a flood from one IP can't block legitimate users.
 *
 * Verification is plugin-side: we generate a hashed token, email the
 * plaintext to the new address, then the confirm endpoint:
 *
 *  1. Re-runs the conflict resolver (a collision can appear between
 *     initiate and confirm — race guard).
 *  2. Sets a transient {@see TRANSIENT_PREFIX} to short-circuit the
 *     UserSync webhook handler while we're committing.
 *  3. Calls `workos()->api()->update_user()` to push the change.
 *  4. Mirrors into WordPress with `wp_update_user()`.
 *  5. Clears the transient and the pending meta.
 *
 * The transient TTL is 60 seconds — long enough to outlast the WorkOS
 * round-trip + the webhook's typical fan-in window, short enough that
 * an orphaned transient can't wedge sync indefinitely.
 */
class RestApi {

	public const NAMESPACE                = 'workos/v1';
	public const TRANSIENT_PREFIX         = '_workos_email_change_in_progress_';
	public const TRANSIENT_TTL            = 60;
	private const RATE_LIMIT_DEFAULT_USER = 3;
	private const RATE_LIMIT_DEFAULT_IP   = 10;
	private const RATE_LIMIT_DEFAULT_WIN  = 3600;

	/**
	 * Rate limiter.
	 *
	 * @var RateLimiter
	 */
	private RateLimiter $rate_limiter;

	/**
	 * Token factory.
	 *
	 * @var TokenFactory
	 */
	private TokenFactory $tokens;

	/**
	 * Pending-change storage.
	 *
	 * @var PendingChange
	 */
	private PendingChange $pending;

	/**
	 * Conflict resolver.
	 *
	 * @var ConflictResolver
	 */
	private ConflictResolver $conflicts;

	/**
	 * Notifier.
	 *
	 * @var Notifier
	 */
	private Notifier $notifier;

	/**
	 * Constructor.
	 *
	 * Redirect-URL validation is done inline in {@see validate_redirect()}
	 * rather than via the PasswordResetAdmin `RedirectValidator` because
	 * that helper requires a `Profile` (which doesn't apply to the
	 * change-email flow). The same same-host policy is enforced.
	 *
	 * @param RateLimiter      $rate_limiter Rate limiter.
	 * @param TokenFactory     $tokens       Token factory.
	 * @param PendingChange    $pending      Pending-change storage.
	 * @param ConflictResolver $conflicts    Conflict resolver.
	 * @param Notifier         $notifier     Email notifier.
	 */
	public function __construct(
		RateLimiter $rate_limiter,
		TokenFactory $tokens,
		PendingChange $pending,
		ConflictResolver $conflicts,
		Notifier $notifier
	) {
		$this->rate_limiter = $rate_limiter;
		$this->tokens       = $tokens;
		$this->pending      = $pending;
		$this->conflicts    = $conflicts;
		$this->notifier     = $notifier;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/users/(?P<id>\d+)/email-change',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'initiate' ],
				'permission_callback' => [ $this, 'check_permission_initiate' ],
				'args'                => [
					'id'           => [ 'sanitize_callback' => 'absint' ],
					'new_email'    => [ 'sanitize_callback' => 'sanitize_email' ],
					'redirect_url' => [ 'sanitize_callback' => 'sanitize_text_field' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/users/(?P<id>\d+)/email-change/confirm',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'confirm' ],
				// Token presence is the authentication — public route by design,
				// the token-hash check inside is the real gatekeeper.
				'permission_callback' => '__return_true',
				'args'                => [
					'id'    => [ 'sanitize_callback' => 'absint' ],
					'token' => [ 'sanitize_callback' => 'sanitize_text_field' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/users/(?P<id>\d+)/email-change/cancel',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'cancel' ],
				// Permission may be granted via edit_user OR a valid cancel
				// token; the handler resolves both paths.
				'permission_callback' => '__return_true',
				'args'                => [
					'id'    => [ 'sanitize_callback' => 'absint' ],
					'token' => [ 'sanitize_callback' => 'sanitize_text_field' ],
				],
			]
		);
	}

	/**
	 * Permission callback for initiate — `edit_user` on the target.
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return true|WP_Error
	 */
	public function check_permission_initiate( WP_REST_Request $request ) {
		$target_id = absint( $request['id'] ?? 0 );
		if ( $target_id <= 0 ) {
			return new WP_Error(
				'workos_invalid_user',
				__( 'Invalid user ID.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! current_user_can( 'edit_user', $target_id ) ) {
			return new WP_Error(
				'workos_forbidden',
				__( 'You do not have permission to change this user’s email.', 'integration-workos' ),
				[ 'status' => 403 ]
			);
		}

		/**
		 * Filter whether the current request can initiate a change for
		 * a given target.
		 *
		 * @param bool $allowed       Whether the request is allowed.
		 * @param int  $target_id     Target user ID.
		 * @param int  $initiator_id  Current user ID (0 for unauthenticated).
		 */
		$allowed = (bool) apply_filters(
			'workos_change_email_can_initiate',
			true,
			$target_id,
			get_current_user_id()
		);
		if ( ! $allowed ) {
			return new WP_Error(
				'workos_forbidden',
				__( 'You do not have permission to change this user’s email.', 'integration-workos' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Initiate a pending email change.
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function initiate( WP_REST_Request $request ) {
		$target_id = absint( $request['id'] );
		$user      = get_userdata( $target_id );
		if ( ! $user instanceof WP_User ) {
			return new WP_Error(
				'workos_user_not_found',
				__( 'User not found.', 'integration-workos' ),
				[ 'status' => 404 ]
			);
		}

		$new_email = sanitize_email( (string) $request->get_param( 'new_email' ) );
		if ( '' === $new_email || ! is_email( $new_email ) ) {
			return new WP_Error(
				'workos_invalid_email',
				__( 'A valid email address is required.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}
		$new_email = strtolower( $new_email );

		$ip          = $this->rate_limiter->client_ip();
		$user_count  = (int) workos()->option( 'change_email_rate_limit_user_count', self::RATE_LIMIT_DEFAULT_USER );
		$user_window = (int) workos()->option( 'change_email_rate_limit_user_window', self::RATE_LIMIT_DEFAULT_WIN );
		$ip_count    = (int) workos()->option( 'change_email_rate_limit_ip_count', self::RATE_LIMIT_DEFAULT_IP );
		$ip_window   = (int) workos()->option( 'change_email_rate_limit_ip_window', self::RATE_LIMIT_DEFAULT_WIN );

		$rate_ok = $this->rate_limiter->attempt( 'change_email_init_ip', $ip, $ip_count, $ip_window );
		if ( is_wp_error( $rate_ok ) ) {
			return $rate_ok;
		}
		$rate_ok = $this->rate_limiter->attempt( 'change_email_init_user', (string) $user->ID, $user_count, $user_window );
		if ( is_wp_error( $rate_ok ) ) {
			return $rate_ok;
		}

		// Treat "change to the address I already have" as a benign no-op
		// rather than an error, but also don't ship a verification email
		// — there's nothing to verify.
		if ( strcasecmp( $new_email, (string) $user->user_email ) === 0 ) {
			return new WP_REST_Response(
				[
					'ok'               => true,
					'masked_new_email' => $this->mask_email( $new_email ),
					'no_op'            => true,
				],
				200
			);
		}

		$conflict = $this->conflicts->check( $new_email, $user );
		if ( is_wp_error( $conflict ) ) {
			EventLogger::log(
				'email_change.conflict_blocked',
				[
					'user_id'  => $user->ID,
					'metadata' => [
						'masked_new_email' => $this->mask_email( $new_email ),
						'policy'           => $this->conflicts->resolve_policy(),
						'initiator_id'     => get_current_user_id(),
					],
				]
			);

			// Enumeration-safe: same shape as success. The conflict is
			// surfaced in the activity log, not in the response.
			return new WP_REST_Response(
				[
					'ok'               => true,
					'masked_new_email' => $this->mask_email( $new_email ),
				],
				200
			);
		}

		$lifetime = $this->token_lifetime();
		$expires  = time() + $lifetime;

		$confirm_token = $this->tokens->generate();
		$cancel_token  = $this->tokens->generate();

		$this->pending->store(
			(int) $user->ID,
			$new_email,
			$confirm_token,
			$cancel_token,
			$expires,
			get_current_user_id()
		);

		$redirect_url = (string) $request->get_param( 'redirect_url' );
		$confirm_url  = $this->build_confirm_url( (int) $user->ID, $confirm_token, $redirect_url );
		$cancel_url   = $this->build_cancel_url( (int) $user->ID, $cancel_token );

		$this->notifier->send_verification( $user, $new_email, $confirm_url, $expires );
		$this->notifier->send_old_address_notice( $user, $new_email, $cancel_url, $expires );

		EventLogger::log(
			'email_change.initiated',
			[
				'user_id'  => $user->ID,
				'metadata' => [
					'masked_new_email' => $this->mask_email( $new_email ),
					'initiator_id'     => get_current_user_id(),
					'self_service'     => get_current_user_id() === (int) $user->ID,
					'expires_at'       => $expires,
				],
			]
		);

		/**
		 * Fires after a pending email change is stored and notifications sent.
		 *
		 * @param int    $user_id      Target WP user ID.
		 * @param string $new_email    Requested new address.
		 * @param int    $initiated_by Current user ID (0 for system).
		 */
		do_action( 'workos_change_email_initiated', (int) $user->ID, $new_email, get_current_user_id() );

		return new WP_REST_Response(
			[
				'ok'               => true,
				'masked_new_email' => $this->mask_email( $new_email ),
				'expires_at'       => $expires,
			],
			200
		);
	}

	/**
	 * Confirm a pending email change.
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function confirm( WP_REST_Request $request ) {
		$target_id = absint( $request['id'] );
		$token     = (string) $request->get_param( 'token' );
		if ( $target_id <= 0 || '' === $token ) {
			return new WP_Error(
				'workos_invalid_request',
				__( 'Invalid confirmation request.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		$user = get_userdata( $target_id );
		if ( ! $user instanceof WP_User ) {
			return new WP_Error(
				'workos_invalid_token',
				__( 'This confirmation link is no longer valid.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		$record = $this->pending->get( (int) $user->ID );
		if ( null === $record ) {
			return new WP_Error(
				'workos_invalid_token',
				__( 'This confirmation link is no longer valid.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		if ( $this->pending->expired( $record ) ) {
			$this->pending->clear( (int) $user->ID );
			EventLogger::log(
				'email_change.expired',
				[
					'user_id'  => $user->ID,
					'metadata' => [ 'masked_new_email' => $this->mask_email( $record['new_email'] ) ],
				]
			);
			return new WP_Error(
				'workos_token_expired',
				__( 'This confirmation link has expired. Please start the change again.', 'integration-workos' ),
				[ 'status' => 410 ]
			);
		}

		if ( ! $this->pending->verify_confirm( $record, $token ) ) {
			return new WP_Error(
				'workos_invalid_token',
				__( 'This confirmation link is no longer valid.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		$new_email = (string) $record['new_email'];

		// Race re-check: another local user may have started using
		// $new_email between initiate and confirm.
		$conflict = $this->conflicts->check( $new_email, $user );
		if ( is_wp_error( $conflict ) ) {
			$this->pending->clear( (int) $user->ID );
			EventLogger::log(
				'email_change.conflict_blocked',
				[
					'user_id'  => $user->ID,
					'metadata' => [
						'masked_new_email' => $this->mask_email( $new_email ),
						'phase'            => 'confirm',
						'policy'           => $this->conflicts->resolve_policy(),
					],
				]
			);
			return $conflict;
		}

		$old_email      = (string) $user->user_email;
		$workos_user_id = (string) get_user_meta( $user->ID, '_workos_user_id', true );

		// Set the in-progress transient BEFORE we touch WorkOS so the
		// user.updated webhook fan-back is a no-op while we own the
		// transition. {@see UserSync::handle_user_updated()}.
		set_transient( self::TRANSIENT_PREFIX . (int) $user->ID, 1, self::TRANSIENT_TTL );

		if ( '' !== $workos_user_id ) {
			$workos_response = workos()->api()->update_user( $workos_user_id, [ 'email' => $new_email ] );
			if ( is_wp_error( $workos_response ) ) {
				delete_transient( self::TRANSIENT_PREFIX . (int) $user->ID );
				EventLogger::log(
					'email_change.commit_failed',
					[
						'user_id'  => $user->ID,
						'metadata' => [
							'masked_new_email' => $this->mask_email( $new_email ),
							'reason'           => $workos_response->get_error_message(),
						],
					]
				);
				return new WP_Error(
					'workos_commit_failed',
					__( 'Could not update the email at WorkOS. Please try again.', 'integration-workos' ),
					[ 'status' => 502 ]
				);
			}
		}

		$wp_update = wp_update_user(
			[
				'ID'         => (int) $user->ID,
				'user_email' => $new_email,
			]
		);

		if ( is_wp_error( $wp_update ) ) {
			// Rollback WorkOS so the two stores don't drift.
			if ( '' !== $workos_user_id && '' !== $old_email ) {
				workos()->api()->update_user( $workos_user_id, [ 'email' => $old_email ] );
			}
			delete_transient( self::TRANSIENT_PREFIX . (int) $user->ID );
			EventLogger::log(
				'email_change.commit_failed',
				[
					'user_id'  => $user->ID,
					'metadata' => [
						'masked_new_email' => $this->mask_email( $new_email ),
						'reason'           => $wp_update->get_error_message(),
						'rolled_back'      => true,
					],
				]
			);
			return new WP_Error(
				'workos_commit_failed',
				__( 'Could not update the email locally. Please try again.', 'integration-workos' ),
				[ 'status' => 500 ]
			);
		}

		$this->pending->clear( (int) $user->ID );
		delete_transient( self::TRANSIENT_PREFIX . (int) $user->ID );

		// Refresh user object now that the email is committed.
		$user = get_userdata( (int) $user->ID );
		if ( $user instanceof WP_User ) {
			$this->notifier->send_confirmation_notice( $user, $old_email, $new_email );
		}

		EventLogger::log(
			'email_change.confirmed',
			[
				'user_id'  => $target_id,
				'metadata' => [
					'masked_new_email' => $this->mask_email( $new_email ),
					'masked_old_email' => $this->mask_email( $old_email ),
				],
			]
		);

		/**
		 * Fires after an email change is committed to WorkOS + WP.
		 *
		 * @param int    $user_id   Target WP user ID.
		 * @param string $old_email Previous email.
		 * @param string $new_email New email.
		 */
		do_action( 'workos_change_email_confirmed', $target_id, $old_email, $new_email );

		$redirect_url = $this->validate_redirect( (string) $request->get_param( 'redirect_url' ) );

		return new WP_REST_Response(
			[
				'ok'           => true,
				'redirect_url' => $redirect_url,
			],
			200
		);
	}

	/**
	 * Cancel a pending email change.
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function cancel( WP_REST_Request $request ) {
		$target_id = absint( $request['id'] );
		$user      = get_userdata( $target_id );
		if ( ! $user instanceof WP_User ) {
			return new WP_Error(
				'workos_user_not_found',
				__( 'User not found.', 'integration-workos' ),
				[ 'status' => 404 ]
			);
		}

		$record = $this->pending->get( (int) $user->ID );
		if ( null === $record ) {
			// Nothing to cancel — treat as success so a double-click on
			// the cancel link doesn't look like an error.
			return new WP_REST_Response( [ 'ok' => true ], 200 );
		}

		$token         = (string) $request->get_param( 'token' );
		$by_token      = '' !== $token && $this->pending->verify_cancel( $record, $token );
		$by_capability = current_user_can( 'edit_user', $user->ID );

		if ( ! $by_token && ! $by_capability ) {
			return new WP_Error(
				'workos_forbidden',
				__( 'You do not have permission to cancel this email change.', 'integration-workos' ),
				[ 'status' => 403 ]
			);
		}

		$this->pending->clear( (int) $user->ID );

		EventLogger::log(
			'email_change.cancelled',
			[
				'user_id'  => $user->ID,
				'metadata' => [
					'masked_new_email' => $this->mask_email( (string) $record['new_email'] ),
					'reason'           => $by_token ? 'token' : 'capability',
				],
			]
		);

		/**
		 * Fires after a pending change is cancelled.
		 *
		 * @param int    $user_id Target WP user ID.
		 * @param string $reason  'token' or 'capability'.
		 */
		do_action( 'workos_change_email_cancelled', (int) $user->ID, $by_token ? 'token' : 'capability' );

		return new WP_REST_Response( [ 'ok' => true ], 200 );
	}

	/**
	 * Resolve the configured token lifetime (clamped to [300, 86400]).
	 *
	 * @return int
	 */
	private function token_lifetime(): int {
		$lifetime = (int) workos()->option( 'change_email_token_lifetime', 3600 );

		/**
		 * Filter the change-email token lifetime in seconds.
		 *
		 * @param int $lifetime Lifetime in seconds.
		 */
		$lifetime = (int) apply_filters( 'workos_change_email_token_lifetime', $lifetime );

		// Defensive clamp so a misconfiguration can't issue infinite or
		// 1-second tokens.
		return max( 300, min( 86400, $lifetime ) );
	}

	/**
	 * Build the frontend confirm URL for an emailed link.
	 *
	 * @param int    $user_id      Target user ID.
	 * @param string $token        Plaintext confirm token.
	 * @param string $redirect_url Optional same-host redirect after success.
	 *
	 * @return string
	 */
	private function build_confirm_url( int $user_id, string $token, string $redirect_url ): string {
		$path = (string) workos()->option( 'change_email_confirm_path', 'workos/change-email' );
		$path = trim( $path, '/' );

		$base = home_url( '/' . $path . '/' );

		$args = [
			'user_id' => $user_id,
			'token'   => $token,
		];
		if ( '' !== $redirect_url ) {
			$args['redirect_to'] = $redirect_url;
		}

		return add_query_arg( $args, $base );
	}

	/**
	 * Build the cancel URL emailed to the old address.
	 *
	 * The cancel URL points at the same frontend confirm route with
	 * `action=cancel`, which the page-side JS picks up to POST to the
	 * cancel endpoint instead of confirm.
	 *
	 * @param int    $user_id Target user ID.
	 * @param string $token   Plaintext cancel token.
	 *
	 * @return string
	 */
	private function build_cancel_url( int $user_id, string $token ): string {
		$path = (string) workos()->option( 'change_email_confirm_path', 'workos/change-email' );
		$path = trim( $path, '/' );

		$base = home_url( '/' . $path . '/' );

		return add_query_arg(
			[
				'user_id' => $user_id,
				'token'   => $token,
				'action'  => 'cancel',
			],
			$base
		);
	}

	/**
	 * Validate a redirect URL against site host, falling back to home.
	 *
	 * @param string $url Raw URL.
	 *
	 * @return string
	 */
	private function validate_redirect( string $url ): string {
		if ( '' === $url ) {
			return home_url( '/' );
		}

		$candidate = wp_validate_redirect( $url, '' );
		if ( '' === $candidate ) {
			return home_url( '/' );
		}

		$url_host  = strtolower( (string) wp_parse_url( $candidate, PHP_URL_HOST ) );
		$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		return $url_host === $site_host ? $candidate : home_url( '/' );
	}

	/**
	 * Mask an email for activity-log + response surfacing.
	 *
	 * @param string $email Address.
	 *
	 * @return string
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
