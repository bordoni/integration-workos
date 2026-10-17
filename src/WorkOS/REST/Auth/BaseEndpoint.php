<?php
/**
 * Shared base for public AuthKit REST endpoints.
 *
 * @package WorkOS\REST\Auth
 */

namespace WorkOS\REST\Auth;

use WorkOS\Auth\AuthKit\LoginCompleter;
use WorkOS\Auth\AuthKit\Nonce;
use WorkOS\Auth\AuthKit\Profile;
use WorkOS\Auth\AuthKit\ProfileRepository;
use WorkOS\Auth\AuthKit\Radar;
use WorkOS\Email\AddressCanonicalizer;
use WorkOS\Http\ClientIp;
use WorkOS\RateLimit\TieredRateLimiter;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Common concerns for every /wp-json/workos/v1/auth/* endpoint.
 *
 * - Profile resolution from the `profile` request param
 * - Per-profile nonce verification (accepts `X-WorkOS-Nonce` header or `_nonce` body param)
 * - Rate limiting via bucketed transients
 * - Radar action-token extraction
 *
 * Each subclass implements `register_routes()` and the route callbacks.
 */
abstract class BaseEndpoint {

	public const NAMESPACE = 'workos/v1';
	public const BASE      = '/auth';

	/**
	 * Buckets for endpoints that mail a caller-chosen address.
	 *
	 * Shared on purpose: every route counting against the same pair means
	 * rotating between them earns an attacker no extra mail. Splitting
	 * them per endpoint multiplies what one inbox can be sent.
	 */
	public const BUCKET_OTP_SEND_IP        = 'otp_send_ip';
	public const BUCKET_OTP_SEND_RECIPIENT = 'otp_send_recipient';

	/**
	 * Buckets for verification codes sent during password sign-in, and
	 * their resends.
	 *
	 * Constants because reserve and refund must name the same bucket: a
	 * mismatch would leave every reservation charged forever. Separate
	 * from the shared `otp_send` pair so heavy magic-code use can't lock
	 * an account out of password sign-in.
	 */
	public const BUCKET_PASSWORD_OTP_IP        = 'password_otp_ip';
	public const BUCKET_PASSWORD_OTP_RECIPIENT = 'password_otp_recipient';

	/**
	 * Profile repository.
	 *
	 * @var ProfileRepository
	 */
	protected ProfileRepository $profiles;

	/**
	 * Nonce helper.
	 *
	 * @var Nonce
	 */
	protected Nonce $nonce;

	/**
	 * Radar helper.
	 *
	 * @var Radar
	 */
	protected Radar $radar;

	/**
	 * Rate limiter.
	 *
	 * @var TieredRateLimiter
	 */
	protected TieredRateLimiter $rate_limiter;

	/**
	 * Client IP resolver.
	 *
	 * @var ClientIp
	 */
	protected ClientIp $client_ip;

	/**
	 * Mailbox canonicalizer for rate-limit subjects.
	 *
	 * @var AddressCanonicalizer
	 */
	protected AddressCanonicalizer $canonicalizer;

	/**
	 * Login completer.
	 *
	 * @var LoginCompleter
	 */
	protected LoginCompleter $login_completer;

	/**
	 * Constructor.
	 *
	 * @param ProfileRepository    $profiles        Profile repository.
	 * @param Nonce                $nonce           Nonce helper.
	 * @param Radar                $radar           Radar helper.
	 * @param TieredRateLimiter    $rate_limiter    Rate limiter.
	 * @param ClientIp             $client_ip       Client IP resolver.
	 * @param AddressCanonicalizer $canonicalizer   Mailbox canonicalizer.
	 * @param LoginCompleter       $login_completer Login completer.
	 */
	public function __construct(
		ProfileRepository $profiles,
		Nonce $nonce,
		Radar $radar,
		TieredRateLimiter $rate_limiter,
		ClientIp $client_ip,
		AddressCanonicalizer $canonicalizer,
		LoginCompleter $login_completer
	) {
		$this->profiles        = $profiles;
		$this->nonce           = $nonce;
		$this->radar           = $radar;
		$this->rate_limiter    = $rate_limiter;
		$this->client_ip       = $client_ip;
		$this->canonicalizer   = $canonicalizer;
		$this->login_completer = $login_completer;
	}

