<?php
/**
 * User-meta backed lock storage.
 *
 * @package WorkOS\Lock\Store
 */

namespace WorkOS\Lock\Store;

use WorkOS\Lock\Contracts\Store;
use WorkOS\Lock\State;
use WorkOS\Lock\Subject;

defined( 'ABSPATH' ) || exit;

/**
 * Stores lock state against the WordPress user it belongs to.
 *
 * One meta row per user per lock, keyed `_workos_lock_{slug}`. The leading
 * underscore keeps it out of the custom-fields UI, matching every other meta
 * key the plugin owns.
 *
 * This is the preferred backend: the record travels with the user, survives
 * every cache layer, is visible on the user-edit screen, and disappears when
 * the user is deleted. It only applies when the subject resolves to a local
 * user — {@see ResolvingStore} handles the rest.
 */
class UserMetaStore implements Store {

	/**
	 * Meta key prefix. The lock slug is appended.
	 */
	public const META_PREFIX = '_workos_lock_';

	/**
	 * Whether this store can hold state for the given subject.
	 *
	 * @param Subject $subject Subject to test.
	 *
	 * @return bool
	 */
	public function supports( Subject $subject ): bool {
		return $subject->is_user_backed();
	}

	/**
	 * Read stored state.
	 *
	 * @param string  $slug    Lock slug.
	 * @param Subject $subject Subject.
	 *
	 * @return State|null
	 */
	public function read( string $slug, Subject $subject ): ?State {
		if ( ! $this->supports( $subject ) ) {
			return null;
		}

		$stored = get_user_meta( $subject->user_id(), $this->meta_key( $slug ), true );

		if ( ! is_array( $stored ) || [] === $stored ) {
			return null;
		}

		return new State( $stored );
	}

	/**
	 * Persist state.
	 *
	 * User meta has no expiry, so `$ttl` is unused here — decay handles
	 * forgiveness on read, and the row is removed once it holds nothing.
	 *
	 * @param string  $slug    Lock slug.
	 * @param Subject $subject Subject.
	 * @param State   $state   State to persist.
	 * @param int     $ttl     Unused; user meta does not expire.
	 *
	 * @return void
	 */
	public function write( string $slug, Subject $subject, State $state, int $ttl ): void {
		if ( ! $this->supports( $subject ) ) {
			return;
		}

		update_user_meta( $subject->user_id(), $this->meta_key( $slug ), $state->to_array() );
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
		if ( ! $this->supports( $subject ) ) {
			return;
		}

		delete_user_meta( $subject->user_id(), $this->meta_key( $slug ) );
	}

	/**
	 * Remove every lock record held against a user.
	 *
	 * Backs the operator unlock, which clears all of a user's locks at once
	 * rather than making an admin guess which one engaged.
	 *
	 * @param int $user_id WP user ID.
	 *
	 * @return int Number of meta rows removed.
	 */
	public function delete_all_for_user( int $user_id ): int {
		$removed = 0;

		foreach ( $this->slugs_for_user( $user_id ) as $slug ) {
			if ( delete_user_meta( $user_id, $this->meta_key( $slug ) ) ) {
				++$removed;
			}
		}

		return $removed;
	}

	/**
	 * List the lock slugs currently stored against a user.
	 *
	 * @param int $user_id WP user ID.
	 *
	 * @return array<int, string>
	 */
	public function slugs_for_user( int $user_id ): array {
		$meta  = get_user_meta( $user_id );
		$slugs = [];

		if ( ! is_array( $meta ) ) {
			return $slugs;
		}

		foreach ( array_keys( $meta ) as $key ) {
			$key = (string) $key;

			if ( 0 !== strpos( $key, self::META_PREFIX ) ) {
				continue;
			}

			$slugs[] = substr( $key, strlen( self::META_PREFIX ) );
		}

		return $slugs;
	}

	/**
	 * Meta key for a lock slug.
	 *
	 * @param string $slug Lock slug.
	 *
	 * @return string
	 */
	public function meta_key( string $slug ): string {
		return self::META_PREFIX . preg_replace( '/[^a-z0-9_]/i', '', $slug );
	}
}
