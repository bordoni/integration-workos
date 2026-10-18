<?php
/**
 * MFA factor service.
 *
 * @package WorkOS\Mfa
 */

namespace WorkOS\Mfa;

use WorkOS\ActivityLog\EventLogger;
use WorkOS\App;
use WorkOS\User;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The one code path for a user's MFA factors.
 *
 * REST routes, the PHP helpers, WP-CLI and the admin screens all call
 * this class, so rate limits aside, every surface behaves the same and
 * fires the same hooks and activity-log events.
 *
 * WorkOS holds the factors and their secrets. WordPress mirrors only:
 *
 * - `_workos_mfa_enabled`: the user has a confirmed factor. WorkOS is the
 *   source of truth: the flag is set when a factor is confirmed or found in
 *   WorkOS, and cleared when the user removes their last factor, an admin
 *   resets them, or WorkOS answers with none (removed in the WorkOS
 *   dashboard or another app). A failed WorkOS call never clears it.
 * - `_workos_mfa_required`: an admin requires MFA for the user.
 * - `_workos_mfa_pending_factor`: a factor enrolled but not yet confirmed
 *   with a code. It never counts as enabled, and is deleted from WorkOS
 *   when the user starts over or it goes stale.
 *
 * No method checks capabilities: callers do.
 */
class FactorService {

	/**
	 * How long an unconfirmed factor is kept, in seconds.
	 */
	public const PENDING_FACTOR_TTL = DAY_IN_SECONDS;

	/**
	 * Per-account lock and code limit.
	 *
	 * @var CodeGuard
	 */
	private CodeGuard $guard;

	/**
	 * Constructor.
	 *
	 * @param CodeGuard|null $guard Per-account lock and code limit; the container's when omitted.
	 */
	public function __construct( ?CodeGuard $guard = null ) {
		$this->guard = $guard ?? App::container()->get( CodeGuard::class );
	}

	/**
	 * The user's MFA status, reconciled with WorkOS.
	 *
	 * @param int $user_id WP user ID.
	 *
	 * @return array|WP_Error {
	 *     @type bool  $enabled     Whether the user has a confirmed factor.
	 *     @type bool  $required    Whether an admin set the require flag.
	 *     @type bool  $must_use    Whether the user must use MFA for any reason (flag, role, filter).
	 *     @type array $factors     Confirmed factors: `{ id, type, created }`.
	 * }
	 */
	public function status( int $user_id ) {
		$factors = $this->list_factors( $user_id );
		if ( is_wp_error( $factors ) ) {
			return $factors;
		}

		return [
			'enabled'  => ! empty( $factors ),
			'required' => $this->is_required_flag( $user_id ),
			'must_use' => App::container()->get( Policy::class )->is_required( $user_id ),
			'factors'  => $factors,
		];
	}

