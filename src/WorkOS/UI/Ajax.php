<?php
/**
 * AJAX handler for headless login.
 *
 * @package WorkOS\UI
 */

namespace WorkOS\UI;

use WorkOS\RateLimit\RateLimiter;
use WorkOS\Auth\Login;
use WorkOS\Http\ClientIp;
use WorkOS\Lock\Registry as LockRegistry;
use WorkOS\Sync\UserSync;
use WorkOS\Vendor\StellarWP\SuperGlobals\SuperGlobals;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the wp_ajax_nopriv_workos_headless_login action.
 *
 * This route reaches `authenticate_with_password` without going through
 * {@see \WorkOS\REST\Auth\BaseEndpoint}, so none of the throttling that guards
 * the REST sign-in path applies automatically — it has to be wired here by
 * hand. The nonce is not a substitute: it is minted for anonymous visitors by
 * the login-button script, so anyone can obtain one.
 */
class Ajax {

	/**
	 * Burst limiter.
	 *
	 * @var RateLimiter
	 */
	private RateLimiter $rate_limiter;

	/**
	 * Durable attempt-lock registry.
	 *
	 * @var LockRegistry
	 */
	private LockRegistry $locks;

	/**
	 * Client IP resolver.
	 *
	 * @var ClientIp
	 */
	private ClientIp $client_ip;

	/**
	 * Constructor — registers AJAX hooks.
	 *
	 * @param RateLimiter  $rate_limiter Burst limiter.
	 * @param LockRegistry $locks        Attempt-lock registry.
	 * @param ClientIp     $client_ip    Client IP resolver.
	 */
	public function __construct( RateLimiter $rate_limiter, LockRegistry $locks, ClientIp $client_ip ) {
		$this->client_ip    = $client_ip;
		$this->rate_limiter = $rate_limiter;
		$this->locks        = $locks;

		add_action( 'wp_ajax_workos_headless_login', [ $this, 'handle' ] );
		add_action( 'wp_ajax_nopriv_workos_headless_login', [ $this, 'handle' ] );
	}

	/**
	 * Handle the AJAX login request.
	 */
	public function handle(): void {
		check_ajax_referer( 'workos_login_button', 'nonce' );

		if ( ! workos()->is_enabled() ) {
			wp_send_json_error( [ 'message' => __( 'WorkOS is not configured.', 'integration-workos' ) ] );
		}

		$email    = sanitize_email( SuperGlobals::get_post_var( 'email' ) ?? '' );
		$password = wp_unslash( $_POST['password'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( ! $email || ! $password ) {
			wp_send_json_error( [ 'message' => __( 'Email and password are required.', 'integration-workos' ) ] );
		}

		$ip = $this->client_ip->get();

		$burst = $this->rate_limiter->attempt( 'headless_login_ip', $ip, 10, MINUTE_IN_SECONDS );
		if ( is_wp_error( $burst ) ) {
			wp_send_json_error( [ 'message' => $burst->get_error_message() ], 429 );
		}

		$burst = $this->rate_limiter->attempt( 'headless_login_email', $email, 5, MINUTE_IN_SECONDS );
		if ( is_wp_error( $burst ) ) {
			wp_send_json_error( [ 'message' => $burst->get_error_message() ], 429 );
		}

		$lock     = $this->locks->get( 'password_auth' );
		$subjects = null !== $lock ? [ $lock->for_email( $email ), $lock->for_ip( $ip ) ] : [];

		foreach ( $subjects as $subject ) {
			$gate = $lock->check( $subject );

			if ( is_wp_error( $gate ) ) {
				wp_send_json_error( [ 'message' => $gate->get_error_message() ], 429 );
			}
		}

		$result = workos()->api()->authenticate_with_password( $email, $password );

		if ( is_wp_error( $result ) ) {
			foreach ( $subjects as $subject ) {
				$lock->record( $subject );
			}

			// The upstream message distinguishes between causes of failure —
			// unknown account, wrong password, unverified address — which is
			// more than the caller needs and more than we should confirm.
			wp_send_json_error( [ 'message' => __( 'Those credentials were not accepted.', 'integration-workos' ) ] );
		}

		foreach ( $subjects as $subject ) {
			$lock->release( $subject );
		}

		$workos_user = $result['user'] ?? $result;
		$wp_user     = UserSync::find_or_create_wp_user( $workos_user );

		if ( is_wp_error( $wp_user ) ) {
			wp_send_json_error( [ 'message' => $wp_user->get_error_message() ] );
		}

		Login::store_tokens( $wp_user->ID, $result );

		/** This action is documented in Auth/Login.php */
		do_action( 'workos_user_authenticated', $wp_user->ID, $result );

		wp_set_auth_cookie( $wp_user->ID, true );
		wp_set_current_user( $wp_user->ID );

		/** This action is documented in Auth/Login.php */
		do_action( 'wp_login', $wp_user->user_login, $wp_user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Firing core wp_login action.

		$redirect_to = sanitize_url( SuperGlobals::get_post_var( 'redirect_to' ) ?? '' );
		if ( ! $redirect_to ) {
			$redirect_to = \WorkOS\Auth\Redirect::resolve( admin_url(), $wp_user );
		}

		wp_send_json_success( [ 'redirect_to' => $redirect_to ] );
	}
}
