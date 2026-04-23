<?php
/**
 * AuthKit React shell renderer.
 *
 * @package WorkOS\Auth\AuthKit
 */

namespace WorkOS\Auth\AuthKit;

defined( 'ABSPATH' ) || exit;

/**
 * Emits the markup + scripts the React shell mounts against.
 *
 * Shared across every entry point that surfaces the AuthKit UI:
 *
 *  - wp-login.php takeover (full-page chrome we control ourselves)
 *  - `[workos_login]` shortcode / Gutenberg block (inline card)
 *  - `/workos/login/{profile}` rewrite (full-bleed template)
 *  - Admin Profile Editor (via a different React bundle in Phase 5)
 *
 * The renderer never decides WHERE to render — it just produces markup
 * the caller can place and promises that the enqueued bundle will mount
 * onto `#workos-authkit-root`.
 */
class Renderer {

	public const SCRIPT_HANDLE = 'workos-authkit';
	public const STYLE_HANDLE  = 'workos-authkit';

	/**
	 * Plugin build assets URL (absolute, trailing slash).
	 *
	 * @var string
	 */
	private string $assets_url;

	/**
	 * Plugin build assets directory (absolute, trailing slash).
	 *
	 * @var string
	 */
	private string $assets_dir;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->assets_url = trailingslashit( WORKOS_URL . 'build' );
		$this->assets_dir = trailingslashit( WORKOS_DIR . 'build' );
	}

	/**
	 * Enqueue the AuthKit bundle, idempotent.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		if ( wp_script_is( self::SCRIPT_HANDLE, 'enqueued' ) ) {
			return;
		}

		$asset_file = $this->assets_dir . 'authkit.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: [
				'dependencies' => [ 'wp-element' ],
				'version'      => WORKOS_VERSION,
			];

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			$this->assets_url . 'authkit.js',
			$asset['dependencies'] ?? [ 'wp-element' ],
			$asset['version'] ?? WORKOS_VERSION,
			true
		);

		wp_enqueue_style(
			self::STYLE_HANDLE,
			$this->assets_url . 'authkit.css',
			[],
			$asset['version'] ?? WORKOS_VERSION
		);
	}

	/**
	 * Render the mount markup for a profile.
	 *
	 * @param Profile $profile      Active Login Profile.
	 * @param array   $context      {
	 *     Optional render context.
	 *
	 *     @type string $redirect_to      Post-login redirect URL.
	 *     @type string $invitation_token If present, shell boots into invite flow.
	 *     @type string $reset_token      If present, shell boots into reset-confirm flow.
	 *     @type string $initial_step     Initial step slug (defaults to 'pick').
	 * }
	 *
	 * @return string Safe HTML for direct output.
	 */
	public function render_mount( Profile $profile, array $context = [] ): string {
		$this->enqueue();

		$profile_data = $profile->to_array();

		// Trim the payload before handing it to the browser: the nonce + org
		// id live on the server; the client sees only what it needs to
		// render.
		unset( $profile_data['id'] );
		$profile_data['methods']  = array_values( $profile_data['methods'] );
		$profile_data['mfa']      = $profile_data['mfa'] ?? [];
		$profile_data['branding'] = $this->resolve_branding( $profile );

		$attrs = [
			'id'                    => 'workos-authkit-root',
			'data-profile'          => wp_json_encode( $profile_data ),
			'data-rest-base'        => esc_url_raw( rest_url( 'workos/v1/auth' ) ),
			'data-redirect-to'      => esc_url_raw( $context['redirect_to'] ?? '' ),
			'data-invitation-token' => sanitize_text_field( $context['invitation_token'] ?? '' ),
			'data-reset-token'      => sanitize_text_field( $context['reset_token'] ?? '' ),
			'data-initial-step'     => sanitize_key( $context['initial_step'] ?? 'pick' ),
		];

		$style_tag = $this->branding_style_tag( $profile_data['branding'] );

		return $style_tag . '<div ' . $this->stringify_attrs( $attrs ) . '></div>';
	}

	/**
	 * Render a full-page login document (for wp-login.php takeover and the
	 * `/workos/login/{profile}` rewrite).
	 *
	 * @param Profile $profile Active profile.
	 * @param array   $context Render context (see render_mount()).
	 *
	 * @return void Sends a full HTML response and exits.
	 */
	public function render_full_page( Profile $profile, array $context = [] ): void {
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );

		$site_name = wp_strip_all_tags( (string) get_bloginfo( 'name' ) );
		$language  = get_bloginfo( 'language' );
		$mount     = $this->render_mount( $profile, $context );

		/*
		 * We intentionally do not call wp_head()/wp_footer(): those pull in
		 * admin and theme chrome we do not want. Instead we call
		 * wp_print_scripts + wp_print_styles after our enqueue so only our
		 * own bundle ships.
		 */
		?>
