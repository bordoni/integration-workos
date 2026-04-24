/**
 * Admin Login Profiles editor.
 *
 * Mounts onto #workos-profiles-admin-root. Talks to the admin REST
 * namespace at /wp-json/workos/v1/admin/profiles. Intentionally kept in
 * a single file — the editor is a small CRUD form, not a full app.
 */

import { createRoot, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { ChangeEvent, MouseEvent, ReactNode } from 'react';
import './styles.css';

declare global {
	interface Window {
		workosProfileAdmin?: {
			restUrl: string;
			nonce: string;
			pageUrl: string;
			profiles: Profile[];
			activeProfileSlug: string;
		};
		wp?: {
			media?: ( config: WpMediaConfig ) => WpMediaFrame;
		};
	}
}

const NEW_PROFILE_SENTINEL = 'new';

interface WpMediaConfig {
	title?: string;
	button?: { text?: string };
	multiple?: boolean;
	library?: { type?: string };
}

interface WpMediaAttachment {
	id: number;
	url: string;
	alt?: string;
	filename?: string;
}

/**
 * `wp.media` selections return Backbone Models. `.first()` is the Model
 * itself — accessing `.url` on it gives Backbone's URL **method**, not
 * the attachment URL. Always call `.toJSON()` to get the plain
 * attachment data.
 */
interface WpMediaModel {
	toJSON(): WpMediaAttachment;
}

interface WpMediaSelection {
	first(): WpMediaModel;
}

interface WpMediaState {
	get( key: string ): WpMediaSelection;
}

interface WpMediaFrame {
	on( event: 'select', cb: () => void ): void;
	open(): void;
	state(): WpMediaState;
}

type AuthMethod =
	| 'password'
	| 'magic_code'
	| 'oauth_google'
	| 'oauth_microsoft'
	| 'oauth_github'
	| 'oauth_apple'
	| 'passkey';

type MfaFactor = 'totp' | 'sms' | 'webauthn';
type MfaEnforce = 'never' | 'if_required' | 'always';
type ProfileMode = 'custom' | 'authkit_redirect';
type LogoMode = 'default' | 'custom' | 'none';

interface Profile {
	id: number;
	slug: string;
	title: string;
	methods: AuthMethod[];
	organization_id: string;
	signup: { enabled: boolean; require_invite: boolean };
	invite_flow: boolean;
	password_reset_flow: boolean;
	mfa: { enforce: MfaEnforce; factors: MfaFactor[] };
	branding: {
		logo_mode: LogoMode;
		logo_attachment_id: number;
		logo_url?: string;
		primary_color: string;
		heading: string;
		subheading: string;
	};
	post_login_redirect: string;
	mode: ProfileMode;
}

interface ApiResult< T > {
	ok: boolean;
	status: number;
	data: T;
}

interface Organization {
	id: string;
	name: string;
}

interface OrganizationsResponse {
	organizations?: Organization[];
	error?: string;
	message?: string;
}

const CUSTOM_ORG_SENTINEL = '__workos_custom_org__';

interface OptionItem< V extends string > {
	value: V;
	label: string;
}

const methodOptions = (): OptionItem< AuthMethod >[] => [
	{ value: 'password',        label: __( 'Email + password', 'integration-workos' ) },
	{ value: 'magic_code',      label: __( 'Magic link / email code', 'integration-workos' ) },
	{ value: 'oauth_google',    label: __( 'Google', 'integration-workos' ) },
	{ value: 'oauth_microsoft', label: __( 'Microsoft', 'integration-workos' ) },
	{ value: 'oauth_github',    label: __( 'GitHub', 'integration-workos' ) },
	{ value: 'oauth_apple',     label: __( 'Apple', 'integration-workos' ) },
	{ value: 'passkey',         label: __( 'Passkey', 'integration-workos' ) },
];

const factorOptions = (): OptionItem< MfaFactor >[] => [
	{ value: 'totp',     label: __( 'Authenticator app (TOTP)', 'integration-workos' ) },
	{ value: 'sms',      label: __( 'SMS', 'integration-workos' ) },
	{ value: 'webauthn', label: __( 'WebAuthn / passkey', 'integration-workos' ) },
];

const enforceOptions = (): OptionItem< MfaEnforce >[] => [
	{ value: 'never',       label: __( 'Never', 'integration-workos' ) },
	{ value: 'if_required', label: __( 'When WorkOS requires it', 'integration-workos' ) },
	{ value: 'always',      label: __( 'Always', 'integration-workos' ) },
];

const modeOptions = (): OptionItem< ProfileMode >[] => [
	{ value: 'custom',           label: __( 'Custom (React UI)', 'integration-workos' ) },
	{ value: 'authkit_redirect', label: __( 'Legacy AuthKit redirect', 'integration-workos' ) },
];

function apiUrl( path = '' ): string {
	const base = window.workosProfileAdmin?.restUrl || '/wp-json/workos/v1/admin/profiles';
	return `${ base }${ path }`;
}

async function apiCall< T >(
	method: string,
	path: string,
	body?: unknown
): Promise< ApiResult< T > > {
	const res = await fetch( apiUrl( path ), {
		method,
		credentials: 'same-origin',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': window.workosProfileAdmin?.nonce || '',
		},
		body: body ? JSON.stringify( body ) : undefined,
	} );
	const data = await res.json().catch( () => ( {} ) );
	return { ok: res.ok, status: res.status, data: data as T };
}