	/**
	 * Register this endpoint's REST routes.
	 *
	 * @return void
	 */
	abstract public function register_routes(): void;

	/**
	 * Public permission check used for anonymous auth routes.
	 *
	 * Always returns true — access control is enforced per-request via
	 * {@see self::verify_nonce()} and {@see self::rate_limit()}. The
	 * method exists so we can pass `[ $this, 'public_permission' ]` to
	 * `register_rest_route()` without magic-method complexity.
	 *
	 * @return true
	 */
	public function public_permission(): bool {
		return true;
	}

	/**
	 * Resolve the Login Profile for a request.
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return Profile|WP_Error
	 */
	protected function resolve_profile( WP_REST_Request $request ) {
		$slug = sanitize_title( (string) $request->get_param( 'profile' ) );
		if ( '' === $slug ) {
			return new WP_Error(
				'workos_authkit_missing_profile',
				__( 'A profile is required.', 'integration-workos' ),
				[ 'status' => 400 ]
			);
		}

		$profile = $this->profiles->find_by_slug( $slug );
		if ( ! $profile ) {
			return new WP_Error(
				'workos_authkit_profile_not_found',
				__( 'Login Profile not found.', 'integration-workos' ),
				[ 'status' => 404 ]
			);
		}

		return $profile;
	}

	/**
	 * Verify the profile-scoped nonce on a mutation request.
	 *
	 * Accepts either the `X-WorkOS-Nonce` header (preferred) or a `_nonce`
	 * body/query param for simple form submissions.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @param Profile         $profile Resolved profile.
	 *
	 * @return true|WP_Error
	 */
	protected function verify_nonce( WP_REST_Request $request, Profile $profile ) {
		$nonce = (string) $request->get_header( 'X-WorkOS-Nonce' );
		if ( '' === $nonce ) {
			$nonce = (string) $request->get_param( '_nonce' );
		}

		if ( ! $this->nonce->verify( $nonce, $profile->get_slug() ) ) {
			return new WP_Error(
				'workos_authkit_invalid_nonce',
				__( 'Your login session has expired. Please refresh and try again.', 'integration-workos' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Apply rate limits for a request.
	 *
	 * Provide one entry per subject that should be throttled (e.g. IP and
	 * email). Each entry is `[bucket, subject, policy]`, where policy is a
	 * tier list from {@see TieredRateLimiter}. First bucket to exceed wins
	 * and returns a 429.
	 *
	 * @param array<int, array{0:string,1:string,2:array}> $rules Rate-limit rules.
	 *
	 * @return true|WP_Error
	 */
	protected function rate_limit( array $rules ) {
		foreach ( $rules as $rule ) {
			[ $bucket, $subject, $policy ] = $rule;
			if ( '' === $subject ) {
				continue;
			}
			$result = $this->rate_limiter->attempt( $bucket, $subject, $policy );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Extract Radar action token from a request.
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return string|null
	 */
	protected function get_radar_token( WP_REST_Request $request ): ?string {
		return $this->radar->extract_from_request( $request );
	}

	/**
	 * Validate a redirect_to URL against the site host.
	 *
	 * @param string $redirect_to Raw value from the client.
	 *
	 * @return string Empty string when invalid or not on-site.
	 */
	protected function sanitize_redirect( string $redirect_to ): string {
		if ( '' === $redirect_to ) {
			return '';
		}

		$validated = wp_validate_redirect( $redirect_to, '' );

		return is_string( $validated ) ? $validated : '';
	}
}
