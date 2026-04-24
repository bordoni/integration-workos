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
	 * Fires `workos_authkit_enqueue_assets` after the bundle + style are
	 * enqueued so extenders can register profile-aware companion assets.
	 *
	 * @param Profile|null $profile Active profile (null on the very first
	 *                              call before render_mount() resolves it
	 *                              — passed to the action when available).
	 *
	 * @return void
	 */
	public function enqueue( ?Profile $profile = null ): void {
		if ( ! wp_script_is( self::SCRIPT_HANDLE, 'enqueued' ) ) {
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

			wp_set_script_translations( self::SCRIPT_HANDLE, 'integration-workos' );

			wp_enqueue_style(
				self::STYLE_HANDLE,
				$this->assets_url . 'authkit.css',
				[],
				$asset['version'] ?? WORKOS_VERSION
			);
		}

		if ( $profile instanceof Profile ) {
			/**
			 * Fires after the AuthKit bundle is enqueued for a profile.
			 *
			 * Fires on every render — when multiple AuthKit instances
			 * appear on one page, extenders see each profile in turn.
			 * Use `wp_enqueue_style()` / `wp_enqueue_script()` here to
			 * ship per-profile CSS/JS. Depend on the `workos-authkit`
			 * handle for ordering, plus `wp-plugins` / `wp-components`
			 * if registering SlotFill plugins.
			 *
			 * @param Profile $profile The active Login Profile.
			 */
			do_action( 'workos_authkit_enqueue_assets', $profile );
		}
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
		$this->enqueue( $profile );

		$profile_data = $profile->to_array();

		// Trim the payload before handing it to the browser: the nonce + org
		// id live on the server; the client sees only what it needs to
		// render.
		unset( $profile_data['id'] );
		$profile_data['methods']  = array_values( $profile_data['methods'] );
		$profile_data['mfa']      = $profile_data['mfa'] ?? [];
		$profile_data['branding'] = $this->resolve_branding( $profile );

		/**
		 * Filters the profile data sent to the React shell.
		 *
		 * Use to inject extra config keys a SlotFill plugin needs at the
		 * client. The shell will receive whatever is returned here as the
		 * `data-profile` JSON.
		 *
		 * @param array   $profile_data Trimmed profile data.
		 * @param Profile $profile      The active profile.
		 */
		$profile_data = (array) apply_filters( 'workos_authkit_profile_data', $profile_data, $profile );

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

		/**
		 * Filters the CSS classes applied to the AuthKit full-page <body>.
		 *
		 * Use to give external stylesheets a per-profile hook (e.g. add
		 * `workos-profile-{slug}` for slug-scoped rules).
		 *
		 * @param string[] $classes Default body classes.
		 * @param Profile  $profile The active profile.
		 */
		$body_classes = (array) apply_filters(
			'workos_authkit_body_classes',
			[ 'workos-authkit-body', 'workos-profile-' . $profile->get_slug() ],
			$profile
		);
		$body_class   = trim( implode( ' ', array_map( 'sanitize_html_class', $body_classes ) ) );

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
		<?php
		/*
		 * Print the explicit handle queue (ours + anything extenders added
		 * via `workos_authkit_enqueue_assets`) instead of `wp_print_styles()`
		 * with no args. The no-arg form fires the `wp_print_styles` action,
		 * which core wires `print_emoji_styles` to — that function was
		 * deprecated in WP 6.4. Passing explicit handles skips the action
		 * and the resulting deprecation notice.
		 */
		wp_print_styles( wp_styles()->queue );
		?>
</head>
<body class="<?php echo esc_attr( $body_class ); ?>" data-site-name="<?php echo esc_attr( $site_name ); ?>" data-lang="<?php echo esc_attr( $language ); ?>">
	<main class="workos-authkit-main">
		<?php
		// render_mount() has already escaped every dynamic value. It
		// additionally returns a <style> tag (allowed here) and our root
		// div. Safe to echo.
		echo $mount; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
	</main>
		<?php wp_print_scripts( wp_scripts()->queue ); ?>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * Resolve the profile's branding, falling back to sensible defaults.
	 *
	 * Logo resolution chain is driven by `branding.logo_mode`:
	 * - `none`   → empty string (no logo rendered; skips all fallbacks).
	 * - `custom` → resolved attachment URL (empty string if the attachment
	 *              has been deleted since the profile was saved).
	 * - `default` (and anything else) → WordPress Site Icon
	 *              (Settings → General) → bundled WP logo shipped in
	 *              core (`admin_url('images/wordpress-logo.svg')`) → empty.
	 *
	 * The bundled logo fallback means an unbranded install still looks
	 * like a WordPress login screen out of the box rather than a blank
	 * card.
	 *
	 * @param Profile $profile Active profile.
	 *
	 * @return array
	 */
	private function resolve_branding( Profile $profile ): array {
		$branding = $profile->get_branding();

		$logo_mode = (string) ( $branding['logo_mode'] ?? Profile::LOGO_MODE_DEFAULT );
		$logo_url  = '';

		if ( Profile::LOGO_MODE_NONE === $logo_mode ) {
			$logo_url = '';
		} elseif ( Profile::LOGO_MODE_CUSTOM === $logo_mode ) {
			if ( ! empty( $branding['logo_attachment_id'] ) ) {
				$logo_url = (string) wp_get_attachment_url( (int) $branding['logo_attachment_id'] );
			}
		} else {
			$logo_url = (string) get_site_icon_url( 192 );
			if ( '' === $logo_url ) {
				$logo_url = admin_url( 'images/wordpress-logo.svg' );
			}
		}

		$resolved = [
			'logo_url'      => $logo_url,
			'primary_color' => (string) ( $branding['primary_color'] ?? '' ),
			'heading'       => (string) ( $branding['heading'] ?? '' ),
			'subheading'    => (string) ( $branding['subheading'] ?? '' ),
		];

		/**
		 * Filters the resolved branding for an AuthKit render.
		 *
		 * Use to override the logo fallback chain or rewrite any branding
		 * field at the last mile before it lands in the data-profile JSON.
		 *
		 * @param array   $resolved Resolved branding.
		 * @param Profile $profile  The active profile.
		 */
		return (array) apply_filters( 'workos_authkit_branding', $resolved, $profile );
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
			// A custom primary drops the matching WP-blue hover, so override
			// hover to the same color. Deriving a darker shade would require
			// a color-math utility and is not worth the added surface area;
			// a flat hover reads cleanly enough for a branded palette.
			$rules[] = '--wa-primary: ' . $primary . ';';
			$rules[] = '--wa-primary-hover: ' . $primary . ';';
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