interface CheckboxesProps< V extends string > {
	label: string;
	options: OptionItem< V >[];
	values: V[];
	onChange: ( next: V[] ) => void;
}

function Checkboxes< V extends string >( {
	label,
	options,
	values,
	onChange,
}: CheckboxesProps< V > ) {
	const toggle = ( value: V ): void => {
		onChange(
			values.includes( value )
				? values.filter( ( v ) => v !== value )
				: [ ...values, value ]
		);
	};
	return (
		<fieldset className="wpa-fieldset">
			<legend>{ label }</legend>
			{ options.map( ( opt ) => (
				<label key={ opt.value } className="wpa-check">
					<input
						type="checkbox"
						checked={ values.includes( opt.value ) }
						onChange={ () => toggle( opt.value ) }
					/>
					{ opt.label }
				</label>
			) ) }
		</fieldset>
	);
}

interface SelectProps< V extends string > {
	label: string;
	value: V;
	onChange: ( next: V ) => void;
	options: OptionItem< V >[];
}

function Select< V extends string >( {
	label,
	value,
	onChange,
	options,
}: SelectProps< V > ) {
	return (
		<label className="wpa-field">
			<span>{ label }</span>
			<select
				value={ value }
				onChange={ ( e: ChangeEvent< HTMLSelectElement > ) =>
					onChange( e.target.value as V )
				}
			>
				{ options.map( ( opt ) => (
					<option key={ opt.value } value={ opt.value }>
						{ opt.label }
					</option>
				) ) }
			</select>
		</label>
	);
}

interface TextFieldProps {
	label: string;
	value?: string;
	onChange: ( next: string ) => void;
	type?: string;
	placeholder?: string;
	disabled?: boolean;
}

function TextField( {
	label,
	value,
	onChange,
	type = 'text',
	placeholder,
	disabled,
}: TextFieldProps ) {
	return (
		<label className="wpa-field">
			<span>{ label }</span>
			<input
				type={ type }
				value={ value ?? '' }
				onChange={ ( e: ChangeEvent< HTMLInputElement > ) => onChange( e.target.value ) }
				placeholder={ placeholder }
				disabled={ disabled }
			/>
		</label>
	);
}

interface OrganizationFieldProps {
	value: string;
	organizations: Organization[];
	loading: boolean;
	error: string;
	onChange: ( next: string ) => void;
}

/**
 * Pinned-organization picker. Renders a select when we have a list from
 * WorkOS, with a "Custom ID…" escape hatch so an org that isn't in the
 * first page of results can still be pinned by pasting its ID.
 */
