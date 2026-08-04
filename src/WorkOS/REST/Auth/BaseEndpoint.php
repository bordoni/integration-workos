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
use WorkOS\Auth\AuthKit\RateLimiter;
use WorkOS\Http\ClientIp;
use WorkOS\Lock\Contracts\Lockable;
use WorkOS\Lock\Registry as LockRegistry;
use WorkOS\Lock\Subject;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Common concerns for every /wp-json/workos/v1/auth/* endpoint.
 *
 * - Profile resolution from the `profile` request param
 * - Per-profile nonce verification (accepts `X-WorkOS-Nonce` header or `_nonce` body param)
 * - Rate limiting via bucketed transients
 * - Durable attempt locks via {@see \WorkOS\Lock\Registry}
 * - Radar action-token extraction
 *
 * Throttling runs in two layers. {@see self::rate_limit()} is the cheap burst
 * check — cache-backed, short windows, rejects a flood before anything else
 * happens. {@see self::lock_check()} is the durable one: database-backed,
 * measured in hours, escalating on repeats, and unaffected by a cache flush.
 * Endpoints that spend money or send mail should apply both.
 *
 * Each subclass implements `register_routes()` and the route callbacks.
 */
abstract class BaseEndpoint {

	public const NAMESPACE = 'workos/v1';
	public const BASE      = '/auth';

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
	 * @var RateLimiter
	 */
	protected RateLimiter $rate_limiter;

	/**
	 * Client IP resolver.
	 *
	 * @var ClientIp
	 */
	protected ClientIp $client_ip;

	/**
	 * Login completer.
	 *
	 * @var LoginCompleter
	 */
	protected LoginCompleter $login_completer;

	/**
	 * Durable attempt-lock registry.
	 *
	 * @var LockRegistry
	 */
	protected LockRegistry $locks;

	/**
	 * Constructor.
	 *
	 * @param ProfileRepository $profiles        Profile repository.
	 * @param Nonce             $nonce           Nonce helper.
	 * @param Radar             $radar           Radar helper.
	 * @param RateLimiter       $rate_limiter    Rate limiter.
	 * @param ClientIp          $client_ip       Client IP resolver.
	 * @param LoginCompleter    $login_completer Login completer.
	 * @param LockRegistry      $locks           Attempt-lock registry.
	 */
	public function __construct(
		ProfileRepository $profiles,
		Nonce $nonce,
		Radar $radar,
		RateLimiter $rate_limiter,
		ClientIp $client_ip,
		LoginCompleter $login_completer,
		LockRegistry $locks
	) {
		$this->profiles        = $profiles;
		$this->nonce           = $nonce;
		$this->radar           = $radar;
		$this->rate_limiter    = $rate_limiter;
		$this->client_ip       = $client_ip;
		$this->login_completer = $login_completer;
		$this->locks           = $locks;
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
	 * email). Each entry is `[bucket, subject, limit, window_seconds]`.
	 * First bucket to exceed wins and returns a 429.
	 *
	 * @param array<int, array{0:string,1:string,2:int,3:int}> $rules Rate-limit rules.
	 *
	 * @return true|WP_Error
	 */
	protected function rate_limit( array $rules ) {
		foreach ( $rules as $rule ) {
			[ $bucket, $subject, $limit, $window ] = $rule;
			if ( '' === $subject ) {
				continue;
			}
			$result = $this->rate_limiter->attempt( $bucket, $subject, $limit, $window );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Resolve a lock by slug.
	 *
	 * @param string $slug Lock slug.
	 *
	 * @return Lockable|null Null when no lock is registered under that slug.
	 */
	protected function lock( string $slug ): ?Lockable {
		return $this->locks->get( $slug );
	}

	/**
	 * Gate a request against a lock, without counting anything.
	 *
	 * Call before the work worth throttling. Returns the first 429 among the
	 * given subjects, so list the most specific subject first — the caller sees
	 * the message that best explains why they were turned away.
	 *
	 * @param string              $slug     Lock slug.
	 * @param array<int, Subject> $subjects Subjects to test.
	 *
	 * @return true|WP_Error
	 */
	protected function lock_check( string $slug, array $subjects ) {
		$lock = $this->lock( $slug );

		if ( null === $lock ) {
			return true;
		}

		foreach ( $subjects as $subject ) {
			$result = $lock->check( $subject );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Count an attempt against a lock.
	 *
	 * Call after the work worth throttling, so the counter reflects what
	 * actually happened. For locks that count failures, call this on the
	 * failure branch and {@see self::lock_release()} on success.
	 *
	 * @param string              $slug     Lock slug.
	 * @param array<int, Subject> $subjects Subjects to count against.
	 *
	 * @return true|WP_Error The first 429 raised, or true when all subjects remain under threshold.
	 */
	protected function lock_record( string $slug, array $subjects ) {
		$lock = $this->lock( $slug );

		if ( null === $lock ) {
			return true;
		}

		$error = true;

		foreach ( $subjects as $subject ) {
			$result = $lock->record( $subject );

			// Keep going even once one subject trips: every subject named here
			// should reflect this attempt, otherwise the later ones undercount.
			if ( is_wp_error( $result ) && ! is_wp_error( $error ) ) {
				$error = $result;
			}
		}

		return $error;
	}

	/**
	 * Clear a lock for the given subjects, e.g. after a successful attempt.
	 *
	 * @param string              $slug     Lock slug.
	 * @param array<int, Subject> $subjects Subjects to clear.
	 *
	 * @return void
	 */
	protected function lock_release( string $slug, array $subjects ): void {
		$lock = $this->lock( $slug );

		if ( null === $lock ) {
			return;
		}

		foreach ( $subjects as $subject ) {
			$lock->release( $subject );
		}
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
