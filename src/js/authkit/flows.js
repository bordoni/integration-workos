/**
 * AuthKit flow components.
 *
 * Each flow is a small state machine that renders a card of inputs and
 * reports its outcome to the parent App via `onDone`. Flows never hold
 * global state — App.js owns step transitions + resulting session.
 */

import { createElement as h, Fragment, useState, useEffect } from '@wordpress/element';
import { Alert, Button, Card, Divider, Field, Heading, Input, LinkButton, Spinner, Subheading } from './ui';

const METHOD_LABELS = {
	oauth_google:    { label: 'Continue with Google',    key: 'google' },
	oauth_microsoft: { label: 'Continue with Microsoft', key: 'microsoft' },
	oauth_github:    { label: 'Continue with GitHub',    key: 'github' },
	oauth_apple:     { label: 'Continue with Apple',     key: 'apple' },
};

function errorMessage( data ) {
	if ( ! data ) {
		return 'Something went wrong. Please try again.';
	}
	return data.message || data.code || 'Unexpected error.';
}

// ---------------------------------------------------------------- MethodPicker

export function MethodPicker( { profile, onChoose, onError } ) {
	const methods = profile.methods || [];
	const hasPassword = methods.includes( 'password' );
	const hasMagic = methods.includes( 'magic_code' );
	const oauthMethods = methods.filter( ( m ) => m.startsWith( 'oauth_' ) );

	const handleOAuth = async ( method ) => {
		try {
			const response = await fetch(
				`${ profile.restBaseUrl }/oauth/authorize-url?profile=${ encodeURIComponent( profile.slug ) }&provider=${ encodeURIComponent( method ) }&redirect_to=${ encodeURIComponent( profile.redirectTo || '' ) }`,
				{ credentials: 'same-origin' }
			);
			const data = await response.json();
			if ( response.ok && data.authorize_url ) {
				window.location.assign( data.authorize_url );
			} else {
				onError( errorMessage( data ) );
			}
		} catch ( e ) {
			onError( 'Unable to start social sign-in.' );
		}
	};

	return h(
		Card,
		null,
		h( Heading, null, profile.branding.heading || 'Sign in' ),
		profile.branding.subheading && h( Subheading, null, profile.branding.subheading ),

		oauthMethods.map( ( method ) =>
			h(
				Button,
				{
					key: method,
					variant: 'secondary',
					onClick: () => handleOAuth( method ),
				},
				METHOD_LABELS[ method ]?.label || method
			)
		),

		oauthMethods.length > 0 && ( hasPassword || hasMagic )
			&& h( Divider, null, 'or' ),

		hasMagic && h(
			Button,
			{ onClick: () => onChoose( 'magic_send' ) },
			'Sign in with a code by email'
		),

		hasPassword && h(
			Button,
			{ variant: 'secondary', onClick: () => onChoose( 'password' ) },
			'Sign in with password'
		),

		profile.signup?.enabled && ! profile.signup?.require_invite && h(
			'p',
			{ className: 'wa-footer' },
			'New here? ',
			h(
				LinkButton,
				{ onClick: () => onChoose( 'signup' ) },
				'Create an account'
			)
		)
	);
}

// -------------------------------------------------------------------- Password

export function Password( { client, onMfa, onSuccess, onBack, profile } ) {
	const [ email, setEmail ] = useState( '' );
	const [ password, setPassword ] = useState( '' );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );

	const submit = async ( event ) => {
		event.preventDefault();
		setLoading( true );
		setError( '' );
		const { ok, data } = await client.json(
			'/password/authenticate',
			{ email, password, redirect_to: profile.redirectTo }
		);
		setLoading( false );
		if ( ! ok ) {
			setError( errorMessage( data ) );
			return;
		}
		if ( data.mfa_required ) {
			onMfa( data );
			return;
		}
		onSuccess( data );
	};

	return h(
		Card,
		null,
		h( Heading, null, 'Sign in' ),
		error && h( Alert, { variant: 'error' }, error ),
		h(
			'form',
			{ onSubmit: submit },
			h( Field, { label: 'Email', htmlFor: 'wa-email' },
				h( Input, {
					id: 'wa-email', type: 'email', value: email, onChange: setEmail,
					autoComplete: 'username', autoFocus: true, required: true,
				} )
			),
			h( Field, { label: 'Password', htmlFor: 'wa-password' },
				h( Input, {
					id: 'wa-password', type: 'password', value: password, onChange: setPassword,
					autoComplete: 'current-password', required: true,
				} )
			),
			h( Button, { type: 'submit', disabled: loading },
				loading ? h( Spinner ) : 'Sign in'
			)
		),
		profile.password_reset_flow && h(
			'p',
			{ className: 'wa-footer' },
			h( LinkButton, { onClick: () => onBack( 'reset' ) }, 'Forgot your password?' )
		),
		h(
			'p',
			{ className: 'wa-footer' },
			h( LinkButton, { onClick: () => onBack() }, '← Back' )
		)
	);
}

