<?php
/**
 * Picks the right backend for each subject.
 *
 * @package WorkOS\Lock\Store
 */

namespace WorkOS\Lock\Store;

use WorkOS\Lock\Contracts\Store;
use WorkOS\Lock\State;
use WorkOS\Lock\Subject;

defined( 'ABSPATH' ) || exit;

/**
 * Routes each subject to the backend that suits it.
 *
 * User-backed subjects go to {@see UserMetaStore}, everything else to
 * {@see OptionStore}. This is the only store a lock is given, so no calling
 * code ever has to reason about which backend applies — including the awkward
 * case where an address has no local user today and gains one tomorrow, at
 * which point counting simply moves to the user's meta.
 *
 * That migration is intentional and harmless: the WordPress user only appears
 * after a *successful* login, which clears the lock anyway.
 */
class ResolvingStore implements Store {

	/**
	 * Backend for subjects that resolve to a WordPress user.
	 *
	 * @var UserMetaStore
	 */
	private UserMetaStore $user_meta;

	/**
	 * Backend for everything else.
	 *
	 * @var OptionStore
	 */
	private OptionStore $options;

	/**
	 * Constructor.
	 *
	 * @param UserMetaStore $user_meta User-meta backend.
	 * @param OptionStore   $options   Option backend.
	 */
	public function __construct( UserMetaStore $user_meta, OptionStore $options ) {
		$this->user_meta = $user_meta;
		$this->options   = $options;
	}

	/**
	 * Whether state can be held for the given subject.
	 *
	 * @param Subject $subject Subject to test.
	 *
	 * @return bool
	 */
	public function supports( Subject $subject ): bool {
		return $subject->is_valid();
	}

	/**
	 * Read stored state from whichever backend owns this subject.
	 *
	 * When a subject has become user-backed since it was last written — an
	 * address that had no WordPress user at the time and has one now — the
	 * earlier record is moved across on first read. Without that step the
	 * counters would silently reset the moment an account appeared, and the
	 * orphaned row would go on reporting a lock that no longer applied.
	 *
	 * @param string  $slug    Lock slug.
	 * @param Subject $subject Subject.
	 *
	 * @return State|null
	 */
	public function read( string $slug, Subject $subject ): ?State {
		$backend = $this->backend_for( $subject );
		$state   = $backend->read( $slug, $subject );

		if ( null !== $state || $backend !== $this->user_meta ) {
			return $state;
		}

		$orphaned = $this->options->read( $slug, $subject );

		if ( null === $orphaned ) {
			return null;
		}

		$this->user_meta->write( $slug, $subject, $orphaned, 0 );
		$this->options->delete( $slug, $subject );

		return $orphaned;
	}

	/**
	 * Persist state.
	 *
	 * @param string  $slug    Lock slug.
	 * @param Subject $subject Subject.
	 * @param State   $state   State to persist.
	 * @param int     $ttl     Seconds after which the record may be pruned.
	 *
	 * @return void
	 */
	public function write( string $slug, Subject $subject, State $state, int $ttl ): void {
		$this->backend_for( $subject )->write( $slug, $subject, $state, $ttl );
	}

	/**
	 * Remove stored state.
	 *
	 * @param string  $slug    Lock slug.
	 * @param Subject $subject Subject.
	 *
	 * @return void
	 */
	public function delete( string $slug, Subject $subject ): void {
		// Both backends, not just the current one: a subject that changed hands
		// may still have a record on the other side, and "clear this" has to
		// mean it.
		$this->user_meta->delete( $slug, $subject );
		$this->options->delete( $slug, $subject );
	}

	/**
	 * The user-meta backend.
	 *
	 * @return UserMetaStore
	 */
	public function user_meta(): UserMetaStore {
		return $this->user_meta;
	}

	/**
	 * The option backend.
	 *
	 * @return OptionStore
	 */
	public function options(): OptionStore {
		return $this->options;
	}

	/**
	 * Choose the backend for a subject.
	 *
	 * @param Subject $subject Subject.
	 *
	 * @return Store
	 */
	private function backend_for( Subject $subject ): Store {
		$store = $this->user_meta->supports( $subject ) ? $this->user_meta : $this->options;

		/**
		 * Filters the backend chosen for a subject.
		 *
		 * @param Store   $store   Selected backend.
		 * @param Subject $subject Subject being stored.
		 */
		$filtered = apply_filters( 'workos_lock_store', $store, $subject );

		return $filtered instanceof Store ? $filtered : $store;
	}
}
