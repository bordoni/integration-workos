/**
 * AuthKit flow components.
 *
 * Each flow is a small state machine that renders a card of inputs and
 * reports its outcome to the parent App via `onDone`. Flows never hold
 * global state — App.tsx owns step transitions + resulting session.
 */

import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { FormEvent } from 'react';
import type { AuthKitClient } from './api';
import type {
	ApiError,
	AuthFactor,
	AuthMethod,
	AuthResult,
	InvitationLookup,
	LoginSuccess,
	MfaRequired,
	Profile,
	Step,
} from './types';
import { isMfaRequired } from './types';
import {
	Alert,
	Button,
	Card,
	Divider,
	Field,
	FlowCard,
	Heading,
	Input,
	LinkButton,
	Spinner,
	Subheading,
} from './ui';
import {
	AuthKitSlot,
	SLOT_AFTER_FORM,
	SLOT_AFTER_PRIMARY_ACTION,
	SLOT_BEFORE_FORM,
	SLOT_PICKER_AFTER_METHODS,
	SLOT_PICKER_BEFORE_METHODS,
} from './slots';
import type { AuthKitSlotFillProps } from './slots';

function fillPropsFor(
	profile: Profile,
	step: Step,
	flow?: string
): AuthKitSlotFillProps {
	return {
		step,
		profileSlug: profile.slug,
		methods: profile.methods || [],
		flow,
	};
}

function methodLabel( method: AuthMethod ): string {
	switch ( method ) {
		case 'oauth_google':
			return __( 'Continue with Google', 'integration-workos' );
		case 'oauth_microsoft':
			return __( 'Continue with Microsoft', 'integration-workos' );
		case 'oauth_github':
			return __( 'Continue with GitHub', 'integration-workos' );
		case 'oauth_apple':
			return __( 'Continue with Apple', 'integration-workos' );
		default:
			return method;
	}
}

function errorMessage( data: unknown ): string {
	if ( ! data || typeof data !== 'object' ) {
		return __( 'Something went wrong. Please try again.', 'integration-workos' );
	}
	const err = data as ApiError;
	return err.message || err.code || __( 'Unexpected error.', 'integration-workos' );
}

// ---------------------------------------------------------------- MethodPicker

interface MethodPickerProps {
	profile: Profile;
	onChoose: ( step: Step ) => void;
	onError: ( message: string ) => void;
}

