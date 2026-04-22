<?php
/**
 * Tests for the Login Profile repository.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Auth\AuthKit\Profile;
use WorkOS\Auth\AuthKit\ProfileRepository;

/**
 * CRUD, default seeding, and slug-uniqueness coverage for ProfileRepository.
 */
class AuthKitProfileRepositoryTest extends WPTestCase {

	/**
	 * Repository instance.
	 *
	 * @var ProfileRepository
	 */
	private ProfileRepository $repository;

	/**
	 * Set up — register the CPT and clear any residual profiles.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->repository = new ProfileRepository();
		$this->repository->register_post_type();

		// Clear any profiles left over from a previous test.
		foreach ( $this->repository->all() as $profile ) {
			wp_delete_post( $profile->get_id(), true );
		}
	}

	/**
	 * Tear down — remove all profiles we may have created.
	 */
	public function tearDown(): void {
		foreach ( $this->repository->all() as $profile ) {
			wp_delete_post( $profile->get_id(), true );
		}

		parent::tearDown();
	}

	/**
	 * save() inserts a new profile and assigns a post ID.
	 */
	public function test_save_inserts_new_profile(): void {
		$profile = Profile::from_array(
			[
				'slug'    => 'members',
				'title'   => 'Members Area',
				'methods' => [ Profile::METHOD_MAGIC_CODE ],
			]
		);

		$saved = $this->repository->save( $profile );

		$this->assertInstanceOf( Profile::class, $saved );
		$this->assertGreaterThan( 0, $saved->get_id() );
		$this->assertSame( 'members', $saved->get_slug() );
	}

	/**
	 * find_by_slug returns the profile we just saved.
	 */
	public function test_find_by_slug_returns_saved_profile(): void {
		$saved = $this->repository->save(
			Profile::from_array(
				[
					'slug'            => 'members',
					'title'           => 'Members',
					'organization_id' => 'org_01XYZ',
				]
			)
		);

		$this->assertInstanceOf( Profile::class, $saved );

		$found = $this->repository->find_by_slug( 'members' );

		$this->assertInstanceOf( Profile::class, $found );
		$this->assertSame( $saved->get_id(), $found->get_id() );
		$this->assertSame( 'org_01XYZ', $found->get_organization_id() );
	}

	/**
	 * find_by_slug returns null for unknown slugs.
	 */
	public function test_find_by_slug_returns_null_for_unknown(): void {
		$this->assertNull( $this->repository->find_by_slug( 'does-not-exist' ) );
	}

	/**
	 * save() updates an existing profile when ID is set.
	 */
	public function test_save_updates_existing_profile(): void {
		$saved = $this->repository->save(
			Profile::from_array(
				[
					'slug'  => 'members',
					'title' => 'Members',
				]
			)
		);

		$this->assertInstanceOf( Profile::class, $saved );

		$updated_input           = $saved->to_array();
		$updated_input['title']  = 'Updated Members';
		$updated_input['methods'] = [ Profile::METHOD_OAUTH_GOOGLE ];

		$updated = $this->repository->save( Profile::from_array( $updated_input ) );
		$this->assertInstanceOf( Profile::class, $updated );

		$this->assertSame( $saved->get_id(), $updated->get_id() );
		$this->assertSame( 'Updated Members', $updated->get_title() );
		$this->assertSame( [ Profile::METHOD_OAUTH_GOOGLE ], $updated->get_methods() );
	}

	/**
	 * save() refuses to create a second profile with the same slug.
	 */
	public function test_save_rejects_duplicate_slug(): void {
		$this->repository->save(
			Profile::from_array(
				[
					'slug'  => 'members',
					'title' => 'Members',
				]
			)
		);

		$second = $this->repository->save(
			Profile::from_array(
				[
					'slug'  => 'members',
					'title' => 'Also Members',
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $second );
		$this->assertSame( 'workos_profile_slug_taken', $second->get_error_code() );
	}

	/**
	 * save() rejects an empty slug.
	 */
	public function test_save_rejects_empty_slug(): void {
		$profile = Profile::from_array( [ 'slug' => '', 'title' => 'Empty' ] );

		$result = $this->repository->save( $profile );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'workos_profile_invalid_slug', $result->get_error_code() );
	}

	/**
	 * all() returns every saved profile.
	 */
	public function test_all_returns_all_profiles(): void {
		$this->repository->save( Profile::from_array( [ 'slug' => 'members', 'title' => 'Members' ] ) );
		$this->repository->save( Profile::from_array( [ 'slug' => 'partners', 'title' => 'Partners' ] ) );

		$all = $this->repository->all();

		$this->assertCount( 2, $all );
		$slugs = array_map( static fn( Profile $p ) => $p->get_slug(), $all );
		$this->assertContains( 'members', $slugs );
		$this->assertContains( 'partners', $slugs );
	}

	/**
	 * delete() removes a non-default profile.
	 */
	public function test_delete_removes_non_default_profile(): void {
		$saved = $this->repository->save(
			Profile::from_array( [ 'slug' => 'members', 'title' => 'Members' ] )
		);
		$this->assertInstanceOf( Profile::class, $saved );

		$result = $this->repository->delete( $saved->get_id() );

		$this->assertTrue( $result );
		$this->assertNull( $this->repository->find_by_slug( 'members' ) );
	}

	/**
	 * delete() refuses to delete the reserved default profile.
	 */
	public function test_delete_refuses_default_profile(): void {
		$default = $this->repository->ensure_default();

		$result = $this->repository->delete( $default->get_id() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'workos_profile_default_locked', $result->get_error_code() );
		$this->assertNotNull( $this->repository->find_by_slug( Profile::DEFAULT_SLUG ) );
	}

	/**
	 * delete() returns an error for an unknown ID.
	 */
	public function test_delete_unknown_id_returns_error(): void {
		$result = $this->repository->delete( 999999 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'workos_profile_not_found', $result->get_error_code() );
	}

	/**
	 * ensure_default creates the default profile on first call and is idempotent.
	 */
	public function test_ensure_default_is_idempotent(): void {
		$first  = $this->repository->ensure_default();
		$second = $this->repository->ensure_default();

		$this->assertInstanceOf( Profile::class, $first );
		$this->assertInstanceOf( Profile::class, $second );
		$this->assertSame( $first->get_id(), $second->get_id() );
		$this->assertSame( Profile::DEFAULT_SLUG, $first->get_slug() );
	}

	/**
	 * find_by_id returns the hydrated profile.
	 */
	public function test_find_by_id_returns_hydrated_profile(): void {
		$saved = $this->repository->save(
			Profile::from_array(
				[
					'slug'            => 'members',
					'title'           => 'Members',
					'organization_id' => 'org_01XYZ',
					'mfa'             => [
						'enforce' => Profile::MFA_ENFORCE_ALWAYS,
						'factors' => [ Profile::FACTOR_TOTP, Profile::FACTOR_WEBAUTHN ],
					],
				]
			)
		);
		$this->assertInstanceOf( Profile::class, $saved );

		$found = $this->repository->find_by_id( $saved->get_id() );

		$this->assertInstanceOf( Profile::class, $found );
		$this->assertSame( 'org_01XYZ', $found->get_organization_id() );
		$this->assertSame( Profile::MFA_ENFORCE_ALWAYS, $found->get_mfa()['enforce'] );
		$this->assertSame(
			[ Profile::FACTOR_TOTP, Profile::FACTOR_WEBAUTHN ],
			$found->get_mfa()['factors']
		);
	}
}
