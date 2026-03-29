<?php
/**
 * Tests for Renderer output escaping via wp_kses.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\UI\Renderer;

/**
 * Renderer kses output escaping tests.
 */
class RendererKsesTest extends WPTestCase {

	/**
	 * Set up each test — ensure WorkOS is active with minimal config.
	 */
	public function setUp(): void {
		parent::setUp();

		\WorkOS\Config::set_active_environment( 'production' );
		update_option( 'workos_production', [
			'api_key'        => 'sk_test_fake',
			'client_id'      => 'client_fake',
			'environment_id' => 'environment_test',
			'login_mode'     => 'redirect',
		] );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();
	}

	/**
	 * Tear down each test.
	 */
	public function tearDown(): void {
		delete_option( 'workos_production' );
		\WorkOS\Config::set_active_environment( 'staging' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		parent::tearDown();
	}

	/**
	 * Test allowed_html includes all post tags.
	 */
	public function test_allowed_html_includes_post_tags(): void {
		$allowed  = Renderer::allowed_html();
		$post_tags = wp_kses_allowed_html( 'post' );

		foreach ( array_keys( $post_tags ) as $tag ) {
			$this->assertArrayHasKey( $tag, $allowed, "Post tag <{$tag}> missing from allowed_html." );
		}
	}

	/**
	 * Test allowed_html includes form elements.
	 */
	public function test_allowed_html_includes_form_elements(): void {
		$allowed = Renderer::allowed_html();

		$this->assertArrayHasKey( 'form', $allowed );
		$this->assertArrayHasKey( 'input', $allowed );
		$this->assertArrayHasKey( 'data-workos-headless-form', $allowed['form'] );
		$this->assertArrayHasKey( 'placeholder', $allowed['input'] );
	}

	/**
	 * Test allowed_html includes SVG elements.
	 */
	public function test_allowed_html_includes_svg_elements(): void {
		$allowed = Renderer::allowed_html();

		$this->assertArrayHasKey( 'svg', $allowed );
		$this->assertArrayHasKey( 'rect', $allowed );
		$this->assertArrayHasKey( 'path', $allowed );
		$this->assertArrayHasKey( 'viewbox', $allowed['svg'] );
		$this->assertArrayHasKey( 'd', $allowed['path'] );
	}

	/**
	 * Test allowed_html extends button with headless toggle data attribute.
	 */
	public function test_allowed_html_extends_button(): void {
		$allowed = Renderer::allowed_html();

		$this->assertArrayHasKey( 'button', $allowed );
		$this->assertArrayHasKey( 'data-workos-headless-toggle', $allowed['button'] );
	}

	/**
	 * Test render output preserves form elements through kses.
	 */
	public function test_render_preserves_form_in_headless_mode(): void {
		// Switch to headless mode.
		$opts               = get_option( 'workos_production' );
		$opts['login_mode'] = 'headless';
		update_option( 'workos_production', $opts );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		// Log out so we get the login form.
		wp_set_current_user( 0 );

		$html = Renderer::render( [ 'mode' => 'headless' ] );

		$this->assertStringContainsString( '<form', $html );
		$this->assertStringContainsString( '<input', $html );
		$this->assertStringContainsString( 'data-workos-headless-form', $html );
	}

	/**
	 * Test render output preserves SVG icon through kses.
	 */
	public function test_render_preserves_svg_icon(): void {
		wp_set_current_user( 0 );

		$html = Renderer::render( [ 'show_icon' => true ] );

		$this->assertStringContainsString( '<svg', $html );
		$this->assertStringContainsString( '<rect', $html );
		$this->assertStringContainsString( '<path', $html );
	}

	/**
	 * Test render strips script tags.
	 */
	public function test_render_strips_disallowed_tags(): void {
		$allowed = Renderer::allowed_html();

		$dirty = '<div class="safe"><script>alert("xss")</script></div>';
		$clean = wp_kses( $dirty, $allowed );

		$this->assertStringNotContainsString( '<script', $clean );
		$this->assertStringContainsString( '<div', $clean );
	}

	/**
	 * Test render returns empty when plugin is not enabled.
	 */
	public function test_render_returns_empty_when_disabled(): void {
		delete_option( 'workos_production' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		$html = Renderer::render( [] );

		$this->assertSame( '', $html );
	}
}
