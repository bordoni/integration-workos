<?php
/**
 * Login button widget.
 *
 * @package WorkOS\UI
 */

namespace WorkOS\UI;

defined( 'ABSPATH' ) || exit;

/**
 * Classic WP_Widget for the WorkOS login button.
 */
class Widget extends \WP_Widget {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			'workos_login_button',
			__( 'WorkOS Login Button', 'workos' ),
			[
				'description' => __( 'Display a WorkOS login or logout button.', 'workos' ),
			]
		);
	}

	/**
	 * Output the widget.
	 *
	 * @param array $args     Widget area arguments.
	 * @param array $instance Widget instance settings.
	 */
	public function widget( $args, $instance ) {
		$html = Renderer::render( $instance );

		if ( '' === $html ) {
			return;
		}

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Widget admin form.
	 *
	 * @param array $instance Current settings.
	 *
	 * @return void
	 */
	public function form( $instance ) {
		$instance = wp_parse_args( (array) $instance, Renderer::DEFAULTS );

		// Auth section.
		$this->render_heading( __( 'Authentication', 'workos' ) );
		$this->render_select( $instance, 'mode', __( 'Mode', 'workos' ), [
			'auto'     => __( 'Auto (from settings)', 'workos' ),
			'redirect' => __( 'Redirect (AuthKit)', 'workos' ),
			'headless' => __( 'Headless (password)', 'workos' ),
		] );
		$this->render_text( $instance, 'redirect_to', __( 'Redirect URL', 'workos' ) );

		// Display section.
		$this->render_heading( __( 'Logged-in Display', 'workos' ) );
		$this->render_select( $instance, 'logged_in_display', __( 'When logged in', 'workos' ), [
			'hide'      => __( 'Hide', 'workos' ),
			'logout'    => __( 'Show logout button', 'workos' ),
			'user_info' => __( 'Show user info + logout', 'workos' ),
		] );

		// Style section.
		$this->render_heading( __( 'Button Styling', 'workos' ) );
		$this->render_text( $instance, 'button_text', __( 'Button text', 'workos' ) );
		$this->render_text( $instance, 'logout_text', __( 'Logout text', 'workos' ) );
		$this->render_select( $instance, 'alignment', __( 'Alignment', 'workos' ), [
			'left'   => __( 'Left', 'workos' ),
			'center' => __( 'Center', 'workos' ),
			'right'  => __( 'Right', 'workos' ),
		] );
		$this->render_select( $instance, 'size', __( 'Size', 'workos' ), [
			'small'  => __( 'Small', 'workos' ),
			'medium' => __( 'Medium', 'workos' ),
			'large'  => __( 'Large', 'workos' ),
		] );
		$this->render_select( $instance, 'style', __( 'Style', 'workos' ), [
			'filled'  => __( 'Filled', 'workos' ),
			'outline' => __( 'Outline', 'workos' ),
			'link'    => __( 'Link', 'workos' ),
		] );
		$this->render_text( $instance, 'bg_color', __( 'Background color (hex)', 'workos' ) );
		$this->render_text( $instance, 'text_color', __( 'Text color (hex)', 'workos' ) );
		$this->render_text( $instance, 'border_color', __( 'Border color (hex)', 'workos' ) );
		$this->render_text( $instance, 'border_radius', __( 'Border radius (px)', 'workos' ) );
		$this->render_checkbox( $instance, 'show_icon', __( 'Show icon', 'workos' ) );

		// Extras section.
		$this->render_heading( __( 'Additional Links', 'workos' ) );
		$this->render_checkbox( $instance, 'show_registration', __( 'Show registration link', 'workos' ) );
		$this->render_text( $instance, 'registration_text', __( 'Registration text', 'workos' ) );
		$this->render_checkbox( $instance, 'show_password_fallback', __( 'Show password fallback link', 'workos' ) );
		$this->render_text( $instance, 'password_fallback_text', __( 'Password fallback text', 'workos' ) );
	}

	/**
	 * Update widget instance.
	 *
	 * @param array $new_instance New settings.
	 * @param array $old_instance Old settings.
	 *
	 * @return array Sanitized settings.
	 */
	public function update( $new_instance, $old_instance ) {
		$instance = [];

		// Text fields.
		$text_fields = [ 'button_text', 'logout_text', 'redirect_to', 'bg_color', 'text_color', 'border_color', 'border_radius', 'registration_text', 'password_fallback_text' ];
		foreach ( $text_fields as $field ) {
			$instance[ $field ] = sanitize_text_field( $new_instance[ $field ] ?? '' );
		}

		// Select fields.
		$instance['mode']              = in_array( $new_instance['mode'] ?? '', [ 'auto', 'redirect', 'headless' ], true ) ? $new_instance['mode'] : 'auto';
		$instance['logged_in_display'] = in_array( $new_instance['logged_in_display'] ?? '', [ 'hide', 'logout', 'user_info' ], true ) ? $new_instance['logged_in_display'] : 'hide';
		$instance['alignment']         = in_array( $new_instance['alignment'] ?? '', [ 'left', 'center', 'right' ], true ) ? $new_instance['alignment'] : 'left';
		$instance['size']              = in_array( $new_instance['size'] ?? '', [ 'small', 'medium', 'large' ], true ) ? $new_instance['size'] : 'medium';
		$instance['style']             = in_array( $new_instance['style'] ?? '', [ 'filled', 'outline', 'link' ], true ) ? $new_instance['style'] : 'filled';

		// Checkboxes.
		$instance['show_icon']               = ! empty( $new_instance['show_icon'] );
		$instance['show_registration']       = ! empty( $new_instance['show_registration'] );
		$instance['show_password_fallback']  = ! empty( $new_instance['show_password_fallback'] );

		return $instance;
	}

	/**
	 * Render a section heading.
	 *
	 * @param string $label Heading text.
	 */
	private function render_heading( string $label ): void {
		printf( '<p><strong>%s</strong></p>', esc_html( $label ) );
	}

	/**
	 * Render a text input field.
	 *
	 * @param array  $instance Current settings.
	 * @param string $key      Field key.
	 * @param string $label    Field label.
	 */
	private function render_text( array $instance, string $key, string $label ): void {
		printf(
			'<p><label for="%1$s">%2$s</label><input class="widefat" id="%1$s" name="%3$s" type="text" value="%4$s" /></p>',
			esc_attr( $this->get_field_id( $key ) ),
			esc_html( $label ),
			esc_attr( $this->get_field_name( $key ) ),
			esc_attr( $instance[ $key ] ?? '' )
		);
	}

	/**
	 * Render a select field.
	 *
	 * @param array  $instance Current settings.
	 * @param string $key      Field key.
	 * @param string $label    Field label.
	 * @param array  $options  Key => label pairs.
	 */
	private function render_select( array $instance, string $key, string $label, array $options ): void {
		$current = $instance[ $key ] ?? '';

		printf(
			'<p><label for="%1$s">%2$s</label><select class="widefat" id="%1$s" name="%3$s">',
			esc_attr( $this->get_field_id( $key ) ),
			esc_html( $label ),
			esc_attr( $this->get_field_name( $key ) )
		);

		foreach ( $options as $value => $option_label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $option_label )
			);
		}

		echo '</select></p>';
	}

	/**
	 * Render a checkbox field.
	 *
	 * @param array  $instance Current settings.
	 * @param string $key      Field key.
	 * @param string $label    Field label.
	 */
	private function render_checkbox( array $instance, string $key, string $label ): void {
		$checked = ! empty( $instance[ $key ] );

		printf(
			'<p><input class="checkbox" type="checkbox" id="%1$s" name="%2$s" value="1"%3$s /><label for="%1$s"> %4$s</label></p>',
			esc_attr( $this->get_field_id( $key ) ),
			esc_attr( $this->get_field_name( $key ) ),
			checked( $checked, true, false ),
			esc_html( $label )
		);
	}
}
