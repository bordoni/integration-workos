/**
 * WorkOS Login Button — Edit component.
 *
 * InspectorControls + ServerSideRender for live preview.
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
		<div { ...blockProps }>
			<InspectorControls>
				{ /* Authentication Panel */ }
				<PanelBody
					title={ __( 'Authentication', 'workos' ) }
					initialOpen={ true }
				>
					<SelectControl
						label={ __( 'Mode', 'workos' ) }
						value={ attributes.mode }
						options={ [
							{
								label: __( 'Auto (from settings)', 'workos' ),
								value: 'auto',
							},
							{
								label: __( 'Redirect (AuthKit)', 'workos' ),
								value: 'redirect',
							},
							{
								label: __( 'Headless (password)', 'workos' ),
								value: 'headless',
							},
						] }
						onChange={ ( mode ) => setAttributes( { mode } ) }
					/>
					<TextControl
						label={ __( 'Redirect URL', 'workos' ) }
						value={ attributes.redirect_to }
						onChange={ ( redirect_to ) =>
							setAttributes( { redirect_to } )
						}
						help={ __(
							'Leave empty to use role-based redirects.',
							'workos'
						) }
					/>
				</PanelBody>

				{ /* Logged-in Display Panel */ }
				<PanelBody
					title={ __( 'Logged-in Display', 'workos' ) }
					initialOpen={ false }
				>
					<SelectControl
						label={ __( 'When logged in', 'workos' ) }
						value={ attributes.logged_in_display }
						options={ [
							{
								label: __( 'Hide', 'workos' ),
								value: 'hide',
							},
							{
								label: __(
									'Show logout button',
									'workos'
								),
								value: 'logout',
							},
							{
								label: __(
									'Show user info + logout',
									'workos'
								),
								value: 'user_info',
							},
						] }
						onChange={ ( logged_in_display ) =>
							setAttributes( { logged_in_display } )
						}
					/>
				</PanelBody>

				{ /* Button Styling Panel */ }
				<PanelBody
					title={ __( 'Button Styling', 'workos' ) }
					initialOpen={ false }
				>
					<TextControl
						label={ __( 'Button text', 'workos' ) }
						value={ attributes.button_text }
						onChange={ ( button_text ) =>
							setAttributes( { button_text } )
						}
						help={ __(
							'Default: "Sign in"',
							'workos'
						) }
					/>
					<TextControl
						label={ __( 'Logout text', 'workos' ) }
						value={ attributes.logout_text }
						onChange={ ( logout_text ) =>
							setAttributes( { logout_text } )
						}
						help={ __(
							'Default: "Sign out"',
							'workos'
						) }
					/>
					<SelectControl
						label={ __( 'Alignment', 'workos' ) }
						value={ attributes.alignment }
						options={ [
							{
								label: __( 'Left', 'workos' ),
								value: 'left',
							},
							{
								label: __( 'Center', 'workos' ),
								value: 'center',
							},
							{
								label: __( 'Right', 'workos' ),
								value: 'right',
							},
						] }
						onChange={ ( alignment ) =>
							setAttributes( { alignment } )
						}
					/>
					<SelectControl
						label={ __( 'Size', 'workos' ) }
						value={ attributes.size }
						options={ [
							{
								label: __( 'Small', 'workos' ),
								value: 'small',
							},
							{
								label: __( 'Medium', 'workos' ),
								value: 'medium',
							},
							{
								label: __( 'Large', 'workos' ),
								value: 'large',
							},
						] }
						onChange={ ( size ) => setAttributes( { size } ) }
					/>
					<SelectControl
						label={ __( 'Style', 'workos' ) }
						value={ attributes.style }
						options={ [
							{
								label: __( 'Filled', 'workos' ),
								value: 'filled',
							},
							{
								label: __( 'Outline', 'workos' ),
								value: 'outline',
							},
							{
								label: __( 'Link', 'workos' ),
								value: 'link',
							},
						] }
						onChange={ ( val ) =>
							setAttributes( { style: val } )
						}
					/>
					<TextControl
						label={ __( 'Background color (hex)', 'workos' ) }
						value={ attributes.bg_color }
						onChange={ ( bg_color ) =>
							setAttributes( { bg_color } )
						}
					/>
					<TextControl
						label={ __( 'Text color (hex)', 'workos' ) }
						value={ attributes.text_color }
						onChange={ ( text_color ) =>
							setAttributes( { text_color } )
						}
					/>
					<TextControl
						label={ __( 'Border color (hex)', 'workos' ) }
						value={ attributes.border_color }
						onChange={ ( border_color ) =>
							setAttributes( { border_color } )
						}
					/>
					<TextControl
						label={ __( 'Border radius (px)', 'workos' ) }
						value={ attributes.border_radius }
						onChange={ ( border_radius ) =>
							setAttributes( { border_radius } )
						}
					/>
					<ToggleControl
						label={ __( 'Show icon', 'workos' ) }
						checked={ attributes.show_icon }
						onChange={ ( show_icon ) =>
							setAttributes( { show_icon } )
						}
					/>
				</PanelBody>

				{ /* Additional Links Panel */ }
				<PanelBody
					title={ __( 'Additional Links', 'workos' ) }
					initialOpen={ false }
				>
					<ToggleControl
						label={ __(
							'Show registration link',
							'workos'
						) }
						checked={ attributes.show_registration }
						onChange={ ( show_registration ) =>
							setAttributes( { show_registration } )
						}
					/>
					{ attributes.show_registration && (
						<TextControl
							label={ __(
								'Registration text',
								'workos'
							) }
							value={ attributes.registration_text }
							onChange={ ( registration_text ) =>
								setAttributes( { registration_text } )
							}
							help={ __(
								'Default: "Create account"',
								'workos'
							) }
						/>
					) }
					<ToggleControl
						label={ __(
							'Show password fallback link',
							'workos'
						) }
						checked={ attributes.show_password_fallback }
						onChange={ ( show_password_fallback ) =>
							setAttributes( { show_password_fallback } )
						}
					/>
					{ attributes.show_password_fallback && (
						<TextControl
							label={ __(
								'Password fallback text',
								'workos'
							) }
							value={
								attributes.password_fallback_text
							}
							onChange={ ( password_fallback_text ) =>
								setAttributes( {
									password_fallback_text,
								} )
							}
							help={ __(
								'Default: "Sign in with password"',
								'workos'
							) }
						/>
					) }
				</PanelBody>
			</InspectorControls>

			<ServerSideRender
				block="workos/login-button"
				attributes={ attributes }
			/>
		</div>
	);
}