function OrganizationField( {
	value,
	organizations,
	loading,
	error,
	onChange,
}: OrganizationFieldProps ) {
	const inList = organizations.some( ( o ) => o.id === value );
	const [ customMode, setCustomMode ] = useState< boolean >(
		() => value !== '' && ! inList
	);

	// If the orgs list arrives after mount and contains the current value,
	// collapse back out of custom mode so the select reflects the choice.
	useEffect( () => {
		if ( value !== '' && organizations.some( ( o ) => o.id === value ) ) {
			setCustomMode( false );
		}
	}, [ value, organizations ] );

	const selectValue = customMode ? CUSTOM_ORG_SENTINEL : value;

	const handleSelect = ( next: string ): void => {
		if ( next === CUSTOM_ORG_SENTINEL ) {
			setCustomMode( true );
			return;
		}
		setCustomMode( false );
		onChange( next );
	};

	// No orgs to pick from — fall back to plain text entry so the field
	// remains usable when WorkOS is unconfigured or the API is down.
	if ( ! loading && organizations.length === 0 ) {
		return (
			<label className="wpa-field">
				<span>{ __( 'Pinned organization', 'integration-workos' ) }</span>
				<input
					type="text"
					value={ value }
					onChange={ ( e: ChangeEvent< HTMLInputElement > ) =>
						onChange( e.target.value )
					}
					placeholder={ __( 'org_01ABC…', 'integration-workos' ) }
				/>
				{ error && <span className="description">{ error }</span> }
			</label>
		);
	}

	return (
		<label className="wpa-field">
			<span>{ __( 'Pinned organization', 'integration-workos' ) }</span>
			<select
				value={ selectValue }
				disabled={ loading }
				onChange={ ( e: ChangeEvent< HTMLSelectElement > ) =>
					handleSelect( e.target.value )
				}
			>
				<option value="">
					{ loading
						? __( 'Loading organizations…', 'integration-workos' )
						: __( '— No pinned organization —', 'integration-workos' ) }
				</option>
				{ organizations.map( ( org ) => (
					<option key={ org.id } value={ org.id }>
						{ org.name } ({ org.id })
					</option>
				) ) }
				<option value={ CUSTOM_ORG_SENTINEL }>
					{ __( 'Custom ID…', 'integration-workos' ) }
				</option>
			</select>
			{ customMode && (
				<input
					type="text"
					value={ value }
					onChange={ ( e: ChangeEvent< HTMLInputElement > ) =>
						onChange( e.target.value )
					}
					placeholder={ __( 'org_01ABC…', 'integration-workos' ) }
				/>
			) }
		</label>
	);
}

interface LogoFieldProps {
	mode: LogoMode;
	attachmentId: number;
	url?: string;
	onChange: ( patch: {
		logo_mode: LogoMode;
		logo_attachment_id: number;
		logo_url: string;
	} ) => void;
}

/**
 * Logo picker for the Branding fieldset.
 *
 * Three exclusive states, driven by `branding.logo_mode`:
 * - `default`: defer to the runtime fallback chain (Site Icon → bundled
 *   WP logo).
 * - `custom`: use the admin's uploaded attachment.
 * - `none`: render no image at all.
 *
 * Mode and attachment id travel together in a single atomic patch so the
 * state can never land in an inconsistent shape (e.g. `mode: custom` with
 * no attachment, or a lingering attachment id when the admin picks
 * "hide"). Opens the WordPress media modal (`wp.media`) — available
 * because AdminPage.php calls `wp_enqueue_media()` on this page.
 */
