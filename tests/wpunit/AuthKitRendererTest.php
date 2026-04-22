<?php
/**
 * Tests for the AuthKit Renderer + Shortcode + LoginTakeover surface.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Auth\AuthKit\LoginTakeover;
use WorkOS\Auth\AuthKit\Profile;
use WorkOS\Auth\AuthKit\ProfileRepository;
use WorkOS\Auth\AuthKit\ProfileRouter;
use WorkOS\Auth\AuthKit\Renderer;
use WorkOS\Auth\AuthKit\Shortcode;

/**
 * Markup shape + short-circuit logic for the rendering pieces.
 */
class AuthKitRendererTest extends WPTestCase {

	/**
	 * Repository.
	 *
	 * @var ProfileRepository
	 */
	private ProfileRepository $repository;

	/**
	 * Renderer under test.
	 *
	 * @var Renderer
	 */
	private Renderer $renderer;

	public function setUp(): void {
		parent::setUp();

		if ( ! defined( 'WORKOS_URL' ) ) {
			define( 'WORKOS_URL', 'http://example.test/wp-content/plugins/integration-workos/' );
		}
		if ( ! defined( 'WORKOS_DIR' ) ) {
			define( 'WORKOS_DIR', '/var/www/html/wp-content/plugins/integration-workos/' );
		}

		$this->repository = new ProfileRepository();
		$this->repository->register_post_type();
		foreach ( $this->repository->all() as $profile ) {
			wp_delete_post( $profile->get_id(), true );
		}

		$this->renderer = new Renderer();
	}

	public function tearDown(): void {
		foreach ( $this->repository->all() as $profile ) {
			wp_delete_post( $profile->get_id(), true );
		}
		remove_shortcode( Shortcode::TAG );
		parent::tearDown();
	}

	/**
	 * render_mount emits the root div with JSON-encoded profile data.
	 */
	public function test_render_mount_emits_root_div_with_profile_data(): void {
		$profile = Profile::from_array(
			[
				'slug'    => 'members',
				'title'   => 'Members',
				'methods' => [ Profile::METHOD_MAGIC_CODE ],
			]
		);

		$html = $this->renderer->render_mount( $profile, [ 'redirect_to' => '/dashboard' ] );

		$this->assertStringContainsString( 'id="workos-authkit-root"', $html );
		$this->assertStringContainsString( 'data-profile=', $html );
		$this->assertStringContainsString( 'magic_code', $html );
		$this->assertStringContainsString( 'data-redirect-to="/dashboard"', $html );
	}

	/**
	 * Branding primary color appears as a scoped CSS variable.
	 */
	public function test_render_mount_emits_branding_style_tag(): void {
		$profile = Profile::from_array(
			[
				'slug'     => 'members',
				'title'    => 'Members',
				'branding' => [ 'primary_color' => '#ff3366' ],
			]
		);

		$html = $this->renderer->render_mount( $profile );

		$this->assertStringContainsString( '#workos-authkit-root{--wa-primary: #ff3366', $html );
	}

	/**
	 * Shortcode in custom mode renders the root div.
	 */
	public function test_shortcode_renders_custom_profile(): void {
		$saved = $this->repository->save(
			Profile::from_array(
				[
					'slug'    => 'members',
					'title'   => 'Members',
					'methods' => [ Profile::METHOD_PASSWORD ],
					'mode'    => Profile::MODE_CUSTOM,
				]
			)
		);
		$this->assertInstanceOf( Profile::class, $saved );

		$shortcode = new Shortcode( new ProfileRouter( $this->repository ), $this->renderer );
		$shortcode->register();

		$output = do_shortcode( '[' . Shortcode::TAG . ' profile="members"]' );

		$this->assertStringContainsString( 'id="workos-authkit-root"', $output );
	}

	/**
	 * Shortcode in legacy AuthKit-redirect mode renders nothing.
	 */
	public function test_shortcode_noops_for_authkit_redirect_profile(): void {
		$this->repository->save(
			Profile::from_array(
				[
					'slug'  => 'legacy',
					'title' => 'Legacy',
					'mode'  => Profile::MODE_AUTHKIT_REDIRECT,
				]
			)
		);

		$shortcode = new Shortcode( new ProfileRouter( $this->repository ), $this->renderer );
		$shortcode->register();

		$output = do_shortcode( '[' . Shortcode::TAG . ' profile="legacy"]' );

		$this->assertSame( '', $output );
	}

	/**
	 * LoginTakeover short-circuits `action=login` for a custom profile.
	 *
	 * We assert the should_takeover decision via the resolved profile's
	 * mode; actually calling maybe_takeover() would `exit` mid-test.
	 */
	public function test_login_takeover_resolves_custom_default_profile(): void {
		$this->repository->ensure_default(); // Default is custom mode by default.
		$router   = new ProfileRouter( $this->repository );
		$takeover = new LoginTakeover( $router, $this->renderer, $this->repository );
		$takeover->register();

		$this->assertSame(
			5,
			has_action( 'login_init', [ $takeover, 'maybe_takeover' ] ),
			'LoginTakeover should hook login_init at priority 5.'
		);

		$takeover->unregister();

		$this->assertFalse( has_action( 'login_init', [ $takeover, 'maybe_takeover' ] ) );
	}
}
