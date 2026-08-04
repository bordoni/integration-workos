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
 * How long state survives is the implementation's defining trade. The shipped
 * stores write to user meta and the options table, so lock state outlives the
 * cache flushes and evictions that reset the burst limiter in front of it —
 * that is what makes the locks the layer with long memory. A swapped-in
 * backend (via the `workos_lock_store` filter) may choose differently: a
 * cache-backed store is a legitimate option on infrastructure where flushes
 * and eviction are controlled, with the understanding that any state it loses
 * hands the full allowance back.
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