// ------------------------------------------------------------------ MagicSend

export function MagicSend( { client, onCodeSent, onBack } ) {
	const [ email, setEmail ] = useState( '' );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );

	const submit = async ( event ) => {
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

	return h(
		Card,
		null,
		h( Heading, null, 'Sign in with a code' ),
		h( Subheading, null, "We'll email you a short code to sign in." ),
		error && h( Alert, { variant: 'error' }, error ),
		h(
			'form',
			{ onSubmit: submit },
			h( Field, { label: 'Email', htmlFor: 'wa-email-magic' },
				h( Input, {
					id: 'wa-email-magic', type: 'email', value: email, onChange: setEmail,
					autoComplete: 'username', autoFocus: true, required: true,
				} )
			),
			h( Button, { type: 'submit', disabled: loading }, loading ? h( Spinner ) : 'Send code' )
		),
		h( 'p', { className: 'wa-footer' },
			h( LinkButton, { onClick: onBack }, '← Back' )
		)
	);
}

// ---------------------------------------------------------------- MagicVerify

export function MagicVerify( { client, email, onMfa, onSuccess, onBack, profile } ) {
	const [ code, setCode ] = useState( '' );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );

	const submit = async ( event ) => {
		event.preventDefault();
		setLoading( true );
		setError( '' );
		const { ok, data } = await client.json(
			'/magic/verify',
			{ email, code, redirect_to: profile.redirectTo }
		);
		setLoading( false );
		if ( ! ok ) {
			setError( errorMessage( data ) );
			return;
		}
		if ( data.mfa_required ) {
			onMfa( data );
			return;
		}
		onSuccess( data );
	};

	return h(
		Card,
		null,
		h( Heading, null, 'Enter your code' ),
		h( Subheading, null, `We sent a code to ${ email }.` ),
		error && h( Alert, { variant: 'error' }, error ),
		h(
			'form',
			{ onSubmit: submit },
			h( Field, { label: 'Code', htmlFor: 'wa-code' },
				h( Input, {
					id: 'wa-code', type: 'text', value: code, onChange: setCode,
					autoComplete: 'one-time-code', autoFocus: true, required: true,
					placeholder: '123456',
				} )
			),
			h( Button, { type: 'submit', disabled: loading }, loading ? h( Spinner ) : 'Sign in' )
		),
		h( 'p', { className: 'wa-footer' },
			h( LinkButton, { onClick: onBack }, '← Back' )
		)
	);
}

// ---------------------------------------------------------------------- Mfa

export function MfaChallenge( { client, pendingAuthToken, factors, onSuccess, profile } ) {
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
			const { ok, data } = await client.json(
				'/mfa/challenge',
				{ factor_id: firstFactor.id }
			);
			if ( ! ok ) {
				setError( errorMessage( data ) );
				return;
			}
			setChallengeId( data.challenge_id );
		} )();
	}, [ firstFactor ] );

	const submit = async ( event ) => {
		event.preventDefault();
		setLoading( true );
		setError( '' );
		const { ok, data } = await client.json(
			'/mfa/verify',
			{
				pending_authentication_token: pendingAuthToken,
				authentication_challenge_id: challengeId,
				code,
				redirect_to: profile.redirectTo,
			}
		);
		setLoading( false );
		if ( ! ok ) {
			setError( errorMessage( data ) );
			return;
		}
		onSuccess( data );
	};

	return h(
		Card,
		null,
		h( Heading, null, 'Verify your identity' ),
		h( Subheading, null,
			firstFactor?.type === 'sms'
				? 'Enter the code we just sent to your phone.'
				: 'Enter the 6-digit code from your authenticator app.'
		),
		error && h( Alert, { variant: 'error' }, error ),
		h(
			'form',
			{ onSubmit: submit },
			h( Field, { label: 'Code', htmlFor: 'wa-mfa-code' },
				h( Input, {
					id: 'wa-mfa-code', type: 'text', value: code, onChange: setCode,
					autoComplete: 'one-time-code', autoFocus: true, required: true, placeholder: '123456',
				} )
			),
			h( Button, { type: 'submit', disabled: loading || ! challengeId },
				loading ? h( Spinner ) : 'Verify'
			)
		)
	);
}

