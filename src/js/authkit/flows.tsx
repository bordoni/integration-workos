/**
 * AuthKit flow components.
 *
 * Each flow is a small state machine that renders a card of inputs and
 * reports its outcome to the parent App via `onDone`. Flows never hold
 * global state — App.tsx owns step transitions + resulting session.
 */

import { useEffect, useState } from '@wordpress/element';
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
	Heading,
	Input,
	LinkButton,
	Spinner,
	Subheading,
} from './ui';

const METHOD_LABELS: Partial< Record< AuthMethod, { label: string; key: string } > > = {
	oauth_google:    { label: 'Continue with Google',    key: 'google' },
	oauth_microsoft: { label: 'Continue with Microsoft', key: 'microsoft' },
	oauth_github:    { label: 'Continue with GitHub',    key: 'github' },
	oauth_apple:     { label: 'Continue with Apple',     key: 'apple' },
};

function errorMessage( data: unknown ): string {
	if ( ! data || typeof data !== 'object' ) {
		return 'Something went wrong. Please try again.';
	}
	const err = data as ApiError;
	return err.message || err.code || 'Unexpected error.';
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
			onError( 'Unable to start social sign-in.' );
		}
	};

	return (
		<Card>
			<Heading>{ profile.branding.heading || 'Sign in' }</Heading>
			{ profile.branding.subheading && (
				<Subheading>{ profile.branding.subheading }</Subheading>
			) }

			{ oauthMethods.map( ( method ) => (
				<Button
					key={ method }
					variant="secondary"
					onClick={ () => handleOAuth( method ) }
				>
					{ METHOD_LABELS[ method ]?.label || method }
				</Button>
			) ) }

			{ oauthMethods.length > 0 && ( hasPassword || hasMagic ) && (
				<Divider>or</Divider>
			) }

			{ hasMagic && (
				<Button onClick={ () => onChoose( 'magic_send' ) }>
					Sign in with a code by email
				</Button>
			) }

			{ hasPassword && (
				<Button variant="secondary" onClick={ () => onChoose( 'password' ) }>
					Sign in with password
				</Button>
			) }

			{ profile.signup?.enabled && ! profile.signup?.require_invite && (
				<p className="wa-footer">
					New here?{ ' ' }
					<LinkButton onClick={ () => onChoose( 'signup' ) }>
						Create an account
					</LinkButton>
				</p>
			) }
		</Card>
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

	return (
		<Card>
			<Heading>Sign in</Heading>
			{ error && <Alert variant="error">{ error }</Alert> }
			<form onSubmit={ submit }>
				<Field label="Email" htmlFor="wa-email">
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
				<Field label="Password" htmlFor="wa-password">
					<Input
						id="wa-password"
						type="password"
						value={ password }
						onChange={ setPassword }
						autoComplete="current-password"
						required={ true }
					/>
				</Field>
				<Button type="submit" disabled={ loading }>
					{ loading ? <Spinner /> : 'Sign in' }
				</Button>
			</form>
			{ profile.password_reset_flow && (
				<p className="wa-footer">
					<LinkButton onClick={ () => onBack( 'reset' ) }>
						Forgot your password?
					</LinkButton>
				</p>
			) }
			<p className="wa-footer">
				<LinkButton onClick={ () => onBack() }>← Back</LinkButton>
			</p>
		</Card>
	);
}

// ------------------------------------------------------------------ MagicSend

interface MagicSendProps {
	client: AuthKitClient;
	onCodeSent: ( email: string ) => void;
	onBack: () => void;
}

