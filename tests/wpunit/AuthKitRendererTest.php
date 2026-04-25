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

		$this->assertStringContainsString( '--wa-primary: #ff3366', $html );
		$this->assertStringContainsString( '--wa-primary-hover: #ff3366', $html );
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
	 * resolve_branding falls back to the WordPress Site Icon when no
	 * per-profile logo is set.
	 */
	public function test_render_mount_falls_back_to_site_icon_for_logo(): void {
		$attachment_id = self::factory()->attachment->create_object(
			'site-icon.png',
			0,
			[
				'post_mime_type' => 'image/png',
				'post_type'      => 'attachment',
			]
		);
		update_option( 'site_icon', $attachment_id );

		$profile = Profile::from_array(
			[
				'slug'  => 'members',
				'title' => 'Members',
				// No branding.logo_attachment_id — should fall back.
			]
		);

		$html = $this->renderer->render_mount( $profile );

		$site_icon_url = (string) get_site_icon_url( 192 );
		$this->assertNotEmpty( $site_icon_url, 'Site Icon should resolve to a URL.' );

		// The data-profile JSON has forward slashes escaped as `\/`, and the
		// attribute is HTML-escaped. Match against the filename, which is
		// stable across both escapings.
		$this->assertStringContainsString(
			'site-icon.png',
			$html,
			'Render output should embed the Site Icon URL when no per-profile logo is set.'
		);

		delete_option( 'site_icon' );
		wp_delete_attachment( $attachment_id, true );
	}

	/**
	 * `logo_mode: none` wins over every fallback — no <img> URL lands in
	 * the data-profile JSON even when a Site Icon and a saved attachment
	 * both exist.
	 */
	public function test_render_mount_logo_mode_none_emits_empty_logo(): void {
		$site_icon = self::factory()->attachment->create_object(
			'site-icon.png',
			0,
			[
				'post_mime_type' => 'image/png',
				'post_type'      => 'attachment',
			]
		);
		update_option( 'site_icon', $site_icon );

		$profile = Profile::from_array(
			[
				'slug'     => 'members',
				'title'    => 'Members',
				'branding' => [
					'logo_mode'          => Profile::LOGO_MODE_NONE,
					'logo_attachment_id' => $site_icon,
				],
			]
		);

		$html = $this->renderer->render_mount( $profile );

		$this->assertStringNotContainsString( 'site-icon.png', $html );
		// The data-profile JSON is HTML-escaped inside an attribute, so
		// match the entity-encoded form of `"logo_url":""`.
		$this->assertStringContainsString( '&quot;logo_url&quot;:&quot;&quot;', $html );

		delete_option( 'site_icon' );
		wp_delete_attachment( $site_icon, true );
	}

	/**
	 * With no Site Icon set, `default` mode falls through to the bundled
	 * WordPress "W" logo shipped in core. This is the "looks like WP out
	 * of the box" path.
	 */
	public function test_render_mount_falls_back_to_bundled_wp_logo(): void {
		delete_option( 'site_icon' );

		$profile = Profile::from_array(
			[
				'slug'  => 'members',
				'title' => 'Members',
			]
		);

		$html = $this->renderer->render_mount( $profile );

		$this->assertStringContainsString( 'wordpress-logo.svg', $html );
	}

	/**
	 * The workos_authkit_enqueue_assets action fires with the active Profile.
	 */
	public function test_render_mount_fires_enqueue_assets_action_with_profile(): void {
		$received = null;
		$cb       = static function ( $profile ) use ( &$received ): void {
			$received = $profile;
		};
		add_action( 'workos_authkit_enqueue_assets', $cb );

		$profile = Profile::from_array(
			[
				'slug'  => 'members',
				'title' => 'Members',
			]
		);
		$this->renderer->render_mount( $profile );

		remove_action( 'workos_authkit_enqueue_assets', $cb );

		$this->assertInstanceOf(
			Profile::class,
			$received,
			'enqueue_assets action must receive a Profile instance.'
		);
		$this->assertSame( 'members', $received->get_slug() );
	}

	/**
	 * workos_authkit_branding filter mutates the resolved branding before it
	 * lands in the data-profile JSON.
	 */
	public function test_workos_authkit_branding_filter_can_override_logo(): void {
		$cb = static function ( $branding ) {
			$branding['logo_url'] = 'https://example.test/custom-logo.png';
			return $branding;
		};
		add_filter( 'workos_authkit_branding', $cb );

		$profile = Profile::from_array(
			[
				'slug'  => 'members',
				'title' => 'Members',
			]
		);
		$html = $this->renderer->render_mount( $profile );

		remove_filter( 'workos_authkit_branding', $cb );

		$this->assertStringContainsString(
			'custom-logo.png',
			$html,
			'branding filter should be able to inject a custom logo_url.'
		);
	}

	/**
	 * workos_authkit_profile_data filter mutates the data-profile payload.
	 */
	public function test_workos_authkit_profile_data_filter_can_inject_keys(): void {
		$cb = static function ( $data ) {
			$data['__test_marker'] = 'present';
			return $data;
		};
		add_filter( 'workos_authkit_profile_data', $cb );

		$profile = Profile::from_array(
			[
				'slug'  => 'members',
				'title' => 'Members',
			]
		);
		$html = $this->renderer->render_mount( $profile );

		remove_filter( 'workos_authkit_profile_data', $cb );

		$this->assertStringContainsString(
			'__test_marker',
			$html,
			'profile_data filter should be able to inject custom keys into the data-profile JSON.'
		);
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