function LogoField( { mode, attachmentId, url, onChange }: LogoFieldProps ) {
	const open = (): void => {
		if ( ! window.wp?.media ) {
			return;
		}
		const frame = window.wp.media( {
			title: __( 'Select login logo', 'integration-workos' ),
			button: { text: __( 'Use this image', 'integration-workos' ) },
			multiple: false,
			library: { type: 'image' },
		} );
		frame.on( 'select', () => {
			const attachment = frame
				.state()
				.get( 'selection' )
				.first()
				.toJSON();
			onChange( {
				logo_mode: 'custom',
				logo_attachment_id: attachment.id,
				logo_url: attachment.url,
			} );
		} );
		frame.open();
	};

	const chooseDefault = (): void =>
		onChange( { logo_mode: 'default', logo_attachment_id: 0, logo_url: '' } );

	const chooseNone = (): void =>
		onChange( { logo_mode: 'none', logo_attachment_id: 0, logo_url: '' } );

	return (
		<div className="wpa-field wpa-logo-field">
			<span>{ __( 'Logo', 'integration-workos' ) }</span>
			{ mode === 'custom' && attachmentId > 0 && url && (
				<img
					className="wpa-logo-preview"
					src={ url }
					alt={ __( 'Selected logo', 'integration-workos' ) }
				/>
			) }
			{ mode === 'none' && (
				<span className="wpa-logo-placeholder">
					{ __( 'No logo — the login card shows no image.', 'integration-workos' ) }
				</span>
			) }
			{ mode === 'default' && (
				<span className="wpa-logo-placeholder">
					{ __(
						'Using the default WordPress fallback (Site Icon or bundled WordPress logo).',
						'integration-workos'
					) }
				</span>
			) }
			<div className="wpa-logo-actions">
				<button type="button" className="button" onClick={ open }>
					{ mode === 'custom'
						? __( 'Replace logo', 'integration-workos' )
						: __( 'Choose logo', 'integration-workos' ) }
				</button>
				{ mode === 'custom' && (
					<button
						type="button"
						className="button"
						onClick={ chooseDefault }
					>
						{ __( 'Use default', 'integration-workos' ) }
					</button>
				) }
				{ mode !== 'none' && (
					<button
						type="button"
						className="button button-link-delete"
						onClick={ chooseNone }
					>
						{ __( 'Hide logo', 'integration-workos' ) }
					</button>
				) }
				{ mode === 'none' && (
					<button
						type="button"
						className="button"
						onClick={ chooseDefault }
					>
						{ __( 'Use default', 'integration-workos' ) }
					</button>
				) }
			</div>
			<span className="description">
				{ __(
					'Defaults to the Site Icon, then the bundled WordPress logo. Choose "Hide logo" to render no image.',
					'integration-workos'
				) }
			</span>
		</div>
	);
}

function emptyProfile(): Profile {
	return {
		id: 0,
		slug: '',
		title: '',
		methods: [ 'password', 'magic_code' ],
		organization_id: '',
		signup: { enabled: false, require_invite: false },
		invite_flow: true,
		password_reset_flow: true,
		mfa: { enforce: 'if_required', factors: [ 'totp' ] },
		branding: {
			logo_mode: 'default',
			logo_attachment_id: 0,
			logo_url: '',
			primary_color: '',
			heading: '',
			subheading: '',
		},
		post_login_redirect: '',
		mode: 'custom',
	};
}

interface EditorProps {
	profile: Profile;
	organizations: Organization[];
	organizationsLoading: boolean;
	organizationsError: string;
	onSave: ( profile: Profile ) => void;
	onCancel: () => void;
	onDelete: ( profile: Profile ) => void;
	saving: boolean;
}

