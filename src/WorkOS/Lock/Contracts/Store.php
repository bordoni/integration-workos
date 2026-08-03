<?php
/**
 * Persistence contract for lock state.
 *
 * @package WorkOS\Lock\Contracts
 */

namespace WorkOS\Lock\Contracts;

use WorkOS\Lock\State;
use WorkOS\Lock\Subject;

defined( 'ABSPATH' ) || exit;

/**
 * Where a lock's counters live.
 *
 * Implementations must be *durable*. This is the difference between a lock and
 * the burst limiter it sits behind: transients and object-cache groups are
 * cache, and a flush or an eviction silently resets every counter. Lock state
 * has to survive that, so implementations write to user meta or the options
 * table and never to a cache layer.
 *
 * Implementations are also responsible for their own expiry semantics. User
 * meta has none, so state is cleaned up when it decays; option rows carry an
 * `expires_at` stamp that {@see \WorkOS\Lock\GarbageCollector} prunes on a
 * schedule.
 */
interface Store {

	/**
	 * Whether this store can hold state for the given subject.
	 *
	 * @param Subject $subject Subject to test.
	 *
	 * @return bool
	 */
	public function supports( Subject $subject ): bool;

	/**
	 * Read stored state, or null when nothing is stored.
	 *
	 * @param string  $slug    Lock slug.
	 * @param Subject $subject Subject.
	 *
	 * @return State|null
	 */
	public function read( string $slug, Subject $subject ): ?State;

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
	public function write( string $slug, Subject $subject, State $state, int $ttl ): void;

	/**
	 * Remove stored state.
	 *
	 * @param string  $slug    Lock slug.
	 * @param Subject $subject Subject.
	 *
	 * @return void
	 */
	public function delete( string $slug, Subject $subject ): void;
}
