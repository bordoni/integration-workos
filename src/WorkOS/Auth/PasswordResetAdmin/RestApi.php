<?php
/**
 * Admin-triggered password-reset REST endpoint.
 *
 * @package WorkOS\Auth\PasswordResetAdmin
 */

namespace WorkOS\Auth\PasswordResetAdmin;

use WorkOS\ActivityLog\EventLogger;
use WorkOS\Auth\AuthKit\Profile;
use WorkOS\Auth\AuthKit\ProfileRepository;
use WorkOS\Auth\AuthKit\RateLimiter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * `POST /workos/v1/admin/users/{id}/password-reset`.
 *
 * Triggers a WorkOS password-reset email on behalf of another user.
 * Requires `edit_user` on the target — which is true for an admin acting
 * on someone else and *also* true for any logged-in user acting on their
 * own ID (WP's default mapping). One endpoint, two valid call paths.
 *
 * Returns a sanitized "email hint" instead of the full address so
 * frontend confirmation surfaces don't leak the recipient.
 */
class RestApi {

	public const NAMESPACE = 'workos/v1';

	private const RATE_LIMIT_IP_ATTEMPTS   = 10;
	private const RATE_LIMIT_USER_ATTEMPTS = 5;
	private const RATE_LIMIT_WINDOW        = 60;

	/**
	 * Profile repository.
	 *
	 * @var ProfileRepository
	 */
	private ProfileRepository $profiles;

	/**
	 * Rate limiter.
	 *
	 * @var RateLimiter
	 */
	private RateLimiter $rate_limiter;

	/**
	 * Redirect URL validator.
	 *
	 * @var RedirectValidator
	 */
	private RedirectValidator $redirect_validator;

	/**
	 * Constructor.
	 *
	 * @param ProfileRepository $profiles           Profile repository.
	 * @param RateLimiter       $rate_limiter       Rate limiter.
	 * @param RedirectValidator $redirect_validator Redirect validator.
	 */
	public function __construct(
		ProfileRepository $profiles,
		RateLimiter $rate_limiter,
		RedirectValidator $redirect_validator
	) {
		$this->profiles           = $profiles;
		$this->rate_limiter       = $rate_limiter;
		$this->redirect_validator = $redirect_validator;
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
			'/admin/users/(?P<id>\d+)/password-reset',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'send_reset' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'id'           => [ 'sanitize_callback' => 'absint' ],
					'redirect_url' => [ 'sanitize_callback' => 'sanitize_text_field' ],
					'profile'      => [ 'sanitize_callback' => 'sanitize_title' ],
				],
			]
		);
	}

	/**
	 * Permission callback — `edit_user` on the target user.
	 *
	 * Granular by design: this is the WP capability that maps to "can edit
	 * the user with this ID", which is true for the user themselves (so
	 * self-service via the shortcode works) and true for admins/editors
	 * with the broader `edit_users` cap acting on others.
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return true|WP_Error
	 */
	public function check_permission( WP_REST_Request $request ) {
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
				__( 'You do not have permission to send a password reset for this user.', 'integration-workos' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Endpoint callback — send a WorkOS reset email for the target user.
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function send_reset( WP_REST_Request $request ) {
		$target_id = absint( $request['id'] );
		$user      = get_userdata( $target_id );
		if ( ! $user instanceof WP_User ) {
			return new WP_Error(
				'workos_user_not_found',
				__( 'User not found.', 'integration-workos' ),
				[ 'status' => 404 ]
			);
		}

		$workos_user_id = (string) get_user_meta( $user->ID, '_workos_user_id', true );
		if ( '' === $workos_user_id ) {
			return new WP_Error(
				'workos_user_not_linked',
				__( 'This user is not linked to a WorkOS account.', 'integration-workos' ),
				[ 'status' => 409 ]
			);
		}

		$email = (string) $user->user_email;
		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error(
				'workos_user_missing_email',
				__( 'This user has no usable email address.', 'integration-workos' ),
				[ 'status' => 409 ]
			);
		}

		$profile = $this->resolve_profile( (string) $request->get_param( 'profile' ) );
		if ( ! $profile instanceof Profile ) {
			return $profile;
		}

		if ( ! $profile->is_password_reset_flow_enabled() ) {
			return new WP_Error(
				'workos_reset_disabled',
				__( 'Password reset is not enabled for this login profile.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		$ip      = $this->rate_limiter->client_ip();
		$rate_ok = $this->rate_limiter->attempt(
			'pw_reset_admin_ip',
			$ip,
			self::RATE_LIMIT_IP_ATTEMPTS,
			self::RATE_LIMIT_WINDOW
		);
		if ( is_wp_error( $rate_ok ) ) {
			return $rate_ok;
		}
		$rate_ok = $this->rate_limiter->attempt(
			'pw_reset_admin_user',
			(string) $user->ID,
			self::RATE_LIMIT_USER_ATTEMPTS,
			self::RATE_LIMIT_WINDOW
		);
		if ( is_wp_error( $rate_ok ) ) {
			return $rate_ok;
		}

		$redirect_url = $this->redirect_validator->validate(
			(string) $request->get_param( 'redirect_url' ),
			$profile
		);

		$reset_url = $this->build_reset_url( $profile, $redirect_url );

		$response = workos()->api()->send_password_reset( $email, $reset_url );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		EventLogger::log(
			'password_reset.admin_sent',
			[
				'user_id'        => $user->ID,
				'user_email'     => $email,
				'workos_user_id' => $workos_user_id,
				'metadata'       => [
					'profile'      => $profile->get_slug(),
					'redirect_url' => $redirect_url,
					'initiator_id' => get_current_user_id(),
					'self_service' => get_current_user_id() === $user->ID,
				],
			]
		);

		return new WP_REST_Response(
			[
				'ok'           => true,
				'email_hint'   => $this->mask_email( $email ),
				'profile'      => $profile->get_slug(),
				'redirect_url' => $redirect_url,
			],
			200
		);
	}

	/**
	 * Resolve the login profile to use, defaulting to the canonical default.
	 *
	 * @param string $slug Optional profile slug from the request.
	 *
	 * @return Profile|WP_Error
	 */
	private function resolve_profile( string $slug ) {
		if ( '' !== $slug ) {
			$profile = $this->profiles->find_by_slug( $slug );
			if ( $profile ) {
				return $profile;
			}
			return new WP_Error(
				'workos_profile_not_found',
				__( 'Login profile not found.', 'integration-workos' ),
				[ 'status' => 404 ]
			);
		}

		$default = $this->profiles->find_by_slug( Profile::DEFAULT_SLUG );
		return $default ? $default : Profile::defaults();
	}

	/**
	 * Build the URL emailed to the user.
	 *
	 * Mirrors REST\Auth\Password::build_password_reset_url() so admin- and
	 * user-initiated emails carry the same URL shape. We re-use the URL
	 * builder by going through the same FrontendRoute helper.
	 *
	 * @param Profile $profile      Active profile.
	 * @param string  $redirect_url Validated redirect URL or empty.
	 *
	 * @return string
	 */
	private function build_reset_url( Profile $profile, string $redirect_url ): string {
		$base = html_entity_decode(
			\WorkOS\Auth\AuthKit\FrontendRoute::url_for_profile( $profile ),
			ENT_QUOTES | ENT_HTML5
		);

		$args = [];
		if ( '' !== $redirect_url ) {
			$args['redirect_to'] = $redirect_url;
		}

		return $args ? add_query_arg( $args, $base ) : $base;
	}

	/**
	 * Mask an email for display in admin notices.
	 *
	 * Preserves the first character of the local part and the TLD; the
	 * rest is replaced with `•`. Example: jdoe@example.com → j•••@e•••.com.
	 *
	 * @param string $email Email address.
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
