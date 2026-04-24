/**
 * WorkOS Login Button — Edit component.
 *
 * InspectorControls + ServerSideRender for live preview.
 *
 * @package WorkOS
 */
import { __ } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

export default function Edit( { attributes, setAttributes } ) {
	const blockProps = useBlockProps();

	return (
		< div { ...blockProps } >
			< InspectorControls >
				{ /* Authentication Panel */ }
				< PanelBody
					title        = { __( 'Authentication', 'integration-workos' ) }
					initialOpen  = { true }
				>
					< SelectControl
						label    = { __( 'Mode', 'integration-workos' ) }
						value    = { attributes.mode }
						options  = { [
							{
								label: __( 'Auto (from settings)', 'integration-workos' ),
								value: 'auto',
							},
							{
								label: __( 'Redirect (AuthKit)', 'integration-workos' ),
								value: 'redirect',
							},
							{
								label: __( 'Headless (password)', 'integration-workos' ),
								value: 'headless',
							},
							] }
						onChange = { ( mode ) => setAttributes( { mode } ) }
					/ >
					< TextControl
						label    = { __( 'Redirect URL', 'integration-workos' ) }
						value    = { attributes.redirect_to }
						onChange = { ( redirect_to ) =>
							setAttributes( { redirect_to } )
						}
						help     = { __(
							'Leave empty to use role-based redirects.',
							'integration-workos'
						) }
					/ >
				< / PanelBody >

				{ /* Logged-in Display Panel */ }
				< PanelBody
					title        = { __( 'Logged-in Display', 'integration-workos' ) }
					initialOpen  = { false }
				>
					< SelectControl
						label    = { __( 'When logged in', 'integration-workos' ) }
						value    = { attributes.logged_in_display }
						options  = { [
							{
								label: __( 'Hide', 'integration-workos' ),
								value: 'hide',
							},
							{
								label: __(
									'Show logout button',
									'integration-workos'
								),
							value: 'logout',
							},
							{
								label: __(
									'Show user info + logout',
									'integration-workos'
								),
							value: 'user_info',
							},
							] }
						onChange = { ( logged_in_display ) =>
							setAttributes( { logged_in_display } )
						}
					/ >
				< / PanelBody >

				{ /* Button Styling Panel */ }
				< PanelBody
					title        = { __( 'Button Styling', 'integration-workos' ) }
					initialOpen  = { false }
				>
					< TextControl
						label    = { __( 'Button text', 'integration-workos' ) }
						value    = { attributes.button_text }
						onChange = { ( button_text ) =>
							setAttributes( { button_text } )
						}
						help     = { __(
							'Default: "Sign in"',
							'integration-workos'
						) }
					/ >
					< TextControl
						label    = { __( 'Logout text', 'integration-workos' ) }
						value    = { attributes.logout_text }
						onChange = { ( logout_text ) =>
							setAttributes( { logout_text } )
						}
						help     = { __(
							'Default: "Sign out"',
							'integration-workos'
						) }
					/ >
					< SelectControl
						label    = { __( 'Alignment', 'integration-workos' ) }
						value    = { attributes.alignment }
						options  = { [
							{
								label: __( 'Left', 'integration-workos' ),
								value: 'left',
							},
							{
								label: __( 'Center', 'integration-workos' ),
								value: 'center',
							},
							{
								label: __( 'Right', 'integration-workos' ),
								value: 'right',
							},
							] }
						onChange = { ( alignment ) =>
							setAttributes( { alignment } )
						}
					/ >
					< SelectControl
						label    = { __( 'Size', 'integration-workos' ) }
						value    = { attributes.size }
						options  = { [
							{
								label: __( 'Small', 'integration-workos' ),
								value: 'small',
							},
							{
								label: __( 'Medium', 'integration-workos' ),
								value: 'medium',
							},
							{
								label: __( 'Large', 'integration-workos' ),
								value: 'large',
							},
							] }
						onChange = { ( size ) => setAttributes( { size } ) }
					/ >
					< SelectControl
						label    = { __( 'Style', 'integration-workos' ) }
						value    = { attributes.style }
						options  = { [
							{
								label: __( 'Filled', 'integration-workos' ),
								value: 'filled',
							},
							{
								label: __( 'Outline', 'integration-workos' ),
								value: 'outline',
							},
							{
								label: __( 'Link', 'integration-workos' ),
								value: 'link',
							},
							] }
						onChange = { ( val ) =>
							setAttributes( { style: val } )
						}
					/ >
					< TextControl
						label    = { __( 'Background color (hex)', 'integration-workos' ) }
						value    = { attributes.bg_color }
						onChange = { ( bg_color ) =>
							setAttributes( { bg_color } )
						}
					/ >
					< TextControl
						label    = { __( 'Text color (hex)', 'integration-workos' ) }
						value    = { attributes.text_color }
						onChange = { ( text_color ) =>
							setAttributes( { text_color } )
						}
					/ >
					< TextControl
						label    = { __( 'Border color (hex)', 'integration-workos' ) }
						value    = { attributes.border_color }
						onChange = { ( border_color ) =>
							setAttributes( { border_color } )
						}
					/ >
					< TextControl
						label    = { __( 'Border radius (px)', 'integration-workos' ) }
						value    = { attributes.border_radius }
						onChange = { ( border_radius ) =>
							setAttributes( { border_radius } )
						}
					/ >
					< ToggleControl
						label    = { __( 'Show icon', 'integration-workos' ) }
						checked  = { attributes.show_icon }
						onChange = { ( show_icon ) =>
							setAttributes( { show_icon } )
						}
					/ >
				< / PanelBody >

				{ /* Additional Links Panel */ }
				< PanelBody
					title            = { __( 'Additional Links', 'integration-workos' ) }
					initialOpen      = { false }
				>
					< ToggleControl
						label        = { __(
							'Show registration link',
							'integration-workos'
						) }
						checked      = { attributes.show_registration }
						onChange     = { ( show_registration ) =>
							setAttributes( { show_registration } )
						}
					/ >
					{ attributes.show_registration && (
						< TextControl
							label    = { __(
								'Registration text',
								'integration-workos'
							) }
							value    = { attributes.registration_text }
							onChange = { ( registration_text ) =>
								setAttributes( { registration_text } )
							}
							help     = { __(
								'Default: "Create account"',
								'integration-workos'
							) }
						/ >
					) }
					< ToggleControl
						label        = { __(
							'Show password fallback link',
							'integration-workos'
						) }
						checked      = { attributes.show_password_fallback }
						onChange     = { ( show_password_fallback ) =>
							setAttributes( { show_password_fallback } )
						}
					/ >
					{ attributes.show_password_fallback && (
						< TextControl
							label    = { __(
								'Password fallback text',
								'integration-workos'
							) }
							value    = {
								attributes.password_fallback_text
							}
							onChange = { ( password_fallback_text ) =>
								setAttributes(
									{
											password_fallback_text,
									}
								)
							}
							help     = { __(
								'Default: "Sign in with password"',
								'integration-workos'
							) }
						/ >
					) }
				< / PanelBody >
			< / InspectorControls >

			< ServerSideRender
				block      = "workos/login-button"
				attributes = { attributes }
			/ >
		< / div >
	);
}