// -------------------------------------------------------------------- Signup

export function Signup( { client, onVerify, onBack } ) {
	const [ email, setEmail ] = useState( '' );
	const [ password, setPassword ] = useState( '' );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );

	const submit = async ( event ) => {
		event.preventDefault();
		setLoading( true );
		setError( '' );
		const { ok, data } = await client.json(
			'/signup/create',
			{ email, password }
		);
		setLoading( false );
		if ( ! ok ) {
			setError( errorMessage( data ) );
			return;
		}
		if ( data.verification_needed ) {
			onVerify( { userId: data.user.id, email } );
		} else {
			// Email already verified — send them to sign-in.
			onBack();
		}
	};

	return h(
		Card,
		null,
		h( Heading, null, 'Create your account' ),
		error && h( Alert, { variant: 'error' }, error ),
		h(
			'form',
			{ onSubmit: submit },
			h( Field, { label: 'Email', htmlFor: 'wa-signup-email' },
				h( Input, {
					id: 'wa-signup-email', type: 'email', value: email, onChange: setEmail,
					autoComplete: 'email', autoFocus: true, required: true,
				} )
			),
			h( Field, { label: 'Password', htmlFor: 'wa-signup-pw' },
				h( Input, {
					id: 'wa-signup-pw', type: 'password', value: password, onChange: setPassword,
					autoComplete: 'new-password', required: true,
				} )
			),
			h( Button, { type: 'submit', disabled: loading }, loading ? h( Spinner ) : 'Create account' )
		),
		h( 'p', { className: 'wa-footer' },
			h( LinkButton, { onClick: onBack }, 'Already have an account? Sign in' )
		)
	);
}

// ---------------------------------------------------------------- SignupVerify

export function SignupVerify( { client, userId, email, onDone } ) {
	const [ code, setCode ] = useState( '' );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );

	const submit = async ( event ) => {
		event.preventDefault();
		setLoading( true );
		setError( '' );
		const { ok, data } = await client.json(
			'/signup/verify',
			{ user_id: userId, code }
		);
		setLoading( false );
		if ( ! ok ) {
			setError( errorMessage( data ) );
			return;
		}
		onDone( data );
	};

	return h(
		Card,
		null,
		h( Heading, null, 'Verify your email' ),
		h( Subheading, null, `Enter the code we sent to ${ email }.` ),
		error && h( Alert, { variant: 'error' }, error ),
		h(
			'form',
			{ onSubmit: submit },
			h( Field, { label: 'Code', htmlFor: 'wa-verify' },
				h( Input, {
					id: 'wa-verify', type: 'text', value: code, onChange: setCode,
					autoFocus: true, required: true, placeholder: '123456',
				} )
			),
			h( Button, { type: 'submit', disabled: loading }, loading ? h( Spinner ) : 'Verify' )
		)
	);
}

// --------------------------------------------------------------------- Reset

export function ResetRequest( { client, onSent, onBack } ) {
	const [ email, setEmail ] = useState( '' );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );

	const submit = async ( event ) => {
		event.preventDefault();
		setLoading( true );
		setError( '' );
		const { ok, data } = await client.json(
			'/password/reset/start',
			{ email }
		);
		setLoading( false );
		if ( ! ok ) {
			setError( errorMessage( data ) );
			return;
		}
		onSent( email );
	};

	return h(
		Card,
		null,
		h( Heading, null, 'Reset your password' ),
		h( Subheading, null, "Enter your email and we'll send a reset link." ),
		error && h( Alert, { variant: 'error' }, error ),
		h(
			'form',
			{ onSubmit: submit },
			h( Field, { label: 'Email', htmlFor: 'wa-reset-email' },
				h( Input, {
					id: 'wa-reset-email', type: 'email', value: email, onChange: setEmail,
					autoComplete: 'email', autoFocus: true, required: true,
				} )
			),
			h( Button, { type: 'submit', disabled: loading }, loading ? h( Spinner ) : 'Send reset link' )
		),
		h( 'p', { className: 'wa-footer' },
			h( LinkButton, { onClick: onBack }, '← Back' )
		)
	);
}

