<?php
/**
 * Shared renderer for the login button UI.
 *
 * @package WorkOS\UI
 */

namespace WorkOS\UI;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the login button HTML, shared by shortcode, widget, and block.
 */
class Renderer {

	/**
	 * Canonical attribute defaults — single source of truth.
	 */
	public const DEFAULTS = [
		// Auth.
		'mode'        => 'auto',
		'redirect_to' => '',

		// Display.
		'logged_in_display' => 'hide',

		// Style.
		'button_text'   => '',
		'logout_text'   => '',
		'alignment'     => 'left',
		'size'          => 'medium',
		'style'         => 'filled',
		'bg_color'      => '',
		'text_color'    => '',
		'border_color'  => '',
		'border_radius' => '',
		'show_icon'     => false,

		// Extras.
		'show_registration'       => false,
		'show_password_fallback'  => false,
		'registration_text'       => '',
		'password_fallback_text'  => '',
	];

	/**
	 * Render the login button.
	 *
	 * @param array $attrs Attributes (merged with DEFAULTS).
	 *
	 * @return string HTML output.
	 */
	public static function render( array $attrs ): string {
		if ( ! function_exists( 'workos' ) || ! workos()->is_enabled() ) {
			return '';
		}

		$attrs = self::normalize( $attrs );

		// In REST context (block editor SSR), always show logged-out preview.
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return self::render_logged_out( $attrs );
		}

		if ( is_user_logged_in() ) {
			return self::render_logged_in( $attrs );
		}

