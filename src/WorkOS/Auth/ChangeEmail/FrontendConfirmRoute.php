<?php
/**
 * Frontend route that renders the confirm/cancel page from the email link.
 *
 * @package WorkOS\Auth\ChangeEmail
 */

namespace WorkOS\Auth\ChangeEmail;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a rewrite rule under an admin-configurable path (default
 * `/workos/change-email/`) and renders the confirmation page when the
 * URL matches.
 *
 * The page itself is dumb — it just enqueues the
 * `change-email-confirm` JS bundle, which reads the token + user_id from
 * the URL and POSTs to the REST confirm (or cancel) endpoint. Doing the
 * mutation in JS lets us keep the GET request side-effect-free (which
 * matters for email-prefetch scanners that GET every link in an
 * inbox).
 *
 * The configurable path is read via `workos()->option(
 * 'change_email_confirm_path', 'workos/change-email' )`. Changing it
 * requires a rewrite-rules flush, handled here by storing the active
 * value in a signature option and re-flushing when it changes.
 */
class FrontendConfirmRoute {

	public const QUERY_VAR        = 'workos_change_email_confirm';
	public const SIGNATURE_OPTION = 'workos_change_email_confirm_path_signature';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', [ $this, 'register_rewrite' ] );
		add_filter( 'query_vars', [ $this, 'register_query_var' ] );
		add_action( 'template_redirect', [ $this, 'maybe_render' ] );
	}

	/**
	 * Register the rewrite rule for the configured confirm path.
	 *
	 * Signature-keyed: if the path option changes between requests we
	 * flush exactly once, then store the new signature so subsequent
	 * `init` ticks no-op.
	 *
	 * @return void
	 */
	public function register_rewrite(): void {
		$path  = $this->path();
		$regex = '^' . preg_quote( $path, '#' ) . '/?$';

		add_rewrite_rule(
			$regex,
			'index.php?' . self::QUERY_VAR . '=1',
			'top'
		);

		$stored = (string) get_option( self::SIGNATURE_OPTION, '' );
		if ( $stored !== $path ) {
			update_option( self::SIGNATURE_OPTION, $path, false );
			// Soft flush — the rule is already added above, the flush
			// just persists the rewrite cache.
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Add our query var so WP exposes it via `get_query_var()`.
	 *
	 * @param array $vars Existing query vars.
	 *
	 * @return array
	 */
	public function register_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * If the current request matches the confirm route, render the page.
	 *
	 * @return void
	 */
	public function maybe_render(): void {
		if ( '1' !== (string) get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		// Pull token + user_id (+ optional redirect target) from the URL —
		// they were appended by build_confirm_url() / build_cancel_url() in
		// RestApi. The redirect is re-validated server-side on confirm.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$token       = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		$user_id     = isset( $_GET['user_id'] ) ? absint( wp_unslash( $_GET['user_id'] ) ) : 0;
		$redirect_to = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '';
		// phpcs:enable

		$site_name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		// Enqueue the page-side JS that will POST to the REST endpoint.
		wp_enqueue_script( 'workos-change-email-confirm' );
		wp_enqueue_style( 'workos-change-email-confirm' );

		$template = workos()->getDir() . 'templates/change-email/confirm-page.php';
		if ( file_exists( $template ) ) {
			include $template;
			exit;
		}
	}

	/**
	 * Resolve the active confirm path (trimmed, slash-free).
	 *
	 * @return string
	 */
	private function path(): string {
		$path = (string) workos()->option( 'change_email_confirm_path', 'workos/change-email' );
		$path = trim( (string) preg_replace( '#[^a-zA-Z0-9/_-]#', '', $path ), '/' );
		return '' !== $path ? $path : 'workos/change-email';
	}
}
