<?php
/**
 * Tests for the Login Profile admin REST API.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Admin\LoginProfiles\RestApi;
use WorkOS\Auth\AuthKit\Profile;
use WorkOS\Auth\AuthKit\ProfileRepository;

/**
 * REST routes for admin Login Profile CRUD.
 */
class AuthKitLoginProfilesRestApiTest extends WPTestCase {

	/**
	 * Repository.
	 *
	 * @var ProfileRepository
	 */
	private ProfileRepository $repository;

	/**
	 * REST namespace + base.
	 */
	private const ROUTE_BASE = '/' . RestApi::NAMESPACE . RestApi::BASE;

	/**
	 * Set up each test — register routes via rest_api_init.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->repository = new ProfileRepository();
		$this->repository->register_post_type();

		// Clear any profiles left over.
		foreach ( $this->repository->all() as $profile ) {
			wp_delete_post( $profile->get_id(), true );
		}

		// Register the REST routes against a fresh REST server.
		new RestApi( $this->repository );
		$server = rest_get_server();
		do_action( 'rest_api_init', $server );
	}

	/**
	 * Tear down — remove profiles and reset the current user.
	 */
	public function tearDown(): void {
		foreach ( $this->repository->all() as $profile ) {
			wp_delete_post( $profile->get_id(), true );
		}

		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Become an admin — all CRUD routes require manage_options.
	 */
	private function become_admin(): int {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * Dispatch a request through the REST server.
	 */
	private function dispatch( string $method, string $route, array $body = null ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, $route );
		if ( null !== $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Anonymous callers are rejected.
	 */
	public function test_anonymous_caller_is_forbidden(): void {
		$response = $this->dispatch( 'GET', self::ROUTE_BASE );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Non-admin users are rejected.
	 */
	public function test_subscriber_is_forbidden(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$response = $this->dispatch( 'GET', self::ROUTE_BASE );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * GET list returns all saved profiles.
	 */
	public function test_list_returns_all_profiles(): void {
		$this->become_admin();

		$this->repository->save( Profile::from_array( [ 'slug' => 'members', 'title' => 'Members' ] ) );
		$this->repository->save( Profile::from_array( [ 'slug' => 'partners', 'title' => 'Partners' ] ) );

		$response = $this->dispatch( 'GET', self::ROUTE_BASE );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'profiles', $data );
		$this->assertCount( 2, $data['profiles'] );

		$slugs = array_column( $data['profiles'], 'slug' );
		$this->assertContains( 'members', $slugs );
		$this->assertContains( 'partners', $slugs );
	}

	/**
	 * POST creates a new profile with id=0 enforced.
	 */
	public function test_create_ignores_client_id_and_persists(): void {
		$this->become_admin();

		$response = $this->dispatch(
			'POST',
			self::ROUTE_BASE,
			[
				'id'      => 9999,
				'slug'    => 'members',
				'title'   => 'Members Area',
				'methods' => [ Profile::METHOD_MAGIC_CODE, Profile::METHOD_OAUTH_GOOGLE ],
			]
		);

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'members', $data['slug'] );
		$this->assertNotSame( 9999, $data['id'] );
		$this->assertGreaterThan( 0, $data['id'] );
		$this->assertSame( [ Profile::METHOD_MAGIC_CODE, Profile::METHOD_OAUTH_GOOGLE ], $data['methods'] );
	}

	/**
	 * POST with a duplicate slug returns 400 with the repository error code.
	 */
	public function test_create_duplicate_slug_returns_400(): void {
		$this->become_admin();

		$this->repository->save( Profile::from_array( [ 'slug' => 'members', 'title' => 'Members' ] ) );

		$response = $this->dispatch(
			'POST',
			self::ROUTE_BASE,
			[
				'slug'  => 'members',
				'title' => 'Dupes',
			]
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'workos_profile_slug_taken', $response->get_data()['code'] );
	}

	/**
	 * GET by ID returns the single profile.
	 */
	public function test_get_by_id_returns_profile(): void {
		$this->become_admin();

		$saved = $this->repository->save( Profile::from_array( [ 'slug' => 'members', 'title' => 'Members' ] ) );
		$this->assertInstanceOf( Profile::class, $saved );

		$response = $this->dispatch( 'GET', self::ROUTE_BASE . '/' . $saved->get_id() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'members', $response->get_data()['slug'] );
	}

	/**
	 * GET by unknown ID returns 404.
	 */
	public function test_get_unknown_id_returns_404(): void {
		$this->become_admin();

		$response = $this->dispatch( 'GET', self::ROUTE_BASE . '/999999' );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * PUT performs a partial merge — untouched fields survive.
	 */
	public function test_update_merges_partial_payload(): void {
		$this->become_admin();

		$saved = $this->repository->save(
			Profile::from_array(
				[
					'slug'            => 'members',
					'title'           => 'Members',
					'organization_id' => 'org_01ABC',
					'methods'         => [ Profile::METHOD_PASSWORD, Profile::METHOD_MAGIC_CODE ],
				]
			)
		);
		$this->assertInstanceOf( Profile::class, $saved );

		$response = $this->dispatch(
			'PUT',
			self::ROUTE_BASE . '/' . $saved->get_id(),
			[ 'title' => 'Renamed Members' ]
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'Renamed Members', $data['title'] );
		// Non-touched fields are preserved.
		$this->assertSame( 'org_01ABC', $data['organization_id'] );
		$this->assertSame( [ Profile::METHOD_PASSWORD, Profile::METHOD_MAGIC_CODE ], $data['methods'] );
	}

	/**
	 * PUT on the default profile cannot change the reserved slug.
	 */
	public function test_update_protects_default_slug(): void {
		$this->become_admin();

		$default = $this->repository->ensure_default();

		$response = $this->dispatch(
			'PUT',
			self::ROUTE_BASE . '/' . $default->get_id(),
			[ 'slug' => 'not-default' ]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( Profile::DEFAULT_SLUG, $response->get_data()['slug'] );
	}

	/**
	 * PUT on an unknown ID returns 404.
	 */
	public function test_update_unknown_id_returns_404(): void {
		$this->become_admin();

		$response = $this->dispatch(
			'PUT',
			self::ROUTE_BASE . '/999999',
			[ 'title' => 'Will not save' ]
		);

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * DELETE removes a non-default profile.
	 */
	public function test_delete_removes_non_default_profile(): void {
		$this->become_admin();

		$saved = $this->repository->save(
			Profile::from_array( [ 'slug' => 'members', 'title' => 'Members' ] )
		);
		$this->assertInstanceOf( Profile::class, $saved );

		$response = $this->dispatch( 'DELETE', self::ROUTE_BASE . '/' . $saved->get_id() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['deleted'] );
		$this->assertNull( $this->repository->find_by_slug( 'members' ) );
	}

	/**
	 * DELETE on the default profile is rejected with 400.
	 */
	public function test_delete_default_profile_rejected(): void {
		$this->become_admin();

		$default = $this->repository->ensure_default();

		$response = $this->dispatch( 'DELETE', self::ROUTE_BASE . '/' . $default->get_id() );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'workos_profile_default_locked', $response->get_data()['code'] );
		$this->assertNotNull( $this->repository->find_by_slug( Profile::DEFAULT_SLUG ) );
	}

	/**
	 * DELETE on an unknown ID returns 404.
	 */
	public function test_delete_unknown_id_returns_404(): void {
		$this->become_admin();

		$response = $this->dispatch( 'DELETE', self::ROUTE_BASE . '/999999' );

		$this->assertSame( 404, $response->get_status() );
	}
}