export function MethodPicker( { profile, onChoose, onError }: MethodPickerProps ) {
	const methods = profile.methods || [];
	const hasPassword = methods.includes( 'password' );
	const hasMagic = methods.includes( 'magic_code' );
	const oauthMethods = methods.filter( ( m ) => m.startsWith( 'oauth_' ) );

	const handleOAuth = async ( method: AuthMethod ): Promise< void > => {
		try {
			const response = await fetch(
				`${ profile.restBaseUrl }/oauth/authorize-url?profile=${ encodeURIComponent(
					profile.slug
				) }&provider=${ encodeURIComponent( method ) }&redirect_to=${ encodeURIComponent(
					profile.redirectTo || ''
				) }`,
				{ credentials: 'same-origin' }
			);
			const data = await response.json();
			if ( response.ok && data.authorize_url ) {
				window.location.assign( data.authorize_url );
			} else {
				onError( errorMessage( data ) );
			}
		} catch ( _ ) {
			onError( __( 'Unable to start social sign-in.', 'integration-workos' ) );
		}
	};

	const fillProps = fillPropsFor( profile, 'pick' );
	const logoAlt =
		profile.branding.heading || __( 'Sign in', 'integration-workos' );

	return (
		<FlowCard
			logoUrl={ profile.branding.logo_url }
			logoAlt={ logoAlt }
			fillProps={ fillProps }
			header={
				<>
					<Heading>
						{ profile.branding.heading ||
							__( 'Sign in', 'integration-workos' ) }
					</Heading>
					{ profile.branding.subheading && (
						<Subheading>{ profile.branding.subheading }</Subheading>
					) }
				</>
			}
			footer={
				profile.signup?.enabled && ! profile.signup?.require_invite ? (
					<p className="wa-footer">
						{ __( 'New here?', 'integration-workos' ) }{ ' ' }
						<LinkButton onClick={ () => onChoose( 'signup' ) }>
							{ __( 'Create an account', 'integration-workos' ) }
						</LinkButton>
					</p>
				) : null
			}
		>
			<AuthKitSlot
				name={ SLOT_PICKER_BEFORE_METHODS }
				fillProps={ fillProps }
			/>

			{ oauthMethods.map( ( method ) => (
				<Button
					key={ method }
					variant="secondary"
					onClick={ () => handleOAuth( method ) }
				>
					{ methodLabel( method ) }
				</Button>
			) ) }

			{ oauthMethods.length > 0 && ( hasPassword || hasMagic ) && (
				<Divider>{ __( 'or', 'integration-workos' ) }</Divider>
			) }

			{ hasMagic && (
				<Button onClick={ () => onChoose( 'magic_send' ) }>
					{ __( 'Sign in with a code by email', 'integration-workos' ) }
				</Button>
			) }

			{ hasPassword && (
				<Button variant="secondary" onClick={ () => onChoose( 'password' ) }>
					{ __( 'Sign in with password', 'integration-workos' ) }
				</Button>
			) }

			<AuthKitSlot
				name={ SLOT_PICKER_AFTER_METHODS }
				fillProps={ fillProps }
			/>
		</FlowCard>
	);
}

// -------------------------------------------------------------------- Password

interface PasswordProps {
	client: AuthKitClient;
	profile: Profile;
	onMfa: ( data: MfaRequired ) => void;
	onSuccess: ( data: LoginSuccess ) => void;
	onBack: ( target?: Step ) => void;
}

export function Password( { client, onMfa, onSuccess, onBack, profile }: PasswordProps ) {
	const [ email, setEmail ] = useState( '' );
	const [ password, setPassword ] = useState( '' );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );

	const submit = async ( event: FormEvent ) => {
		event.preventDefault();
		setLoading( true );
		setError( '' );
		const { ok, data } = await client.json< AuthResult >(
			'/password/authenticate',
			{ email, password, redirect_to: profile.redirectTo }
		);
		setLoading( false );
		if ( ! ok ) {
			setError( errorMessage( data ) );
			return;
		}
		const result = data as AuthResult;
		if ( isMfaRequired( result ) ) {
			onMfa( result );
			return;
		}
		onSuccess( result );
	};

	const fillProps = fillPropsFor( profile, 'password', 'password' );

	return (
		<FlowCard
			logoUrl={ profile.branding.logo_url }
			logoAlt={
				profile.branding.heading || __( 'Sign in', 'integration-workos' )
			}
			fillProps={ fillProps }
			header={ <Heading>{ __( 'Sign in', 'integration-workos' ) }</Heading> }
			footer={
				<p className="wa-footer">
					<LinkButton onClick={ () => onBack() }>
						{ __( '← Back', 'integration-workos' ) }
					</LinkButton>
				</p>
			}
		>
			{ error && <Alert variant="error">{ error }</Alert> }
			<form onSubmit={ submit }>
				<AuthKitSlot name={ SLOT_BEFORE_FORM } fillProps={ fillProps } />
				<Field label={ __( 'Email', 'integration-workos' ) } htmlFor="wa-email">
					<Input
						id="wa-email"
						type="email"
						value={ email }
						onChange={ setEmail }
						autoComplete="username"
						autoFocus={ true }
						required={ true }
					/>
				</Field>
				<Field label={ __( 'Password', 'integration-workos' ) } htmlFor="wa-password">
					<Input
						id="wa-password"
						type="password"
						value={ password }
						onChange={ setPassword }
						autoComplete="current-password"
						required={ true }
					/>
				</Field>
				<AuthKitSlot name={ SLOT_AFTER_FORM } fillProps={ fillProps } />
				<Button type="submit" disabled={ loading }>
					{ loading ? <Spinner /> : __( 'Sign in', 'integration-workos' ) }
				</Button>
				<AuthKitSlot
					name={ SLOT_AFTER_PRIMARY_ACTION }
					fillProps={ fillProps }
				/>
			</form>
		</FlowCard>
	);
}

