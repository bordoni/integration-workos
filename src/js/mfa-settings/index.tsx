/**
 * Two-step verification account panel.
 *
 * Mounts on every `.workos-mfa-panel` (the user's profile screen and the
 * `[workos:mfa]` shortcode) and talks to `/workos/v1/users/{id}/mfa…` with
 * the core `wp_rest` nonce. Turning MFA on scans a QR code and confirms the
 * first code; removing a factor needs a current code from it.
 */

import { createRoot, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { FormEvent } from 'react';
import './styles.css';

interface MfaConfig {
	/** Base REST URL — routes are `${restUrl}{id}/mfa…`. */
	restUrl: string;
	/** WP REST nonce (`wp_rest`). */
	nonce: string;
}

interface Factor {
	id: string;
	type: string;
	created: string;
}

interface Status {
	enabled: boolean;
	required: boolean;
	factors: Factor[];
}

interface Enrollment {
	factor_id: string;
	challenge_id: string;
	qr_code: string;
	secret: string;
}

interface ApiError {
	code?: string;
	message?: string;
}

declare global {
	interface Window {
		workosMfa?: MfaConfig;
	}
}

async function api< T >(
	userId: number,
	path: string,
	method = 'GET',
	body?: Record< string, string >
): Promise< { ok: boolean; data: T | ApiError } > {
	const config = window.workosMfa as MfaConfig;
	const response = await fetch( `${ config.restUrl }${ userId }/mfa${ path }`, {
		method,
		credentials: 'same-origin',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': config.nonce,
		},
		body: body ? JSON.stringify( body ) : undefined,
	} );
	const data = ( await response.json().catch( () => ( {} ) ) ) as T | ApiError;
	return { ok: response.ok, data };
}

function message( data: unknown ): string {
	return (
		( data as ApiError )?.message ||
		__( 'Something went wrong. Please try again.', 'integration-workos' )
	);
}

function CodeField( {
	id,
	value,
	onChange,
}: {
	id: string;
	value: string;
	onChange: ( value: string ) => void;
} ) {
	return (
		<p>
			<label htmlFor={ id }>
				{ __( 'Code from your authenticator app', 'integration-workos' ) }
			</label>
			<br />
			<input
				id={ id }
				className="regular-text workos-mfa-code"
				type="text"
				inputMode="numeric"
				autoComplete="one-time-code"
				placeholder="123456"
				value={ value }
				onChange={ ( event ) => onChange( event.target.value ) }
				required
			/>
		</p>
	);
}