function Editor( {
	profile,
	organizations,
	organizationsLoading,
	organizationsError,
	onSave,
	onCancel,
	onDelete,
	saving,
}: EditorProps ) {
	const [ data, setData ] = useState< Profile >( profile );

	useEffect( () => setData( profile ), [ profile.id, profile.slug ] );

	const set = ( patch: Partial< Profile > ): void =>
		setData( ( prev ) => ( { ...prev, ...patch } ) );
	const setSignup = ( patch: Partial< Profile[ 'signup' ] > ): void =>
		set( { signup: { ...data.signup, ...patch } } );
	const setMfa = ( patch: Partial< Profile[ 'mfa' ] > ): void =>
		set( { mfa: { ...data.mfa, ...patch } } );
	const setBranding = ( patch: Partial< Profile[ 'branding' ] > ): void =>
		set( { branding: { ...data.branding, ...patch } } );

	const isDefault = data.slug === 'default';

	return (
		<div className="wpa-editor">
			<h2>
				{ data.id
					? sprintf(
							/* translators: %s: profile title. */
							__( 'Edit: %s', 'integration-workos' ),
							data.title
					  )
					: __( 'New Login Profile', 'integration-workos' ) }
			</h2>

			<TextField
				label={ __( 'Title', 'integration-workos' ) }
				value={ data.title }
				onChange={ ( v ) => set( { title: v } ) }
			/>

			<TextField
				label={ __( 'Slug', 'integration-workos' ) }
				value={ data.slug }
				onChange={ ( v ) => set( { slug: v } ) }
				disabled={ isDefault }
				placeholder={ __( 'members-area', 'integration-workos' ) }
			/>

			<Select< ProfileMode >
				label={ __( 'Mode', 'integration-workos' ) }
				value={ data.mode }
				onChange={ ( v ) => set( { mode: v } ) }
				options={ modeOptions() }
			/>

			<Checkboxes< AuthMethod >
				label={ __( 'Enabled sign-in methods', 'integration-workos' ) }
				options={ methodOptions() }
				values={ data.methods }
				onChange={ ( v ) => set( { methods: v } ) }
			/>

			<OrganizationField
				value={ data.organization_id }
				organizations={ organizations }
				loading={ organizationsLoading }
				error={ organizationsError }
				onChange={ ( v ) => set( { organization_id: v } ) }
			/>

			<TextField
				label={ __( 'Redirect after login', 'integration-workos' ) }
				value={ data.post_login_redirect }
				onChange={ ( v ) => set( { post_login_redirect: v } ) }
				placeholder={ __( '/dashboard', 'integration-workos' ) }
			/>

			<fieldset className="wpa-fieldset">
				<legend>{ __( 'Sign-up', 'integration-workos' ) }</legend>
				<label className="wpa-check">
					<input
						type="checkbox"
						checked={ data.signup.enabled }
						onChange={ ( e: ChangeEvent< HTMLInputElement > ) =>
							setSignup( { enabled: e.target.checked } )
						}
					/>
					{ __( 'Allow sign-up', 'integration-workos' ) }
				</label>
				<label className="wpa-check">
					<input
						type="checkbox"
						checked={ data.signup.require_invite }
						onChange={ ( e: ChangeEvent< HTMLInputElement > ) =>
							setSignup( { require_invite: e.target.checked } )
						}
					/>
					{ __( 'Require invitation', 'integration-workos' ) }
				</label>
			</fieldset>

			<fieldset className="wpa-fieldset">
				<legend>{ __( 'Flows', 'integration-workos' ) }</legend>
				<label className="wpa-check">
					<input
						type="checkbox"
						checked={ data.invite_flow }
						onChange={ ( e: ChangeEvent< HTMLInputElement > ) =>
							set( { invite_flow: e.target.checked } )
						}
					/>
					{ __( 'Invitation acceptance', 'integration-workos' ) }
				</label>
				<label className="wpa-check">
					<input
						type="checkbox"
						checked={ data.password_reset_flow }
						onChange={ ( e: ChangeEvent< HTMLInputElement > ) =>
							set( { password_reset_flow: e.target.checked } )
						}
					/>
					{ __( 'Password reset', 'integration-workos' ) }
				</label>
			</fieldset>

			<Select< MfaEnforce >
				label={ __( 'MFA enforcement', 'integration-workos' ) }
				value={ data.mfa.enforce }
				onChange={ ( v ) => setMfa( { enforce: v } ) }
				options={ enforceOptions() }
			/>

			<Checkboxes< MfaFactor >
				label={ __( 'MFA factors', 'integration-workos' ) }
				options={ factorOptions() }
				values={ data.mfa.factors }
				onChange={ ( v ) => setMfa( { factors: v } ) }
			/>

			<fieldset className="wpa-fieldset">
				<legend>{ __( 'Branding', 'integration-workos' ) }</legend>
				<LogoField
					mode={ data.branding.logo_mode }
					attachmentId={ data.branding.logo_attachment_id }
					url={ data.branding.logo_url }
					onChange={ ( patch ) => setBranding( patch ) }
				/>
				<TextField
					label={ __( 'Heading', 'integration-workos' ) }
					value={ data.branding.heading }
					onChange={ ( v ) => setBranding( { heading: v } ) }
				/>
				<TextField
					label={ __( 'Subheading', 'integration-workos' ) }
					value={ data.branding.subheading }
					onChange={ ( v ) => setBranding( { subheading: v } ) }
				/>
				<TextField
					label={ __( 'Primary color', 'integration-workos' ) }
					value={ data.branding.primary_color }
					onChange={ ( v ) => setBranding( { primary_color: v } ) }
					placeholder={ __( '#2271b1', 'integration-workos' ) }
				/>
			</fieldset>

			<div className="wpa-actions">
				<button
					className="button button-primary"
					disabled={ saving }
					onClick={ () => onSave( data ) }
				>
					{ saving
						? __( 'Saving…', 'integration-workos' )
						: __( 'Save profile', 'integration-workos' ) }
				</button>
				<button className="button" onClick={ onCancel }>
					{ __( 'Cancel', 'integration-workos' ) }
				</button>
				{ data.id > 0 && ! isDefault && (
					<button
						className="button button-link-delete"
						onClick={ () => {
							const message = sprintf(
								/* translators: %s: profile title. */
								__( 'Delete “%s”?', 'integration-workos' ),
								data.title
							);
							if ( window.confirm( message ) ) {
								onDelete( data );
							}
						} }
					>
						{ __( 'Delete', 'integration-workos' ) }
					</button>
				) }
			</div>
		</div>
	);
}