<!DOCTYPE html>
<html <?php echo esc_attr( get_language_attributes() ); ?>>
<head>
	<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="robots" content="noindex, nofollow" />
	<title><?php echo esc_html( sprintf( /* translators: %s: site name */ __( 'Sign in — %s', 'integration-workos' ), $site_name ) ); ?></title>
		<?php wp_print_styles( self::STYLE_HANDLE ); ?>
</head>
<body class="workos-authkit-body" data-site-name="<?php echo esc_attr( $site_name ); ?>" data-lang="<?php echo esc_attr( $language ); ?>">
	<main class="workos-authkit-main">
		<?php
		// render_mount() has already escaped every dynamic value. It
		// additionally returns a <style> tag (allowed here) and our root
		// div. Safe to echo.
		echo $mount; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
	</main>
		<?php wp_print_scripts( self::SCRIPT_HANDLE ); ?>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * Resolve the profile's branding, falling back to sensible defaults.
	 *
	 * @param Profile $profile Active profile.
	 *
	 * @return array
	 */
	private function resolve_branding( Profile $profile ): array {
		$branding = $profile->get_branding();

		$logo_url = '';
		if ( ! empty( $branding['logo_attachment_id'] ) ) {
			$logo_url = (string) wp_get_attachment_url( (int) $branding['logo_attachment_id'] );
		}

		return [
			'logo_url'      => $logo_url,
			'primary_color' => (string) ( $branding['primary_color'] ?? '' ),
			'heading'       => (string) ( $branding['heading'] ?? '' ),
			'subheading'    => (string) ( $branding['subheading'] ?? '' ),
		];
	}

	/**
	 * Build an inline <style> tag that applies profile-level branding.
	 *
	 * The tag lives next to the mount div so it is scoped to this exact
	 * shell instance — multiple AuthKit widgets on one page each get their
	 * own variables.
	 *
	 * @param array $branding Branding data.
	 *
	 * @return string
	 */
	private function branding_style_tag( array $branding ): string {
		$rules = [];

		// Re-validate the primary color as defense-in-depth. Profile::from_array
		// already regex-matches it against a hex pattern on save, but this
		// renderer is the last line before raw emission into a CSS context
		// where `esc_attr` is semantically wrong. Enforcing the same regex
		// here guarantees that whatever arrives in $branding — now or from
		// future call sites — cannot introduce a semicolon, closing brace,
		// or `</style>` sequence.
		$primary = (string) ( $branding['primary_color'] ?? '' );
		if ( '' !== $primary && preg_match( '/^#[0-9a-fA-F]{3,8}$/', $primary ) ) {
			$rules[] = '--wa-primary: ' . $primary . ';';
		}

		if ( empty( $rules ) ) {
			return '';
		}

		return '<style>#workos-authkit-root{' . implode( ' ', $rules ) . '}</style>';
	}

	/**
	 * Render an attributes array as an HTML attribute string.
	 *
	 * @param array $attrs Key-value attributes.
	 *
	 * @return string
	 */
	private function stringify_attrs( array $attrs ): string {
		$pieces = [];
		foreach ( $attrs as $name => $value ) {
			$pieces[] = sprintf( '%s="%s"', esc_attr( $name ), esc_attr( (string) $value ) );
		}
		return implode( ' ', $pieces );
	}
}
