/**
 * Admin Login Profiles editor.
 *
 * Mounts onto #workos-profiles-admin-root. Talks to the admin REST
 * namespace at /wp-json/workos/v1/admin/profiles. Intentionally kept in
 * a single file — the editor is a small CRUD form, not a full app.
 */

import { createRoot, useEffect, useState } from '@wordpress/element';
import type { ChangeEvent, ReactNode } from 'react';
import './styles.css';

declare global {
	interface Window {
		workosProfileAdmin?: {
			restUrl: string;
			nonce: string;
		};
	}
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
		logo_attachment_id: number;
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

interface OptionItem< V extends string > {
	value: V;
	label: string;
}

const METHOD_OPTIONS: OptionItem< AuthMethod >[] = [
	{ value: 'password',        label: 'Email + password' },
	{ value: 'magic_code',      label: 'Magic link / email code' },
	{ value: 'oauth_google',    label: 'Google' },
	{ value: 'oauth_microsoft', label: 'Microsoft' },
	{ value: 'oauth_github',    label: 'GitHub' },
	{ value: 'oauth_apple',     label: 'Apple' },
	{ value: 'passkey',         label: 'Passkey' },
];

const FACTOR_OPTIONS: OptionItem< MfaFactor >[] = [
	{ value: 'totp',     label: 'Authenticator app (TOTP)' },
	{ value: 'sms',      label: 'SMS' },
	{ value: 'webauthn', label: 'WebAuthn / passkey' },
];

const ENFORCE_OPTIONS: OptionItem< MfaEnforce >[] = [
	{ value: 'never',       label: 'Never' },
	{ value: 'if_required', label: 'When WorkOS requires it' },
	{ value: 'always',      label: 'Always' },
];

const MODE_OPTIONS: OptionItem< ProfileMode >[] = [
	{ value: 'custom',           label: 'Custom (React UI)' },
	{ value: 'authkit_redirect', label: 'Legacy AuthKit redirect' },
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
			logo_attachment_id: 0,
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
	onSave: ( profile: Profile ) => void;
	onCancel: () => void;
	onDelete: ( profile: Profile ) => void;
	saving: boolean;
}

function Editor( { profile, onSave, onCancel, onDelete, saving }: EditorProps ) {
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
			<h2>{ data.id ? `Edit: ${ data.title }` : 'New Login Profile' }</h2>

			<TextField
				label="Title"
				value={ data.title }
				onChange={ ( v ) => set( { title: v } ) }
			/>

			<TextField
				label="Slug"
				value={ data.slug }
				onChange={ ( v ) => set( { slug: v } ) }
				disabled={ isDefault }
				placeholder="members-area"
			/>

			<Select< ProfileMode >
				label="Mode"
				value={ data.mode }
				onChange={ ( v ) => set( { mode: v } ) }
				options={ MODE_OPTIONS }
			/>

			<Checkboxes< AuthMethod >
				label="Enabled sign-in methods"
				options={ METHOD_OPTIONS }
				values={ data.methods }
				onChange={ ( v ) => set( { methods: v } ) }
			/>

			<TextField
				label="Pinned organization ID"
				value={ data.organization_id }
				onChange={ ( v ) => set( { organization_id: v } ) }
				placeholder="org_01ABC…"
			/>

			<TextField
				label="Redirect after login"
				value={ data.post_login_redirect }
				onChange={ ( v ) => set( { post_login_redirect: v } ) }
				placeholder="/dashboard"
			/>

			<fieldset className="wpa-fieldset">
				<legend>Sign-up</legend>
				<label className="wpa-check">
					<input
						type="checkbox"
						checked={ data.signup.enabled }
						onChange={ ( e: ChangeEvent< HTMLInputElement > ) =>
							setSignup( { enabled: e.target.checked } )
						}
					/>
					Allow sign-up
				</label>
				<label className="wpa-check">
					<input
						type="checkbox"
						checked={ data.signup.require_invite }
						onChange={ ( e: ChangeEvent< HTMLInputElement > ) =>
							setSignup( { require_invite: e.target.checked } )
						}
					/>
					Require invitation
				</label>
			</fieldset>

			<fieldset className="wpa-fieldset">
				<legend>Flows</legend>
				<label className="wpa-check">
					<input
						type="checkbox"
						checked={ data.invite_flow }
						onChange={ ( e: ChangeEvent< HTMLInputElement > ) =>
							set( { invite_flow: e.target.checked } )
						}
					/>
					Invitation acceptance
				</label>
				<label className="wpa-check">
					<input
						type="checkbox"
						checked={ data.password_reset_flow }
						onChange={ ( e: ChangeEvent< HTMLInputElement > ) =>
							set( { password_reset_flow: e.target.checked } )
						}
					/>
					Password reset
				</label>
			</fieldset>

			<Select< MfaEnforce >
				label="MFA enforcement"
				value={ data.mfa.enforce }
				onChange={ ( v ) => setMfa( { enforce: v } ) }
				options={ ENFORCE_OPTIONS }
			/>

			<Checkboxes< MfaFactor >
				label="MFA factors"
				options={ FACTOR_OPTIONS }
				values={ data.mfa.factors }
				onChange={ ( v ) => setMfa( { factors: v } ) }
			/>

			<fieldset className="wpa-fieldset">
				<legend>Branding</legend>
				<TextField
					label="Heading"
					value={ data.branding.heading }
					onChange={ ( v ) => setBranding( { heading: v } ) }
				/>
				<TextField
					label="Subheading"
					value={ data.branding.subheading }
					onChange={ ( v ) => setBranding( { subheading: v } ) }
				/>
				<TextField
					label="Primary color"
					value={ data.branding.primary_color }
					onChange={ ( v ) => setBranding( { primary_color: v } ) }
					placeholder="#0057ff"
				/>
			</fieldset>

			<div className="wpa-actions">
				<button
					className="button button-primary"
					disabled={ saving }
					onClick={ () => onSave( data ) }
				>
					{ saving ? 'Saving…' : 'Save profile' }
				</button>
				<button className="button" onClick={ onCancel }>
					Cancel
				</button>
				{ data.id > 0 && ! isDefault && (
					<button
						className="button button-link-delete"
						onClick={ () => {
							if ( window.confirm( `Delete "${ data.title }"?` ) ) {
								onDelete( data );
							}
						} }
					>
						Delete
					</button>
				) }
			</div>
		</div>
	);
}