export function MagicSend( { client, onCodeSent, onBack }: MagicSendProps ) {
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

	return (
		<Card>
			<Heading>Sign in with a code</Heading>
			<Subheading>We&apos;ll email you a short code to sign in.</Subheading>
			{ error && <Alert variant="error">{ error }</Alert> }
			<form onSubmit={ submit }>
				<Field label="Email" htmlFor="wa-email-magic">
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
				<Button type="submit" disabled={ loading }>
					{ loading ? <Spinner /> : 'Send code' }
				</Button>
			</form>
			<p className="wa-footer">
				<LinkButton onClick={ onBack }>← Back</LinkButton>
			</p>
		</Card>
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

	return (
		<Card>
			<Heading>Enter your code</Heading>
			<Subheading>We sent a code to { email }.</Subheading>
			{ error && <Alert variant="error">{ error }</Alert> }
			<form onSubmit={ submit }>
				<Field label="Code" htmlFor="wa-code">
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
				<Button type="submit" disabled={ loading }>
					{ loading ? <Spinner /> : 'Sign in' }
				</Button>
			</form>
			<p className="wa-footer">
				<LinkButton onClick={ onBack }>← Back</LinkButton>
			</p>
		</Card>
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

	return (
		<Card>
			<Heading>Verify your identity</Heading>
			<Subheading>
				{ firstFactor?.type === 'sms'
					? 'Enter the code we just sent to your phone.'
					: 'Enter the 6-digit code from your authenticator app.' }
			</Subheading>
			{ error && <Alert variant="error">{ error }</Alert> }
			<form onSubmit={ submit }>
				<Field label="Code" htmlFor="wa-mfa-code">
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
				<Button type="submit" disabled={ loading || ! challengeId }>
					{ loading ? <Spinner /> : 'Verify' }
				</Button>
			</form>
		</Card>
	);
}

// -------------------------------------------------------------------- Signup

interface SignupContext {
	userId: string;
	email: string;
}

interface SignupProps {
	client: AuthKitClient;
	onVerify: ( ctx: SignupContext ) => void;
	onBack: () => void;
}

export function Signup( { client, onVerify, onBack }: SignupProps ) {
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

	return (
		<Card>
			<Heading>Create your account</Heading>
			{ error && <Alert variant="error">{ error }</Alert> }
			<form onSubmit={ submit }>
				<Field label="Email" htmlFor="wa-signup-email">
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
				<Field label="Password" htmlFor="wa-signup-pw">
					<Input
						id="wa-signup-pw"
						type="password"
						value={ password }
						onChange={ setPassword }
						autoComplete="new-password"
						required={ true }
					/>
				</Field>
				<Button type="submit" disabled={ loading }>
					{ loading ? <Spinner /> : 'Create account' }
				</Button>
			</form>
			<p className="wa-footer">
				<LinkButton onClick={ onBack }>Already have an account? Sign in</LinkButton>
			</p>
		</Card>
	);
}

// ---------------------------------------------------------------- SignupVerify

interface SignupVerifyProps {
	client: AuthKitClient;
	userId: string;
	email: string;
	onDone: ( data: unknown ) => void;
}

export function SignupVerify( { client, userId, email, onDone }: SignupVerifyProps ) {
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

	return (
		<Card>
			<Heading>Verify your email</Heading>
			<Subheading>Enter the code we sent to { email }.</Subheading>
			{ error && <Alert variant="error">{ error }</Alert> }
			<form onSubmit={ submit }>
				<Field label="Code" htmlFor="wa-verify">
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
				<Button type="submit" disabled={ loading }>
					{ loading ? <Spinner /> : 'Verify' }
				</Button>
			</form>
		</Card>
	);
}

// --------------------------------------------------------------------- Reset

interface ResetRequestProps {
	client: AuthKitClient;
	onSent: ( email: string ) => void;
	onBack: () => void;
}

export function ResetRequest( { client, onSent, onBack }: ResetRequestProps ) {
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

	return (
		<Card>
			<Heading>Reset your password</Heading>
			<Subheading>Enter your email and we&apos;ll send a reset link.</Subheading>
			{ error && <Alert variant="error">{ error }</Alert> }
			<form onSubmit={ submit }>
				<Field label="Email" htmlFor="wa-reset-email">
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
				<Button type="submit" disabled={ loading }>
					{ loading ? <Spinner /> : 'Send reset link' }
				</Button>
			</form>
			<p className="wa-footer">
				<LinkButton onClick={ onBack }>← Back</LinkButton>
			</p>
		</Card>
	);
}

interface ResetConfirmProps {
	client: AuthKitClient;
	token: string;
	onDone: () => void;
}

export function ResetConfirm( { client, token, onDone }: ResetConfirmProps ) {
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

	if ( success ) {
		return (
			<Card>
				<Heading>Password reset</Heading>
				<Subheading>You can now sign in with your new password.</Subheading>
				<Button onClick={ onDone }>Continue to sign in</Button>
			</Card>
		);
	}

	return (
		<Card>
			<Heading>Set a new password</Heading>
			{ error && <Alert variant="error">{ error }</Alert> }
			<form onSubmit={ submit }>
				<Field label="New password" htmlFor="wa-new-pw">
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
				<Button type="submit" disabled={ loading }>
					{ loading ? <Spinner /> : 'Save new password' }
				</Button>
			</form>
		</Card>
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

	return (
		<Card>
			<Heading>Accept your invitation</Heading>
			{ invitation && <Subheading>Welcome, { invitation.email }.</Subheading> }
			{ error && <Alert variant="error">{ error }</Alert> }
			<form onSubmit={ submit }>
				<Field label="Set a password" htmlFor="wa-inv-pw">
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
				<Button type="submit" disabled={ loading }>
					{ loading ? <Spinner /> : 'Accept invitation' }
				</Button>
			</form>
		</Card>
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
			<Heading>Signed in</Heading>
			<Subheading>Redirecting…</Subheading>
			<Spinner />
		</Card>
	);
}
