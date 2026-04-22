/**
 * Admin Login Profiles editor.
 *
 * Mounts onto #workos-profiles-admin-root. Talks to the admin REST
 * namespace at /wp-json/workos/v1/admin/profiles. Intentionally kept in
 * a single file — the editor is a small CRUD form, not a full app.
 */

import { createElement as h, createRoot, Fragment, useEffect, useState } from '@wordpress/element';
import './styles.css';

const METHOD_OPTIONS = [
	{ value: 'password',        label: 'Email + password' },
	{ value: 'magic_code',      label: 'Magic link / email code' },
	{ value: 'oauth_google',    label: 'Google' },
	{ value: 'oauth_microsoft', label: 'Microsoft' },
	{ value: 'oauth_github',    label: 'GitHub' },
	{ value: 'oauth_apple',     label: 'Apple' },
	{ value: 'passkey',         label: 'Passkey' },
];

const FACTOR_OPTIONS = [
	{ value: 'totp',     label: 'Authenticator app (TOTP)' },
	{ value: 'sms',      label: 'SMS' },
	{ value: 'webauthn', label: 'WebAuthn / passkey' },
];

const ENFORCE_OPTIONS = [
	{ value: 'never',       label: 'Never' },
	{ value: 'if_required', label: 'When WorkOS requires it' },
	{ value: 'always',      label: 'Always' },
];

const MODE_OPTIONS = [
	{ value: 'custom',           label: 'Custom (React UI)' },
	{ value: 'authkit_redirect', label: 'Legacy AuthKit redirect' },
];

function apiUrl( path = '' ) {
	const base = window.workosProfileAdmin?.restUrl || '/wp-json/workos/v1/admin/profiles';
	return `${ base }${ path }`;
}

async function apiCall( method, path, body ) {
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
	return { ok: res.ok, status: res.status, data };
}

function Checkboxes( { label, options, values, onChange } ) {
	const toggle = ( value ) => {
		onChange(
			values.includes( value )
				? values.filter( ( v ) => v !== value )
				: [ ...values, value ]
		);
	};
	return h(
		'fieldset',
		{ className: 'wpa-fieldset' },
		h( 'legend', null, label ),
		options.map( ( opt ) =>
			h(
				'label',
				{ key: opt.value, className: 'wpa-check' },
				h( 'input', {
					type: 'checkbox',
					checked: values.includes( opt.value ),
					onChange: () => toggle( opt.value ),
				} ),
				opt.label
			)
		)
	);
}

function Select( { label, value, onChange, options } ) {
	return h(
		'label',
		{ className: 'wpa-field' },
		h( 'span', null, label ),
		h(
			'select',
			{
				value,
				onChange: ( e ) => onChange( e.target.value ),
			},
			options.map( ( opt ) => h( 'option', { key: opt.value, value: opt.value }, opt.label ) )
		)
	);
}

function TextField( { label, value, onChange, type = 'text', placeholder, disabled } ) {
	return h(
		'label',
		{ className: 'wpa-field' },
		h( 'span', null, label ),
		h( 'input', {
			type,
			value: value ?? '',
			onChange: ( e ) => onChange( e.target.value ),
			placeholder,
			disabled,
		} )
	);
}

function emptyProfile() {
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
		branding: { logo_attachment_id: 0, primary_color: '', heading: '', subheading: '' },
		post_login_redirect: '',
		mode: 'custom',
	};
}

