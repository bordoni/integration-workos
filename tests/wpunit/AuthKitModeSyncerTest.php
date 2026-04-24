<?php
/**
 * Tests for ModeSyncer — bidirectional sync between login_mode env
 * option and the default Login Profile's mode.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Auth\AuthKit\ModeSyncer;
use WorkOS\Auth\AuthKit\Profile;
use WorkOS\Auth\AuthKit\ProfileRepository;

/**
 * Ensures the Settings → Authentication → Login Mode dropdown and the
 * default Login Profile's `mode` never drift apart.
 */
class AuthKitModeSyncerTest extends WPTestCase {

	private ProfileRepository $repository;
	private ModeSyncer $syncer;

	public function setUp(): void {
		parent::setUp();

		\WorkOS\Config::set_active_environment( 'production' );
		update_option(
			'workos_production',
			[
				'api_key'        => 'sk_test_fake',
				'client_id'      => 'client_fake',
				'environment_id' => 'environment_test',
				'login_mode'     => 'custom',
			]
		);
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$this->repository = new ProfileRepository();
		$this->repository->register_post_type();

		foreach ( $this->repository->all() as $profile ) {
			wp_delete_post( $profile->get_id(), true );
		}

		$this->syncer = new ModeSyncer( $this->repository );
		$this->syncer->register();
	}

	public function tearDown(): void {
		remove_action( 'update_option_workos_production', [ $this->syncer, 'on_option_update' ], 10 );
		remove_action( 'update_option_workos_staging', [ $this->syncer, 'on_option_update' ], 10 );
		remove_action( 'add_option_workos_production', [ $this->syncer, 'on_option_add' ], 10 );
		remove_action( 'add_option_workos_staging', [ $this->syncer, 'on_option_add' ], 10 );
		remove_action( 'workos_login_profile_saved', [ $this->syncer, 'on_profile_saved' ], 10 );

		foreach ( $this->repository->all() as $profile ) {
			wp_delete_post( $profile->get_id(), true );
		}
		delete_option( 'workos_production' );
		\WorkOS\Config::set_active_environment( 'staging' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		parent::tearDown();
	}

	/**
	 * Saving `login_mode=redirect` flips the default profile to `authkit_redirect`.
	 */
	public function test_option_redirect_pushes_profile_to_authkit_redirect(): void {
		$default = $this->repository->ensure_default();
		$this->assertSame( Profile::MODE_CUSTOM, $default->get_mode() );

		$options               = get_option( 'workos_production' );
		$options['login_mode'] = 'redirect';
		update_option( 'workos_production', $options );

		$after = $this->repository->find_by_slug( Profile::DEFAULT_SLUG );
		$this->assertInstanceOf( Profile::class, $after );
		$this->assertSame( Profile::MODE_AUTHKIT_REDIRECT, $after->get_mode() );
	}

	/**
	 * Saving `login_mode=custom` restores the profile to `custom`.
	 */
	public function test_option_custom_pushes_profile_to_custom(): void {
		$default        = $this->repository->ensure_default();
		$redirect_data  = $default->to_array();
		$redirect_data['mode'] = Profile::MODE_AUTHKIT_REDIRECT;
		$this->repository->save( Profile::from_array( $redirect_data ) );

		$options               = get_option( 'workos_production' );
		$options['login_mode'] = 'custom';
		update_option( 'workos_production', $options );

		$after = $this->repository->find_by_slug( Profile::DEFAULT_SLUG );
		$this->assertInstanceOf( Profile::class, $after );
		$this->assertSame( Profile::MODE_CUSTOM, $after->get_mode() );
	}

	/**
	 * Saving `login_mode=headless` leaves the profile untouched (orthogonal).
	 */
	public function test_option_headless_does_not_change_profile(): void {
		$default = $this->repository->ensure_default();
		$this->assertSame( Profile::MODE_CUSTOM, $default->get_mode() );

		$options               = get_option( 'workos_production' );
		$options['login_mode'] = 'headless';
		update_option( 'workos_production', $options );

		$after = $this->repository->find_by_slug( Profile::DEFAULT_SLUG );
		$this->assertInstanceOf( Profile::class, $after );
		$this->assertSame( Profile::MODE_CUSTOM, $after->get_mode() );
	}

	/**
	 * Editing the default profile's mode writes back to the env option.
	 */
	public function test_profile_mode_change_writes_back_to_login_mode(): void {
		$default = $this->repository->ensure_default();

		$next = $default->to_array();
		$next['mode'] = Profile::MODE_AUTHKIT_REDIRECT;
		$this->repository->save( Profile::from_array( $next ) );

		$options = get_option( 'workos_production' );
		$this->assertSame( 'redirect', $options['login_mode'] );
	}

	/**
	 * A non-default profile change must NOT touch the env option.
	 */
	public function test_non_default_profile_does_not_touch_login_mode(): void {
		$this->repository->ensure_default();
		$options_before = get_option( 'workos_production' );

		$this->repository->save(
			Profile::from_array(
				[
					'slug'  => 'members',
					'title' => 'Members',
					'mode'  => Profile::MODE_AUTHKIT_REDIRECT,
				]
			)
		);

		$options_after = get_option( 'workos_production' );
		$this->assertSame(
			$options_before['login_mode'],
			$options_after['login_mode']
		);
	}

	/**
	 * When already aligned, no-op — prevents write loops.
	 */
	public function test_noop_when_already_aligned(): void {
		$default = $this->repository->ensure_default();
		$this->assertSame( Profile::MODE_CUSTOM, $default->get_mode() );

		// Re-save the same value; no cascading updates.
		$options               = get_option( 'workos_production' );
		$options['login_mode'] = 'custom';
		update_option( 'workos_production', $options );

		$this->assertSame(
			Profile::MODE_CUSTOM,
			$this->repository->find_by_slug( Profile::DEFAULT_SLUG )->get_mode()
		);
	}
}
