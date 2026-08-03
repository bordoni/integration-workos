<?php
/**
 * Lookup for every registered lock.
 *
 * @package WorkOS\Lock
 */

namespace WorkOS\Lock;

use WorkOS\Lock\Contracts\Lockable;
use WorkOS\Lock\Locks\MfaChallengeLock;
use WorkOS\Lock\Locks\OtpSendLock;
use WorkOS\Lock\Locks\PasswordAuthLock;
use WorkOS\Lock\Locks\PasswordResetLock;
use WorkOS\Lock\Locks\SignupLock;
use WorkOS\Lock\Locks\TokenGuessLock;
use WorkOS\Lock\Store\ResolvingStore;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves a lock by slug, and lists them for the admin and CLI.
 *
 * The single entry point callers use. Endpoints ask for `otp_send`, not for a
 * class, which keeps them decoupled from the lock implementations and lets a
 * site swap one out through `workos_locks`.
 *
 * Locks are built once and reused, so a request that touches the same lock
 * twice does not construct it twice.
 */
class Registry {

	/**
	 * Shared persistence backend handed to every lock.
	 *
	 * @var ResolvingStore
	 */
	private ResolvingStore $store;

	/**
	 * Built locks, keyed by slug.
	 *
	 * @var array<string, Lockable>|null
	 */
	private ?array $locks = null;

	/**
	 * Constructor.
	 *
	 * @param ResolvingStore $store Persistence backend.
	 */
	public function __construct( ResolvingStore $store ) {
		$this->store = $store;
	}

	/**
	 * All registered locks, keyed by slug.
	 *
	 * @return array<string, Lockable>
	 */
	public function all(): array {
		if ( null !== $this->locks ) {
			return $this->locks;
		}

		$locks = [];

		foreach ( $this->default_locks() as $lock ) {
			$locks[ $lock->slug() ] = $lock;
		}

		/**
		 * Filters the registered locks.
		 *
		 * Add an entry to register a custom lock, or replace one to change the
		 * behavior of a built-in. Keys must match each lock's `slug()`.
		 *
		 * @param array<string, Lockable> $locks Locks keyed by slug.
		 * @param ResolvingStore          $store Shared persistence backend, for constructing new locks.
		 */
		$filtered = apply_filters( 'workos_locks', $locks, $this->store );

		$this->locks = [];

		foreach ( (array) $filtered as $slug => $lock ) {
			if ( $lock instanceof Lockable ) {
				$this->locks[ (string) $slug ] = $lock;
			}
		}

		return $this->locks;
	}

	/**
	 * Resolve a lock by slug.
	 *
	 * @param string $slug Lock slug.
	 *
	 * @return Lockable|null Null when no lock is registered under that slug.
	 */
	public function get( string $slug ): ?Lockable {
		$locks = $this->all();

		return $locks[ $slug ] ?? null;
	}

	/**
	 * Whether a lock is registered under the given slug.
	 *
	 * @param string $slug Lock slug.
	 *
	 * @return bool
	 */
	public function has( string $slug ): bool {
		return null !== $this->get( $slug );
	}

	/**
	 * Registered slugs.
	 *
	 * @return array<int, string>
	 */
	public function slugs(): array {
		return array_keys( $this->all() );
	}

	/**
	 * Clear every lock held against a WordPress user.
	 *
	 * Backs the operator unlock and the CLI. Clears all locks rather than one,
	 * because an operator helping a stuck user should not have to work out
	 * which of six locks engaged.
	 *
	 * @param int $user_id WP user ID.
	 *
	 * @return int Number of locks cleared.
	 */
	public function release_all_for_user( int $user_id ): int {
		$cleared = 0;

		foreach ( $this->all() as $lock ) {
			$released = false;

			foreach ( $this->subjects_for_user( $lock, $user_id ) as $subject ) {
				if ( $lock->status( $subject )->is_empty() ) {
					continue;
				}

				$lock->release( $subject );
				$released = true;
			}

			if ( $released ) {
				++$cleared;
			}
		}

		return $cleared;
	}

	/**
	 * Locks currently holding state against a user, keyed by slug.
	 *
	 * @param int $user_id WP user ID.
	 *
	 * @return array<string, State>
	 */
	public function status_for_user( int $user_id ): array {
		$statuses = [];

		foreach ( $this->all() as $slug => $lock ) {
			foreach ( $this->subjects_for_user( $lock, $user_id ) as $subject ) {
				$state = $lock->status( $subject );

				if ( ! $state->is_empty() ) {
					$statuses[ (string) $slug ] = $state;
					break;
				}
			}
		}

		return $statuses;
	}

	/**
	 * Every subject under which a given user might be counted.
	 *
	 * A person is reachable two ways: by user ID, and by the address on their
	 * account. Both matter, because counting starts before the WordPress user
	 * exists — accounts are created upstream and only mirrored here on a
	 * successful login — so an operator looking at a user needs to see what was
	 * recorded against their address beforehand as well.
	 *
	 * Reading the address subject also triggers the migration in
	 * {@see \WorkOS\Lock\Store\ResolvingStore::read()}, which folds any earlier
	 * record onto the user.
	 *
	 * @param Lockable $lock    Lock to build subjects for.
	 * @param int      $user_id WP user ID.
	 *
	 * @return array<int, \WorkOS\Lock\Subject>
	 */
	private function subjects_for_user( Lockable $lock, int $user_id ): array {
		$subjects = [];
		$user     = get_userdata( $user_id );

		if ( $user && is_email( $user->user_email ) ) {
			$subjects[] = $lock->for_email( $user->user_email );
		}

		$subjects[] = $lock->for_user( $user_id );

		return $subjects;
	}

	/**
	 * The locks shipped with the plugin.
	 *
	 * @return array<int, Lockable>
	 */
	private function default_locks(): array {
		return [
			new OtpSendLock( $this->store ),
			new PasswordAuthLock( $this->store ),
			new PasswordResetLock( $this->store ),
			new MfaChallengeLock( $this->store ),
			new SignupLock( $this->store ),
			new TokenGuessLock( $this->store ),
		];
	}
}
