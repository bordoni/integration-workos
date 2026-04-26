<?php
/**
 * Login Profile repository.
 *
 * @package WorkOS\Auth\AuthKit
 */

namespace WorkOS\Auth\AuthKit;

use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD for Login Profiles, backed by the workos_login_profile CPT.
 *
 * Storage model:
 * - CPT `workos_login_profile` (non-public, admin-only).
 * - Slug lives in `post_name`; title in `post_title`.
 * - Everything else is stored as a single JSON blob in post meta
 *   `_workos_profile_config` — keeps the shape stable as the Profile
 *   schema evolves and avoids dozens of meta keys.
 */
class ProfileRepository {

	public const POST_TYPE = 'workos_login_profile';
	public const META_KEY  = '_workos_profile_config';

	/**
	 * Register the CPT with WordPress.
	 *
	 * Called once from AuthKit\Controller on `init`.
	 *
	 * @return void
	 */
	public function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			[
				'labels'              => [
					'name'          => __( 'Login Profiles', 'integration-workos' ),
					'singular_name' => __( 'Login Profile', 'integration-workos' ),
				],
				'public'              => false,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_rest'        => false,
				'show_in_admin_bar'   => false,
				'show_in_nav_menus'   => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'supports'            => [ 'title' ],
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			]
		);
	}

	/**
	 * Fetch a profile by slug.
	 *
	 * @param string $slug Profile slug.
	 *
	 * @return Profile|null
	 */
	public function find_by_slug( string $slug ): ?Profile {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return null;
		}

		$posts = get_posts(
			[
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'any',
				'name'             => $slug,
				'numberposts'      => 1,
				'suppress_filters' => true,
			]
		);

		if ( empty( $posts ) ) {
			return null;
		}

		return $this->hydrate( $posts[0] );
	}

	/**
	 * Fetch a profile by post ID.
	 *
	 * @param int $id Post ID.
	 *
	 * @return Profile|null
	 */
	public function find_by_id( int $id ): ?Profile {
		if ( $id <= 0 ) {
			return null;
		}

		$post = get_post( $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}

		return $this->hydrate( $post );
	}

	/**
	 * List all profiles.
	 *
	 * @return Profile[]
	 */
	public function all(): array {
		$posts = get_posts(
			[
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'any',
				'numberposts'      => -1,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => true,
			]
		);

		$profiles = [];
		foreach ( $posts as $post ) {
			$profile = $this->hydrate( $post );
			if ( $profile ) {
				$profiles[] = $profile;
			}
		}

		return $profiles;
	}

	/**
	 * Create or update a profile.
	 *
	 * The profile's slug must be unique; passing a slug that already exists
	 * and differs from the profile's post ID returns a WP_Error.
	 *
	 * @param Profile $profile Profile to persist.
	 *
	 * @return Profile|WP_Error The saved profile (with its post ID), or WP_Error on failure.
	 */
	public function save( Profile $profile ) {
		$slug = $profile->get_slug();
		if ( '' === $slug ) {
			return new WP_Error(
				'workos_profile_invalid_slug',
				__( 'Profile slug cannot be empty.', 'integration-workos' )
			);
		}

		// Enforce slug uniqueness against any other profile.
		$existing = $this->find_by_slug( $slug );
		if ( $existing && $existing->get_id() !== $profile->get_id() ) {
			return new WP_Error(
				'workos_profile_slug_taken',
				sprintf(
					/* translators: %s: slug */
					__( 'A Login Profile with the slug "%s" already exists.', 'integration-workos' ),
					$slug
				)
			);
		}

		$custom_path = $profile->get_custom_path();
		if ( '' !== $custom_path ) {
			$reserved = in_array( $custom_path, Profile::RESERVED_PATHS, true )
				|| Profile::DEFAULT_SLUG === $custom_path
				|| 0 === strpos( $custom_path, 'wp-admin' )
				|| 0 === strpos( $custom_path, 'workos/' );
			if ( $reserved ) {
				return new WP_Error(
					'workos_profile_path_reserved',
					sprintf(
						/* translators: %s: requested custom path. */
						__( '"%s" is a reserved path and cannot be used as a custom login path.', 'integration-workos' ),
						$custom_path
					)
				);
			}

			foreach ( $this->all() as $other ) {
				if ( $other->get_id() === $profile->get_id() ) {
					continue;
				}
				if ( $other->get_custom_path() === $custom_path ) {
					return new WP_Error(
						'workos_profile_path_taken',
						sprintf(
							/* translators: %s: requested custom path. */
							__( 'The path "%s" is already used by another Login Profile.', 'integration-workos' ),
							$custom_path
						)
					);
				}
			}
		}

		$post_args = [
			'post_type'   => self::POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => $profile->get_title(),
			'post_name'   => $slug,
		];

		if ( $profile->get_id() > 0 ) {
			$post_args['ID'] = $profile->get_id();
			$post_id         = wp_update_post( $post_args, true );
		} else {
			$post_id = wp_insert_post( $post_args, true );
		}

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, self::META_KEY, wp_json_encode( $profile->to_array() ) );

		$saved = $profile->with_id( (int) $post_id );

		/**
		 * Fires after a Login Profile is saved.
		 *
		 * Lets other subsystems react (e.g. keep the global `login_mode`
		 * env option aligned with the default profile's mode field).
		 *
		 * @param Profile $saved The freshly-saved profile (with its post ID).
		 */
		do_action( 'workos_login_profile_saved', $saved );

		return $saved;
	}

	/**
	 * Delete a profile.
	 *
	 * The reserved `default` profile cannot be deleted.
	 *
	 * @param int $id Post ID.
	 *
	 * @return true|WP_Error
	 */
	public function delete( int $id ) {
		$profile = $this->find_by_id( $id );
		if ( ! $profile ) {
			return new WP_Error(
				'workos_profile_not_found',
				__( 'Profile not found.', 'integration-workos' )
			);
		}

		if ( Profile::DEFAULT_SLUG === $profile->get_slug() ) {
			return new WP_Error(
				'workos_profile_default_locked',
				__( 'The default Login Profile cannot be deleted.', 'integration-workos' )
			);
		}

		$result = wp_delete_post( $id, true );
		if ( ! $result ) {
			return new WP_Error(
				'workos_profile_delete_failed',
				__( 'Failed to delete the Login Profile.', 'integration-workos' )
			);
		}

		/**
		 * Fires after a Login Profile is deleted.
		 *
		 * Lets subsystems react to removal — used by the FrontendRoute to
		 * invalidate its cached custom-path rewrite signature.
		 *
		 * @param Profile $profile The profile that was deleted.
		 */
		do_action( 'workos_login_profile_deleted', $profile );

		return true;
	}

	/**
	 * Ensure the reserved `default` profile exists; create it if not.
	 *
	 * Idempotent — safe to call on every activation and on plugin boot.
	 *
	 * @return Profile The default profile.
	 */
	public function ensure_default(): Profile {
		$existing = $this->find_by_slug( Profile::DEFAULT_SLUG );
		if ( $existing ) {
			return $existing;
		}

		$saved = $this->save( Profile::defaults() );
		if ( is_wp_error( $saved ) ) {
			// This should not happen under normal conditions; fall back to an
			// in-memory default so callers can still render something sane.
			return Profile::defaults();
		}

		return $saved;
	}

	/**
	 * Hydrate a Profile from a WP_Post.
	 *
	 * @param WP_Post $post Post object.
	 *
	 * @return Profile|null Returns null if the meta payload is corrupt beyond recovery.
	 */
	private function hydrate( WP_Post $post ): ?Profile {
		$raw = get_post_meta( $post->ID, self::META_KEY, true );

		$data = [];
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$data = $decoded;
			}
		}

		// Title / slug always come from the post record itself; we don't
		// trust the meta payload to hold authoritative copies.
		$data['id']    = (int) $post->ID;
		$data['slug']  = (string) $post->post_name;
		$data['title'] = (string) $post->post_title;

		return Profile::from_array( $data );
	}
}
