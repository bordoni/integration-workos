<?php
/**
 * `[workos:password-reset]` shortcode.
 *
 * @package WorkOS\Auth\PasswordResetAdmin
 */

namespace WorkOS\Auth\PasswordResetAdmin;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a button that triggers the password-reset admin endpoint.
 *
 * Two modes, picked by the `user` attribute:
 *
 *  - `user="<id|email>"` — admin-of-other mode. Visible only to viewers
 *    with `edit_user` on the target. Useful on internal dashboards.
 *
 *  - no `user` attribute — self-service mode. Visible to any logged-in
 *    user; pre-targets their own account.
 *
 * In both cases the click fires `POST /workos/v1/admin/users/{id}/password-reset`,
 * which validates the redirect_url, enforces capability, and rate-limits.
 *
 * Attributes:
 *  - user="42" or user="jane@example.com" — target user (omit for self-service)
 *  - redirect_url="/welcome" — same-host URL after reset (optional)
 *  - label="Reset password" — button label (optional)
 */
class Shortcode {

	public const TAG = 'workos:password-reset';

	/**
	 * Register the shortcode.
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( self::TAG, [ $this, 'render' ] );
	}

	/**
	 * Shortcode callback.
	 *
	 * @param array $atts Shortcode attributes.
	 *
	 * @return string
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts(
			[
				'user'         => '',
				'redirect_url' => '',
				'label'        => '',
				'profile'      => '',
			],
			is_array( $atts ) ? $atts : [],
			self::TAG
		);

		$target = $this->resolve_target( (string) $atts['user'] );
		if ( 0 === $target ) {
			return '';
		}

		if ( ! current_user_can( 'edit_user', $target ) ) {
			return '';
		}

		// Confirm the user is actually linked to WorkOS — otherwise this
		// button can't do anything useful, so silently render nothing.
		if ( '' === (string) get_user_meta( $target, '_workos_user_id', true ) ) {
			return '';
		}

		wp_enqueue_script( 'workos-admin-password-reset' );
		wp_enqueue_style( 'workos-admin-password-reset' );

		$label = (string) $atts['label'];
		if ( '' === $label ) {
			$label = get_current_user_id() === $target
				? __( 'Reset my password', 'integration-workos' )
				: __( 'Send password reset email', 'integration-workos' );
		}

		return sprintf(
			'<button type="button" class="workos-pwreset-trigger button" data-user-id="%d" data-redirect-url="%s" data-profile="%s">%s</button>',
			(int) $target,
			esc_attr( (string) $atts['redirect_url'] ),
			esc_attr( (string) $atts['profile'] ),
			esc_html( $label )
		);
	}

	/**
	 * Resolve the shortcode's `user` attribute to a WP user ID.
	 *
	 * Accepts a numeric ID, an email address, or an empty string (in which
	 * case the currently logged-in user is used).
	 *
	 * @param string $raw Raw attribute value.
	 *
	 * @return int User ID, or 0 if unresolved.
	 */
	private function resolve_target( string $raw ): int {
		$raw = trim( $raw );

		if ( '' === $raw ) {
			return is_user_logged_in() ? get_current_user_id() : 0;
		}

		if ( ctype_digit( $raw ) ) {
			return (int) $raw;
		}

		if ( is_email( $raw ) ) {
			$user = get_user_by( 'email', $raw );
			return $user ? (int) $user->ID : 0;
		}

		return 0;
	}
}