		return self::render_logged_out( $attrs );
	}

	/**
	 * Merge with defaults and sanitize attribute values.
	 *
	 * @param array $attrs Raw attributes.
	 *
	 * @return array Normalized attributes.
	 */
	public static function normalize( array $attrs ): array {
		$attrs = wp_parse_args( $attrs, self::DEFAULTS );

		// Sanitize enums.
		$attrs['mode']             = in_array( $attrs['mode'], [ 'auto', 'redirect', 'headless' ], true ) ? $attrs['mode'] : 'auto';
		$attrs['logged_in_display'] = in_array( $attrs['logged_in_display'], [ 'hide', 'logout', 'user_info' ], true ) ? $attrs['logged_in_display'] : 'hide';
		$attrs['alignment']        = in_array( $attrs['alignment'], [ 'left', 'center', 'right' ], true ) ? $attrs['alignment'] : 'left';
		$attrs['size']             = in_array( $attrs['size'], [ 'small', 'medium', 'large' ], true ) ? $attrs['size'] : 'medium';
		$attrs['style']            = in_array( $attrs['style'], [ 'filled', 'outline', 'link' ], true ) ? $attrs['style'] : 'filled';

		// Sanitize booleans.
		$attrs['show_icon']               = self::to_bool( $attrs['show_icon'] );
		$attrs['show_registration']       = self::to_bool( $attrs['show_registration'] );
		$attrs['show_password_fallback']  = self::to_bool( $attrs['show_password_fallback'] );

		// Sanitize colors (hex only).
		$attrs['bg_color']      = self::sanitize_hex( $attrs['bg_color'] );
		$attrs['text_color']    = self::sanitize_hex( $attrs['text_color'] );
		$attrs['border_color']  = self::sanitize_hex( $attrs['border_color'] );
		$attrs['border_radius'] = self::sanitize_px( $attrs['border_radius'] );

		// Sanitize text.
		$attrs['button_text']          = sanitize_text_field( $attrs['button_text'] );
		$attrs['logout_text']          = sanitize_text_field( $attrs['logout_text'] );
		$attrs['redirect_to']          = $attrs['redirect_to'] ? sanitize_url( $attrs['redirect_to'] ) : '';
		$attrs['registration_text']    = sanitize_text_field( $attrs['registration_text'] );
		$attrs['password_fallback_text'] = sanitize_text_field( $attrs['password_fallback_text'] );

		return $attrs;
	}

	/**
	 * Resolve 'auto' mode to the actual config value.
	 *
	 * @param string $mode Mode attribute.
	 *
	 * @return string 'redirect' or 'headless'.
	 */
	public static function resolve_mode( string $mode ): string {
		if ( 'auto' === $mode ) {
			return workos()->option( 'login_mode', 'redirect' );
		}

		return $mode;
	}

	/**
	 * Build inline style string from color/radius attributes.
	 *
	 * @param array $attrs Normalized attributes.
	 *
	 * @return string Inline style attribute value.
	 */
	public static function build_inline_styles( array $attrs ): string {
		$styles = [];

		if ( $attrs['bg_color'] ) {
			$styles[] = 'background-color:' . $attrs['bg_color'];
		}

		if ( $attrs['text_color'] ) {
			$styles[] = 'color:' . $attrs['text_color'];
		}

		if ( $attrs['border_color'] ) {
			$styles[] = 'border-color:' . $attrs['border_color'];
		}

		if ( $attrs['border_radius'] ) {
			$styles[] = 'border-radius:' . $attrs['border_radius'];
		}

		return implode( ';', $styles );
	}

	/**
	 * Render the logged-out state.
	 *
	 * @param array $attrs Normalized attributes.
	 *
	 * @return string HTML.
	 */
	private static function render_logged_out( array $attrs ): string {
		$mode        = self::resolve_mode( $attrs['mode'] );
		$button_text = $attrs['button_text'] ?: __( 'Sign in', 'integration-workos' );
		$inline      = self::build_inline_styles( $attrs );
		$style_attr  = $inline ? ' style="' . esc_attr( $inline ) . '"' : '';

		$classes = [
			'workos-login-button',
			'workos-login-button--' . $attrs['alignment'],
			'workos-login-button--' . $attrs['size'],
		];

		$btn_classes = [
			'workos-login-button__btn',
			'workos-login-button__btn--' . $attrs['style'],
		];

		$icon_html = '';
		if ( $attrs['show_icon'] ) {
			$icon_html = '<span class="workos-login-button__icon" aria-hidden="true">'
				. '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
				. '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>'
				. '<path d="M7 11V7a5 5 0 0 1 10 0v4"/>'
				. '</svg>'
				. '</span> ';
		}

		$html = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '">';

		if ( 'redirect' === $mode ) {
			$login_url = wp_login_url( $attrs['redirect_to'] );
			$html     .= '<a href="' . esc_url( $login_url ) . '" class="' . esc_attr( implode( ' ', $btn_classes ) ) . '"' . $style_attr . '>'
				. $icon_html
				. esc_html( $button_text )
				. '</a>';
		} else {
			// Headless mode: button toggles form.
			$html .= '<button type="button" class="' . esc_attr( implode( ' ', $btn_classes ) ) . '"' . $style_attr . ' data-workos-headless-toggle>'
				. $icon_html
				. esc_html( $button_text )
				. '</button>';

			$email_id    = 'workos-email-' . wp_unique_id();
			$password_id = 'workos-password-' . wp_unique_id();

			$html .= '<form class="workos-login-button__form" data-workos-headless-form style="display:none;">';

			if ( $attrs['redirect_to'] ) {
				$html .= '<input type="hidden" name="redirect_to" value="' . esc_attr( $attrs['redirect_to'] ) . '" />';
			}

			$html .= '<label class="workos-login-button__label" for="' . esc_attr( $email_id ) . '">' . esc_html__( 'Email', 'integration-workos' ) . '</label>'
				. '<input type="email" class="workos-login-button__input" name="email" required placeholder="' . esc_attr__( 'Email address', 'integration-workos' ) . '" id="' . esc_attr( $email_id ) . '" />'
				. '<label class="workos-login-button__label" for="' . esc_attr( $password_id ) . '">' . esc_html__( 'Password', 'integration-workos' ) . '</label>'
				. '<input type="password" class="workos-login-button__input" name="password" required placeholder="' . esc_attr__( 'Password', 'integration-workos' ) . '" id="' . esc_attr( $password_id ) . '" />'
				. '<button type="submit" class="workos-login-button__submit workos-login-button__btn--' . esc_attr( $attrs['style'] ) . '"' . $style_attr . '>'
				. esc_html( $button_text )
				. '</button>'
				. '<div class="workos-login-button__error" role="alert" aria-live="polite"></div>'
				. '</form>';
		}

		// Additional links.
		$links = self::build_links( $attrs );
		if ( $links ) {
			$html .= '<div class="workos-login-button__links">' . $links . '</div>';
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Render the logged-in state.
	 *
	 * @param array $attrs Normalized attributes.
	 *
	 * @return string HTML.
	 */
	private static function render_logged_in( array $attrs ): string {
		if ( 'hide' === $attrs['logged_in_display'] ) {
			return '';
		}

		$classes = [
			'workos-login-button',
			'workos-login-button--' . $attrs['alignment'],
			'workos-login-button--' . $attrs['size'],
		];

		$logout_text = $attrs['logout_text'] ?: __( 'Sign out', 'integration-workos' );
		$logout_url  = wp_logout_url( $attrs['redirect_to'] ?: home_url() );
		$inline      = self::build_inline_styles( $attrs );
		$style_attr  = $inline ? ' style="' . esc_attr( $inline ) . '"' : '';

		$btn_classes = [
			'workos-login-button__btn',
			'workos-login-button__btn--' . $attrs['style'],
		];

		$html = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '">';

		if ( 'user_info' === $attrs['logged_in_display'] ) {
			$user = wp_get_current_user();
			$html .= '<div class="workos-login-button__user">';
			$html .= get_avatar( $user->ID, 32, '', '', [ 'class' => 'workos-login-button__avatar' ] );
			$html .= '<span class="workos-login-button__name">' . esc_html( $user->display_name ) . '</span>';
			$html .= '</div>';
		}

		$html .= '<a href="' . esc_url( $logout_url ) . '" class="' . esc_attr( implode( ' ', $btn_classes ) ) . '"' . $style_attr . '>'
			. esc_html( $logout_text )
			. '</a>';

		$html .= '</div>';

		return $html;
	}

	/**
	 * Build the additional links HTML (registration, password fallback).
	 *
	 * @param array $attrs Normalized attributes.
	 *
	 * @return string Links HTML or empty string.
	 */
	private static function build_links( array $attrs ): string {
		$links = '';

		if ( $attrs['show_registration'] && get_option( 'users_can_register' ) ) {
			$reg_text = $attrs['registration_text'] ?: __( 'Create account', 'integration-workos' );
			$links   .= '<a href="' . esc_url( wp_registration_url() ) . '" class="workos-login-button__link">'
				. esc_html( $reg_text )
				. '</a>';
		}

		if ( $attrs['show_password_fallback'] && workos()->option( 'allow_password_fallback', true ) ) {
			$fb_text = $attrs['password_fallback_text'] ?: __( 'Sign in with password', 'integration-workos' );
			$links  .= '<a href="' . esc_url( wp_login_url() . '?fallback=1' ) . '" class="workos-login-button__link">'
				. esc_html( $fb_text )
				. '</a>';
		}

		return $links;
	}

	/**
	 * Cast a value to boolean, handling string 'true'/'false'/'1'/'0'.
	 *
	 * @param mixed $value Value to cast.
	 *
	 * @return bool
	 */
	private static function to_bool( $value ): bool {
		if ( is_string( $value ) ) {
			return in_array( strtolower( $value ), [ 'true', '1', 'yes' ], true );
		}

		return (bool) $value;
	}

	/**
	 * Sanitize a hex color value.
	 *
	 * @param string $value Hex color.
	 *
	 * @return string Sanitized hex or empty string.
	 */
	private static function sanitize_hex( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		// Ensure # prefix.
		if ( '#' !== $value[0] ) {
			$value = '#' . $value;
		}

		return sanitize_hex_color( $value ) ?: '';
	}

	/**
	 * Sanitize a pixel value.
	 *
	 * @param string $value Pixel value (e.g. '4px' or '4').
	 *
	 * @return string Sanitized px value or empty string.
	 */
	private static function sanitize_px( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		$num = absint( $value );

		return $num ? $num . 'px' : '';
	}
}