// ------------------------------------------------------------------ MagicSend

interface MagicSendProps {
	client: AuthKitClient;
	profile: Profile;
	onCodeSent: ( email: string ) => void;
	onBack: () => void;
}

export function MagicSend( { client, profile, onCodeSent, onBack }: MagicSendProps ) {
	const [ email, setEmail ] = useState( '' );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );

	const submit = async ( event: FormEvent ) => {
		event.preventDefault();
		setLoading( true );
		setError( '' );
		const { ok, data } = await client.json( '/magic/send', { email } );
		setLoading( false );
		if ( ! ok ) {
			setError( errorMessage( data ) );
			return;
		}
		onCodeSent( email );
	};

	const fillProps = fillPropsFor( profile, 'magic_send', 'magic_send' );

	return (
		<FlowCard
			logoUrl={ profile.branding.logo_url }
			logoAlt={
				profile.branding.heading || __( 'Sign in', 'integration-workos' )
			}
			fillProps={ fillProps }
			header={
				<>
					<Heading>
						{ __( 'Sign in with a code', 'integration-workos' ) }
					</Heading>
					<Subheading>
						{ __(
							'We’ll email you a short code to sign in.',
							'integration-workos'
						) }
					</Subheading>
				</>
			}
			footer={
				<p className="wa-footer">
					<LinkButton onClick={ onBack }>
						{ __( '← Back', 'integration-workos' ) }
					</LinkButton>
				</p>
			}
		>
			{ error && <Alert variant="error">{ error }</Alert> }
			<form onSubmit={ submit }>
				<AuthKitSlot name={ SLOT_BEFORE_FORM } fillProps={ fillProps } />
				<Field label={ __( 'Email', 'integration-workos' ) } htmlFor="wa-email-magic">
					<Input
						id="wa-email-magic"
						type="email"
						value={ email }
						onChange={ setEmail }
						autoComplete="username"
						autoFocus={ true }
						required={ true }
					/>
				</Field>
				<AuthKitSlot name={ SLOT_AFTER_FORM } fillProps={ fillProps } />
				<Button type="submit" disabled={ loading }>
					{ loading ? <Spinner /> : __( 'Send code', 'integration-workos' ) }
				</Button>
				<AuthKitSlot
					name={ SLOT_AFTER_PRIMARY_ACTION }
					fillProps={ fillProps }
				/>
			</form>
		</FlowCard>
	);
}

// ---------------------------------------------------------------- MagicVerify

interface MagicVerifyProps {
	client: AuthKitClient;
	profile: Profile;
	email: string;
	onMfa: ( data: MfaRequired ) => void;
	onSuccess: ( data: LoginSuccess ) => void;
	onBack: () => void;
}