interface ListProps {
	profiles: Profile[];
	onSelect: ( profile: Profile ) => void;
	onCreate: () => void;
}

function List( { profiles, onSelect, onCreate }: ListProps ) {
	return (
		<div className="wpa-list">
			<div className="wpa-list-header">
				<h2>Login Profiles</h2>
				<button className="button button-primary" onClick={ onCreate }>
					Add profile
				</button>
			</div>
			<table className="wp-list-table widefat striped">
				<thead>
					<tr>
						<th>Title</th>
						<th>Slug</th>
						<th>Mode</th>
						<th>Methods</th>
						<th>Organization</th>
					</tr>
				</thead>
				<tbody>
					{ profiles.map( ( p ) => (
						<tr
							key={ p.id }
							className="wpa-row"
							onClick={ () => onSelect( p ) }
						>
							<td>
								<strong>
									<a href="#">{ p.title }</a>
								</strong>
							</td>
							<td>
								<code>{ p.slug }</code>
							</td>
							<td>{ p.mode }</td>
							<td>{ ( p.methods || [] ).join( ', ' ) }</td>
							<td>{ p.organization_id || '—' }</td>
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

function App(): ReactNode {
	const [ profiles, setProfiles ] = useState< Profile[] >( [] );
	const [ selected, setSelected ] = useState< Profile | null >( null );
	const [ error, setError ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ loading, setLoading ] = useState( true );

	const load = async (): Promise< void > => {
		setLoading( true );
		const { ok, data } = await apiCall< ListResponse >( 'GET', '' );
		setLoading( false );
		if ( ok ) {
			setProfiles( data.profiles || [] );
		} else {
			setError( data.message || 'Failed to load profiles.' );
		}
	};

	useEffect( () => {
		load();
	}, [] );

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
			setError( data.message || 'Failed to save.' );
			return;
		}
		await load();
		setSelected( data );
	};

	const handleDelete = async ( profile: Profile ): Promise< void > => {
		const { ok, data } = await apiCall< { message?: string } >(
			'DELETE',
			`/${ profile.id }`
		);
		if ( ! ok ) {
			setError( data.message || 'Failed to delete.' );
			return;
		}
		setSelected( null );
		await load();
	};

	if ( loading ) {
		return <p>Loading profiles…</p>;
	}

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
					onSave={ handleSave }
					onCancel={ () => setSelected( null ) }
					onDelete={ handleDelete }
					saving={ saving }
				/>
			) : (
				<List
					profiles={ profiles }
					onSelect={ ( p ) => setSelected( p ) }
					onCreate={ () => setSelected( emptyProfile() ) }
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