export function ResetConfirm( { client, token, onDone } ) {
	const [ password, setPassword ] = useState( '' );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ success, setSuccess ] = useState( false );

	const submit = async ( event ) => {
		event.preventDefault();
		setLoading( true );
		setError( '' );
		const { ok, data } = await client.json(
			'/password/reset/confirm',
			{ token, new_password: password }
		);
		setLoading( false );
		if ( ! ok ) {
			setError( errorMessage( data ) );
			return;
		}
		setSuccess( true );
	};

	if ( success ) {
		return h(
			Card,
			null,
			h( Heading, null, 'Password reset' ),
			h( Subheading, null, 'You can now sign in with your new password.' ),
			h( Button, { onClick: onDone }, 'Continue to sign in' )
		);
	}

	return h(
		Card,
		null,
		h( Heading, null, 'Set a new password' ),
		error && h( Alert, { variant: 'error' }, error ),
		h(
			'form',
			{ onSubmit: submit },
			h( Field, { label: 'New password', htmlFor: 'wa-new-pw' },
				h( Input, {
					id: 'wa-new-pw', type: 'password', value: password, onChange: setPassword,
					autoComplete: 'new-password', autoFocus: true, required: true,
				} )
			),
			h( Button, { type: 'submit', disabled: loading }, loading ? h( Spinner ) : 'Save new password' )
		)
	);
}

// ----------------------------------------------------------- InvitationAccept

export function InvitationAccept( { client, invitationToken, profile, onSuccess } ) {
	const [ email, setEmail ] = useState( '' );
	const [ password, setPassword ] = useState( '' );
	const [ invitation, setInvitation ] = useState( null );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		( async () => {
			const resp = await fetch(
				`${ profile.restBaseUrl }/invitation/${ encodeURIComponent( invitationToken ) }`,
				{ credentials: 'same-origin' }
			);
			const data = await resp.json();
			if ( resp.ok ) {
				setInvitation( data );
				setEmail( data.email || '' );
			} else {
				setError( errorMessage( data ) );
			}
		} )();
	}, [ invitationToken ] );

	const submit = async ( event ) => {
		event.preventDefault();
		setLoading( true );
		setError( '' );
		const { ok, data } = await client.json(
			'/invitation/accept',
			{
				invitation_token: invitationToken,
				email,
				password,
				redirect_to: profile.redirectTo,
			}
		);
		setLoading( false );
		if ( ! ok ) {
			setError( errorMessage( data ) );
			return;
		}
		onSuccess( data );
	};

	return h(
		Card,
		null,
		h( Heading, null, 'Accept your invitation' ),
		invitation && h( Subheading, null, `Welcome, ${ invitation.email }.` ),
		error && h( Alert, { variant: 'error' }, error ),
		h(
			'form',
			{ onSubmit: submit },
			h( Field, { label: 'Email', htmlFor: 'wa-inv-email' },
				h( Input, {
					id: 'wa-inv-email', type: 'email', value: email, onChange: setEmail,
					required: true, disabled: !! invitation?.email,
				} )
			),
			h( Field, { label: 'Set a password', htmlFor: 'wa-inv-pw' },
				h( Input, {
					id: 'wa-inv-pw', type: 'password', value: password, onChange: setPassword,
					autoComplete: 'new-password', autoFocus: true, required: true,
				} )
			),
			h( Button, { type: 'submit', disabled: loading }, loading ? h( Spinner ) : 'Accept invitation' )
		)
	);
}

// ------------------------------------------------------------------ Complete

export function Complete( { redirectTo } ) {
	useEffect( () => {
		if ( redirectTo ) {
			window.location.assign( redirectTo );
		}
	}, [ redirectTo ] );

	return h(
		Card,
		null,
		h( Heading, null, 'Signed in' ),
		h( Subheading, null, 'Redirecting…' ),
		h( Spinner )
	);
}