export function MagicVerify( {
	client,
	email,
	onMfa,
	onSuccess,
	onBack,
	profile,
}: MagicVerifyProps ) {
	const [ code, setCode ] = useState( '' );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );

	const submit = async ( event: FormEvent ) => {
		event.preventDefault();
		setLoading( true );
		setError( '' );
		const { ok, data } = await client.json< AuthResult >( '/magic/verify', {
			email,
			code,
			redirect_to: profile.redirectTo,
		} );
		setLoading( false );
		if ( ! ok ) {
			setError( errorMessage( data ) );
			return;
		}
		const result = data as AuthResult;
		if ( isMfaRequired( result ) ) {
			onMfa( result );
			return;
		}
		onSuccess( result );
	};

	const fillProps = fillPropsFor( profile, 'magic_verify', 'magic_verify' );

	return (
		<FlowCard
			logoUrl={ profile.branding.logo_url }
			logoAlt={
				profile.branding.heading || __( 'Sign in', 'integration-workos' )
			}
			fillProps={ fillProps }
			header={
				<>
					<Heading>
						{ __( 'Enter your code', 'integration-workos' ) }
					</Heading>
					<Subheading>
						{ sprintf(
							/* translators: %s: email address. */
							__( 'We sent a code to %s.', 'integration-workos' ),
							email
						) }
					</Subheading>
				</>
			}
			footer={
				<p className="wa-footer">
					<LinkButton onClick={ onBack }>
						{ __( '← Back', 'integration-workos' ) }
					</LinkButton>
				</p>
			}
		>
			{ error && <Alert variant="error">{ error }</Alert> }
			<form onSubmit={ submit }>
				<AuthKitSlot name={ SLOT_BEFORE_FORM } fillProps={ fillProps } />
				<Field label={ __( 'Code', 'integration-workos' ) } htmlFor="wa-code">
					<Input
						id="wa-code"
						type="text"
						value={ code }
						onChange={ setCode }
						autoComplete="one-time-code"
						autoFocus={ true }
						required={ true }
						placeholder="123456"
					/>
				</Field>
				<AuthKitSlot name={ SLOT_AFTER_FORM } fillProps={ fillProps } />
				<Button type="submit" disabled={ loading }>
					{ loading ? <Spinner /> : __( 'Sign in', 'integration-workos' ) }
				</Button>
				<AuthKitSlot
					name={ SLOT_AFTER_PRIMARY_ACTION }
					fillProps={ fillProps }
				/>
			</form>
		</FlowCard>
	);
}

// ---------------------------------------------------------------------- Mfa

interface MfaChallengeProps {
	client: AuthKitClient;
	profile: Profile;
	pendingAuthToken: string;
	factors: AuthFactor[];
	onSuccess: ( data: LoginSuccess ) => void;
}

export function MfaChallenge( {
	client,
	pendingAuthToken,
	factors,
	onSuccess,
	profile,
}: MfaChallengeProps ) {
	const firstFactor = factors && factors.length > 0 ? factors[ 0 ] : null;
	const [ challengeId, setChallengeId ] = useState( '' );
	const [ code, setCode ] = useState( '' );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		if ( ! firstFactor ) {
			return;
		}
		( async () => {
			const { ok, data } = await client.json< { challenge_id: string } >(
				'/mfa/challenge',
				{ factor_id: firstFactor.id }
			);
			if ( ! ok ) {
				setError( errorMessage( data ) );
				return;
			}
			setChallengeId( ( data as { challenge_id: string } ).challenge_id );
		} )();
	}, [ firstFactor, client ] );

	const submit = async ( event: FormEvent ) => {
		event.preventDefault();
		setLoading( true );
		setError( '' );
		const { ok, data } = await client.json< LoginSuccess >( '/mfa/verify', {
			pending_authentication_token: pendingAuthToken,
			authentication_challenge_id: challengeId,
			code,
			redirect_to: profile.redirectTo,
		} );
		setLoading( false );
		if ( ! ok ) {
			setError( errorMessage( data ) );
			return;
		}
		onSuccess( data as LoginSuccess );
	};

	const fillProps = fillPropsFor( profile, 'mfa', 'mfa' );

	return (
		<FlowCard
			logoUrl={ profile.branding.logo_url }
			logoAlt={
				profile.branding.heading || __( 'Sign in', 'integration-workos' )
			}
			fillProps={ fillProps }
			header={
				<>
					<Heading>
						{ __( 'Verify your identity', 'integration-workos' ) }
					</Heading>
					<Subheading>
						{ firstFactor?.type === 'sms'
							? __(
									'Enter the code we just sent to your phone.',
									'integration-workos'
							  )
							: __(
									'Enter the 6-digit code from your authenticator app.',
									'integration-workos'
							  ) }
					</Subheading>
				</>
			}
		>
			{ error && <Alert variant="error">{ error }</Alert> }
			<form onSubmit={ submit }>
				<AuthKitSlot name={ SLOT_BEFORE_FORM } fillProps={ fillProps } />
				<Field label={ __( 'Code', 'integration-workos' ) } htmlFor="wa-mfa-code">
					<Input
						id="wa-mfa-code"
						type="text"
						value={ code }
						onChange={ setCode }
						autoComplete="one-time-code"
						autoFocus={ true }
						required={ true }
						placeholder="123456"
					/>
				</Field>
				<AuthKitSlot name={ SLOT_AFTER_FORM } fillProps={ fillProps } />
				<Button type="submit" disabled={ loading || ! challengeId }>
					{ loading ? <Spinner /> : __( 'Verify', 'integration-workos' ) }
				</Button>
				<AuthKitSlot
					name={ SLOT_AFTER_PRIMARY_ACTION }
					fillProps={ fillProps }
				/>
			</form>
		</FlowCard>
	);
}

