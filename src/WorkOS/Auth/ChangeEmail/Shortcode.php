<?php
/**
 * `[workos:change-email]` shortcode for self-service email changes.
 *
 * @package WorkOS\Auth\ChangeEmail
 */

namespace WorkOS\Auth\ChangeEmail;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a self-service "change my email" form.
 *
 * Modes:
 *
 *  - `user="<id|email>"` — admin-of-other mode. Visible only to viewers
 *    with `edit_user` on the target.
 *  - no `user` attribute — self-service: the form pre-targets the
 *    logged-in user.
 *
 * In both cases submit fires `POST /workos/v1/users/{id}/email-change`,
 * which validates the redirect_url, enforces capability, and
 * rate-limits.
 *
 * Attributes:
 *  - user="42" or user="jane@example.com" — target user (omit for self-service)
 *  - redirect_url="/welcome" — same-host URL after the change is confirmed
 *  - label="Update my email" — button label
 */
class Shortcode {

	public const TAG = 'workos:change-email';

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

		if ( '' === (string) get_user_meta( $target, '_workos_user_id', true ) ) {
			return '';
		}

		wp_enqueue_script( Assets::ADMIN_SCRIPT_HANDLE );
		wp_enqueue_style( Assets::ADMIN_STYLE_HANDLE );

		$label = (string) $atts['label'];
		if ( '' === $label ) {
			$label = get_current_user_id() === $target
				? __( 'Change my email', 'integration-workos' )
				: __( 'Change email', 'integration-workos' );
		}

		ob_start();
		?>
		<form class="workos-change-email-form" data-user-id="<?php echo esc_attr( (string) $target ); ?>" data-redirect-url="<?php echo esc_attr( (string) $atts['redirect_url'] ); ?>" onsubmit="return false;">
			<p>
				<label for="workos-change-email-input-<?php echo esc_attr( (string) $target ); ?>">
					<?php esc_html_e( 'New email address', 'integration-workos' ); ?>
				</label>
				<input
					type="email"
					id="workos-change-email-input-<?php echo esc_attr( (string) $target ); ?>"
					class="workos-change-email-input"
					required
					autocomplete="email"
				/>
			</p>
			<p>
				<button type="submit" class="workos-change-email-trigger button">
					<?php echo esc_html( $label ); ?>
				</button>
			</p>
			<div class="workos-change-email-status" aria-live="polite"></div>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Resolve the shortcode's `user` attribute to a WP user ID.
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