	/**
	 * Confirmed factors, read from WorkOS.
	 *
	 * Mirrors the answer into `_workos_mfa_enabled`: set when WorkOS has a
	 * factor the flag didn't know about (added outside WordPress, or before
	 * this version), cleared when WorkOS has none left. Drops a stale
	 * unconfirmed factor.
	 *
	 * @param int $user_id WP user ID.
	 *
	 * @return array|WP_Error List of `{ id, type, created }`.
	 */
	public function list_factors( int $user_id ) {
		$workos_id = $this->workos_id( $user_id );
		if ( is_wp_error( $workos_id ) ) {
			return $workos_id;
		}

		$result = workos()->api()->list_auth_factors( $workos_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$pending = $this->pending_factor( $user_id );
		if ( $pending && ( time() - $pending['created'] ) > self::PENDING_FACTOR_TTL && $this->drop_pending( $user_id, $pending ) ) {
			$pending = null;
		}

		$factors = [];
		foreach ( (array) ( $result['data'] ?? [] ) as $factor ) {
			if ( ! is_array( $factor ) || empty( $factor['id'] ) ) {
				continue;
			}
			if ( $pending && $pending['factor_id'] === $factor['id'] ) {
				continue;
			}
			$factors[] = [
				'id'      => (string) $factor['id'],
				'type'    => (string) ( $factor['type'] ?? '' ),
				'created' => (string) ( $factor['created_at'] ?? '' ),
			];
		}

		if ( ! empty( $factors ) ) {
			$this->sync_enabled( $user_id, true );
		} elseif ( $this->is_enabled( $user_id ) ) {
			$this->removed_in_workos( $user_id );
		}

		return $factors;
	}

	/**
	 * Follow factors removed outside WordPress: turn the user's MFA off.
	 *
	 * The require flag and required roles are untouched, so a user who must
	 * use MFA enrolls again at their next sign-in.
	 *
	 * @param int $user_id WP user ID.
	 *
	 * @return void
	 */
	private function removed_in_workos( int $user_id ): void {
		$this->sync_enabled( $user_id, false );

		EventLogger::log(
			'mfa.removed_in_workos',
			[
				'user_id' => $user_id,
			]
		);

		/**
		 * Fires when WorkOS has no factors left for a user WordPress had as
		 * enrolled, and WordPress turns their MFA off to match.
		 *
		 * @param int $user_id WP user ID.
		 */
		do_action( 'workos_mfa_removed_in_workos', $user_id );
	}

	/**
	 * Start a TOTP enrollment.
	 *
	 * Creates the factor in WorkOS and remembers it as unconfirmed. Any
	 * earlier unconfirmed factor is deleted first, so restarting doesn't
	 * pile up factors the user never finished. Only one enrollment per
	 * account runs at a time: two at once would leave a factor nobody
	 * tracks as unconfirmed, which would then count as confirmed.
	 *
	 * Doesn't check whether the user already has a factor: callers that
	 * shouldn't let a session add a second one without a code check first.
	 *
	 * @param int $user_id WP user ID.
	 *
	 * @return array|WP_Error `{ factor_id, challenge_id, qr_code, secret, otpauth_uri }`.
	 */
	public function start_enrollment( int $user_id ) {
		$workos_id = $this->workos_id( $user_id );
		if ( is_wp_error( $workos_id ) ) {
			return $workos_id;
		}

		$subject = 'enroll:' . $user_id;
		if ( ! $this->guard->lock( $subject ) ) {
			return new WP_Error(
				'workos_mfa_busy',
				__( 'Another setup is in progress for this account. Try again in a moment.', 'integration-workos' ),
				[ 'status' => 429 ]
			);
		}

		try {
			$pending = $this->pending_factor( $user_id );
			if ( $pending && ! $this->drop_pending( $user_id, $pending ) ) {
				return new WP_Error(
					'workos_mfa_pending_cleanup_failed',
					__( 'Couldn’t clear the earlier setup. Try again in a moment.', 'integration-workos' ),
					[ 'status' => 502 ]
				);
			}

			$user   = get_userdata( $user_id );
			$enroll = $this->enroll_totp( $workos_id, $user ? (string) $user->user_email : '', $user_id );
			if ( is_wp_error( $enroll ) ) {
				return $enroll;
			}

			update_user_meta(
				$user_id,
				User::META_MFA_PENDING_FACTOR,
				[
					'factor_id' => $enroll['factor_id'],
					'created'   => time(),
				]
			);

			return $enroll;
		} finally {
			$this->guard->unlock( $subject );
		}
	}

	/**
	 * Create a TOTP factor on a WorkOS user, with nothing stored in WordPress.
	 *
	 * `start_enrollment()` builds on it. The sign-in flow uses it directly
	 * when WorkOS answers `mfa_enrollment`, where the WP user may not
	 * exist yet.
	 *
	 * @param string $workos_id WorkOS user ID.
	 * @param string $label     Account label the authenticator app shows (usually the email).
	 * @param int    $user_id   WP user ID, when known (passed to the issuer filter).
	 *
	 * @return array|WP_Error `{ factor_id, challenge_id, qr_code, secret, otpauth_uri }`.
	 */
	public function enroll_totp( string $workos_id, string $label, int $user_id = 0 ) {
		/**
		 * Filters the issuer an authenticator app shows for the factor.
		 *
		 * @param string $issuer  Issuer. Default: the site name.
		 * @param int    $user_id WP user ID, or 0 when the sign-in hasn't matched one yet.
		 */
		$issuer = (string) apply_filters( 'workos_mfa_totp_issuer', (string) get_bloginfo( 'name' ), $user_id );

		$result = workos()->api()->enroll_totp_factor( $workos_id, $issuer, $label );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$factor    = isset( $result['authentication_factor'] ) && is_array( $result['authentication_factor'] ) ? $result['authentication_factor'] : $result;
		$challenge = isset( $result['authentication_challenge'] ) && is_array( $result['authentication_challenge'] ) ? $result['authentication_challenge'] : [];
		$totp      = isset( $factor['totp'] ) && is_array( $factor['totp'] ) ? $factor['totp'] : [];
		$factor_id = (string) ( $factor['id'] ?? '' );

		if ( '' === $factor_id ) {
			return new WP_Error(
				'workos_mfa_invalid_response',
				__( 'Unexpected response from the authentication provider.', 'integration-workos' ),
				[ 'status' => 502 ]
			);
		}

		return [
			'factor_id'    => $factor_id,
			'challenge_id' => (string) ( $challenge['id'] ?? '' ),
			'qr_code'      => (string) ( $totp['qr_code'] ?? '' ),
			'secret'       => (string) ( $totp['secret'] ?? '' ),
			'otpauth_uri'  => (string) ( $totp['uri'] ?? '' ),
		];
	}

	/**
	 * Confirm an enrollment with the first code from the authenticator.
	 *
	 * @param int    $user_id      WP user ID.
	 * @param string $factor_id    Factor from start_enrollment().
	 * @param string $code         Code from the authenticator app.
	 * @param string $challenge_id Challenge from start_enrollment(); a new one is created when empty.
	 *
	 * @return array|WP_Error Status, as status() returns it.
	 */
	public function confirm_enrollment( int $user_id, string $factor_id, string $code, string $challenge_id = '' ) {
		$pending = $this->pending_factor( $user_id );
		if ( ! $pending || $pending['factor_id'] !== $factor_id ) {
			return $this->factor_not_found();
		}

		$valid = $this->check_code( $user_id, $factor_id, $code, $challenge_id );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		delete_user_meta( $user_id, User::META_MFA_PENDING_FACTOR );
		$this->note_enrolled( $user_id, $factor_id );

		return $this->status( $user_id );
	}

	/**
	 * Record a newly confirmed factor: flag, activity log, hook.
	 *
	 * `confirm_enrollment()` calls it, and so does the sign-in flow when
	 * WorkOS itself confirmed the factor (the `mfa_enrollment` step).
	 *
	 * @param int    $user_id   WP user ID.
	 * @param string $factor_id Confirmed factor.
	 * @param string $type      Factor type.
	 *
	 * @return void
	 */
	public function note_enrolled( int $user_id, string $factor_id, string $type = 'totp' ): void {
		$this->sync_enabled( $user_id, true );

		EventLogger::log(
			'mfa.enrolled',
			[
				'user_id'  => $user_id,
				'metadata' => [
					'factor_id'   => $factor_id,
					'factor_type' => $type,
					'actor_id'    => get_current_user_id(),
				],
			]
		);

		/**
		 * Fires after a user confirms a new MFA factor.
		 *
		 * @param int   $user_id WP user ID.
		 * @param array $factor  `{ id, type }`.
		 */
		do_action(
			'workos_mfa_enrolled',
			$user_id,
			[
				'id'   => $factor_id,
				'type' => $type,
			]
		);
	}

	/**
	 * Check a code for a user, counted against the account's code limit.
	 *
	 * The way every surface checks a code: the sign-in step, confirming an
	 * enrollment, removing a factor, adding a second one. A wrong code
	 * counts toward {@see CodeGuard::POLICY}; a right one doesn't.
	 *
	 * @param int    $user_id      WP user ID.
	 * @param string $factor_id    Factor ID (the caller has checked it's the user's).
	 * @param string $code         Code from the authenticator app.
	 * @param string $challenge_id Existing challenge; a new one is created when empty.
	 *
	 * @return true|WP_Error
	 */
	public function check_code( int $user_id, string $factor_id, string $code, string $challenge_id = '' ) {
		$subject = CodeGuard::for_user( $user_id );
		$begun   = $this->guard->begin( $subject );
		if ( is_wp_error( $begun ) ) {
			return $begun;
		}

		$valid = $this->verify_code( $factor_id, $code, $challenge_id );

		if ( true === $valid ) {
			$this->guard->passed( $subject );
		} else {
			$this->guard->failed( $subject );
		}

		return $valid;
	}

	/**
	 * Check a code against a factor, with no account limit.
	 *
	 * Callers outside sign-in and self-service should use check_code().
	 * Doesn't fire hooks: callers know what the code was for. A challenge
	 * passed in must belong to the factor; WorkOS would accept a code for
	 * any factor's challenge.
	 *
	 * @param string $factor_id    Factor ID.
	 * @param string $code         Code from the authenticator app.
	 * @param string $challenge_id Existing challenge; a new one is created when empty.
	 *
	 * @return true|WP_Error
	 */
	public function verify_code( string $factor_id, string $code, string $challenge_id = '' ) {
		$code = preg_replace( '/\D/', '', $code );
		if ( 6 !== strlen( (string) $code ) ) {
			return $this->invalid_code();
		}

		if ( '' === $challenge_id ) {
			$challenge = workos()->api()->challenge_auth_factor( $factor_id );
			if ( is_wp_error( $challenge ) ) {
				return $challenge;
			}
			$challenge_id = (string) ( $challenge['id'] ?? ( $challenge['authentication_challenge']['id'] ?? '' ) );
		}

		$result = workos()->api()->verify_auth_challenge( $challenge_id, (string) $code );
		if ( is_wp_error( $result ) ) {
			// A wrong code may come back as an error rather than `valid: false`.
			$status = (int) ( $result->get_error_data()['status'] ?? 0 );
			return ( $status >= 400 && $status < 500 ) ? $this->invalid_code() : $result;
		}

		if ( empty( $result['valid'] ) ) {
			return $this->invalid_code();
		}

		$challenged = (string) ( $result['challenge']['authentication_factor_id'] ?? '' );

		return $challenged === $factor_id ? true : $this->invalid_code();
	}

	/**
	 * Prove the caller holds one of the user's factors, if they have any.
	 *
	 * For actions a borrowed session shouldn't be able to take alone, like
	 * adding a second authenticator: with a factor already on the account,
	 * a current code from it is required. Counted against the account's
	 * code limit.
	 *
	 * @param int    $user_id   WP user ID.
	 * @param string $factor_id One of the user's factors.
	 * @param string $code      Current code from it.
	 *
	 * @return true|WP_Error True when the user has no factor yet, or the code checks out.
	 */
	public function require_current_factor( int $user_id, string $factor_id, string $code ) {
		$factors = $this->list_factors( $user_id );
		if ( is_wp_error( $factors ) ) {
			return $factors;
		}

		if ( empty( $factors ) ) {
			return true;
		}

		if ( '' === $factor_id || '' === $code ) {
			return new WP_Error(
				'workos_mfa_code_required',
				__( 'Enter a current code from your authenticator app to add another one.', 'integration-workos' ),
				[ 'status' => 403 ]
			);
		}

		if ( ! in_array( $factor_id, array_column( $factors, 'id' ), true ) ) {
			return $this->factor_not_found();
		}

		return $this->check_code( $user_id, $factor_id, $code );
	}

	/**
	 * The same error without the WorkOS response body.
	 *
	 * `Api\Client` errors carry WorkOS's body (user and factor details),
	 * which REST would hand to the browser.
	 *
	 * @param WP_Error $error Error.
	 *
	 * @return WP_Error
	 */
	public static function without_body( WP_Error $error ): WP_Error {
		$data = $error->get_error_data();

		return new WP_Error(
			$error->get_error_code(),
			$error->get_error_message(),
			[ 'status' => is_array( $data ) ? (int) ( $data['status'] ?? 500 ) : 500 ]
		);
	}

	/**
	 * Remove one of the user's factors.
	 *
	 * @param int    $user_id   WP user ID.
	 * @param string $factor_id Factor ID; must belong to the user.
	 *
	 * @return true|WP_Error
	 */
	public function delete_factor( int $user_id, string $factor_id ) {
		$factors = $this->list_factors( $user_id );
		if ( is_wp_error( $factors ) ) {
			return $factors;
		}

		// The plugin calls WorkOS with a tenant-wide key, so WorkOS can't
		// tell whose factor this is. 404, not 403, so factor IDs that
		// belong to other users don't leak.
		if ( ! in_array( $factor_id, array_column( $factors, 'id' ), true ) ) {
			return $this->factor_not_found();
		}

		$result = workos()->api()->delete_auth_factor( $factor_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->sync_enabled( $user_id, count( $factors ) > 1 );

		EventLogger::log(
			'mfa.removed',
			[
				'user_id'  => $user_id,
				'metadata' => [
					'factor_id' => $factor_id,
					'actor_id'  => get_current_user_id(),
				],
			]
		);

		/**
		 * Fires after one of a user's MFA factors is removed.
		 *
		 * @param int    $user_id   WP user ID.
		 * @param string $factor_id Factor ID.
		 */
		do_action( 'workos_mfa_factor_removed', $user_id, $factor_id );

		return true;
	}

	/**
	 * Remove every factor the user has, confirmed or not.
	 *
	 * For a user who lost their authenticator. With the require flag set
	 * they enroll again at their next sign-in.
	 *
	 * @param int $user_id WP user ID.
	 *
	 * @return int|WP_Error Number of factors removed.
	 */
	public function reset( int $user_id ) {
		$workos_id = $this->workos_id( $user_id );
		if ( is_wp_error( $workos_id ) ) {
			return $workos_id;
		}

		$result = workos()->api()->list_auth_factors( $workos_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$removed = 0;
		foreach ( (array) ( $result['data'] ?? [] ) as $factor ) {
			if ( ! is_array( $factor ) || empty( $factor['id'] ) ) {
				continue;
			}
			$deleted = workos()->api()->delete_auth_factor( (string) $factor['id'] );
			if ( is_wp_error( $deleted ) ) {
				return $deleted;
			}
			++$removed;
		}

		delete_user_meta( $user_id, User::META_MFA_PENDING_FACTOR );
		$this->sync_enabled( $user_id, false );

		EventLogger::log(
			'mfa.reset',
			[
				'user_id'  => $user_id,
				'metadata' => [
					'removed'  => $removed,
					'actor_id' => get_current_user_id(),
				],
			]
		);

		/**
		 * Fires after every MFA factor of a user is removed.
		 *
		 * @param int $user_id WP user ID.
		 * @param int $removed Number of factors removed.
		 */
		do_action( 'workos_mfa_reset', $user_id, $removed );

		return $removed;
	}

	/**
	 * Set or clear the admin require flag.
	 *
	 * @param int  $user_id  WP user ID.
	 * @param bool $required Whether the user must use MFA.
	 *
	 * @return bool Whether the flag changed.
	 */
	public function set_required( int $user_id, bool $required ): bool {
		if ( $this->is_required_flag( $user_id ) === $required ) {
			return false;
		}

		if ( $required ) {
			update_user_meta( $user_id, User::META_MFA_REQUIRED, '1' );
		} else {
			delete_user_meta( $user_id, User::META_MFA_REQUIRED );
		}

		EventLogger::log(
			$required ? 'mfa.required_set' : 'mfa.required_cleared',
			[
				'user_id'  => $user_id,
				'metadata' => [ 'actor_id' => get_current_user_id() ],
			]
		);

		/**
		 * Fires after the admin require flag on a user changes.
		 *
		 * @param int  $user_id  WP user ID.
		 * @param bool $required New value.
		 */
		do_action( 'workos_mfa_required_changed', $user_id, $required );

		return true;
	}

	/**
	 * Whether the user has a confirmed factor (mirrored, no API call).
	 *
	 * @param int $user_id WP user ID.
	 *
	 * @return bool
	 */
	public function is_enabled( int $user_id ): bool {
		return '1' === (string) get_user_meta( $user_id, User::META_MFA_ENABLED, true );
	}

	/**
	 * Whether an admin set the require flag (roles and filters aside).
	 *
	 * @param int $user_id WP user ID.
	 *
	 * @return bool
	 */
	public function is_required_flag( int $user_id ): bool {
		return '1' === (string) get_user_meta( $user_id, User::META_MFA_REQUIRED, true );
	}

	/**
	 * The unconfirmed factor, if any.
	 *
	 * @param int $user_id WP user ID.
	 *
	 * @return array{factor_id: string, created: int}|null
	 */
	public function pending_factor( int $user_id ): ?array {
		$pending = get_user_meta( $user_id, User::META_MFA_PENDING_FACTOR, true );
		if ( ! is_array( $pending ) || empty( $pending['factor_id'] ) ) {
			return null;
		}

		return [
			'factor_id' => (string) $pending['factor_id'],
			'created'   => (int) ( $pending['created'] ?? 0 ),
		];
	}

	/**
	 * Delete an unconfirmed factor from WorkOS and forget it.
	 *
	 * Kept when WorkOS couldn't delete it, so it's never mistaken for a
	 * confirmed factor; a later call tries again.
	 *
	 * @param int   $user_id WP user ID.
	 * @param array $pending Unconfirmed factor from pending_factor().
	 *
	 * @return bool Whether it's gone.
	 */
	private function drop_pending( int $user_id, array $pending ): bool {
		$deleted = workos()->api()->delete_auth_factor( $pending['factor_id'] );
		if ( is_wp_error( $deleted ) && 404 !== (int) ( $deleted->get_error_data()['status'] ?? 0 ) ) {
			return false;
		}

		delete_user_meta( $user_id, User::META_MFA_PENDING_FACTOR );

		return true;
	}

	/**
	 * Linked WorkOS user ID, or an error.
	 *
	 * @param int $user_id WP user ID.
	 *
	 * @return string|WP_Error
	 */
	private function workos_id( int $user_id ) {
		$workos_id = $user_id > 0 ? User::get_workos_id( $user_id ) : '';
		if ( '' === $workos_id ) {
			return new WP_Error(
				'workos_mfa_user_not_linked',
				__( 'This account is not linked to a WorkOS user.', 'integration-workos' ),
				[ 'status' => 409 ]
			);
		}

		return $workos_id;
	}

	/**
	 * Mirror the enabled state.
	 *
	 * @param int  $user_id WP user ID.
	 * @param bool $enabled Whether the user has a confirmed factor.
	 *
	 * @return void
	 */
	private function sync_enabled( int $user_id, bool $enabled ): void {
		if ( $enabled ) {
			update_user_meta( $user_id, User::META_MFA_ENABLED, '1' );
		} else {
			delete_user_meta( $user_id, User::META_MFA_ENABLED );
		}
	}

	/**
	 * Error for a factor the user doesn't own.
	 *
	 * @return WP_Error
	 */
	private function factor_not_found(): WP_Error {
		return new WP_Error(
			'workos_mfa_factor_not_found',
			__( 'Factor not found.', 'integration-workos' ),
			[ 'status' => 404 ]
		);
	}

	/**
	 * Error for a wrong or malformed code.
	 *
	 * @return WP_Error
	 */
	private function invalid_code(): WP_Error {
		return new WP_Error(
			'workos_mfa_invalid_code',
			__( 'That code didn’t work. Check your authenticator app and try again.', 'integration-workos' ),
			[ 'status' => 400 ]
		);
	}
}
