<?php
/**
 * Pending email-change state stored as user_meta.
 *
 * @package WorkOS\Auth\ChangeEmail
 */

namespace WorkOS\Auth\ChangeEmail;

defined( 'ABSPATH' ) || exit;

/**
 * Persists the pending email-change record for a single user.
 *
 * The full payload contains the *new* address, the hashed confirm token,
 * the hashed cancel token (used by the "this wasn't me" link to the old
 * address), the initiator, the issue time, and the absolute expiry. Only
 * hashes — never plaintext tokens — sit in the database.
 *
 * Single-use is enforced by clearing the meta on confirm, cancel, or
 * expiry. There is at most one pending change per user.
 */
class PendingChange {

	public const META_KEY = '_workos_pending_email_change';

	/**
	 * Token factory used for confirm-token verification.
	 *
	 * @var TokenFactory
	 */
	private TokenFactory $tokens;

	/**
	 * Constructor.
	 *
	 * @param TokenFactory $tokens Token factory.
	 */
	public function __construct( TokenFactory $tokens ) {
		$this->tokens = $tokens;
	}

	/**
	 * Persist a new pending change.
	 *
	 * @param int    $user_id            Target WP user ID.
	 * @param string $new_email          Lowercased, sanitized new email.
	 * @param string $confirm_token      Plaintext confirm token (hashed before storage).
	 * @param string $cancel_token       Plaintext cancel token (hashed before storage).
	 * @param int    $expires_at         Unix timestamp at which the confirm token expires.
	 * @param int    $initiated_by       WP user ID of the initiator (0 for system).
	 *
	 * @return void
	 */
	public function store(
		int $user_id,
		string $new_email,
		string $confirm_token,
		string $cancel_token,
		int $expires_at,
		int $initiated_by
	): void {
		update_user_meta(
			$user_id,
			self::META_KEY,
			[
				'new_email'         => $new_email,
				'token_hash'        => $this->tokens->hash( $confirm_token ),
				'cancel_token_hash' => $this->tokens->hash( $cancel_token ),
				'expires_at'        => $expires_at,
				'initiated_by'      => $initiated_by,
				'initiated_at'      => time(),
			]
		);
	}

	/**
	 * Load the stored pending change for a user, or null if none exists.
	 *
	 * @param int $user_id WP user ID.
	 *
	 * @return array{new_email:string,token_hash:string,cancel_token_hash:string,expires_at:int,initiated_by:int,initiated_at:int}|null
	 */
	public function get( int $user_id ): ?array {
		$stored = get_user_meta( $user_id, self::META_KEY, true );
		if ( ! is_array( $stored ) ) {
			return null;
		}

		// Defensive: only return well-formed records. Anything missing a
		// required field is treated as "no pending change" rather than
		// silently authenticating a half-filled meta row.
		foreach ( [ 'new_email', 'token_hash', 'cancel_token_hash', 'expires_at' ] as $required ) {
			if ( ! isset( $stored[ $required ] ) ) {
				return null;
			}
		}

		return [
			'new_email'         => (string) $stored['new_email'],
			'token_hash'        => (string) $stored['token_hash'],
			'cancel_token_hash' => (string) $stored['cancel_token_hash'],
			'expires_at'        => (int) $stored['expires_at'],
			'initiated_by'      => (int) ( $stored['initiated_by'] ?? 0 ),
			'initiated_at'      => (int) ( $stored['initiated_at'] ?? 0 ),
		];
	}

	/**
	 * Check whether a stored record is past its expiry.
	 *
	 * @param array $record Record as returned by {@see get()}.
	 *
	 * @return bool
	 */
	public function expired( array $record ): bool {
		return (int) ( $record['expires_at'] ?? 0 ) <= time();
	}

	/**
	 * Verify a candidate confirm token against the stored record.
	 *
	 * @param array  $record    Record as returned by {@see get()}.
	 * @param string $candidate Plaintext token.
	 *
	 * @return bool
	 */
	public function verify_confirm( array $record, string $candidate ): bool {
		return $this->tokens->verify( $candidate, (string) ( $record['token_hash'] ?? '' ) );
	}

	/**
	 * Verify a candidate cancel token against the stored record.
	 *
	 * @param array  $record    Record as returned by {@see get()}.
	 * @param string $candidate Plaintext token.
	 *
	 * @return bool
	 */
	public function verify_cancel( array $record, string $candidate ): bool {
		return $this->tokens->verify( $candidate, (string) ( $record['cancel_token_hash'] ?? '' ) );
	}

	/**
	 * Clear the pending change for a user.
	 *
	 * @param int $user_id WP user ID.
	 *
	 * @return void
	 */
	public function clear( int $user_id ): void {
		delete_user_meta( $user_id, self::META_KEY );
	}
}