function Editor( { profile, onSave, onCancel, onDelete, saving } ) {
	const [ data, setData ] = useState( profile );

	useEffect( () => setData( profile ), [ profile.id, profile.slug ] );

	const set = ( patch ) => setData( ( prev ) => ( { ...prev, ...patch } ) );
	const setSignup = ( patch ) => set( { signup: { ...data.signup, ...patch } } );
	const setMfa = ( patch ) => set( { mfa: { ...data.mfa, ...patch } } );
	const setBranding = ( patch ) => set( { branding: { ...data.branding, ...patch } } );

	const isDefault = data.slug === 'default';

	return h(
		'div',
		{ className: 'wpa-editor' },
		h( 'h2', null, data.id ? `Edit: ${ data.title }` : 'New Login Profile' ),

		h( TextField, {
			label: 'Title',
			value: data.title,
			onChange: ( v ) => set( { title: v } ),
		} ),

		h( TextField, {
			label: 'Slug',
			value: data.slug,
			onChange: ( v ) => set( { slug: v } ),
			disabled: isDefault,
			placeholder: 'members-area',
		} ),

		h( Select, {
			label: 'Mode',
			value: data.mode,
			onChange: ( v ) => set( { mode: v } ),
			options: MODE_OPTIONS,
		} ),

		h( Checkboxes, {
			label: 'Enabled sign-in methods',
			options: METHOD_OPTIONS,
			values: data.methods,
			onChange: ( v ) => set( { methods: v } ),
		} ),

		h( TextField, {
			label: 'Pinned organization ID',
			value: data.organization_id,
			onChange: ( v ) => set( { organization_id: v } ),
			placeholder: 'org_01ABC…',
		} ),

		h( TextField, {
			label: 'Redirect after login',
			value: data.post_login_redirect,
			onChange: ( v ) => set( { post_login_redirect: v } ),
			placeholder: '/dashboard',
		} ),

		h(
			'fieldset',
			{ className: 'wpa-fieldset' },
			h( 'legend', null, 'Sign-up' ),
			h(
				'label',
				{ className: 'wpa-check' },
				h( 'input', {
					type: 'checkbox',
					checked: data.signup.enabled,
					onChange: ( e ) => setSignup( { enabled: e.target.checked } ),
				} ),
				'Allow sign-up'
			),
			h(
				'label',
				{ className: 'wpa-check' },
				h( 'input', {
					type: 'checkbox',
					checked: data.signup.require_invite,
					onChange: ( e ) => setSignup( { require_invite: e.target.checked } ),
				} ),
				'Require invitation'
			)
		),

		h(
			'fieldset',
			{ className: 'wpa-fieldset' },
			h( 'legend', null, 'Flows' ),
			h(
				'label',
				{ className: 'wpa-check' },
				h( 'input', {
					type: 'checkbox',
					checked: data.invite_flow,
					onChange: ( e ) => set( { invite_flow: e.target.checked } ),
				} ),
				'Invitation acceptance'
			),
			h(
				'label',
				{ className: 'wpa-check' },
				h( 'input', {
					type: 'checkbox',
					checked: data.password_reset_flow,
					onChange: ( e ) => set( { password_reset_flow: e.target.checked } ),
				} ),
				'Password reset'
			)
		),

		h( Select, {
			label: 'MFA enforcement',
			value: data.mfa.enforce,
			onChange: ( v ) => setMfa( { enforce: v } ),
			options: ENFORCE_OPTIONS,
		} ),

		h( Checkboxes, {
			label: 'MFA factors',
			options: FACTOR_OPTIONS,
			values: data.mfa.factors,
			onChange: ( v ) => setMfa( { factors: v } ),
		} ),

		h(
			'fieldset',
			{ className: 'wpa-fieldset' },
			h( 'legend', null, 'Branding' ),
			h( TextField, {
				label: 'Heading',
				value: data.branding.heading,
				onChange: ( v ) => setBranding( { heading: v } ),
			} ),
			h( TextField, {
				label: 'Subheading',
				value: data.branding.subheading,
				onChange: ( v ) => setBranding( { subheading: v } ),
			} ),
			h( TextField, {
				label: 'Primary color',
				value: data.branding.primary_color,
				onChange: ( v ) => setBranding( { primary_color: v } ),
				placeholder: '#0057ff',
			} )
		),

		h(
			'div',
			{ className: 'wpa-actions' },
			h(
				'button',
				{ className: 'button button-primary', disabled: saving, onClick: () => onSave( data ) },
				saving ? 'Saving…' : 'Save profile'
			),
			h(
				'button',
				{ className: 'button', onClick: onCancel },
				'Cancel'
			),
			data.id > 0 && ! isDefault && h(
				'button',
				{
					className: 'button button-link-delete',
					onClick: () => {
						if ( window.confirm( `Delete "${ data.title }"?` ) ) {
							onDelete( data );
						}
					},
				},
				'Delete'
			)
		)
	);
}

function List( { profiles, onSelect, onCreate } ) {
	return h(
		'div',
		{ className: 'wpa-list' },
		h(
			'div',
			{ className: 'wpa-list-header' },
			h( 'h2', null, 'Login Profiles' ),
			h(
				'button',
				{ className: 'button button-primary', onClick: onCreate },
				'Add profile'
			)
		),
		h(
			'table',
			{ className: 'wp-list-table widefat striped' },
			h(
				'thead',
				null,
				h(
					'tr',
					null,
					h( 'th', null, 'Title' ),
					h( 'th', null, 'Slug' ),
					h( 'th', null, 'Mode' ),
					h( 'th', null, 'Methods' ),
					h( 'th', null, 'Organization' )
				)
			),
			h(
				'tbody',
				null,
				profiles.map( ( p ) =>
					h(
						'tr',
						{ key: p.id, className: 'wpa-row', onClick: () => onSelect( p ) },
						h(
							'td',
							null,
							h( 'strong', null, h( 'a', { href: '#' }, p.title ) )
						),
						h( 'td', null, h( 'code', null, p.slug ) ),
						h( 'td', null, p.mode ),
						h( 'td', null, ( p.methods || [] ).join( ', ' ) ),
						h( 'td', null, p.organization_id || '—' )
					)
				)
			)
		)
	);
}

function App() {
	const [ profiles, setProfiles ] = useState( [] );
	const [ selected, setSelected ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ loading, setLoading ] = useState( true );

	const load = async () => {
		setLoading( true );
		const { ok, data } = await apiCall( 'GET' );
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

	const handleSave = async ( profile ) => {
		setSaving( true );
		setError( '' );
		const isNew = ! profile.id;
		const { ok, data } = await apiCall(
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

	const handleDelete = async ( profile ) => {
		const { ok, data } = await apiCall( 'DELETE', `/${ profile.id }` );
		if ( ! ok ) {
			setError( data.message || 'Failed to delete.' );
			return;
		}
		setSelected( null );
		await load();
	};

	if ( loading ) {
		return h( 'p', null, 'Loading profiles…' );
	}

	return h(
		Fragment,
		null,
		error && h( 'div', { className: 'notice notice-error' }, h( 'p', null, error ) ),
		selected
			? h( Editor, {
				profile: selected,
				onSave: handleSave,
				onCancel: () => setSelected( null ),
				onDelete: handleDelete,
				saving,
			} )
			: h( List, {
				profiles,
				onSelect: ( p ) => setSelected( p ),
				onCreate: () => setSelected( emptyProfile() ),
			} )
	);
}

function mount() {
	const root = document.getElementById( 'workos-profiles-admin-root' );
	if ( ! root ) {
		return;
	}
	createRoot( root ).render( h( App, null ) );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mount );
} else {
	mount();
}