function Panel( { userId }: { userId: number } ) {
	const [ status, setStatus ] = useState< Status | null >( null );
	const [ enrollment, setEnrollment ] = useState< Enrollment | null >( null );
	const [ removing, setRemoving ] = useState< string >( '' );
	const [ code, setCode ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ notice, setNotice ] = useState( '' );

	useEffect( () => {
		( async () => {
			const { ok, data } = await api< Status >( userId, '' );
			if ( ok ) {
				setStatus( data as Status );
			} else {
				setError( message( data ) );
			}
		} )();
	}, [ userId ] );

	const reset = ( next: Status, done: string ) => {
		setStatus( next );
		setEnrollment( null );
		setRemoving( '' );
		setCode( '' );
		setNotice( done );
	};

	const start = async () => {
		setBusy( true );
		setError( '' );
		setNotice( '' );
		const { ok, data } = await api< Enrollment >( userId, '/enroll', 'POST' );
		setBusy( false );
		if ( ! ok ) {
			setError( message( data ) );
			return;
		}
		setEnrollment( data as Enrollment );
	};

	const confirm = async ( event: FormEvent ) => {
		event.preventDefault();
		if ( ! enrollment ) {
			return;
		}
		setBusy( true );
		setError( '' );
		const { ok, data } = await api< Status >( userId, '/confirm', 'POST', {
			factor_id: enrollment.factor_id,
			challenge_id: enrollment.challenge_id,
			code,
		} );
		setBusy( false );
		if ( ! ok ) {
			setError( message( data ) );
			return;
		}
		reset(
			data as Status,
			__( 'Two-step verification is on.', 'integration-workos' )
		);
	};

	const remove = async ( event: FormEvent ) => {
		event.preventDefault();
		setBusy( true );
		setError( '' );
		const { ok, data } = await api< Status >(
			userId,
			`/factors/${ encodeURIComponent( removing ) }`,
			'DELETE',
			{ code }
		);
		setBusy( false );
		if ( ! ok ) {
			setError( message( data ) );
			return;
		}
		reset(
			data as Status,
			__( 'The authenticator app was removed.', 'integration-workos' )
		);
	};

	if ( ! status ) {
		return error ? (
			<div className="notice notice-error inline">
				<p>{ error }</p>
			</div>
		) : (
			<p className="workos-mfa-loading">
				{ __( 'Loading…', 'integration-workos' ) }
			</p>
		);
	}

	return (
		<div className="workos-mfa">
			{ notice && (
				<div className="notice notice-success inline">
					<p>{ notice }</p>
				</div>
			) }
			{ error && (
				<div className="notice notice-error inline">
					<p>{ error }</p>
				</div>
			) }

			<p className="workos-mfa-state">
				{ status.enabled
					? __(
							'On. Signing in asks for a code from your authenticator app.',
							'integration-workos'
					  )
					: __(
							'Off. Add an authenticator app to protect your account with a second step at sign-in.',
							'integration-workos'
					  ) }
			</p>
			{ status.required && (
				<p className="description">
					{ __(
						'Your administrator requires two-step verification for this account.',
						'integration-workos'
					) }
				</p>
			) }

			{ status.factors.length > 0 && (
				<ul className="workos-mfa-factors">
					{ status.factors.map( ( factor ) => (
						<li key={ factor.id }>
							<span>
								{ __( 'Authenticator app', 'integration-workos' ) }
								{ factor.created && (
									<span className="description">
										{ ' · ' }
										{ new Date( factor.created ).toLocaleDateString() }
									</span>
								) }
							</span>
							{ removing !== factor.id && (
								<button
									type="button"
									className="button-link button-link-delete"
									onClick={ () => {
										setRemoving( factor.id );
										setEnrollment( null );
										setCode( '' );
										setError( '' );
									} }
								>
									{ __( 'Remove', 'integration-workos' ) }
								</button>
							) }
						</li>
					) ) }
				</ul>
			) }

			{ removing && (
				<form className="workos-mfa-step" onSubmit={ remove }>
					<p>
						{ status.required && status.factors.length === 1
							? __(
									'This is your only authenticator app. Because two-step verification is required, you’ll set up a new one the next time you sign in.',
									'integration-workos'
							  )
							: __(
									'Enter a code from this app to confirm it’s you.',
									'integration-workos'
							  ) }
					</p>
					<CodeField
						id={ `workos-mfa-remove-${ userId }` }
						value={ code }
						onChange={ setCode }
					/>
					<p>
						<button
							type="submit"
							className="button button-secondary"
							disabled={ busy }
						>
							{ __( 'Remove authenticator app', 'integration-workos' ) }
						</button>{ ' ' }
						<button
							type="button"
							className="button-link"
							onClick={ () => setRemoving( '' ) }
						>
							{ __( 'Cancel', 'integration-workos' ) }
						</button>
					</p>
				</form>
			) }

			{ ! enrollment && ! removing && ! status.enabled && (
				<p>
					<button
						type="button"
						className="button button-primary"
						onClick={ start }
						disabled={ busy }
					>
						{ __( 'Turn on two-step verification', 'integration-workos' ) }
					</button>
				</p>
			) }

			{ enrollment && (
				<form className="workos-mfa-step" onSubmit={ confirm }>
					<p>
						{ __(
							'Scan this QR code with an authenticator app (1Password, Google Authenticator, Authy…), then enter the 6-digit code it shows.',
							'integration-workos'
						) }
					</p>
					{ enrollment.qr_code && (
						<img
							className="workos-mfa-qr"
							src={ enrollment.qr_code }
							alt={ __(
								'QR code for your authenticator app',
								'integration-workos'
							) }
							width={ 180 }
							height={ 180 }
						/>
					) }
					<p className="description">
						{ __( 'Can’t scan? Enter this setup key:', 'integration-workos' ) }{ ' ' }
						<code className="workos-mfa-secret">{ enrollment.secret }</code>
					</p>
					<CodeField
						id={ `workos-mfa-enroll-${ userId }` }
						value={ code }
						onChange={ setCode }
					/>
					<p>
						<button
							type="submit"
							className="button button-primary"
							disabled={ busy }
						>
							{ __( 'Verify and turn on', 'integration-workos' ) }
						</button>{ ' ' }
						<button
							type="button"
							className="button-link"
							onClick={ () => setEnrollment( null ) }
						>
							{ __( 'Cancel', 'integration-workos' ) }
						</button>
					</p>
				</form>
			) }
		</div>
	);
}

function mount(): void {
	if ( ! window.workosMfa ) {
		return;
	}
	document
		.querySelectorAll< HTMLElement >( '.workos-mfa-panel' )
		.forEach( ( root ) => {
			const userId = Number( root.dataset.userId || 0 );
			if ( userId > 0 ) {
				createRoot( root ).render( <Panel userId={ userId } /> );
			}
		} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mount );
} else {
	mount();
}
