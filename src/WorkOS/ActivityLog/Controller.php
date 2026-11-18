<?php
/**
 * Activity Log Controller
 *
 * @package WorkOS\ActivityLog
 */

namespace WorkOS\ActivityLog;

defined( 'ABSPATH' ) || exit;

use WorkOS\Contracts\Controller as BaseController;

/**
 * Registers activity logging hooks.
 */
class Controller extends BaseController {

	/**
	 * Register activity log hooks.
	 *
	 * @return void
	 */
	protected function doRegister(): void {
		// Log login events.
		add_action( 'workos_user_authenticated', [ $this, 'log_login' ], 10, 2 );

		// Log logout.
		add_action( 'wp_logout', [ $this, 'log_logout' ], 5 );

		// Log failed logins.
		add_action( 'wp_login_failed', [ $this, 'log_login_failed' ], 10, 2 );

		// Log requests refused by a rate limit.
		add_action( 'workos_rate_limited', [ $this, 'log_rate_limited' ], 10, 3 );

		// Register admin page (always available to show stats even when logging is off).
		if ( is_admin() ) {
			$this->container->singleton( AdminPage::class );
			$this->container->get( AdminPage::class );
		}
	}

	/**
	 * Unregister.
	 *
	 * @return void
	 */
	protected function doUnregister(): void {
	}

	/**
	 * Log a successful login.
	 *
	 * @param int   $user_id     WP user ID.
	 * @param array $workos_data WorkOS auth response.
	 */
	public function log_login( int $user_id, array $workos_data ): void {
		EventLogger::log(
			'login',
			[
				'user_id'        => $user_id,
				'workos_user_id' => $workos_data['user']['id'] ?? '',
				'metadata'       => [
					'method' => ! empty( $workos_data['access_token'] ) ? 'authkit' : 'headless',
				],
			]
		);
	}

	/**
	 * Log a logout.
	 *
	 * @param int $user_id User ID.
	 */
	public function log_logout( int $user_id ): void {
		EventLogger::log( 'logout', [ 'user_id' => $user_id ] );
	}

	/**
	 * Log a failed login attempt.
	 *
	 * @param string    $username Username that was attempted.
	 * @param \WP_Error $error    Error object.
	 */
	public function log_login_failed( string $username, \WP_Error $error ): void {
		EventLogger::log(
			'login_failed',
			[
				'user_id'  => 0,
				'metadata' => [
					'username' => $username,
					'error'    => $error->get_error_code(),
				],
			]
		);
	}

	/**
	 * Log a request refused by a rate limit.
	 *
	 * Carries the bucket and the hashed subject only. The user and IP
	 * columns stay empty on purpose: for a per-IP or per-email bucket they
	 * would hold the very subject the hash is there to hide.
	 *
	 * @param string $bucket       Bucket that refused.
	 * @param string $subject_hash Keyed hash of the subject.
	 * @param int    $retry_after  Seconds until the refusing window rolls.
	 */
	public function log_rate_limited( string $bucket, string $subject_hash, int $retry_after ): void {
		EventLogger::log(
			'rate_limited',
			[
				'user_id'    => 0,
				'ip_address' => '',
				'metadata'   => [
					'bucket'       => $bucket,
					'subject_hash' => $subject_hash,
					'retry_after'  => $retry_after,
				],
			]
		);
	}
}