// -------------------------------------------------------------------- Signup

interface SignupContext {
	userId: string;
	email: string;
}

interface SignupProps {
	client: AuthKitClient;
	profile: Profile;
	onVerify: ( ctx: SignupContext ) => void;
	onBack: () => void;
}

export function Signup( { client, profile, onVerify, onBack }: SignupProps ) {
	const [ email, setEmail ] = useState( '' );
	const [ password, setPassword ] = useState( '' );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );

	const submit = async ( event: FormEvent ) => {
		event.preventDefault();
		setLoading( true );
		setError( '' );
		const { ok, data } = await client.json< {
			verification_needed: boolean;
			user: { id: string };
		} >( '/signup/create', { email, password } );
		setLoading( false );
		if ( ! ok ) {
			setError( errorMessage( data ) );
			return;
		}
		const payload = data as { verification_needed: boolean; user: { id: string } };
		if ( payload.verification_needed ) {
			onVerify( { userId: payload.user.id, email } );
		} else {
			// Email already verified — send them to sign-in.
			onBack();
		}
	};

	const fillProps = fillPropsFor( profile, 'signup', 'signup' );

	return (
		<FlowCard
			logoUrl={ profile.branding.logo_url }
			logoAlt={
				profile.branding.heading || __( 'Sign in', 'integration-workos' )
			}
			fillProps={ fillProps }
			header={
				<Heading>
					{ __( 'Create your account', 'integration-workos' ) }
				</Heading>
			}
			footer={
				<p className="wa-footer">
					<LinkButton onClick={ onBack }>
						{ __(
							'Already have an account? Sign in',
							'integration-workos'
						) }
					</LinkButton>
				</p>
			}
		>
			{ error && <Alert variant="error">{ error }</Alert> }
			<form onSubmit={ submit }>
				<AuthKitSlot name={ SLOT_BEFORE_FORM } fillProps={ fillProps } />
				<Field
					label={ __( 'Email', 'integration-workos' ) }
					htmlFor="wa-signup-email"
				>
					<Input
						id="wa-signup-email"
						type="email"
						value={ email }
						onChange={ setEmail }
						autoComplete="email"
						autoFocus={ true }
						required={ true }
					/>
				</Field>
				<Field
					label={ __( 'Password', 'integration-workos' ) }
					htmlFor="wa-signup-pw"
				>
					<Input
						id="wa-signup-pw"
						type="password"
						value={ password }
						onChange={ setPassword }
						autoComplete="new-password"
						required={ true }
					/>
				</Field>
				<AuthKitSlot name={ SLOT_AFTER_FORM } fillProps={ fillProps } />
				<Button type="submit" disabled={ loading }>
					{ loading ? <Spinner /> : __( 'Create account', 'integration-workos' ) }
				</Button>
				<AuthKitSlot
					name={ SLOT_AFTER_PRIMARY_ACTION }
					fillProps={ fillProps }
				/>
			</form>
		</FlowCard>
	);
}

