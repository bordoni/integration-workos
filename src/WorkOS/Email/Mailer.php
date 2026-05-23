<?php
/**
 * Small wp_mail() wrapper used by plugin-side notifications.
 *
 * @package WorkOS\Email
 */

namespace WorkOS\Email;

defined( 'ABSPATH' ) || exit;

/**
 * Renders templates from {@see workos()->getDir()}/templates/ and ships
 * them out via `wp_mail()` with HTML headers + a configurable from
 * address.
 *
 * Plugin-side transactional emails (today: the change-email flow)
 * deliberately do NOT use the WorkOS-hosted email infrastructure
 * because the verification step is owned by WordPress — see the
 * commentary in {@see \WorkOS\Auth\ChangeEmail\Controller} for the
 * design choice.
 *
 * Output is filterable at every seam so a site can theme the email or
 * route it through a different transport (e.g. Postmark/SendGrid via
 * `wp_mail` overrides).
 */
class Mailer {

	/**
	 * Send a templated HTML email.
	 *
	 * @param string $to        Recipient address.
	 * @param string $subject   Email subject line.
	 * @param string $template  Template basename under templates/ (no `.php`).
	 * @param array  $context   Template context vars.
	 *
	 * @return bool True when wp_mail accepted the message.
	 */
	public function send( string $to, string $subject, string $template, array $context ): bool {
		$body = $this->render( $template, $context );
		if ( '' === $body ) {
			return false;
		}

		$headers = $this->build_headers( $template, $context );

		/**
		 * Filter the rendered email body before sending.
		 *
		 * @param string $body     Rendered HTML body.
		 * @param string $template Template basename.
		 * @param array  $context  Template context.
		 */
		$body = (string) apply_filters( 'workos_email_body', $body, $template, $context );

		/**
		 * Filter the email subject line before sending.
		 *
		 * @param string $subject  Subject line.
		 * @param string $template Template basename.
		 * @param array  $context  Template context.
		 */
		$subject = (string) apply_filters( 'workos_email_subject', $subject, $template, $context );

		return (bool) wp_mail( $to, $subject, $body, $headers );
	}

	/**
	 * Render a template to a string.
	 *
	 * Templates are PHP files. The `$context` array is extracted into
	 * the local scope before inclusion — keys that collide with
	 * WordPress globals are skipped via `EXTR_SKIP`.
	 *
	 * @param string $template Template basename (e.g. `change-email/verification-email`).
	 * @param array  $context  Template context.
	 *
	 * @return string
	 */
	public function render( string $template, array $context ): string {
		$path = $this->locate( $template );
		if ( '' === $path ) {
			return '';
		}

		ob_start();
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Template helper; keys collisioning with globals are skipped via EXTR_SKIP.
		extract( $context, EXTR_SKIP );
		include $path;
		return (string) ob_get_clean();
	}

	/**
	 * Resolve a template basename to an absolute filesystem path.
	 *
	 * Allows themes / mu-plugins to override a template by placing a
	 * file at `wp-content/themes/{theme}/integration-workos/{template}.php`,
	 * mirroring the standard WP template-override pattern.
	 *
	 * @param string $template Basename (without `.php`).
	 *
	 * @return string Absolute path, or '' when no candidate exists.
	 */
	private function locate( string $template ): string {
		$relative = ltrim( $template, '/' ) . '.php';

		$theme_override = locate_template( 'integration-workos/' . $relative );
		if ( '' !== $theme_override && file_exists( $theme_override ) ) {
			return $theme_override;
		}

		$plugin_path = workos()->getDir() . 'templates/' . $relative;
		if ( file_exists( $plugin_path ) ) {
			return $plugin_path;
		}

		return '';
	}

	/**
	 * Build the headers array (Content-Type + From).
	 *
	 * @param string $template Template basename.
	 * @param array  $context  Template context.
	 *
	 * @return array<int,string>
	 */
	private function build_headers( string $template, array $context ): array {
		$site_name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$from      = sprintf( '%s <%s>', $site_name, $this->from_address() );

		$headers = [
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $from,
		];

		/**
		 * Filter the headers used for plugin-sent emails.
		 *
		 * @param array  $headers  Header lines.
		 * @param string $template Template basename.
		 * @param array  $context  Template context.
		 */
		return (array) apply_filters( 'workos_email_headers', $headers, $template, $context );
	}

	/**
	 * Pick a reasonable from-address.
	 *
	 * Filterable via the WP-standard `wp_mail_from` so existing email
	 * routing plugins continue to work without a WorkOS-specific config.
	 *
	 * @return string
	 */
	private function from_address(): string {
		$host    = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$default = (string) get_option( 'admin_email', 'wordpress@' . ( '' !== $host ? $host : 'localhost' ) );

		// `wp_mail_from` is a WP core filter — we honor it so existing
		// transport plugins continue to work. PHPCS doesn't recognize core
		// filter names as "allowed unprefixed" because they aren't.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		return (string) apply_filters( 'wp_mail_from', $default );
	}
}