interface ListProps {
	profiles: Profile[];
	organizations: Organization[];
	onSelect: ( profile: Profile ) => void;
	onCreate: () => void;
}

function profileUrl( slug: string ): string {
	const base = window.workosProfileAdmin?.pageUrl || '';
	return '' === base
		? '#'
		: `${ base }&profile=${ encodeURIComponent( slug ) }`;
}

function List( { profiles, organizations, onSelect, onCreate }: ListProps ) {
	const orgName = ( id: string ): string => {
		if ( '' === id ) {
			return '—';
		}
		const match = organizations.find( ( o ) => o.id === id );
		return match ? match.name : id;
	};

	// Real anchors so middle-click + copy-link work; left-click is
	// intercepted by `onSelect` to keep navigation client-side.
	const handleClick = (
		event: MouseEvent< HTMLAnchorElement >,
		profile: Profile
	): void => {
		// Honor modifier keys / non-left-click so the browser opens the link
		// in a new tab/window when the user asks for it.
		if ( event.defaultPrevented || event.button !== 0 ) {
			return;
		}
		if ( event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
			return;
		}
		event.preventDefault();
		onSelect( profile );
	};

	return (
		<div className="wpa-list">
			<div className="wpa-list-header">
				<button className="button button-primary" onClick={ onCreate }>
					{ __( 'Add profile', 'integration-workos' ) }
				</button>
			</div>
			<table className="wp-list-table widefat striped">
				<thead>
					<tr>
						<th>{ __( 'Title', 'integration-workos' ) }</th>
						<th>{ __( 'Slug', 'integration-workos' ) }</th>
						<th>{ __( 'Mode', 'integration-workos' ) }</th>
						<th>{ __( 'Methods', 'integration-workos' ) }</th>
						<th>{ __( 'Organization', 'integration-workos' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ profiles.map( ( p ) => (
						<tr key={ p.id } className="wpa-row">
							<td>
								<strong>
									<a
										href={ profileUrl( p.slug ) }
										onClick={ ( e ) => handleClick( e, p ) }
									>
										{ p.title }
									</a>
								</strong>
							</td>
							<td>
								<code>{ p.slug }</code>
							</td>
							<td>{ p.mode }</td>
							<td>{ ( p.methods || [] ).join( ', ' ) }</td>
							<td>{ orgName( p.organization_id ) }</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}

interface ListResponse {
	profiles?: Profile[];
	message?: string;
}

/**
 * Read the current `?profile=…` slug from the URL. Empty string means
 * the index view; `'new'` means the empty-profile editor; anything else
 * is treated as a slug and resolved against the in-memory list.
 */
function readProfileSlugFromUrl(): string {
	const params = new URLSearchParams( window.location.search );
	return ( params.get( 'profile' ) || '' ).trim();
}

/**
 * Resolve a slug against the loaded list and return the matching record,
 * an empty editor for `'new'`, or `null` for the index view / unknown
 * slugs.
 */
function resolveSelected(
	slug: string,
	profiles: Profile[]
): Profile | null {
	if ( '' === slug ) {
		return null;
	}
	if ( NEW_PROFILE_SENTINEL === slug ) {
		return emptyProfile();
	}
	return profiles.find( ( p ) => p.slug === slug ) ?? null;
}

/**
 * Push or replace the URL so the current view is bookmarkable. We use
 * the page URL provided by PHP (escaped via esc_url_raw) as the base so
 * we never rely on string-stitching `?page=` ourselves.
 */
function navigateTo( slug: string, replace = false ): void {
	const base   = window.workosProfileAdmin?.pageUrl || window.location.pathname + window.location.search.replace( /([?&])profile=[^&]*/g, '' );
	const target = '' === slug ? base : `${ base }&profile=${ encodeURIComponent( slug ) }`;
	const method = replace ? 'replaceState' : 'pushState';
	window.history[ method ]( {}, '', target );
}

function App(): ReactNode {
	const initialProfiles  = window.workosProfileAdmin?.profiles ?? [];
	const initialSlug      = window.workosProfileAdmin?.activeProfileSlug ?? '';

	const [ profiles, setProfiles ] = useState< Profile[] >( initialProfiles );
	const [ selected, setSelected ] = useState< Profile | null >( () =>
		resolveSelected( initialSlug, initialProfiles )
	);
	const [ error, setError ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ organizations, setOrganizations ] = useState< Organization[] >( [] );
	const [ organizationsLoading, setOrganizationsLoading ] = useState( true );
	const [ organizationsError, setOrganizationsError ] = useState( '' );

	const refreshProfiles = async (): Promise< Profile[] > => {
		const { ok, data } = await apiCall< ListResponse >( 'GET', '' );
		if ( ok ) {
			const next = data.profiles || [];
			setProfiles( next );
			return next;
		}
		setError(
			data.message || __( 'Failed to load profiles.', 'integration-workos' )
		);
		return profiles;
	};

	const loadOrganizations = async (): Promise< void > => {
		setOrganizationsLoading( true );
		const { ok, data } = await apiCall< OrganizationsResponse >(
			'GET',
			'/organizations'
		);
		setOrganizationsLoading( false );
		if ( ok ) {
			setOrganizations( data.organizations || [] );
			setOrganizationsError( data.error || '' );
			return;
		}
		setOrganizationsError(
			data.message ||
				data.error ||
				__( 'Failed to load organizations.', 'integration-workos' )
		);
	};

	useEffect( () => {
		loadOrganizations();
	}, [] );

	// Browser back/forward — re-derive the selection from the current URL.
	useEffect( () => {
		const onPop = (): void => {
			setSelected( resolveSelected( readProfileSlugFromUrl(), profiles ) );
		};
		window.addEventListener( 'popstate', onPop );
		return () => window.removeEventListener( 'popstate', onPop );
	}, [ profiles ] );

	const openProfile = ( profile: Profile ): void => {
		setSelected( profile );
		navigateTo( profile.slug );
	};

	const openNewProfile = (): void => {
		setSelected( emptyProfile() );
		navigateTo( NEW_PROFILE_SENTINEL );
	};

	const closeEditor = (): void => {
		setSelected( null );
		navigateTo( '' );
	};

	const handleSave = async ( profile: Profile ): Promise< void > => {
		setSaving( true );
		setError( '' );
		const isNew = ! profile.id;
		const { ok, data } = await apiCall< Profile & { message?: string } >(
			isNew ? 'POST' : 'PUT',
			isNew ? '' : `/${ profile.id }`,
			profile
		);
		setSaving( false );
		if ( ! ok ) {
			setError(
				data.message || __( 'Failed to save.', 'integration-workos' )
			);
			return;
		}
		await refreshProfiles();
		setSelected( data );
		// Slug normalization on the server may rename — keep the URL in sync.
		navigateTo( data.slug, true );
	};

	const handleDelete = async ( profile: Profile ): Promise< void > => {
		const { ok, data } = await apiCall< { message?: string } >(
			'DELETE',
			`/${ profile.id }`
		);
		if ( ! ok ) {
			setError(
				data.message || __( 'Failed to delete.', 'integration-workos' )
			);
			return;
		}
		setSelected( null );
		navigateTo( '' );
		await refreshProfiles();
	};

	return (
		<>
			{ error && (
				<div className="notice notice-error">
					<p>{ error }</p>
				</div>
			) }
			{ selected ? (
				<Editor
					profile={ selected }
					organizations={ organizations }
					organizationsLoading={ organizationsLoading }
					organizationsError={ organizationsError }
					onSave={ handleSave }
					onCancel={ closeEditor }
					onDelete={ handleDelete }
					saving={ saving }
				/>
			) : (
				<List
					profiles={ profiles }
					organizations={ organizations }
					onSelect={ openProfile }
					onCreate={ openNewProfile }
				/>
			) }
		</>
	);
}

function mount(): void {
	const root = document.getElementById( 'workos-profiles-admin-root' );
	if ( ! root ) {
		return;
	}
	createRoot( root ).render( <App /> );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mount );
} else {
	mount();
}