// ---------------------------------------------------------------- SignupVerify

interface SignupVerifyProps {
	client: AuthKitClient;
	profile: Profile;
	userId: string;
	email: string;
	onDone: ( data: unknown ) => void;
}

export function SignupVerify( {
	client,
	profile,
	userId,
	email,
	onDone,
}: SignupVerifyProps ) {
	const [ code, setCode ] = useState( '' );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );

	const submit = async ( event: FormEvent ) => {
		event.preventDefault();
		setLoading( true );
		setError( '' );
		const { ok, data } = await client.json( '/signup/verify', {
			user_id: userId,
			code,
		} );
		setLoading( false );
		if ( ! ok ) {
			setError( errorMessage( data ) );
			return;
		}
		onDone( data );
	};

	const fillProps = fillPropsFor( profile, 'signup_verify', 'signup_verify' );

	return (
		<FlowCard
			logoUrl={ profile.branding.logo_url }
			logoAlt={
				profile.branding.heading || __( 'Sign in', 'integration-workos' )
			}
			fillProps={ fillProps }
			header={
				<>
					<Heading>
						{ __( 'Verify your email', 'integration-workos' ) }
					</Heading>
					<Subheading>
						{ sprintf(
							/* translators: %s: email address. */
							__( 'Enter the code we sent to %s.', 'integration-workos' ),
							email
						) }
					</Subheading>
				</>
			}
		>
			{ error && <Alert variant="error">{ error }</Alert> }
			<form onSubmit={ submit }>
				<AuthKitSlot name={ SLOT_BEFORE_FORM } fillProps={ fillProps } />
				<Field label={ __( 'Code', 'integration-workos' ) } htmlFor="wa-verify">
					<Input
						id="wa-verify"
						type="text"
						value={ code }
						onChange={ setCode }
						autoFocus={ true }
						required={ true }
						placeholder="123456"
					/>
				</Field>
				<AuthKitSlot name={ SLOT_AFTER_FORM } fillProps={ fillProps } />
				<Button type="submit" disabled={ loading }>
					{ loading ? <Spinner /> : __( 'Verify', 'integration-workos' ) }
				</Button>
				<AuthKitSlot
					name={ SLOT_AFTER_PRIMARY_ACTION }
					fillProps={ fillProps }
				/>
			</form>
		</FlowCard>
	);
}

// --------------------------------------------------------------------- Reset

interface ResetRequestProps {
	client: AuthKitClient;
	profile: Profile;
	onSent: ( email: string ) => void;
	onBack: () => void;
}

export function ResetRequest( { client, profile, onSent, onBack }: ResetRequestProps ) {
	const [ email, setEmail ] = useState( '' );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );

	const submit = async ( event: FormEvent ) => {
		event.preventDefault();
		setLoading( true );
		setError( '' );
		const { ok, data } = await client.json( '/password/reset/start', {
			email,
		} );
		setLoading( false );
		if ( ! ok ) {
			setError( errorMessage( data ) );
			return;
		}
		onSent( email );
	};

	const fillProps = fillPropsFor( profile, 'reset', 'reset' );

	return (
		<FlowCard
			logoUrl={ profile.branding.logo_url }
			logoAlt={
				profile.branding.heading || __( 'Sign in', 'integration-workos' )
			}
			fillProps={ fillProps }
			header={
				<>
					<Heading>
						{ __( 'Reset your password', 'integration-workos' ) }
					</Heading>
					<Subheading>
						{ __(
							'Enter your email and we’ll send a reset link.',
							'integration-workos'
						) }
					</Subheading>
				</>
			}
			footer={
				<p className="wa-footer">
					<LinkButton onClick={ onBack }>
						{ __( '← Back', 'integration-workos' ) }
					</LinkButton>
				</p>
			}
		>
			{ error && <Alert variant="error">{ error }</Alert> }
			<form onSubmit={ submit }>
				<AuthKitSlot name={ SLOT_BEFORE_FORM } fillProps={ fillProps } />
				<Field
					label={ __( 'Email', 'integration-workos' ) }
					htmlFor="wa-reset-email"
				>
					<Input
						id="wa-reset-email"
						type="email"
						value={ email }
						onChange={ setEmail }
						autoComplete="email"
						autoFocus={ true }
						required={ true }
					/>
				</Field>
				<AuthKitSlot name={ SLOT_AFTER_FORM } fillProps={ fillProps } />
				<Button type="submit" disabled={ loading }>
					{ loading ? <Spinner /> : __( 'Send reset link', 'integration-workos' ) }
				</Button>
				<AuthKitSlot
					name={ SLOT_AFTER_PRIMARY_ACTION }
					fillProps={ fillProps }
				/>
			</form>
		</FlowCard>
	);
}

interface ResetConfirmProps {
	client: AuthKitClient;
	profile: Profile;
	token: string;
	onDone: () => void;
}

export function ResetConfirm( { client, profile, token, onDone }: ResetConfirmProps ) {
	const [ password, setPassword ] = useState( '' );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ success, setSuccess ] = useState( false );

	const submit = async ( event: FormEvent ) => {
		event.preventDefault();
		setLoading( true );
		setError( '' );
		const { ok, data } = await client.json( '/password/reset/confirm', {
			token,
			new_password: password,
		} );
		setLoading( false );
		if ( ! ok ) {
			setError( errorMessage( data ) );
			return;
		}
		setSuccess( true );
	};

	const fillProps = fillPropsFor( profile, 'reset_confirm', 'reset_confirm' );
	const logoAlt =
		profile.branding.heading || __( 'Sign in', 'integration-workos' );

	if ( success ) {
		return (
			<FlowCard
				logoUrl={ profile.branding.logo_url }
				logoAlt={ logoAlt }
				fillProps={ fillProps }
				header={
					<>
						<Heading>
							{ __( 'Password reset', 'integration-workos' ) }
						</Heading>
						<Subheading>
							{ __(
								'You can now sign in with your new password.',
								'integration-workos'
							) }
						</Subheading>
					</>
				}
			>
				<Button onClick={ onDone }>
					{ __( 'Continue to sign in', 'integration-workos' ) }
				</Button>
				<AuthKitSlot
					name={ SLOT_AFTER_PRIMARY_ACTION }
					fillProps={ fillProps }
				/>
			</FlowCard>
		);
	}

	return (
		<FlowCard
			logoUrl={ profile.branding.logo_url }
			logoAlt={ logoAlt }
			fillProps={ fillProps }
			header={
				<Heading>
					{ __( 'Set a new password', 'integration-workos' ) }
				</Heading>
			}
		>
			{ error && <Alert variant="error">{ error }</Alert> }
			<form onSubmit={ submit }>
				<AuthKitSlot name={ SLOT_BEFORE_FORM } fillProps={ fillProps } />
				<Field
					label={ __( 'New password', 'integration-workos' ) }
					htmlFor="wa-new-pw"
				>
					<Input
						id="wa-new-pw"
						type="password"
						value={ password }
						onChange={ setPassword }
						autoComplete="new-password"
						autoFocus={ true }
						required={ true }
					/>
				</Field>
				<AuthKitSlot name={ SLOT_AFTER_FORM } fillProps={ fillProps } />
				<Button type="submit" disabled={ loading }>
					{ loading ? <Spinner /> : __( 'Save new password', 'integration-workos' ) }
				</Button>
				<AuthKitSlot
					name={ SLOT_AFTER_PRIMARY_ACTION }
					fillProps={ fillProps }
				/>
			</form>
		</FlowCard>
	);
}

// ----------------------------------------------------------- InvitationAccept

interface InvitationAcceptProps {
	client: AuthKitClient;
	invitationToken: string;
	profile: Profile;
	onSuccess: ( data: LoginSuccess ) => void;
}

export function InvitationAccept( {
	client,
	invitationToken,
	profile,
	onSuccess,
}: InvitationAcceptProps ) {
	const [ password, setPassword ] = useState( '' );
	const [ invitation, setInvitation ] = useState< InvitationLookup | null >( null );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );

	// The invitation email is authoritative and comes from WorkOS — we show
	// it read-only so the user sees who they're being invited as, but we
	// never send it back on accept. The server forwards only the token +
	// password, and WorkOS matches the invitation to its bound email.
	useEffect( () => {
		( async () => {
			const resp = await fetch(
				`${ profile.restBaseUrl }/invitation/${ encodeURIComponent( invitationToken ) }`,
				{ credentials: 'same-origin' }
			);
			const data = await resp.json();
			if ( resp.ok ) {
				setInvitation( data as InvitationLookup );
			} else {
				setError( errorMessage( data ) );
			}
		} )();
	}, [ invitationToken, profile.restBaseUrl ] );

	const submit = async ( event: FormEvent ) => {
		event.preventDefault();
		setLoading( true );
		setError( '' );
		const { ok, data } = await client.json< LoginSuccess >(
			'/invitation/accept',
			{
				invitation_token: invitationToken,
				password,
				redirect_to: profile.redirectTo,
			}
		);
		setLoading( false );
		if ( ! ok ) {
			setError( errorMessage( data ) );
			return;
		}
		onSuccess( data as LoginSuccess );
	};

	const fillProps = fillPropsFor( profile, 'invitation', 'invitation' );

	return (
		<FlowCard
			logoUrl={ profile.branding.logo_url }
			logoAlt={
				profile.branding.heading || __( 'Sign in', 'integration-workos' )
			}
			fillProps={ fillProps }
			header={
				<>
					<Heading>
						{ __( 'Accept your invitation', 'integration-workos' ) }
					</Heading>
					{ invitation && (
						<Subheading>
							{ sprintf(
								/* translators: %s: email address. */
								__( 'Welcome, %s.', 'integration-workos' ),
								invitation.email
							) }
						</Subheading>
					) }
				</>
			}
		>
			{ error && <Alert variant="error">{ error }</Alert> }
			<form onSubmit={ submit }>
				<AuthKitSlot name={ SLOT_BEFORE_FORM } fillProps={ fillProps } />
				<Field
					label={ __( 'Set a password', 'integration-workos' ) }
					htmlFor="wa-inv-pw"
				>
					<Input
						id="wa-inv-pw"
						type="password"
						value={ password }
						onChange={ setPassword }
						autoComplete="new-password"
						autoFocus={ true }
						required={ true }
					/>
				</Field>
				<AuthKitSlot name={ SLOT_AFTER_FORM } fillProps={ fillProps } />
				<Button type="submit" disabled={ loading }>
					{ loading ? <Spinner /> : __( 'Accept invitation', 'integration-workos' ) }
				</Button>
				<AuthKitSlot
					name={ SLOT_AFTER_PRIMARY_ACTION }
					fillProps={ fillProps }
				/>
			</form>
		</FlowCard>
	);
}

// ------------------------------------------------------------------ Complete

export function Complete( { redirectTo }: { redirectTo: string } ) {
	useEffect( () => {
		if ( redirectTo ) {
			window.location.assign( redirectTo );
		}
	}, [ redirectTo ] );

	return (
		<Card>
			<Heading>{ __( 'Signed in', 'integration-workos' ) }</Heading>
			<Subheading>{ __( 'Redirecting…', 'integration-workos' ) }</Subheading>
			<Spinner />
		</Card>
	);
}
